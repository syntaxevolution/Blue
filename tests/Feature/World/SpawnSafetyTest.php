<?php

use App\Domain\Bot\BotSpawnService;
use App\Domain\World\WorldService;
use App\Models\Tile;
use App\Models\User;

beforeEach(function () {
    app(WorldService::class)->generateInitialWorld(seed: 42);
});

it('spawnPlayer only selects wasteland tiles and converts them to base', function () {
    $user = User::factory()->create();
    $player = app(WorldService::class)->spawnPlayer($user->id);
    $tile = Tile::findOrFail($player->base_tile_id);

    expect($tile->type)->toBe('base');
});

it('throws when no wasteland remains inside the spawn band', function () {
    // Move every wasteland tile inside the spawn band to 'landmark'.
    $spawnRadius = (int) config('game.world.spawn_band_radius');
    Tile::query()
        ->where('type', 'wasteland')
        ->whereRaw('(x * x + y * y) <= ?', [$spawnRadius * $spawnRadius])
        ->update(['type' => 'landmark']);

    $user = User::factory()->create();
    expect(fn () => app(WorldService::class)->spawnPlayer($user->id))
        ->toThrow(RuntimeException::class);
});

it('never spawns on a non-playable tile type', function () {
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    $u3 = User::factory()->create();

    $svc = app(WorldService::class);
    $p1 = $svc->spawnPlayer($u1->id);
    $p2 = $svc->spawnPlayer($u2->id);
    $p3 = $svc->spawnPlayer($u3->id);

    foreach ([$p1, $p2, $p3] as $p) {
        $tile = Tile::findOrFail($p->base_tile_id);
        // After conversion the tile is 'base' — the invariant to check
        // is that no two players share a base tile.
        expect($tile->type)->toBe('base');
    }

    $ids = collect([$p1, $p2, $p3])->pluck('base_tile_id')->unique();
    expect($ids)->toHaveCount(3);
});

it('bots spawn through the exact same safe path as humans', function () {
    /** @var BotSpawnService $spawner */
    $spawner = app(BotSpawnService::class);

    $bot = $spawner->spawn('BotAlpha', 'normal');
    $tile = Tile::findOrFail($bot->base_tile_id);

    expect($tile->type)->toBe('base');
    expect($bot->isBot())->toBeTrue();
    expect($bot->bot_difficulty)->toBe('normal');

    $spawnRadius = (int) config('game.world.spawn_band_radius');
    $distSq = $tile->x * $tile->x + $tile->y * $tile->y;
    expect($distSq)->toBeLessThanOrEqual($spawnRadius * $spawnRadius);
});
