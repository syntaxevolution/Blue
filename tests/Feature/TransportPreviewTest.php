<?php

use App\Domain\Player\MapStateBuilder;
use App\Domain\World\WorldService;
use App\Models\Player;
use App\Models\Tile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| MapStateBuilder neighbor preview — should reflect active transport spaces
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    app(WorldService::class)->generateInitialWorld(seed: 42);
    $this->seed(\Database\Seeders\ItemsCatalogSeeder::class);
});

function spawnCentralForPreview(): Player
{
    $user = User::factory()->create();
    $player = app(WorldService::class)->spawnPlayer($user->id);

    $mid = Tile::where(['x' => 0, 'y' => 0])->first() ?? Tile::first();
    $player->update(['current_tile_id' => $mid->id, 'oil_barrels' => 1000]);

    return $player->fresh();
}

function grantTransport(Player $player, string $key): void
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

it('walking preview shows the immediate neighbor in each direction', function () {
    $player = spawnCentralForPreview();
    $state = app(MapStateBuilder::class)->build($player->fresh());

    $cx = $state['current_tile']['x'];
    $cy = $state['current_tile']['y'];

    $byDir = [];
    foreach ($state['neighbors'] as $n) {
        if ($n['direction']) {
            $byDir[$n['direction']] = $n;
        }
    }

    // Walking is spaces=1, so every neighbor is exactly 1 tile away.
    if (isset($byDir['n'])) expect($byDir['n']['y'])->toBe($cy + 1);
    if (isset($byDir['s'])) expect($byDir['s']['y'])->toBe($cy - 1);
    if (isset($byDir['e'])) expect($byDir['e']['x'])->toBe($cx + 1);
    if (isset($byDir['w'])) expect($byDir['w']['x'])->toBe($cx - 1);
});

it('bicycle preview shows the tile 2 spaces away', function () {
    $player = spawnCentralForPreview();
    grantTransport($player, 'bicycle');

    $state = app(MapStateBuilder::class)->build($player->fresh());

    $cx = $state['current_tile']['x'];
    $cy = $state['current_tile']['y'];

    $byDir = [];
    foreach ($state['neighbors'] as $n) {
        if ($n['direction']) {
            $byDir[$n['direction']] = $n;
        }
    }

    // At least one direction should have resolved — assert any present
    // tile is exactly 2 away, not 1.
    foreach (['n', 's', 'e', 'w'] as $dir) {
        if (! isset($byDir[$dir])) {
            continue;
        }
        $t = $byDir[$dir];
        $offset = match ($dir) {
            'n' => ['x' => 0, 'y' => 2],
            's' => ['x' => 0, 'y' => -2],
            'e' => ['x' => 2, 'y' => 0],
            'w' => ['x' => -2, 'y' => 0],
        };
        expect($t['x'])->toBe($cx + $offset['x']);
        expect($t['y'])->toBe($cy + $offset['y']);
    }
});

it('motorcycle preview shows the tile 5 spaces away', function () {
    $player = spawnCentralForPreview();
    grantTransport($player, 'motorcycle');

    $state = app(MapStateBuilder::class)->build($player->fresh());

    $cx = $state['current_tile']['x'];
    $cy = $state['current_tile']['y'];

    foreach ($state['neighbors'] as $n) {
        if ($n['direction'] === 'n') {
            expect($n['y'])->toBe($cy + 5);
            expect($n['x'])->toBe($cx);
        }
    }
});

it('airplane preview shows the tile 50 spaces away or is omitted at edge', function () {
    $player = spawnCentralForPreview();
    grantTransport($player, 'airplane');

    $state = app(MapStateBuilder::class)->build($player->fresh());

    $cx = $state['current_tile']['x'];
    $cy = $state['current_tile']['y'];

    foreach ($state['neighbors'] as $n) {
        if ($n['direction']) {
            $offset = match ($n['direction']) {
                'n' => ['dx' => 0, 'dy' => 50],
                's' => ['dx' => 0, 'dy' => -50],
                'e' => ['dx' => 50, 'dy' => 0],
                'w' => ['dx' => -50, 'dy' => 0],
            };
            expect($n['x'])->toBe($cx + $offset['dx']);
            expect($n['y'])->toBe($cy + $offset['dy']);
        }
    }
});
