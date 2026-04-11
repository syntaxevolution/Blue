<?php

use App\Domain\Economy\ShopService;
use App\Domain\Items\PassiveBonusService;
use App\Domain\Player\MoveRegenService;
use App\Domain\World\WorldService;
use App\Models\Player;
use App\Models\Post;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Iron Lungs — stackable +10 bank_cap_bonus from the general store
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    app(WorldService::class)->generateInitialWorld(seed: 42);
    $this->seed(\Database\Seeders\ItemsCatalogSeeder::class);
});

function spawnAtGeneralPostForLungs(): Player
{
    $user = User::factory()->create();
    $player = app(WorldService::class)->spawnPlayer($user->id);

    $postTile = Post::where('post_type', 'general')->firstOrFail();
    $player->update(['current_tile_id' => $postTile->tile_id]);

    return $player->fresh();
}

it('raises the per-player bank cap by 10 after one purchase', function () {
    $player = spawnAtGeneralPostForLungs();
    $player->update(['oil_barrels' => 10000]);

    $regen = app(MoveRegenService::class);
    expect($regen->bankCapFor($player))->toBe(350);

    app(ShopService::class)->purchase($player->id, 'iron_lungs');

    // bankCapFor re-queries PassiveBonusService which was flushed on purchase.
    expect($regen->bankCapFor($player->fresh()))->toBe(360);
});

it('stacks additively across multiple purchases', function () {
    $player = spawnAtGeneralPostForLungs();
    $player->update(['oil_barrels' => 100000]);

    $shop = app(ShopService::class);
    $shop->purchase($player->id, 'iron_lungs');
    $shop->purchase($player->id, 'iron_lungs');
    $shop->purchase($player->id, 'iron_lungs');
    $shop->purchase($player->id, 'iron_lungs');
    $shop->purchase($player->id, 'iron_lungs');

    // 350 base + 5 × 10 = 400
    expect(app(MoveRegenService::class)->bankCapFor($player->fresh()))->toBe(400);
    // 5 × 2500 = 12500 deducted
    expect($player->fresh()->oil_barrels)->toBe(100000 - 12500);
});

it('the base bankCap() is unchanged regardless of ownership', function () {
    $player = spawnAtGeneralPostForLungs();
    $player->update(['oil_barrels' => 10000]);

    app(ShopService::class)->purchase($player->id, 'iron_lungs');

    expect(app(MoveRegenService::class)->bankCap())->toBe(350);
});

it('PassiveBonusService reports the quantity-scaled bonus', function () {
    $player = spawnAtGeneralPostForLungs();
    $player->update(['oil_barrels' => 100000]);

    $shop = app(ShopService::class);
    $shop->purchase($player->id, 'iron_lungs');
    $shop->purchase($player->id, 'iron_lungs');
    $shop->purchase($player->id, 'iron_lungs');

    expect(app(PassiveBonusService::class)->bankCapBonus($player->fresh()))->toBe(30);
});

it('reconcile respects the elevated cap from Iron Lungs', function () {
    $player = spawnAtGeneralPostForLungs();
    $player->update(['oil_barrels' => 10000]);
    app(ShopService::class)->purchase($player->id, 'iron_lungs');

    // Seed the player at the raised cap minus 2, then let 10 ticks elapse.
    // Without Iron Lungs the cap would be 350 and the player's moves
    // would clip there; with Iron Lungs the cap is 360 so they should
    // reach 360 and stop.
    $player->fresh()->update([
        'moves_current' => 358,
        'moves_updated_at' => now()->subSeconds(10 * 432),
    ]);

    $reconciled = app(MoveRegenService::class)->reconcile($player->fresh());

    expect($reconciled->moves_current)->toBe(360);
});
