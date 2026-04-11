<?php

use App\Domain\Economy\TeleportService;
use App\Domain\Exceptions\CannotPurchaseException;
use App\Domain\Exceptions\CannotTravelException;
use App\Domain\World\WorldService;
use App\Models\Player;
use App\Models\Tile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    app(WorldService::class)->generateInitialWorld(seed: 42);
    $this->seed(\Database\Seeders\ItemsCatalogSeeder::class);
});

function newPlayerWithBarrels(int $barrels = 300000): Player
{
    $user = User::factory()->create();
    $player = app(WorldService::class)->spawnPlayer($user->id);
    $player->update(['oil_barrels' => $barrels]);

    return $player->fresh();
}

function grantTeleporter(Player $player): void
{
    DB::table('player_items')->insert([
        'player_id' => $player->id,
        'item_key' => 'teleporter',
        'quantity' => 1,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('teleports to a valid tile and deducts 5000 barrels', function () {
    $player = newPlayerWithBarrels(20000);
    grantTeleporter($player);

    $dest = Tile::first();
    $starting = $player->oil_barrels;

    app(TeleportService::class)->teleport($player->id, $dest->x, $dest->y);

    $player = $player->fresh();
    expect($player->current_tile_id)->toBe($dest->id);
    expect($player->oil_barrels)->toBe($starting - 5000);
});

it('rejects teleport to a non-existent tile and does NOT charge', function () {
    $player = newPlayerWithBarrels(20000);
    grantTeleporter($player);
    $starting = $player->oil_barrels;

    expect(fn () => app(TeleportService::class)->teleport($player->id, 99999, 99999))
        ->toThrow(CannotTravelException::class);

    expect($player->fresh()->oil_barrels)->toBe($starting);
});

it('rejects teleport without a teleporter', function () {
    $player = newPlayerWithBarrels(20000);
    $dest = Tile::first();

    expect(fn () => app(TeleportService::class)->teleport($player->id, $dest->x, $dest->y))
        ->toThrow(CannotTravelException::class);
});

it('rejects teleport with insufficient barrels', function () {
    $player = newPlayerWithBarrels(100); // less than 5000 cost
    grantTeleporter($player);
    $dest = Tile::first();

    expect(fn () => app(TeleportService::class)->teleport($player->id, $dest->x, $dest->y))
        ->toThrow(CannotPurchaseException::class);
});
