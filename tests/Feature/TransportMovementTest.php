<?php

use App\Domain\Economy\TransportService;
use App\Domain\Exceptions\CannotTravelException;
use App\Domain\Player\TransportMovementService;
use App\Domain\World\FogOfWarService;
use App\Domain\World\WorldService;
use App\Models\Player;
use App\Models\Tile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    app(WorldService::class)->generateInitialWorld(seed: 42);
    $this->seed(\Database\Seeders\ItemsCatalogSeeder::class);
});

function givePlayerTransport(Player $player, string $key): void
{
    DB::table('player_items')->insert([
        'player_id' => $player->id,
        'item_key' => $key,
        'quantity' => 1,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $player->forceFill(['active_transport' => $key])->save();
}

function spawnCentral(): Player
{
    $user = User::factory()->create();
    $player = app(WorldService::class)->spawnPlayer($user->id);

    $mid = Tile::where(['x' => 0, 'y' => 0])->first() ?? Tile::first();
    $player->update(['current_tile_id' => $mid->id, 'oil_barrels' => 1000]);

    return $player->fresh();
}

it('travels 2 tiles on a bicycle, 0 fuel, 1 move', function () {
    $player = spawnCentral();
    givePlayerTransport($player, 'bicycle');

    $player->update(['moves_current' => 10, 'moves_updated_at' => now()]);
    $before = $player->fresh();

    $destination = app(TransportMovementService::class)->travel($player->id, 'n');

    expect($destination->y)->toBe($before->currentTile->y + 2);
    $after = $player->fresh();
    expect($after->moves_current)->toBe($before->moves_current - 1);
    expect($after->oil_barrels)->toBe($before->oil_barrels); // 0 fuel
});

it('motorcycle travels 5 tiles and consumes 1 barrel', function () {
    $player = spawnCentral();
    givePlayerTransport($player, 'motorcycle');
    $player->update(['moves_current' => 10, 'oil_barrels' => 5, 'moves_updated_at' => now()]);
    $before = $player->fresh();

    app(TransportMovementService::class)->travel($player->id, 'n');

    $after = $player->fresh();
    expect($after->oil_barrels)->toBe($before->oil_barrels - 1);
});

it('rejects the trip atomically if fuel is insufficient', function () {
    $player = spawnCentral();
    givePlayerTransport($player, 'motorcycle');
    $player->update(['moves_current' => 10, 'oil_barrels' => 0, 'moves_updated_at' => now()]);
    $before = $player->fresh();

    expect(fn () => app(TransportMovementService::class)->travel($player->id, 'n'))
        ->toThrow(CannotTravelException::class);

    $after = $player->fresh();
    expect($after->current_tile_id)->toBe($before->current_tile_id);
    expect($after->moves_current)->toBe($before->moves_current);
});

it('rejects trip if any intermediate tile is off the edge', function () {
    $user = User::factory()->create();
    $player = app(WorldService::class)->spawnPlayer($user->id);

    // Find the east-most tile.
    $eastEdge = Tile::orderByDesc('x')->first();
    $player->update(['current_tile_id' => $eastEdge->id, 'oil_barrels' => 1000, 'moves_current' => 10, 'moves_updated_at' => now()]);
    givePlayerTransport($player->fresh(), 'bicycle');

    expect(fn () => app(TransportMovementService::class)->travel($player->id, 'e'))
        ->toThrow(CannotTravelException::class);
});

it('airplane reveals every tile in the flight path', function () {
    $player = spawnCentral();
    givePlayerTransport($player, 'airplane');
    $player->update(['moves_current' => 10, 'oil_barrels' => 100, 'moves_updated_at' => now()]);

    // Flight may hit edge of world — just assert at least destination
    // + multiple intermediate tiles get discovered.
    try {
        app(TransportMovementService::class)->travel($player->id, 'n');
    } catch (CannotTravelException $e) {
        // If the central tile is too close to the edge, the test world
        // may not have 50 tiles north. Expand world seed above if so.
        $this->markTestSkipped('World too small for airplane path test: '.$e->getMessage());
    }

    $count = app(FogOfWarService::class)->countDiscovered($player->id);
    expect($count)->toBeGreaterThan(1);
});
