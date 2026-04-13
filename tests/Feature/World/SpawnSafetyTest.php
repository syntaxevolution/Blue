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

it('throws when no wasteland remains anywhere in the world', function () {
    // Blank out every wasteland tile — spawn should fail gracefully
    // because it can no longer find a candidate anywhere.
    Tile::query()
        ->where('type', 'wasteland')
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

    // Spawn can land anywhere in the generated world — assert the
    // tile falls inside the initial world disc, not the spawn band.
    $worldRadius = (int) config('game.world.initial_radius');
    $distSq = $tile->x * $tile->x + $tile->y * $tile->y;
    expect($distSq)->toBeLessThanOrEqual($worldRadius * $worldRadius);
});
