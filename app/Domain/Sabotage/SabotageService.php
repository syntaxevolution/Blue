<?php

namespace App\Domain\Sabotage;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Exceptions\CannotSabotageException;
use App\Domain\Exceptions\InsufficientMovesException;
use App\Domain\Items\ItemBreakService;
use App\Domain\Notifications\ActivityLogService;
use App\Domain\Player\MoveRegenService;
use App\Events\RigSabotaged;
use App\Events\SabotageTriggered;
use App\Models\DrillPoint;
use App\Models\DrillPointSabotage;
use App\Models\Item;
use App\Models\OilField;
use App\Models\Player;
use App\Models\Tile;
use Illuminate\Support\Facades\DB;

/**
 * Deploys and resolves drill-point sabotage devices.
 *
 * Two responsibilities:
 *
 *   1. place($playerId, $gridX, $gridY, $itemKey)
 *      Plant an owned deployable on a specific drill point. Own
 *      transaction + lockForUpdate on both the player and the drill
 *      point. Decrements player_items.quantity by 1 (deleting the row
 *      when it hits zero) and inserts a new drill_point_sabotages row.
 *
 *   2. trigger($sabotage, $driller, $activeDrillItemKey, $hasDetector, $hasScanner)
 *      Called from inside DrillService::drill() AFTER the drill point
 *      lock is held but BEFORE yield compute. Decides whether the trap
 *      fires, fizzles, or is caught by a counter. Mutates state inline
 *      (break the drill, siphon barrels, consume a Tripwire Ward),
 *      writes the sabotage row's outcome, fires broadcast events, logs
 *      to ActivityLog for both sides. Returns an outcome string used
 *      by DrillService to short-circuit yield computation.
 *
 *      This path intentionally does NOT open its own DB::transaction —
 *      it runs inside the outer drill transaction so that rolling the
 *      drill back also rolls back the trigger (and vice versa). The
 *      ItemBreakService::markBroken call nested inside is safe here
 *      because nested Laravel transactions use savepoints.
 *
 * The "findActiveTrap" read used by DrillService is a plain Eloquent
 * lookup under lockForUpdate so two concurrent drill attempts on the
 * same armed cell can't both resolve the trap twice.
 */
class SabotageService
{
    /**
     * Effect key used to mark a deployable sabotage device in the items
     * catalog. Values are sabotage_kind strings — currently 'rig_wrecker'
     * (Gremlin Coil) and 'siphon' (Siphon Charge). Adding a new device is
     * a matter of adding a new effects JSON entry and a new kind handler
     * in trigger().
     */
    public const EFFECT_KEY_DEPLOYABLE = 'deployable_sabotage';

    /**
     * Effect key used to flag counter-measure items (Tripwire Ward).
     * Deep Scanner is keyed through the existing 'unlocks' system
     * (sabotage_scanner unlock) rather than a counter_measure entry.
     */
    public const EFFECT_KEY_COUNTER = 'counter_measure';

    public const COUNTER_DETECTOR_KEY = 'tripwire_ward';

    public const SCANNER_UNLOCK_KEY = 'sabotage_scanner';

    public function __construct(
        private readonly GameConfigResolver $config,
        private readonly MoveRegenService $moveRegen,
        private readonly ItemBreakService $itemBreak,
        private readonly ActivityLogService $activityLog,
    ) {}

    /**
     * Plant a device on one drill point of the oil field the player is
     * currently standing on.
     *
     * @return array{
     *     sabotage_id: int,
     *     device_key: string,
     *     remaining_quantity: int,
     *     moves_remaining: int,
     * }
     */
    public function place(int $playerId, int $gridX, int $gridY, string $itemKey): array
    {
        if ($gridX < 0 || $gridX > 4 || $gridY < 0 || $gridY > 4) {
            throw CannotSabotageException::outOfRange($gridX, $gridY);
        }

        $placeCost = max(0, (int) $this->config->get('sabotage.place_move_cost', 1));

        return DB::transaction(function () use ($playerId, $gridX, $gridY, $itemKey, $placeCost) {
            /** @var Player $player */
            $player = Player::query()->lockForUpdate()->findOrFail($playerId);

            $this->moveRegen->reconcile($player);
            $player->refresh();

            if ($player->moves_current < $placeCost) {
                throw InsufficientMovesException::forAction('place_device', $player->moves_current, $placeCost);
            }

            /** @var Tile $tile */
            $tile = Tile::query()->findOrFail($player->current_tile_id);
            if ($tile->type !== 'oil_field') {
                throw CannotSabotageException::notAnOilField($tile->type);
            }

            /** @var OilField $field */
            $field = OilField::query()->where('tile_id', $tile->id)->firstOrFail();

            /** @var Item|null $item */
            $item = Item::query()->where('key', $itemKey)->first();
            if ($item === null) {
                throw CannotSabotageException::unknownDevice($itemKey);
            }

            // Must carry the deployable_sabotage effect — rejects anyone
            // trying to "place" a stat item or a map or whatever.
            $effects = $item->effects ?? [];
            if (! isset($effects[self::EFFECT_KEY_DEPLOYABLE])) {
                throw CannotSabotageException::unknownDevice($itemKey);
            }

            // Inventory check — lock the row so we don't race with a
            // second concurrent place from the same account.
            $inventoryRow = DB::table('player_items')
                ->where('player_id', $player->id)
                ->where('item_key', $itemKey)
                ->where('status', 'active')
                ->where('quantity', '>', 0)
                ->lockForUpdate()
                ->first();

            if ($inventoryRow === null) {
                throw CannotSabotageException::notOwned($itemKey);
            }

            /** @var DrillPoint $point */
            $point = DrillPoint::query()
                ->lockForUpdate()
                ->where('oil_field_id', $field->id)
                ->where('grid_x', $gridX)
                ->where('grid_y', $gridY)
                ->firstOrFail();

            // Active-trap uniqueness: one armed device per drill point.
            // Checked under a lock because the drill_points row above
            // is already locked — any concurrent place() on the same
            // cell must wait for this transaction to commit first.
            $alreadyRigged = DrillPointSabotage::query()
                ->where('drill_point_id', $point->id)
                ->whereNull('triggered_at')
                ->exists();

            if ($alreadyRigged) {
                throw CannotSabotageException::pointAlreadyRigged($gridX, $gridY);
            }

            // Spend moves.
            if ($placeCost > 0) {
                $player->update([
                    'moves_current' => $player->moves_current - $placeCost,
                ]);
            }

            // Decrement inventory. Delete the row when it would hit
            // zero so the Toolbox HUD hides empty entries automatically
            // and single-item loadouts don't linger as ghost rows.
            $newQty = (int) $inventoryRow->quantity - 1;
            if ($newQty <= 0) {
                DB::table('player_items')->where('id', $inventoryRow->id)->delete();
                $newQty = 0;
            } else {
                DB::table('player_items')
                    ->where('id', $inventoryRow->id)
                    ->update([
                        'quantity' => $newQty,
                        'updated_at' => now(),
                    ]);
            }

            // Insert the sabotage row.
            $sabotage = DrillPointSabotage::create([
                'drill_point_id' => $point->id,
                'oil_field_id' => $field->id,
                'device_key' => $itemKey,
                'placed_by_player_id' => $player->id,
                'placed_at' => now(),
            ]);

            return [
                'sabotage_id' => (int) $sabotage->id,
                'device_key' => $itemKey,
                'remaining_quantity' => $newQty,
                'moves_remaining' => (int) $player->moves_current,
            ];
        });
    }

    /**
     * Find an armed trap on the given drill point under a row lock so
     * concurrent drills can't both resolve it. Returns null if none.
     */
    public function findActiveTrapLocked(int $drillPointId): ?DrillPointSabotage
    {
        /** @var DrillPointSabotage|null $row */
        $row = DrillPointSabotage::query()
            ->where('drill_point_id', $drillPointId)
            ->whereNull('triggered_at')
            ->lockForUpdate()
            ->first();

        return $row;
    }

    /**
     * Does this player currently own an active Deep Scanner? Used by
     * DrillService (defensive block) and MapStateBuilder (UI reveal).
     *
     * Implemented as a PHP-side iteration over the player's owned items
     * and their effect JSONs. Avoids MariaDB-specific JSON_SEARCH syntax
     * (project is on MariaDB, not MySQL 8) and stays consistent with
     * MapStateBuilder::playerUnlocks which does the same dance.
     */
    public function playerOwnsScanner(int $playerId): bool
    {
        $rows = DB::table('player_items')
            ->join('items_catalog', 'items_catalog.key', '=', 'player_items.item_key')
            ->where('player_items.player_id', $playerId)
            ->where('player_items.status', 'active')
            ->where('player_items.quantity', '>', 0)
            ->whereNotNull('items_catalog.effects')
            ->pluck('items_catalog.effects')
            ->all();

        foreach ($rows as $json) {
            $effects = is_string($json) ? json_decode($json, true) : (array) $json;
            if (! is_array($effects)) {
                continue;
            }
            $unlocks = $effects['unlocks'] ?? null;
            if (is_array($unlocks) && in_array(self::SCANNER_UNLOCK_KEY, $unlocks, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Active detector count for a player. Zero if they own none.
     */
    public function playerDetectorCount(int $playerId): int
    {
        return (int) (DB::table('player_items')
            ->where('player_id', $playerId)
            ->where('item_key', self::COUNTER_DETECTOR_KEY)
            ->where('status', 'active')
            ->value('quantity') ?? 0);
    }

    /**
     * Resolve a trap in the context of an in-progress drill.
     *
     * Must be called from inside DrillService::drill()'s outer
     * transaction. Assumes the caller already holds lockForUpdate on:
     *   - the drill point row
     *   - the driller's player row
     *   - the sabotage row (via findActiveTrapLocked above)
     *
     * Mutates state and returns the outcome:
     *   'normal'                    — planter is drilling their own trap; skip
     *   'detected'                  — Tripwire Ward consumed, rig safe, no barrels
     *   'fizzled_immune'            — 48h immunity, no effect, trap consumed
     *   'fizzled_tier_one'          — tier-1 drill can't break; rig wrecker no-op
     *                                  (siphon path never returns this — siphon
     *                                  still works on tier 1, just skips break)
     *   'drill_broken'              — rig wrecked, no siphon
     *   'drill_broken_and_siphoned' — rig wrecked AND oil siphoned to planter
     *   'siphoned_tier_one'         — tier 1: rig safe, oil still siphoned
     *
     * @return array{
     *     outcome: string,
     *     siphoned_barrels: int,
     *     device_key: string,
     *     planter_player_id: int,
     * }
     */
    public function trigger(
        DrillPointSabotage $sabotage,
        Player $driller,
        ?string $activeDrillItemKey,
    ): array {
        $deviceKey = (string) $sabotage->device_key;

        // --- Own trap short-circuit -----------------------------------
        $ignoreOwn = (bool) $this->config->get('sabotage.ignore_own_traps', true);
        if ($ignoreOwn && (int) $sabotage->placed_by_player_id === (int) $driller->id) {
            return [
                'outcome' => 'normal',
                'siphoned_barrels' => 0,
                'device_key' => $deviceKey,
                'planter_player_id' => (int) $sabotage->placed_by_player_id,
            ];
        }

        // --- Immunity fizzle ------------------------------------------
        $immuneProtected = (bool) $this->config->get('sabotage.immune_players_protected', true);
        $isImmune = $driller->immunity_expires_at !== null
            && $driller->immunity_expires_at->isFuture();

        if ($immuneProtected && $isImmune) {
            $this->finalizeSabotage($sabotage, $driller, 'fizzled', 0);
            $this->notifyImmuneFizzle($sabotage, $driller);

            return [
                'outcome' => 'fizzled_immune',
                'siphoned_barrels' => 0,
                'device_key' => $deviceKey,
                'planter_player_id' => (int) $sabotage->placed_by_player_id,
            ];
        }

        // --- Detector counter -----------------------------------------
        // Passive: any owned Tripwire Ward auto-consumes on contact.
        $detectorQty = $this->playerDetectorCount($driller->id);
        if ($detectorQty > 0) {
            $this->consumeOneDetector($driller->id);
            $this->finalizeSabotage($sabotage, $driller, 'detected', 0);
            $this->notifyDetected($sabotage, $driller);

            return [
                'outcome' => 'detected',
                'siphoned_barrels' => 0,
                'device_key' => $deviceKey,
                'planter_player_id' => (int) $sabotage->placed_by_player_id,
            ];
        }

        // --- Trap fires -----------------------------------------------
        $minTierToBreak = (int) $this->config->get('sabotage.min_drill_tier_to_break', 2);
        $canBreakRig = (int) $driller->drill_tier >= $minTierToBreak && $activeDrillItemKey !== null;

        $kind = $this->deviceKind($deviceKey);
        $siphoned = 0;

        // Siphon portion runs regardless of tier (tier 1 users still
        // lose oil; only the RIG is protected on tier 1).
        if ($kind === 'siphon') {
            $siphoned = $this->applySiphon($sabotage, $driller);
        }

        // Rig break portion — skipped entirely when the driller is on
        // tier 1 (they always have the starter drill, can't lose it).
        if ($canBreakRig) {
            $this->itemBreak->markBroken($driller, $activeDrillItemKey);
        }

        // Pick the narrative outcome for the audit row.
        $outcome = match (true) {
            $kind === 'siphon' && $canBreakRig => 'drill_broken_and_siphoned',
            $kind === 'siphon' && ! $canBreakRig => 'siphoned_tier_one',
            $kind !== 'siphon' && $canBreakRig => 'drill_broken',
            default => 'fizzled_tier_one',
        };

        // DB enum values. Tier-1 siphons MUST map to 'siphoned_only'
        // (not 'drill_broken_and_siphoned') because AttackLogService
        // derives rig_broken from the stored outcome. Collapsing them
        // would mislabel tier-1 victims as having a wrecked rig in
        // their feed.
        $storedOutcome = match ($outcome) {
            'drill_broken' => 'drill_broken',
            'drill_broken_and_siphoned' => 'drill_broken_and_siphoned',
            'siphoned_tier_one' => 'siphoned_only',
            'fizzled_tier_one' => 'fizzled',
            default => 'fizzled',
        };

        $this->finalizeSabotage($sabotage, $driller, $storedOutcome, $siphoned);
        $this->notifyTriggered($sabotage, $driller, $outcome, $siphoned, $canBreakRig);

        return [
            'outcome' => $outcome,
            'siphoned_barrels' => $siphoned,
            'device_key' => $deviceKey,
            'planter_player_id' => (int) $sabotage->placed_by_player_id,
        ];
    }

    /**
     * @return 'rig_wrecker'|'siphon'|'unknown'
     */
    private function deviceKind(string $deviceKey): string
    {
        /** @var Item|null $item */
        $item = Item::query()->where('key', $deviceKey)->first();
        if ($item === null) {
            return 'unknown';
        }
        $effects = $item->effects ?? [];
        $kind = (string) ($effects[self::EFFECT_KEY_DEPLOYABLE] ?? 'unknown');

        return match ($kind) {
            'rig_wrecker', 'siphon' => $kind,
            default => 'unknown',
        };
    }

    /**
     * Transfer oil barrels from the driller to the planter according to
     * sabotage.siphon.steal_pct. Both rows are assumed already locked
     * by the outer transaction (driller by DrillService, planter by
     * this method's own lockForUpdate). No cap — per spec #7.
     */
    private function applySiphon(DrillPointSabotage $sabotage, Player $driller): int
    {
        $stealPct = (float) $this->config->get('sabotage.siphon.steal_pct', 0.5);
        $stealPct = max(0.0, min(1.0, $stealPct));

        $siphoned = (int) floor((int) $driller->oil_barrels * $stealPct);
        if ($siphoned <= 0) {
            return 0;
        }

        // Victim loses the barrels.
        $driller->update([
            'oil_barrels' => max(0, (int) $driller->oil_barrels - $siphoned),
        ]);

        // Planter gains them. Lock the planter's player row so a
        // simultaneous siphon from a separate trap doesn't race us.
        /** @var Player|null $planter */
        $planter = Player::query()
            ->lockForUpdate()
            ->find((int) $sabotage->placed_by_player_id);

        if ($planter !== null) {
            $planter->update([
                'oil_barrels' => (int) $planter->oil_barrels + $siphoned,
            ]);
        }

        return $siphoned;
    }

    /**
     * Atomic player_items decrement for the Tripwire Ward counter.
     * Deletes the row when quantity would hit zero.
     */
    private function consumeOneDetector(int $playerId): void
    {
        $row = DB::table('player_items')
            ->where('player_id', $playerId)
            ->where('item_key', self::COUNTER_DETECTOR_KEY)
            ->where('status', 'active')
            ->where('quantity', '>', 0)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            return;
        }

        $newQty = (int) $row->quantity - 1;
        if ($newQty <= 0) {
            DB::table('player_items')->where('id', $row->id)->delete();
        } else {
            DB::table('player_items')->where('id', $row->id)->update([
                'quantity' => $newQty,
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Write the outcome/trigger columns back on the sabotage row.
     * Single stable call site so the DB columns stay in sync with the
     * enum values we committed to in the migration.
     */
    private function finalizeSabotage(
        DrillPointSabotage $sabotage,
        Player $driller,
        string $outcome,
        int $siphoned,
    ): void {
        $sabotage->update([
            'triggered_at' => now(),
            'triggered_by_player_id' => $driller->id,
            'outcome' => $outcome,
            'siphoned_barrels' => $siphoned,
        ]);
    }

    /**
     * Deferral helper: wrap every activity log write + broadcast dispatch
     * inside DB::afterCommit so a rollback of the enclosing drill
     * transaction can't leak a toast ("your rig was wrecked!") for an
     * event that never actually persisted. Safe to use outside of a
     * transaction too — Laravel falls through to immediate execution.
     */
    private function deferSideEffects(callable $fn): void
    {
        DB::afterCommit($fn);
    }

    /**
     * Broadcast + activity log for a "detected" outcome: the driller
     * had a Tripwire Ward, it fired, rig safe, planter wasted a device.
     */
    private function notifyDetected(DrillPointSabotage $sabotage, Player $driller): void
    {
        $planterUser = $this->userIdForPlayer((int) $sabotage->placed_by_player_id);
        $drillerUser = (int) $driller->user_id;
        $drillerName = (string) ($driller->user?->name ?? 'someone');

        $deviceKey = (string) $sabotage->device_key;
        $deviceName = $this->deviceDisplayName($deviceKey);
        $sabotageId = (int) $sabotage->id;

        if ($planterUser !== null) {
            $this->activityLog->record(
                $planterUser,
                'sabotage.detected',
                "Your {$deviceName} was disarmed",
                [
                    'sabotage_id' => $sabotageId,
                    'device_key' => $deviceKey,
                    'device_name' => $deviceName,
                    'by' => $drillerName,
                    'outcome' => 'detected',
                ],
            );

            $this->deferSideEffects(function () use ($planterUser, $deviceKey, $sabotageId) {
                SabotageTriggered::dispatch($planterUser, $deviceKey, 'detected', 0, $sabotageId);
            });
        }

        $this->activityLog->record(
            $drillerUser,
            'sabotage.warded',
            'A Tripwire Ward saved your rig',
            [
                'sabotage_id' => $sabotageId,
                'device_key' => $deviceKey,
                'device_name' => $deviceName,
                'outcome' => 'detected',
            ],
        );

        $this->deferSideEffects(function () use ($drillerUser, $deviceKey, $sabotageId) {
            RigSabotaged::dispatch($drillerUser, $deviceKey, 'detected', 0, false, $sabotageId);
        });
    }

    /**
     * Broadcast + activity log for a "fizzled because immune" outcome.
     * The user spec explicitly asks for "you were lucky this time" copy
     * so the driller knows the world was out to get them, briefly.
     */
    private function notifyImmuneFizzle(DrillPointSabotage $sabotage, Player $driller): void
    {
        $planterUser = $this->userIdForPlayer((int) $sabotage->placed_by_player_id);
        $drillerUser = (int) $driller->user_id;
        $drillerName = (string) ($driller->user?->name ?? 'a new settler');

        $deviceKey = (string) $sabotage->device_key;
        $deviceName = $this->deviceDisplayName($deviceKey);
        $sabotageId = (int) $sabotage->id;

        if ($planterUser !== null) {
            $this->activityLog->record(
                $planterUser,
                'sabotage.fizzled',
                "Your {$deviceName} fizzled on an immune player",
                [
                    'sabotage_id' => $sabotageId,
                    'device_key' => $deviceKey,
                    'device_name' => $deviceName,
                    'by' => $drillerName,
                    'outcome' => 'fizzled_immune',
                ],
            );

            $this->deferSideEffects(function () use ($planterUser, $deviceKey, $sabotageId) {
                SabotageTriggered::dispatch($planterUser, $deviceKey, 'fizzled_immune', 0, $sabotageId);
            });
        }

        $this->activityLog->record(
            $drillerUser,
            'sabotage.near_miss',
            'A drill point was booby-trapped — you got lucky this time',
            [
                'sabotage_id' => $sabotageId,
                'device_key' => $deviceKey,
                'device_name' => $deviceName,
                'outcome' => 'fizzled_immune',
            ],
        );

        $this->deferSideEffects(function () use ($drillerUser, $deviceKey, $sabotageId) {
            RigSabotaged::dispatch($drillerUser, $deviceKey, 'fizzled_immune', 0, false, $sabotageId);
        });
    }

    /**
     * Broadcast + activity log for a triggered trap that actually did
     * damage (break, siphon, or both). Also used for tier-1 fizzles so
     * the planter sees the "wasted on a tier-1 driller" message.
     */
    private function notifyTriggered(
        DrillPointSabotage $sabotage,
        Player $driller,
        string $outcome,
        int $siphoned,
        bool $rigBroken,
    ): void {
        $planterUser = $this->userIdForPlayer((int) $sabotage->placed_by_player_id);
        $drillerUser = (int) $driller->user_id;
        $drillerName = (string) ($driller->user?->name ?? 'someone');

        $deviceKey = (string) $sabotage->device_key;
        $deviceName = $this->deviceDisplayName($deviceKey);
        $sabotageId = (int) $sabotage->id;

        $planterTitle = match ($outcome) {
            'drill_broken_and_siphoned' => "Your {$deviceName} wrecked a rig and siphoned {$siphoned} barrels",
            'drill_broken' => "Your {$deviceName} wrecked a rig",
            'siphoned_tier_one' => "Your {$deviceName} hit a tier-1 driller (rig safe, siphoned {$siphoned} barrels)",
            'fizzled_tier_one' => "Your {$deviceName} triggered on a tier-1 driller and did nothing",
            default => "Your {$deviceName} was triggered",
        };

        if ($planterUser !== null) {
            $this->activityLog->record(
                $planterUser,
                'sabotage.triggered',
                $planterTitle,
                [
                    'sabotage_id' => $sabotageId,
                    'device_key' => $deviceKey,
                    'device_name' => $deviceName,
                    'by' => $drillerName,
                    'outcome' => $outcome,
                    'siphoned_barrels' => $siphoned,
                    'rig_broken' => $rigBroken,
                ],
            );

            $this->deferSideEffects(function () use ($planterUser, $deviceKey, $outcome, $siphoned, $sabotageId) {
                SabotageTriggered::dispatch($planterUser, $deviceKey, $outcome, $siphoned, $sabotageId);
            });
        }

        // Victim copy intentionally does NOT reveal the planter's
        // username — only the Attack Log (Counter-Intel Dossier) does
        // that, mirroring how raids work. The activity log + toast
        // is anonymous.
        $drillerTitle = match ($outcome) {
            'drill_broken_and_siphoned' => "Your rig was wrecked and {$siphoned} barrels were siphoned out",
            'drill_broken' => 'Your rig was wrecked by a planted device',
            'siphoned_tier_one' => "A siphon charge drained {$siphoned} barrels from your stash",
            'fizzled_tier_one' => 'A booby-trap triggered on your drill but the starter rig shrugged it off',
            default => 'A planted device triggered on your drill',
        };

        $this->activityLog->record(
            $drillerUser,
            'sabotage.hit',
            $drillerTitle,
            [
                'sabotage_id' => $sabotageId,
                'device_key' => $deviceKey,
                'device_name' => $deviceName,
                'outcome' => $outcome,
                'siphoned_barrels' => $siphoned,
                'rig_broken' => $rigBroken,
            ],
        );

        $this->deferSideEffects(function () use ($drillerUser, $deviceKey, $outcome, $siphoned, $rigBroken, $sabotageId) {
            RigSabotaged::dispatch($drillerUser, $deviceKey, $outcome, $siphoned, $rigBroken, $sabotageId);
        });
    }

    /**
     * Cheap lookup: player_id → user_id for broadcast/channel targeting.
     * Intentionally *not* cached. SabotageService is wired as a
     * constructor dependency of DrillService, which is registered as a
     * singleton in AppServiceProvider — a per-instance cache on this
     * service would effectively become process-lifetime state and leak
     * stale rows across requests on a long-lived Horizon worker.
     * At 100-user scale the extra single-column SELECT per trap is a
     * non-event, and going direct to the DB guarantees freshness.
     */
    private function userIdForPlayer(int $playerId): ?int
    {
        $row = DB::table('players')->where('id', $playerId)->value('user_id');

        return $row !== null ? (int) $row : null;
    }

    /**
     * Human-friendly display name for a device_key, resolved from the
     * items catalog. Falls back to a title-cased version of the key
     * if the item row has been deleted (shouldn't happen but cheap
     * insurance). The result is safe to interpolate into flash
     * messages, activity-log titles, and notification copy.
     *
     * Local per-method memoisation only — no instance-level cache,
     * same reasoning as userIdForPlayer above.
     */
    private function deviceDisplayName(string $deviceKey): string
    {
        static $localCache = [];
        if (isset($localCache[$deviceKey])) {
            return $localCache[$deviceKey];
        }

        $name = DB::table('items_catalog')
            ->where('key', $deviceKey)
            ->value('name');

        if (! is_string($name) || $name === '') {
            $name = ucwords(str_replace('_', ' ', $deviceKey));
        }

        $localCache[$deviceKey] = $name;

        return $name;
    }
}
