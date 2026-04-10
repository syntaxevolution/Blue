<?php

namespace App\Domain\Economy;

use App\Domain\Exceptions\CannotPurchaseException;
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
 *   - On success: currencies are deducted, effects are applied to the
 *     Player row, and an entry is inserted/incremented in player_items
 *     so the owned-gear list reflects the purchase
 *
 * Recognized effect keys (from items_catalog.effects JSON):
 *   stat_add:       {strength?: int, fortification?: int, stealth?: int, security?: int}
 *   set_drill_tier: int   (sets drill_tier; does not increment)
 *
 * Everything runs inside a DB::transaction with lockForUpdate on the
 * Player row so simultaneous purchases cannot double-spend.
 */
class ShopService
{
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

            $this->assertAffordable($player, $item);
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

    private function applyEffects(Player $player, Item $item): void
    {
        $effects = $item->effects ?? [];
        $updates = [];

        if (isset($effects['stat_add']) && is_array($effects['stat_add'])) {
            foreach (['strength', 'fortification', 'stealth', 'security'] as $stat) {
                $delta = (int) ($effects['stat_add'][$stat] ?? 0);
                if ($delta !== 0) {
                    $updates[$stat] = ((int) $player->$stat) + $delta;
                }
            }
        }

        if (isset($effects['set_drill_tier'])) {
            $updates['drill_tier'] = (int) $effects['set_drill_tier'];
        }

        if ($updates !== []) {
            $player->update($updates);
        }
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
                    'updated_at' => $now,
                ]);

            return (int) $existing->quantity + 1;
        }

        DB::table('player_items')->insert([
            'player_id' => $player->id,
            'item_key' => $item->key,
            'quantity' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return 1;
    }
}
