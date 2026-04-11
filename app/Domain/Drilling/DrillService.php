<?php

namespace App\Domain\Drilling;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Config\RngService;
use App\Domain\Exceptions\CannotDrillException;
use App\Domain\Exceptions\InsufficientMovesException;
use App\Domain\Items\ItemBreakService;
use App\Domain\Items\PassiveBonusService;
use App\Domain\Player\MoveRegenService;
use App\Models\DrillPoint;
use App\Models\Item;
use App\Models\OilField;
use App\Models\Player;
use App\Models\PlayerItem;
use App\Models\Tile;
use Illuminate\Support\Facades\DB;

/**
 * Drill a single point in the 5×5 sub-grid of the oil field a player
 * is standing on.
 *
 * The gameplay contract:
 *   - Player must be on a tile of type 'oil_field'
 *   - Costs actions.drill.move_cost moves
 *   - (grid_x, grid_y) must be inside 0..4
 *   - The target point must not already be drilled_at (depleted)
 *   - The player cannot exceed drilling.daily_limit_per_field drills
 *     on the same oil field per calendar day (server time). Different
 *     oil fields have independent counters. Counters reset at midnight
 *     implicitly — tomorrow's date won't match today's row.
 *   - Yield is rolled via RngService against drilling.yields[quality]
 *     and multiplied by the player's drill tier yield_multiplier
 *   - Barrels land in the player's oil_barrels balance
 *   - The point is marked drilled_at=now() and becomes unusable until
 *     OilFieldRegenJob resets it at the next regen window
 *   - AFTER the drill resolves, if the player is using a non-starter
 *     drill, a break roll happens. If it breaks the player row gets
 *     broken_item_key set, which trips BlockOnBrokenItem on the next
 *     request and overlays BrokenItemModal on the map.
 *
 * Everything runs inside a DB::transaction with lockForUpdate on the
 * Player row and a second lock on the drill point so simultaneous
 * requests cannot double-drill the same cell or race the daily count.
 */
class DrillService
{
    public function __construct(
        private readonly GameConfigResolver $config,
        private readonly RngService $rng,
        private readonly MoveRegenService $moveRegen,
        private readonly ItemBreakService $itemBreak,
        private readonly PassiveBonusService $passiveBonus,
        private readonly OilFieldRegenService $fieldRegen,
    ) {}

    /**
     * @return array{
     *     quality: string,
     *     barrels: int,
     *     new_balance: int,
     *     moves_remaining: int,
     *     daily_count: int,
     *     daily_limit: int,
     *     drill_broke: bool,
     *     broken_item_key: string|null,
     * }
     */
    public function drill(int $playerId, int $gridX, int $gridY): array
    {
        if ($gridX < 0 || $gridX > 4 || $gridY < 0 || $gridY > 4) {
            throw CannotDrillException::outOfRange($gridX, $gridY);
        }

        $cost = (int) $this->config->get('actions.drill.move_cost');

        // Defensive default guards against stale config:cache where the
        // key is missing; 0/negative would kill drilling entirely.
        $dailyLimit = (int) $this->config->get('drilling.daily_limit_per_field', 5);
        if ($dailyLimit <= 0) {
            $dailyLimit = 5;
        }

        return DB::transaction(function () use ($playerId, $gridX, $gridY, $cost, $dailyLimit) {
            /** @var Player $player */
            $player = Player::query()->lockForUpdate()->findOrFail($playerId);

            $this->moveRegen->reconcile($player);
            $player->refresh();

            if ($player->moves_current < $cost) {
                throw InsufficientMovesException::forAction('drill', $player->moves_current, $cost);
            }

            /** @var Tile $tile */
            $tile = Tile::query()->findOrFail($player->current_tile_id);
            if ($tile->type !== 'oil_field') {
                throw CannotDrillException::notAnOilField($tile->type);
            }

            /** @var OilField $field */
            $field = OilField::query()
                ->where('tile_id', $tile->id)
                ->firstOrFail();

            // Lazy refill: if this field has been fully depleted for
            // `drilling.field_refill_hours`, unmark every drill point
            // before we check whether the target cell is available.
            // Reconcile is idempotent and cheap when the field isn't
            // due for regen, so calling it on every drill is fine.
            $field = $this->fieldRegen->reconcile($field);

            // Apply any passive daily_drill_limit_bonus from owned items.
            $dailyLimit += $this->passiveBonus->drillLimitBonus($player);

            // Daily-limit check. Lock the counter row (if it exists) so
            // two concurrent drills can't both pass the pre-check and then
            // both bump it over the cap.
            $today = now()->toDateString();
            $countRow = DB::table('player_drill_counts')
                ->where('player_id', $player->id)
                ->where('oil_field_id', $field->id)
                ->where('drill_date', $today)
                ->lockForUpdate()
                ->first();

            $currentDailyCount = $countRow ? (int) $countRow->drill_count : 0;

            if ($currentDailyCount >= $dailyLimit) {
                throw CannotDrillException::dailyLimitReached($dailyLimit);
            }

            /** @var DrillPoint $point */
            $point = DrillPoint::query()
                ->lockForUpdate()
                ->where('oil_field_id', $field->id)
                ->where('grid_x', $gridX)
                ->where('grid_y', $gridY)
                ->firstOrFail();

            if ($point->drilled_at !== null) {
                throw CannotDrillException::pointDepleted($gridX, $gridY);
            }

            $yieldEventKey = 'drill-yield-'.$player->id.'-'.$field->id.'-'.$gridX.'-'.$gridY.'-'.now()->timestamp;
            $rawBarrels = $this->computeYield($point->quality, $player->drill_tier, $yieldEventKey);
            // Apply passive yield bonuses (e.g., lucky_charm +5%).
            $bonusPct = $this->passiveBonus->yieldBonusPct($player);
            $barrels = (int) floor($rawBarrels * (1.0 + $bonusPct));

            $point->update(['drilled_at' => now()]);

            // If that was the last undrilled cell, stamp the field's
            // depleted_at so the refill countdown starts. Any subsequent
            // read within the refill window will see the depleted state;
            // the next read after the window passes will clear it.
            $this->fieldRegen->markIfDepleted($field);

            $player->update([
                'moves_current' => $player->moves_current - $cost,
                'oil_barrels' => $player->oil_barrels + $barrels,
            ]);

            // Increment (or insert) the daily count row.
            $now = now();
            $newCount = $currentDailyCount + 1;

            if ($countRow) {
                DB::table('player_drill_counts')
                    ->where('id', $countRow->id)
                    ->update([
                        'drill_count' => $newCount,
                        'updated_at' => $now,
                    ]);
            } else {
                DB::table('player_drill_counts')->insert([
                    'player_id' => $player->id,
                    'oil_field_id' => $field->id,
                    'drill_date' => $today,
                    'drill_count' => $newCount,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // Break roll for the active drill item (tier 2+ only —
            // the starter "Dentist Drill" (tier 1) is never in
            // player_items so it's automatically exempt).
            $brokeNow = false;
            $brokenKey = null;
            $activeDrillKey = $this->activeDrillItemKey($player);

            if ($activeDrillKey !== null) {
                $eventKey = 'drill-'.$player->id.'-'.$field->id.'-'.$gridX.'-'.$gridY.'-'.$now->timestamp;
                $breakEvent = $this->itemBreak->rollBreak($player, $eventKey);

                if ($breakEvent) {
                    $this->itemBreak->markBroken($player, $activeDrillKey);
                    $brokeNow = true;
                    $brokenKey = $activeDrillKey;
                }
            }

            // update() already mutated oil_barrels and moves_current in-memory.
            return [
                'quality' => $point->quality,
                'barrels' => $barrels,
                'new_balance' => (int) $player->oil_barrels,
                'moves_remaining' => (int) $player->moves_current,
                'daily_count' => $newCount,
                'daily_limit' => $dailyLimit,
                'drill_broke' => $brokeNow,
                'broken_item_key' => $brokenKey,
            ];
        });
    }

    /**
     * Find the owned drill item key that matches the player's current
     * drill_tier. Returns null for tier 1 (starter Dentist Drill, never
     * owned as a player_item row).
     */
    private function activeDrillItemKey(Player $player): ?string
    {
        if ((int) $player->drill_tier <= 1) {
            return null;
        }

        // Find the item whose effects contain set_drill_tier = current tier
        // AND which the player owns in active state.
        $items = Item::query()
            ->where('post_type', 'tech')
            ->get()
            ->filter(function (Item $item) use ($player) {
                $effects = $item->effects ?? [];

                return isset($effects['set_drill_tier'])
                    && (int) $effects['set_drill_tier'] === (int) $player->drill_tier;
            });

        foreach ($items as $item) {
            $owns = PlayerItem::query()
                ->where('player_id', $player->id)
                ->where('item_key', $item->key)
                ->where('status', 'active')
                ->where('quantity', '>', 0)
                ->exists();

            if ($owns) {
                return $item->key;
            }
        }

        return null;
    }

    /**
     * Roll the barrel yield for a drill point of the given quality,
     * then scale by the player's drill tier yield_multiplier.
     */
    private function computeYield(string $quality, int $drillTier, string $eventKey): int
    {
        /** @var array{0:int,1:int}|null $band */
        $band = $this->config->get("drilling.yields.{$quality}");

        if (! is_array($band) || count($band) < 2) {
            return 0;
        }

        [$min, $max] = [(int) $band[0], (int) $band[1]];

        if ($min === 0 && $max === 0) {
            return 0;
        }

        // Deterministic seed so the roll is replayable/auditable. CLAUDE.md
        // mandates every random roll go through RngService with a stable event key.
        $base = $this->rng->rollInt(
            "drilling.yield.{$quality}",
            $eventKey,
            $min,
            $max,
        );

        $multiplier = (float) $this->config->get("drilling.equipment.{$drillTier}.yield_multiplier", 1.0);

        return (int) floor($base * $multiplier);
    }
}
