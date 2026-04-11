<?php

use App\Domain\Config\GameConfigResolver;
use App\Domain\Items\ItemBreakService;
use App\Domain\World\WorldService;
use App\Models\Item;
use App\Models\Player;
use App\Models\PlayerItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    app(WorldService::class)->generateInitialWorld(seed: 42);
    $this->seed(\Database\Seeders\ItemsCatalogSeeder::class);
});

function givePlayerDrill(Player $player, string $key, int $tier): void
{
    DB::table('player_items')->insert([
        'player_id' => $player->id,
        'item_key' => $key,
        'quantity' => 1,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $player->forceFill(['drill_tier' => $tier])->save();
}

function basicPlayer(): Player
{
    $user = User::factory()->create();
    $p = app(WorldService::class)->spawnPlayer($user->id);
    $p->update(['oil_barrels' => 1000]);

    return $p->fresh();
}

it('markBroken sets the broken_item_key and row status', function () {
    $player = basicPlayer();
    givePlayerDrill($player, 'shovel_rig', 2);

    app(ItemBreakService::class)->markBroken($player->fresh(), 'shovel_rig');

    $player = $player->fresh();
    expect($player->broken_item_key)->toBe('shovel_rig');

    $row = PlayerItem::where('player_id', $player->id)
        ->where('item_key', 'shovel_rig')
        ->first();
    expect($row->status)->toBe('broken');
});

it('repair deducts barrels and restores active status', function () {
    $player = basicPlayer();
    givePlayerDrill($player, 'heavy_drill', 4);
    app(ItemBreakService::class)->markBroken($player->fresh(), 'heavy_drill');

    $cost = (int) ceil(180 * 0.10); // heavy_drill price 180, repair pct 0.10

    app(ItemBreakService::class)->repair($player->fresh());

    $player = $player->fresh();
    expect($player->broken_item_key)->toBeNull();
    expect($player->oil_barrels)->toBe(1000 - $cost);

    $row = PlayerItem::where('player_id', $player->id)->where('item_key', 'heavy_drill')->first();
    expect($row->status)->toBe('active');
});

it('abandon drops drill_tier to the next-highest owned tier', function () {
    $player = basicPlayer();
    // Own both shovel_rig (tier 2) AND heavy_drill (tier 4).
    givePlayerDrill($player, 'shovel_rig', 4);
    DB::table('player_items')->insert([
        'player_id' => $player->id,
        'item_key' => 'heavy_drill',
        'quantity' => 1,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $player->forceFill(['drill_tier' => 4])->save();

    // Break and abandon the heavy drill; the player should drop to tier 2.
    app(ItemBreakService::class)->markBroken($player->fresh(), 'heavy_drill');
    app(ItemBreakService::class)->abandon($player->fresh());

    $player = $player->fresh();
    expect($player->broken_item_key)->toBeNull();
    expect($player->drill_tier)->toBe(2);

    // The heavy_drill row should be gone.
    $row = PlayerItem::where('player_id', $player->id)->where('item_key', 'heavy_drill')->first();
    expect($row)->toBeNull();
});

it('abandon drops drill_tier to 1 when no other drills remain', function () {
    $player = basicPlayer();
    givePlayerDrill($player, 'medium_drill', 3);

    app(ItemBreakService::class)->markBroken($player->fresh(), 'medium_drill');
    app(ItemBreakService::class)->abandon($player->fresh());

    expect($player->fresh()->drill_tier)->toBe(1);
});
