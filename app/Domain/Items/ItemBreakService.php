<?php

namespace App\Domain\Items;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Config\RngService;
use App\Domain\Exceptions\CannotPurchaseException;
use App\Models\Item;
use App\Models\Player;
use App\Models\PlayerItem;
use Illuminate\Support\Facades\DB;

/**
 * Handles the lifecycle of breakable tech items.
 *
 * Contract:
 *   - Only items whose effect keys appear in items.break.eligible_effect_keys
 *     are subject to break rolls. Defaults to ['set_drill_tier'] — drill only.
 *   - The starter drill (implicit tier 1, never in player_items) cannot break
 *     because there's no row to mark broken — it's always available.
 *   - When a break roll succeeds, the player_item row is marked status='broken'
 *     and the player's broken_item_key column is set, which triggers the
 *     BlockOnBrokenItem middleware on the next request.
 *   - The player must then repair (10% of original barrel cost by default)
 *     or abandon (drill_tier drops to next-highest owned drill tier, or 1).
 *   - If the player has insufficient barrels to repair, the UI forces them
 *     to abandon — this service doesn't enforce that directly, the frontend
 *     renders the repair button disabled.
 */
class ItemBreakService
{
    public function __construct(
        private readonly GameConfigResolver $config,
        private readonly RngService $rng,
        private readonly PassiveBonusService $passiveBonus,
    ) {}

    /**
     * Is the system enabled AND is this item_key subject to breaking?
     */
    public function isBreakable(Item $item): bool
    {
        if (! (bool) $this->config->get('items.break.enabled')) {
            return false;
        }

        $eligible = (array) $this->config->get('items.break.eligible_effect_keys');
        $effects = $item->effects ?? [];

        foreach ($eligible as $key) {
            if (array_key_exists($key, $effects)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Roll for break. Returns true if the item broke this use.
     * Caller is responsible for marking the row broken via markBroken().
     *
     * Passive items (e.g. lucky_coin, torque_wrench, lucky_rabbit_foot)
     * with `break_chance_reduction_pct` additively reduce the roll chance,
     * floored at 0.
     */
    public function rollBreak(Player $player, string $eventKey): bool
    {
        $chance = (float) $this->config->get('drilling.break_chance_pct');
        $chance -= $this->passiveBonus->breakChanceReductionPct($player);
        $chance = max(0.0, $chance);

        if ($chance <= 0) {
            return false;
        }

        return $this->rng->rollBool('item_break', $eventKey, $chance);
    }

    /**
     * Mark the given owned item as broken and lock the player.
     *
     * Wraps the PlayerItem update + Player update in DB::transaction so
     * the two writes are atomic. Laravel's nested-transaction handling
     * uses savepoints, so calling this from inside DrillService::drill
     * (which already holds an outer transaction) does NOT create a new
     * real transaction — the inner begin/commit becomes a savepoint on
     * the same connection, and the outer transaction still owns the
     * visible rollback boundary. Calling it from a test or any future
     * caller without an outer transaction gets real atomicity.
     */
    public function markBroken(Player $player, string $itemKey): void
    {
        DB::transaction(function () use ($player, $itemKey) {
            $row = PlayerItem::query()
                ->where('player_id', $player->id)
                ->where('item_key', $itemKey)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                // Nothing owned to break — no-op.
                return;
            }

            $row->update([
                'status' => 'broken',
                'broken_at' => now(),
            ]);

            $player->forceFill(['broken_item_key' => $itemKey])->save();
        });
    }

    /**
     * Repair the currently-broken item. Deducts oil_barrels equal to
     * repair_cost_pct of the original price. Throws if unaffordable.
     */
    public function repair(Player $player): PlayerItem
    {
        return DB::transaction(function () use ($player) {
            $player = Player::query()->lockForUpdate()->findOrFail($player->id);

            $key = $player->broken_item_key;
            if ($key === null) {
                throw CannotPurchaseException::unknownItem('(no broken item)');
            }

            /** @var Item $item */
            $item = Item::query()->where('key', $key)->firstOrFail();
            $cost = (int) ceil($item->price_barrels * (float) $this->config->get('items.break.repair_cost_pct'));

            if ($player->oil_barrels < $cost) {
                throw CannotPurchaseException::insufficientBarrels($player->oil_barrels, $cost);
            }

            // lockForUpdate closes a theoretical race with a concurrent
            // abandon() call (same player, two tabs). The outer player
            // row lock already serializes same-player requests, but this
            // is cheap belt-and-braces safety — and makes the lock intent
            // explicit for future readers.
            $row = PlayerItem::query()
                ->where('player_id', $player->id)
                ->where('item_key', $key)
                ->lockForUpdate()
                ->firstOrFail();

            $row->update([
                'status' => 'active',
                'broken_at' => null,
            ]);

            $player->update([
                'oil_barrels' => $player->oil_barrels - $cost,
                'broken_item_key' => null,
            ]);

            return $row;
        });
    }

    /**
     * Permanently abandon the currently-broken item. Deletes the row,
     * recomputes drill_tier to the next-highest still-owned drill tier
     * (or 1 — the implicit starter — if none remain).
     */
    public function abandon(Player $player): void
    {
        DB::transaction(function () use ($player) {
            $player = Player::query()->lockForUpdate()->findOrFail($player->id);

            $key = $player->broken_item_key;
            if ($key === null) {
                return;
            }

            /** @var Item $item */
            $item = Item::query()->where('key', $key)->firstOrFail();

            PlayerItem::query()
                ->where('player_id', $player->id)
                ->where('item_key', $key)
                ->delete();

            $updates = ['broken_item_key' => null];

            // If this was a drill-tier item, recompute drill_tier.
            $effects = $item->effects ?? [];
            if (isset($effects['set_drill_tier'])) {
                $updates['drill_tier'] = $this->computeHighestOwnedDrillTier($player->id);
            }

            $player->update($updates);
        });
    }

    /**
     * The player's drill_tier after an abandon: scan active drill items,
     * pick the highest set_drill_tier, or fall back to 1 (starter).
     */
    private function computeHighestOwnedDrillTier(int $playerId): int
    {
        $owned = DB::table('player_items')
            ->join('items_catalog', 'player_items.item_key', '=', 'items_catalog.key')
            ->where('player_items.player_id', $playerId)
            ->where('player_items.status', 'active')
            ->where('player_items.quantity', '>', 0)
            ->pluck('items_catalog.effects');

        $max = 1;
        foreach ($owned as $effectsJson) {
            $effects = is_string($effectsJson) ? json_decode($effectsJson, true) : (array) $effectsJson;
            if (isset($effects['set_drill_tier'])) {
                $tier = (int) $effects['set_drill_tier'];
                if ($tier > $max) {
                    $max = $tier;
                }
            }
        }

        return $max;
    }
}
