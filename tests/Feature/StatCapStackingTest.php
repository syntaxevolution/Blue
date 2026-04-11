<?php

use App\Domain\Exceptions\CannotPurchaseException;
use App\Domain\Economy\ShopService;
use App\Domain\World\WorldService;
use App\Models\Item;
use App\Models\Player;
use App\Models\Post;
use App\Models\Tile;
use App\Models\User;

beforeEach(function () {
    app(WorldService::class)->generateInitialWorld(seed: 42);
    $this->seed(\Database\Seeders\ItemsCatalogSeeder::class);
});

function spawnPlayerAtPost(): Player
{
    $user = User::factory()->create();
    $player = app(WorldService::class)->spawnPlayer($user->id);

    // Find a strength post and put the player on it.
    $postTile = Post::where('post_type', 'strength')->firstOrFail();
    $player->update(['current_tile_id' => $postTile->tile_id]);

    return $player->fresh();
}

it('allows buying a stat item once', function () {
    $player = spawnPlayerAtPost();
    $player->update(['oil_barrels' => 1000]);

    $result = app(ShopService::class)->purchase($player->id, 'small_rock');

    expect($result['item']->key)->toBe('small_rock');
    expect($player->fresh()->strength)->toBe(2); // starting 1 + 1
});

it('rejects buying the same stat item twice', function () {
    $player = spawnPlayerAtPost();
    $player->update(['oil_barrels' => 1000]);

    app(ShopService::class)->purchase($player->id, 'small_rock');

    expect(fn () => app(ShopService::class)->purchase($player->id, 'small_rock'))
        ->toThrow(CannotPurchaseException::class);
});

it('allows buying different stat items on the same post', function () {
    $player = spawnPlayerAtPost();
    $player->update(['oil_barrels' => 1000]);

    app(ShopService::class)->purchase($player->id, 'small_rock');
    app(ShopService::class)->purchase($player->id, 'boulder');

    expect($player->fresh()->strength)->toBe(4); // 1 + 1 + 2
});

it('banks overflow when purchase exceeds the hard cap', function () {
    $player = spawnPlayerAtPost();
    $player->update(['oil_barrels' => 10000, 'strength' => 48]);

    // Surplus minigun is +10 str, 1400 barrels. Should apply 2 (cap 50), bank 8.
    app(ShopService::class)->purchase($player->id, 'surplus_minigun');

    $player = $player->fresh();
    expect($player->strength)->toBe(50);
    expect($player->strength_banked)->toBe(8);
});
