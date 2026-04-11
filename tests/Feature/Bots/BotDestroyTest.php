<?php

use App\Domain\Bot\BotSpawnService;
use App\Domain\World\WorldService;
use App\Models\Player;
use App\Models\Tile;
use App\Models\User;

beforeEach(function () {
    app(WorldService::class)->generateInitialWorld(seed: 42);
});

it('destroys a bot and releases the base tile back to wasteland', function () {
    /** @var BotSpawnService $spawner */
    $spawner = app(BotSpawnService::class);

    $bot = $spawner->spawn('Tracer', 'normal');
    $userId = $bot->user_id;
    $baseTileId = $bot->base_tile_id;

    expect(Tile::find($baseTileId)->type)->toBe('base');

    $spawner->destroy($bot);

    // Tile is back in the wasteland pool.
    $tile = Tile::find($baseTileId);
    expect($tile->type)->toBe('wasteland');
    expect($tile->subtype)->toBeNull();

    // Player and user rows are gone.
    expect(Player::find($bot->id))->toBeNull();
    expect(User::find($userId))->toBeNull();
});

it('allows a subsequent spawn to reuse the freed tile', function () {
    /** @var BotSpawnService $spawner */
    $spawner = app(BotSpawnService::class);

    $first = $spawner->spawn('Alpha', 'easy');
    $spawner->destroy($first);

    // New bot should be able to spawn cleanly — at minimum, spawn
    // must not throw on a world where tiles have been reclaimed.
    $second = $spawner->spawn('Beta', 'easy');
    expect($second)->toBeInstanceOf(Player::class);
});
