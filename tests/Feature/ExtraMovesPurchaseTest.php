<?php

use App\Domain\Economy\ShopService;
use App\Domain\World\WorldService;
use App\Models\Player;
use App\Models\Post;
use App\Models\User;

beforeEach(function () {
    app(WorldService::class)->generateInitialWorld(seed: 42);
    $this->seed(\Database\Seeders\ItemsCatalogSeeder::class);
});

function spawnAtGeneralPost(): Player
{
    $user = User::factory()->create();
    $player = app(WorldService::class)->spawnPlayer($user->id);

    $postTile = Post::where('post_type', 'general')->firstOrFail();
    $player->update(['current_tile_id' => $postTile->tile_id]);

    return $player->fresh();
}

it('grants the configured amount of moves per purchase', function () {
    $player = spawnAtGeneralPost();
    $player->update(['oil_barrels' => 10000, 'moves_current' => 50, 'moves_updated_at' => now()]);

    app(ShopService::class)->purchase($player->id, 'extra_moves_pack');

    // Config default: general_store.extra_moves.amount = 50.
    expect($player->fresh()->moves_current)->toBe(100);
});

it('allows repeated purchases', function () {
    $player = spawnAtGeneralPost();
    $player->update(['oil_barrels' => 10000, 'moves_current' => 50, 'moves_updated_at' => now()]);

    app(ShopService::class)->purchase($player->id, 'extra_moves_pack');
    app(ShopService::class)->purchase($player->id, 'extra_moves_pack');
    app(ShopService::class)->purchase($player->id, 'extra_moves_pack');

    // Each pack grants 50 moves and costs 1500 barrels.
    expect($player->fresh()->moves_current)->toBe(200);
    expect($player->fresh()->oil_barrels)->toBe(10000 - 3 * 1500);
});

it('can push moves above the bank cap', function () {
    $player = spawnAtGeneralPost();
    $bankCap = (int) floor(200 * 1.75); // config default 350
    $player->update([
        'oil_barrels' => 10000,
        'moves_current' => $bankCap,
        'moves_updated_at' => now(),
    ]);

    app(ShopService::class)->purchase($player->id, 'extra_moves_pack');

    expect($player->fresh()->moves_current)->toBe($bankCap + 50);
});
