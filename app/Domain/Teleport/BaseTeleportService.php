<?php

namespace App\Domain\Teleport;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Exceptions\CannotBaseTeleportException;
use App\Domain\Exceptions\CannotPurchaseException;
use App\Domain\Exceptions\InsufficientMovesException;
use App\Domain\Notifications\ActivityLogService;
use App\Domain\Player\MoveRegenService;
use App\Domain\World\FogOfWarService;
use App\Events\BaseRelocated;
use App\Models\Player;
use App\Models\SpyAttempt;
use App\Models\Tile;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Handles the three toolbox-triggered base teleport items:
 *
 *   Homing Flare       — unlimited-use snap back to your own base.
 *                        Single-purchase (player_items quantity stays
 *                        at 1). Each use deducts moves + barrels.
 *
 *   Foundation Charge  — one-shot relocation of your OWN base to the
 *                        wasteland tile you're standing on. Stackable,
 *                        consumed on success.
 *
 *   Abduction Anchor   — one-shot relocation of a RIVAL's base to
 *                        your current wasteland tile. Stackable,
 *                        consumed on success ONLY. Requires a fresh
 *                        successful spy on the target and is blocked
 *                        by same-MDN, immunity, and Deadbolt Plinth.
 *
 * Every write happens inside a DB transaction. Lock acquisition
 * follows a strict ascending-ID order for both players AND tiles
 * to eliminate ABBA deadlocks when two players act simultaneously:
 *   - `moveEnemyBase` locks the caller + target Player rows in
 *     ascending ID, then the destination + old-base Tile rows in
 *     ascending ID.
 *   - `moveOwnBase` has only one player but still sorts the two
 *     tile locks by ID so concurrent Foundation Charges by two
 *     players onto each other's former base tiles cannot cycle.
 *
 * All guards run BEFORE any decrement / tile-type mutation so a
 * rejection leaves state untouched — user spec: "not consumed" on
 * failed use.
 */
class BaseTeleportService
{
    public const HOMING_FLARE_KEY = 'homing_flare';

    public const FOUNDATION_CHARGE_KEY = 'foundation_charge';

    public const ABDUCTION_ANCHOR_KEY = 'abduction_anchor';

    public function __construct(
        private readonly GameConfigResolver $config,
        private readonly FogOfWarService $fogOfWar,
        private readonly MoveRegenService $moveRegen,
        private readonly ActivityLogService $activityLog,
    ) {}

    /**
     * Homing Flare — teleport the caller back to their own base.
     *
     * Guards:
     *   - owns a homing_flare in player_items (quantity ≥ 1)
     *   - has a base_tile_id set
     *   - not already standing on the base (no wasted flares)
     *   - has moves_current ≥ configured move cost
     *   - has oil_barrels ≥ configured per-use barrel cost
     *
     * Move regen is reconciled before the move-budget check so a
     * player with pending regen doesn't get spuriously blocked.
     *
     * @return Tile the new current tile (== base tile)
     */
    public function teleportSelfToBase(int $playerId): Tile
    {
        return DB::transaction(function () use ($playerId) {
            /** @var Player $player */
            $player = Player::query()->lockForUpdate()->findOrFail($playerId);

            $owns = DB::table('player_items')
                ->where('player_id', $player->id)
                ->where('item_key', self::HOMING_FLARE_KEY)
                ->where('status', 'active')
                ->where('quantity', '>', 0)
                ->exists();

            if (! $owns) {
                throw CannotBaseTeleportException::homingFlareNotOwned();
            }

            if ($player->base_tile_id === null) {
                throw CannotBaseTeleportException::targetNotFound();
            }

            if ((int) $player->current_tile_id === (int) $player->base_tile_id) {
                throw CannotBaseTeleportException::alreadyAtBase();
            }

            $this->moveRegen->reconcile($player);
            $player->refresh();

            $moveCost = (int) $this->config->get('teleport_items.homing_flare.move_cost');
            if ($moveCost > 0 && (int) $player->moves_current < $moveCost) {
                throw InsufficientMovesException::forAction('homing_flare', (int) $player->moves_current, $moveCost);
            }

            $barrelCost = (int) $this->config->get('teleport_items.homing_flare.oil_cost_per_use');
            if ((int) $player->oil_barrels < $barrelCost) {
                throw CannotPurchaseException::insufficientBarrels((int) $player->oil_barrels, $barrelCost);
            }

            /** @var Tile $base */
            $base = Tile::query()->findOrFail($player->base_tile_id);

            $player->update([
                'oil_barrels' => (int) $player->oil_barrels - $barrelCost,
                'moves_current' => (int) $player->moves_current - $moveCost,
                'current_tile_id' => $base->id,
            ]);

            // Defensive — the base tile has always been discovered at
            // signup, but calling this is cheap and guarantees fog state
            // is consistent if a future migration ever moves bases onto
            // undiscovered tiles.
            $this->fogOfWar->markDiscovered($player->id, $base->id);

            return $base;
        });
    }

    /**
     * Foundation Charge — relocate the caller's OWN base to the
     * wasteland tile they are currently standing on.
     *
     * @return Tile the new base tile
     */
    public function moveOwnBase(int $playerId): Tile
    {
        return DB::transaction(function () use ($playerId) {
            /** @var Player $player */
            $player = Player::query()->lockForUpdate()->findOrFail($playerId);

            $inventoryRow = DB::table('player_items')
                ->where('player_id', $player->id)
                ->where('item_key', self::FOUNDATION_CHARGE_KEY)
                ->where('status', 'active')
                ->where('quantity', '>', 0)
                ->lockForUpdate()
                ->first();

            if ($inventoryRow === null) {
                throw CannotBaseTeleportException::foundationChargeNotOwned();
            }

            // Tile locks in ascending ID order: destination + old base.
            // Two concurrent Foundation Charges by two players onto
            // each other's former base tiles would otherwise cycle
            // (ABBA). Sorting eliminates the cycle deterministically.
            $tiles = $this->lockTilesById([
                (int) $player->current_tile_id,
                $player->base_tile_id !== null ? (int) $player->base_tile_id : null,
            ]);

            $destination = $tiles[(int) $player->current_tile_id] ?? null;
            if ($destination === null) {
                throw CannotBaseTeleportException::targetNotFound();
            }
            if ($destination->type !== 'wasteland') {
                throw CannotBaseTeleportException::notOnWasteland($destination->type);
            }

            $this->moveRegen->reconcile($player);
            $player->refresh();

            $moveCost = (int) $this->config->get('teleport_items.foundation_charge.move_cost');
            if ($moveCost > 0 && (int) $player->moves_current < $moveCost) {
                throw InsufficientMovesException::forAction('foundation_charge', (int) $player->moves_current, $moveCost);
            }

            $oldBase = $player->base_tile_id !== null
                ? ($tiles[(int) $player->base_tile_id] ?? null)
                : null;

            // Mutate tile types. If oldBase and destination happen to
            // be the same row (defensive — alreadyAtBase would normally
            // block this), skip the demotion step.
            if ($oldBase !== null && $oldBase->id !== $destination->id) {
                $oldBase->update(['type' => 'wasteland']);
            }
            $destination->update(['type' => 'base']);

            $player->update([
                'base_tile_id' => $destination->id,
                'moves_current' => (int) $player->moves_current - $moveCost,
            ]);

            $this->decrementStack((int) $inventoryRow->id);

            return $destination->fresh();
        });
    }

    /**
     * Abduction Anchor — relocate a RIVAL's base to the caller's
     * current wasteland tile.
     *
     * Guards (every one runs before any decrement or tile mutation):
     *   - caller owns an abduction_anchor
     *   - current tile is wasteland
     *   - target player exists and is not the caller
     *   - target is not same-MDN
     *   - target not under new-player immunity
     *   - target's base_move_protected flag is false (Deadbolt Plinth)
     *   - caller has a successful spy on target within the configured
     *     freshness window (teleport_items.abduction_anchor.spy_freshness_hours)
     *   - caller has moves_current ≥ move cost
     *
     * Cooldown is irrelevant here (user spec). Attack cooldown too.
     *
     * Lock order: both Player rows in ascending ID, then both Tile
     * rows (destination + target's old base) in ascending ID. This
     * eliminates the mutual-strike ABBA cycle when two players fire
     * Abduction Anchors at each other simultaneously.
     *
     * On success: old target base → wasteland, current tile → base,
     * target.base_tile_id updated, one abduction_anchor consumed, and
     * a BaseRelocated event dispatched to the victim so they see a
     * toast + activity log entry with their new coordinates.
     *
     * @return array{new_base: Tile, target_username: string}
     */
    public function moveEnemyBase(int $playerId, int $targetPlayerId): array
    {
        if ($playerId === $targetPlayerId) {
            throw CannotBaseTeleportException::targetIsSelf();
        }

        $result = DB::transaction(function () use ($playerId, $targetPlayerId) {
            // Lock both players in ascending ID order so two players
            // simultaneously targeting each other cannot form a cycle.
            $players = $this->lockPlayersById([$playerId, $targetPlayerId]);

            /** @var Player|null $player */
            $player = $players[$playerId] ?? null;
            if ($player === null) {
                throw CannotBaseTeleportException::targetNotFound();
            }

            /** @var Player|null $target */
            $target = $players[$targetPlayerId] ?? null;
            if ($target === null || $target->base_tile_id === null) {
                throw CannotBaseTeleportException::targetNotFound();
            }

            $inventoryRow = DB::table('player_items')
                ->where('player_id', $player->id)
                ->where('item_key', self::ABDUCTION_ANCHOR_KEY)
                ->where('status', 'active')
                ->where('quantity', '>', 0)
                ->lockForUpdate()
                ->first();

            if ($inventoryRow === null) {
                throw CannotBaseTeleportException::abductionAnchorNotOwned();
            }

            // Inline same-MDN guard. Deliberately NOT delegating to
            // MdnService::assertCanAttackOrSpy because that method
            // also enforces the MDN-join/leave hop cooldown, which is
            // irrelevant for base teleport per user spec, and it
            // throws a sibling exception type we'd then have to
            // disambiguate via fragile string matching.
            if ($this->sameMdn($player, $target)) {
                throw CannotBaseTeleportException::sameMdn();
            }

            if ($target->immunity_expires_at !== null && $target->immunity_expires_at->isFuture()) {
                throw CannotBaseTeleportException::targetImmune();
            }

            if ((bool) $target->base_move_protected) {
                throw CannotBaseTeleportException::targetProtected();
            }

            // Tile locks in ascending ID order. Two tiles in scope:
            // the caller's current tile (destination) and the target's
            // old base tile.
            $tiles = $this->lockTilesById([
                (int) $player->current_tile_id,
                (int) $target->base_tile_id,
            ]);

            $destination = $tiles[(int) $player->current_tile_id] ?? null;
            if ($destination === null) {
                throw CannotBaseTeleportException::targetNotFound();
            }
            if ($destination->type !== 'wasteland') {
                throw CannotBaseTeleportException::notOnWasteland($destination->type);
            }

            $oldBase = $tiles[(int) $target->base_tile_id] ?? null;
            if ($oldBase === null) {
                throw CannotBaseTeleportException::targetNotFound();
            }

            $freshnessHours = (int) $this->config->get('teleport_items.abduction_anchor.spy_freshness_hours');
            $hasFreshSpy = SpyAttempt::query()
                ->where('spy_player_id', $player->id)
                ->where('target_player_id', $target->id)
                ->where('success', true)
                ->where('created_at', '>=', now()->subHours($freshnessHours))
                ->exists();

            if (! $hasFreshSpy) {
                throw CannotBaseTeleportException::spyIntelStale($freshnessHours);
            }

            $this->moveRegen->reconcile($player);
            $player->refresh();

            $moveCost = (int) $this->config->get('teleport_items.abduction_anchor.move_cost');
            if ($moveCost > 0 && (int) $player->moves_current < $moveCost) {
                throw InsufficientMovesException::forAction('abduction_anchor', (int) $player->moves_current, $moveCost);
            }

            if ($oldBase->id !== $destination->id) {
                $oldBase->update(['type' => 'wasteland']);
            }
            $destination->update(['type' => 'base']);

            $target->update(['base_tile_id' => $destination->id]);
            $player->update([
                'moves_current' => (int) $player->moves_current - $moveCost,
            ]);

            $this->decrementStack((int) $inventoryRow->id);

            // Victim-side activity log so offline players see the
            // relocation and new coordinates on next login. Broadcast
            // fires AFTER commit (below) for online players.
            $attackerUsername = (string) ($player->user?->name ?? 'Unknown');
            $targetUsername = (string) ($target->user?->name ?? 'Unknown');
            $destinationFresh = $destination->fresh();

            $this->activityLog->record(
                (int) $target->user_id,
                'base.relocated',
                'Your base has been forcibly relocated',
                [
                    'attacker_username' => $attackerUsername,
                    'new_x' => (int) $destinationFresh->x,
                    'new_y' => (int) $destinationFresh->y,
                    'old_x' => (int) $oldBase->x,
                    'old_y' => (int) $oldBase->y,
                ],
            );

            return [
                'new_base' => $destinationFresh,
                'target_username' => $targetUsername,
                '_target_user_id' => (int) $target->user_id,
                '_attacker_username' => $attackerUsername,
            ];
        });

        if ((bool) $this->config->get('notifications.broadcast_enabled')) {
            BaseRelocated::dispatch(
                $result['_target_user_id'],
                $result['_attacker_username'],
                (int) $result['new_base']->x,
                (int) $result['new_base']->y,
            );
        }

        unset($result['_target_user_id'], $result['_attacker_username']);

        return $result;
    }

    /**
     * Build the eligible-target list for the Abduction Anchor picker
     * modal. Returns every rival the caller has a successful spy on
     * within the configured freshness window, annotated with a
     * `reason` string when the rival fails a service-layer guard
     * (same-MDN, newbie immunity, or Deadbolt Plinth). The UI renders
     * ineligible rows greyed out with the reason visible so the
     * player understands why a visible target is unclickable.
     *
     * Shared between `Web\BaseTeleportController` and
     * `Api\V1\BaseTeleportController` per CLAUDE.md's "web + API must
     * stay in sync" rule — any new guard added to `moveEnemyBase`
     * should also be reflected here (or both endpoints diverge).
     *
     * @return list<array{
     *   id: int,
     *   username: string,
     *   base_x: int,
     *   base_y: int,
     *   spied_at: string,
     *   eligible: bool,
     *   reason: ?string
     * }>
     */
    public function listAbductionTargets(int $playerId): array
    {
        /** @var Player|null $player */
        $player = Player::query()->find($playerId);
        if ($player === null) {
            return [];
        }

        $freshnessHours = (int) $this->config->get('teleport_items.abduction_anchor.spy_freshness_hours');

        $rows = SpyAttempt::query()
            ->where('spy_player_id', $player->id)
            ->where('success', true)
            ->where('created_at', '>=', now()->subHours($freshnessHours))
            ->orderByDesc('created_at')
            ->get(['target_player_id', 'created_at']);

        // Collapse duplicates — the most recent successful spy per
        // target is the one we care about.
        /** @var array<int, CarbonInterface> $latestByTarget */
        $latestByTarget = [];
        foreach ($rows as $row) {
            $id = (int) $row->target_player_id;
            if (! isset($latestByTarget[$id])) {
                $latestByTarget[$id] = $row->created_at;
            }
        }

        if ($latestByTarget === []) {
            return [];
        }

        $targetModels = Player::query()
            ->whereIn('id', array_keys($latestByTarget))
            ->with(['user:id,name', 'baseTile:id,x,y'])
            ->get();

        $results = [];
        foreach ($targetModels as $target) {
            $reason = $this->ineligibleReason($player, $target);

            $results[] = [
                'id' => (int) $target->id,
                'username' => (string) ($target->user?->name ?? 'Unknown'),
                'base_x' => (int) ($target->baseTile?->x ?? 0),
                'base_y' => (int) ($target->baseTile?->y ?? 0),
                'spied_at' => $latestByTarget[(int) $target->id]->toIso8601String(),
                'eligible' => $reason === null,
                'reason' => $reason,
            ];
        }

        // Sort eligible first, then most recently spied.
        usort($results, function (array $a, array $b) {
            if ($a['eligible'] !== $b['eligible']) {
                return $a['eligible'] ? -1 : 1;
            }

            return strcmp($b['spied_at'], $a['spied_at']);
        });

        return $results;
    }

    /**
     * Mirrors the guard order in moveEnemyBase() for the picker UI.
     * Kept in this service (not duplicated across controllers) so a
     * new guard only needs to be added in one place.
     */
    private function ineligibleReason(Player $player, Player $target): ?string
    {
        if ((int) $target->id === (int) $player->id) {
            return 'Cannot target your own base.';
        }
        if ($this->sameMdn($player, $target)) {
            return 'Same MDN — attacks forbidden by charter.';
        }
        if ($target->immunity_expires_at !== null && $target->immunity_expires_at->isFuture()) {
            return 'Target is under new-player immunity.';
        }
        if ((bool) $target->base_move_protected) {
            return 'Target has a Deadbolt Plinth installed.';
        }

        return null;
    }

    private function sameMdn(Player $a, Player $b): bool
    {
        return $a->mdn_id !== null
            && $b->mdn_id !== null
            && (int) $a->mdn_id === (int) $b->mdn_id;
    }

    /**
     * Lock a set of Player rows in ascending ID order and return a
     * map keyed by ID. Any missing IDs are silently omitted from the
     * result — callers should null-check for rows they expect.
     *
     * @param  array<int>  $ids
     * @return array<int, Player>
     */
    private function lockPlayersById(array $ids): array
    {
        $unique = array_values(array_unique(array_map('intval', $ids)));
        sort($unique, SORT_NUMERIC);

        $out = [];
        foreach ($unique as $id) {
            /** @var Player|null $row */
            $row = Player::query()->lockForUpdate()->find($id);
            if ($row !== null) {
                $out[$id] = $row;
            }
        }

        return $out;
    }

    /**
     * Lock a set of Tile rows in ascending ID order and return a map
     * keyed by ID. Nulls in the input are filtered out. Missing rows
     * are silently omitted — callers should null-check.
     *
     * @param  array<int|null>  $ids
     * @return array<int, Tile>
     */
    private function lockTilesById(array $ids): array
    {
        $filtered = [];
        foreach ($ids as $id) {
            if ($id === null) {
                continue;
            }
            $filtered[] = (int) $id;
        }
        $unique = array_values(array_unique($filtered));
        sort($unique, SORT_NUMERIC);

        $out = [];
        foreach ($unique as $id) {
            /** @var Tile|null $row */
            $row = Tile::query()->lockForUpdate()->find($id);
            if ($row !== null) {
                $out[$id] = $row;
            }
        }

        return $out;
    }

    /**
     * Atomic SQL decrement on a player_items row. The caller has
     * already locked the row via lockForUpdate, so this could use a
     * PHP-side `quantity - 1` safely, but the SQL form is more
     * defensive and removes the need to pass the stale quantity in.
     */
    private function decrementStack(int $rowId): void
    {
        DB::table('player_items')->where('id', $rowId)->decrement('quantity');

        // Delete zero-quantity rows so the Toolbox HUD filters them
        // out and the auth-share query doesn't render a ghost entry.
        DB::table('player_items')
            ->where('id', $rowId)
            ->where('quantity', '<=', 0)
            ->delete();
    }
}
