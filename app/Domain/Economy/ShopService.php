<?php

namespace App\Domain\Economy;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Exceptions\CannotPurchaseException;
use App\Domain\Items\StatOverflowService;
use App\Models\Item;
use App\Models\Player;
use App\Models\Post;
use App\Models\Tile;
use Illuminate\Support\Facades\DB;

/**
 * Shop purchase pipeline for player-at-post transactions.
 *
 * The gameplay contract:
 *   - Player must be standing on a tile of type 'post'
 *   - The item must exist in items_catalog and its post_type must
 *     match the current post's post_type
 *   - Player must be able to afford every non-zero price component
 *   - Stat-adding items can only be purchased ONCE per key (config
 *     stats.stat_items_single_purchase = true), and overflow above
 *     stats.hard_cap is banked via StatOverflowService (never rejected).
 *   - Drill-tier items must be a strict upgrade: "best tech only, no
 *     stacking" — we never apply a tier lower than the player's current.
 *   - Transport, teleporter, extra_moves, and other feature unlocks
 *     each get their own handler; effects are applied immediately.
 *   - On success: currencies are deducted, effects are applied to the
 *     Player row, and an entry is inserted/incremented in player_items
 *     so the owned-gear list reflects the purchase.
 *
 * Recognized effect keys (from items_catalog.effects JSON):
 *   stat_add           : {strength?, fortification?, stealth?, security?}
 *   set_drill_tier     : int
 *   unlocks            : list<string>  (feature unlocks like 'atlas')
 *   grant_moves        : int           (add to moves_current, can overflow)
 *   unlocks_transport  : string        (transport item — listed in config)
 *   unlocks_teleport   : bool          (purchase teleporter)
 *
 * Everything runs inside a DB::transaction with lockForUpdate on the
 * Player row so simultaneous purchases cannot double-spend or race the
 * hard-cap/drill-tier checks.
 */
class ShopService
{
    public function __construct(
        private readonly GameConfigResolver $config,
        private readonly StatOverflowService $statOverflow,
        private readonly ExtraMovesService $extraMoves,
    ) {}

    /**
     * @return array{item: Item, quantity: int}
     */
    public function purchase(int $playerId, string $itemKey): array
    {
        return DB::transaction(function () use ($playerId, $itemKey) {
            /** @var Player $player */
            $player = Player::query()->lockForUpdate()->findOrFail($playerId);

            /** @var Tile $tile */
            $tile = Tile::query()->findOrFail($player->current_tile_id);

            if ($tile->type !== 'post') {
                throw CannotPurchaseException::notOnAPost($tile->type);
            }

            /** @var Post $post */
            $post = Post::query()->where('tile_id', $tile->id)->firstOrFail();

            /** @var Item|null $item */
            $item = Item::query()->where('key', $itemKey)->first();

            if ($item === null) {
                throw CannotPurchaseException::unknownItem($itemKey);
            }

            if ($item->post_type !== $post->post_type) {
                throw CannotPurchaseException::wrongPostType($itemKey, $post->post_type, $item->post_type);
            }

            // Guard gates run before any writes. Order doesn't matter —
            // the player row is locked, so any order is consistent.
            $this->assertAffordable($player, $item);
            $this->assertSinglePurchaseRules($player, $item);
            $this->assertDrillUpgrade($player, $item);

            $this->deductCurrencies($player, $item);
            $this->applyEffects($player, $item);
            $quantity = $this->recordOwnership($player, $item);

            return ['item' => $item, 'quantity' => $quantity];
        });
    }

    private function assertAffordable(Player $player, Item $item): void
    {
        if ($item->price_barrels > 0 && $player->oil_barrels < $item->price_barrels) {
            throw CannotPurchaseException::insufficientBarrels($player->oil_barrels, $item->price_barrels);
        }

        $cash = (float) $item->price_cash;
        if ($cash > 0 && (float) $player->akzar_cash < $cash) {
            throw CannotPurchaseException::insufficientCash((float) $player->akzar_cash, $cash);
        }

        if ($item->price_intel > 0 && $player->intel < $item->price_intel) {
            throw CannotPurchaseException::insufficientIntel($player->intel, $item->price_intel);
        }
    }

    /**
     * Enforce "one purchase per item" rules:
     *   - stat-add items (when config flag is enabled)
     *   - transport items
     *   - teleporter
     *   - feature unlocks (explorers_atlas, etc.)
     *   - any item that grants a passive 'unlocks' list
     *
     * Items like extra_moves_pack (grant_moves) are deliberately
     * exempt — they're consumables with no practical ownership cap.
     */
    private function assertSinglePurchaseRules(Player $player, Item $item): void
    {
        $effects = $item->effects ?? [];

        $singlePurchaseFlagged = false;

        if (isset($effects['stat_add']) && is_array($effects['stat_add'])) {
            if ((bool) $this->config->get('stats.stat_items_single_purchase')) {
                $singlePurchaseFlagged = true;
            }
        }

        if (isset($effects['unlocks']) && is_array($effects['unlocks'])) {
            $singlePurchaseFlagged = true;
        }

        if (isset($effects['unlocks_transport'])) {
            $singlePurchaseFlagged = true;
        }

        if (array_key_exists('unlocks_teleport', $effects)) {
            $singlePurchaseFlagged = true;
        }

        if (! $singlePurchaseFlagged) {
            return;
        }

        $alreadyOwned = DB::table('player_items')
            ->where('player_id', $player->id)
            ->where('item_key', $item->key)
            ->where('quantity', '>', 0)
            ->exists();

        if ($alreadyOwned) {
            throw CannotPurchaseException::alreadyOwned($item->key);
        }
    }

    /**
     * Drill-tier items are upgrade-only. Buying a Shovel Rig when you
     * already own a Refinery is nonsense — "best tech only, no stacking".
     */
    private function assertDrillUpgrade(Player $player, Item $item): void
    {
        $effects = $item->effects ?? [];
        if (! isset($effects['set_drill_tier'])) {
            return;
        }

        $newTier = (int) $effects['set_drill_tier'];
        $currentTier = (int) $player->drill_tier;

        if ($newTier <= $currentTier) {
            throw CannotPurchaseException::alreadyHaveBetterDrill($currentTier, $newTier);
        }
    }

    private function deductCurrencies(Player $player, Item $item): void
    {
        $updates = [];

        if ($item->price_barrels > 0) {
            $updates['oil_barrels'] = $player->oil_barrels - $item->price_barrels;
        }
        if ((float) $item->price_cash > 0) {
            $updates['akzar_cash'] = (float) $player->akzar_cash - (float) $item->price_cash;
        }
        if ($item->price_intel > 0) {
            $updates['intel'] = $player->intel - $item->price_intel;
        }

        if ($updates !== []) {
            $player->update($updates);
        }
    }

    /**
     * Apply every recognized effect on the item. Passes in a fresh
     * $player reference so mutations from StatOverflowService carry
     * through to the update() call.
     */
    private function applyEffects(Player $player, Item $item): void
    {
        $effects = $item->effects ?? [];

        // Stat boosts go through the overflow service so excess gets
        // banked instead of rejected. The service mutates $player in
        // memory; we still have to save() at the end.
        if (isset($effects['stat_add']) && is_array($effects['stat_add'])) {
            $this->statOverflow->apply($player, $effects['stat_add']);
        }

        // Drill tier upgrade (assertDrillUpgrade guarantees this is
        // a strict increase by the time we get here).
        if (isset($effects['set_drill_tier'])) {
            $player->drill_tier = (int) $effects['set_drill_tier'];
        }

        // Extra moves — unlimited, can push moves_current above the
        // normal bank cap (user spec).
        if (isset($effects['grant_moves'])) {
            // grant_moves may be true (use config amount) or an int
            // delta embedded in the effect itself. Supports both shapes.
            if ($effects['grant_moves'] === true) {
                $this->extraMoves->grant($player);
            } else {
                $player->moves_current = (int) $player->moves_current + (int) $effects['grant_moves'];
            }
        }

        // Transport / teleporter / feature unlocks have no direct
        // Player row mutation — they're gated by player_items ownership
        // lookups in TransportService / TeleportService / MapStateBuilder.

        $player->save();
    }

    /**
     * Upsert the player_items row and return the new quantity.
     */
    private function recordOwnership(Player $player, Item $item): int
    {
        $now = now();

        $existing = DB::table('player_items')
            ->where('player_id', $player->id)
            ->where('item_key', $item->key)
            ->first();

        if ($existing) {
            DB::table('player_items')
                ->where('id', $existing->id)
                ->update([
                    'quantity' => $existing->quantity + 1,
                    'status' => 'active',
                    'updated_at' => $now,
                ]);

            return (int) $existing->quantity + 1;
        }

        DB::table('player_items')->insert([
            'player_id' => $player->id,
            'item_key' => $item->key,
            'quantity' => 1,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return 1;
    }
}
