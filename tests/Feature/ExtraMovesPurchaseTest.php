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

it('grants 10 moves per purchase', function () {
    $player = spawnAtGeneralPost();
    $player->update(['oil_barrels' => 10000, 'moves_current' => 50, 'moves_updated_at' => now()]);

    app(ShopService::class)->purchase($player->id, 'extra_moves_pack');

    expect($player->fresh()->moves_current)->toBe(60);
});

it('allows repeated purchases', function () {
    $player = spawnAtGeneralPost();
    $player->update(['oil_barrels' => 10000, 'moves_current' => 50, 'moves_updated_at' => now()]);

    app(ShopService::class)->purchase($player->id, 'extra_moves_pack');
    app(ShopService::class)->purchase($player->id, 'extra_moves_pack');
    app(ShopService::class)->purchase($player->id, 'extra_moves_pack');

    // Each pack grants 10 moves and costs 1000 barrels.
    expect($player->fresh()->moves_current)->toBe(80);
    expect($player->fresh()->oil_barrels)->toBe(7000);
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

    expect($player->fresh()->moves_current)->toBe($bankCap + 10);
});
