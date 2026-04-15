<?php

namespace App\Domain\Teleport;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Exceptions\CannotBaseTeleportException;
use App\Domain\Exceptions\CannotPurchaseException;
use App\Domain\Exceptions\CannotSpyException;
use App\Domain\Exceptions\InsufficientMovesException;
use App\Domain\Mdn\MdnService;
use App\Domain\Notifications\ActivityLogService;
use App\Domain\Player\MoveRegenService;
use App\Domain\World\FogOfWarService;
use App\Events\BaseRelocated;
use App\Models\Player;
use App\Models\SpyAttempt;
use App\Models\Tile;
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
 * Every write happens inside a DB transaction with lockForUpdate on
 * the acting player (and, where applicable, the target player) so
 * concurrent toolbox clicks cannot double-move, double-consume, or
 * race against a purchase of the Deadbolt Plinth on the target side.
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
        private readonly MdnService $mdn,
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
     * Transaction does, in order:
     *   1. Validate ownership, wasteland tile type, move budget
     *   2. Flip old base tile → wasteland
     *   3. Flip new tile → base
     *   4. Update player.base_tile_id
     *   5. Decrement player_items.quantity for foundation_charge
     *
     * The user spec says "overwrite" if the destination wasteland has
     * something on it — no guard against occupying loot crates or
     * standing players. Old base tile becomes plain wasteland with
     * no preserved state (acceptable because bases currently store
     * no per-tile metadata beyond the type enum).
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

            /** @var Tile $destination */
            $destination = Tile::query()->lockForUpdate()->findOrFail($player->current_tile_id);
            if ($destination->type !== 'wasteland') {
                throw CannotBaseTeleportException::notOnWasteland($destination->type);
            }

            $this->moveRegen->reconcile($player);
            $player->refresh();

            $moveCost = (int) $this->config->get('teleport_items.foundation_charge.move_cost');
            if ($moveCost > 0 && (int) $player->moves_current < $moveCost) {
                throw InsufficientMovesException::forAction('foundation_charge', (int) $player->moves_current, $moveCost);
            }

            /** @var Tile|null $oldBase */
            $oldBase = $player->base_tile_id !== null
                ? Tile::query()->lockForUpdate()->find($player->base_tile_id)
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

            $this->decrementStack($inventoryRow->id, (int) $inventoryRow->quantity);

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
     * On success: old target base → wasteland, current tile → base,
     * target.base_tile_id updated, one abduction_anchor consumed, and
     * a BaseRelocated event dispatched to the victim so they see a
     * toast + activity log entry with their new coordinates.
     *
     * @return array{new_base: Tile, target_username: string}
     */
    public function moveEnemyBase(int $playerId, int $targetPlayerId): array
    {
        $result = DB::transaction(function () use ($playerId, $targetPlayerId) {
            /** @var Player $player */
            $player = Player::query()->lockForUpdate()->findOrFail($playerId);

            if ((int) $playerId === (int) $targetPlayerId) {
                throw CannotBaseTeleportException::targetIsSelf();
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

            /** @var Tile $destination */
            $destination = Tile::query()->lockForUpdate()->findOrFail($player->current_tile_id);
            if ($destination->type !== 'wasteland') {
                throw CannotBaseTeleportException::notOnWasteland($destination->type);
            }

            /** @var Player|null $target */
            $target = Player::query()->lockForUpdate()->find($targetPlayerId);
            if ($target === null || $target->base_tile_id === null) {
                throw CannotBaseTeleportException::targetNotFound();
            }

            if ($target->immunity_expires_at !== null && $target->immunity_expires_at->isFuture()) {
                throw CannotBaseTeleportException::targetImmune();
            }

            if ((bool) $target->base_move_protected) {
                throw CannotBaseTeleportException::targetProtected();
            }

            // Same-MDN check — reuse the shared MDN gate. It throws
            // CannotSpyException / CannotAttackException on same-MDN,
            // so we catch and re-throw our own typed exception to keep
            // controller error handling focused on a single namespace.
            try {
                $this->mdn->assertCanAttackOrSpy($player, $target, 'spy');
            } catch (CannotSpyException $e) {
                // Strip the MDN-hop cooldown case — that's irrelevant
                // here per spec. Only same-MDN should bubble up.
                if (str_contains($e->getMessage(), 'MDN')) {
                    throw CannotBaseTeleportException::sameMdn();
                }
                // Any other MDN-layer rejection is unexpected — rethrow.
                throw $e;
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

            /** @var Tile $oldBase */
            $oldBase = Tile::query()->lockForUpdate()->findOrFail($target->base_tile_id);

            if ($oldBase->id !== $destination->id) {
                $oldBase->update(['type' => 'wasteland']);
            }
            $destination->update(['type' => 'base']);

            $target->update(['base_tile_id' => $destination->id]);
            $player->update([
                'moves_current' => (int) $player->moves_current - $moveCost,
            ]);

            $this->decrementStack($inventoryRow->id, (int) $inventoryRow->quantity);

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
     * Decrement a stackable consumable. Deletes the row when quantity
     * hits zero so the Toolbox HUD filters it out and the auth-share
     * query doesn't waste a render slot on a 0-count ghost.
     */
    private function decrementStack(int $rowId, int $currentQuantity): void
    {
        if ($currentQuantity <= 1) {
            DB::table('player_items')->where('id', $rowId)->delete();

            return;
        }

        DB::table('player_items')
            ->where('id', $rowId)
            ->update([
                'quantity' => $currentQuantity - 1,
                'updated_at' => now(),
            ]);
    }
}
