<?php

use App\Models\Player;
use App\Models\Tile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function createUndiscoveredCommandTile(int $x, int $y, string $type = 'wasteland', ?string $subtype = null): Tile
{
    return Tile::query()->create([
        'x' => $x,
        'y' => $y,
        'type' => $type,
        'subtype' => $subtype,
        'seed' => abs(crc32("{$x}:{$y}:{$type}:".($subtype ?? ''))),
    ]);
}

it('prints coordinates for tiles the player has not discovered', function () {
    $base = createUndiscoveredCommandTile(0, 0, 'base');
    $seen = createUndiscoveredCommandTile(1, 0);
    createUndiscoveredCommandTile(2, 0, 'oil_field');
    createUndiscoveredCommandTile(-1, 1, 'post', 'general');

    $user = User::factory()->create();
    $player = Player::query()->create([
        'user_id' => $user->id,
        'base_tile_id' => $base->id,
        'current_tile_id' => $base->id,
        'moves_current' => 200,
        'moves_updated_at' => now(),
    ]);

    DB::table('tile_discoveries')->insert([
        ['player_id' => $player->id, 'tile_id' => $base->id, 'discovered_at' => now()],
        ['player_id' => $player->id, 'tile_id' => $seen->id, 'discovered_at' => now()],
    ]);

    $expected = [
        [
            'x' => -1,
            'y' => 1,
            'type' => 'post',
            'subtype' => 'general',
        ],
        [
            'x' => 2,
            'y' => 0,
            'type' => 'oil_field',
            'subtype' => null,
        ],
    ];

    $this->artisan('players:undiscovered-tiles', [
        'user' => $user->name,
        '--json' => true,
    ])
        ->expectsOutput(json_encode($expected, JSON_PRETTY_PRINT))
        ->assertSuccessful();
});

it('can print only the undiscovered tile count', function () {
    $base = createUndiscoveredCommandTile(0, 0, 'base');
    createUndiscoveredCommandTile(1, 0);

    $user = User::factory()->create();
    $player = Player::query()->create([
        'user_id' => $user->id,
        'base_tile_id' => $base->id,
        'current_tile_id' => $base->id,
        'moves_current' => 200,
        'moves_updated_at' => now(),
    ]);

    DB::table('tile_discoveries')->insert([
        'player_id' => $player->id,
        'tile_id' => $base->id,
        'discovered_at' => now(),
    ]);

    $this->artisan('players:undiscovered-tiles', [
        'user' => $user->email,
        '--count' => true,
    ])
        ->expectsOutput('1')
        ->assertSuccessful();
});
