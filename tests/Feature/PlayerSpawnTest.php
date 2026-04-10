<?php

use App\Domain\World\WorldService;
use App\Models\Player;
use App\Models\Tile;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Feature tests for WorldService::spawnPlayer
|--------------------------------------------------------------------------
|
| Hits a real MySQL database through RefreshDatabase. Each test first
| generates a deterministic world (seed 42) so a wasteland tile pool
| exists, then spawns a freshly-minted user.
|
*/

beforeEach(function () {
    app(WorldService::class)->generateInitialWorld(seed: 42);
});

it('converts a wasteland tile into a player base', function () {
    $user = User::factory()->create();

    $player = app(WorldService::class)->spawnPlayer($user->id);

    expect($player)->toBeInstanceOf(Player::class);
    expect($player->user_id)->toBe($user->id);

    $baseTile = Tile::find($player->base_tile_id);
    expect($baseTile->type)->toBe('base');
    expect($baseTile->subtype)->toBeNull();
});

it('sets current_tile_id equal to base_tile_id on spawn', function () {
    $user = User::factory()->create();

    $player = app(WorldService::class)->spawnPlayer($user->id);

    expect($player->current_tile_id)->toBe($player->base_tile_id);
});

it('gives the spawned player the full starting loadout from config', function () {
    $user = User::factory()->create();

    $player = app(WorldService::class)->spawnPlayer($user->id);

    expect((float) $player->akzar_cash)->toBe(5.00);
    expect($player->oil_barrels)->toBe(0);
    expect($player->intel)->toBe(0);
    expect($player->moves_current)->toBe(200);
    expect($player->strength)->toBe(1);
    expect($player->fortification)->toBe(0);
    expect($player->stealth)->toBe(0);
    expect($player->security)->toBe(0);
    expect($player->drill_tier)->toBe(1);
});

it('sets a 48-hour immunity window from now', function () {
    $user = User::factory()->create();

    $player = app(WorldService::class)->spawnPlayer($user->id);

    $hoursFromNow = now()->diffInHours($player->immunity_expires_at, absolute: false);
    expect($hoursFromNow)->toBeGreaterThanOrEqual(47)->toBeLessThanOrEqual(49);
});

it('places the spawn inside the configured spawn band radius', function () {
    $user = User::factory()->create();

    $player = app(WorldService::class)->spawnPlayer($user->id);
    $base = $player->baseTile;

    $spawnRadius = 12; // world.spawn_band_radius
    $distanceSq = $base->x ** 2 + $base->y ** 2;

    expect($distanceSq)->toBeLessThanOrEqual($spawnRadius * $spawnRadius);
});

it('throws when no wasteland tiles are available', function () {
    // Wipe the world — spawn should fail gracefully.
    Tile::query()->delete();
    $user = User::factory()->create();

    expect(fn () => app(WorldService::class)->spawnPlayer($user->id))
        ->toThrow(RuntimeException::class);
});

it('different users get different base tiles', function () {
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    $u3 = User::factory()->create();

    $svc = app(WorldService::class);
    $p1 = $svc->spawnPlayer($u1->id);
    $p2 = $svc->spawnPlayer($u2->id);
    $p3 = $svc->spawnPlayer($u3->id);

    $tiles = collect([$p1, $p2, $p3])->pluck('base_tile_id')->unique();
    expect($tiles)->toHaveCount(3);
});
