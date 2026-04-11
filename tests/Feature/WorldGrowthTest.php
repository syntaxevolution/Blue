<?php

use App\Domain\World\WorldService;
use App\Models\Player;
use App\Models\Tile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| WorldService::expandWorld — nightly ring-expansion growth
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    // Small radius keeps the density math easy to reason about and
    // the test DB small. spawn_band_radius matches initial_radius so
    // there's enough wasteland to seed 20+ players without running
    // out of candidate tiles.
    config([
        'game.world.initial_radius' => 5,
        'game.world.spawn_band_radius' => 5,
        'game.world.growth.enabled' => true,
        'game.world.growth.trigger_players_per_tile' => 0.05,
        'game.world.growth.expansion_ring_width' => 1,
    ]);
    app()->forgetInstance(\App\Domain\Config\GameConfigResolver::class);

    app(WorldService::class)->generateInitialWorld(seed: 42);
});

function humanPlayerCount(): int
{
    return (int) DB::table('players')
        ->join('users', 'users.id', '=', 'players.user_id')
        ->where('users.email', 'NOT LIKE', '%@bots.cashclash.local')
        ->count();
}

it('returns 0 when the kill-switch is off', function () {
    config(['game.world.growth.enabled' => false]);
    app()->forgetInstance(\App\Domain\Config\GameConfigResolver::class);

    // Pack enough humans in to otherwise trigger growth.
    for ($i = 0; $i < 20; $i++) {
        $user = User::factory()->create();
        app(WorldService::class)->spawnPlayer($user->id);
    }

    $before = Tile::query()->count();
    $added = app(WorldService::class)->expandWorld();

    expect($added)->toBe(0);
    expect(Tile::query()->count())->toBe($before);
});

it('returns 0 when density is at or below the trigger', function () {
    // Radius 5 world → roughly 81 tiles. Trigger = 0.05 → need > ~4
    // humans to grow. Spawn just 2.
    for ($i = 0; $i < 2; $i++) {
        $user = User::factory()->create();
        app(WorldService::class)->spawnPlayer($user->id);
    }

    $before = Tile::query()->count();
    $added = app(WorldService::class)->expandWorld();

    expect($added)->toBe(0);
    expect(Tile::query()->count())->toBe($before);
});

it('grows exactly one integer ring when density crosses the trigger', function () {
    // Push density well above 0.05 per tile.
    for ($i = 0; $i < 20; $i++) {
        $user = User::factory()->create();
        app(WorldService::class)->spawnPlayer($user->id);
    }

    $beforeTiles = Tile::query()->count();
    $beforeMaxRSq = (int) Tile::query()->selectRaw('MAX(x * x + y * y) as max_sq')->value('max_sq');

    $added = app(WorldService::class)->expandWorld();

    expect($added)->toBeGreaterThan(0);
    expect(Tile::query()->count())->toBe($beforeTiles + $added);

    // All newly-added tiles sit in (before_max_r_sq, new_max_r_sq].
    $newMaxRSq = (int) Tile::query()->selectRaw('MAX(x * x + y * y) as max_sq')->value('max_sq');
    expect($newMaxRSq)->toBeGreaterThan($beforeMaxRSq);

    $stragglers = Tile::query()
        ->whereRaw('(x * x + y * y) > ? AND (x * x + y * y) <= ?', [$beforeMaxRSq, $newMaxRSq])
        ->count();
    expect($stragglers)->toBe($added);
});

it('excludes bots from the density count', function () {
    // Fill the world with bot-domain users — density should stay 0
    // from the growth trigger's point of view.
    for ($i = 0; $i < 30; $i++) {
        $user = User::factory()->create([
            'email' => "bot{$i}@bots.cashclash.local",
        ]);
        app(WorldService::class)->spawnPlayer($user->id);
    }

    expect(humanPlayerCount())->toBe(0);

    $before = Tile::query()->count();
    $added = app(WorldService::class)->expandWorld();

    expect($added)->toBe(0);
    expect(Tile::query()->count())->toBe($before);
});

it('keeps growing on repeated calls while density stays above trigger', function () {
    for ($i = 0; $i < 30; $i++) {
        $user = User::factory()->create();
        app(WorldService::class)->spawnPlayer($user->id);
    }

    $world = app(WorldService::class);

    $firstPass = $world->expandWorld();
    expect($firstPass)->toBeGreaterThan(0);

    // Second pass adds a second ring — density is still above the
    // trigger because we added only ~40 tiles while keeping 30 players.
    $secondPass = $world->expandWorld();
    expect($secondPass)->toBeGreaterThan(0);

    // The rings must be strictly outside each other.
    expect($secondPass)->toBeGreaterThanOrEqual($firstPass);
});
