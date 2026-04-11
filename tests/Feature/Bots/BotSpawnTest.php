<?php

use App\Domain\Bot\BotSpawnService;
use App\Domain\World\WorldService;
use App\Models\Player;
use App\Models\Tile;

beforeEach(function () {
    app(WorldService::class)->generateInitialWorld(seed: 42);
});

it('spawns a bot with the correct user + player shape', function () {
    /** @var BotSpawnService $spawner */
    $spawner = app(BotSpawnService::class);

    $bot = $spawner->spawn('TestBot', 'easy');
    expect($bot)->toBeInstanceOf(Player::class);
    expect($bot->isBot())->toBeTrue();
    expect($bot->bot_difficulty)->toBe('easy');
    expect($bot->user->is_bot)->toBeTrue();
    expect($bot->user->email)->toContain('@bots.cashclash.local');
    expect($bot->akzar_cash)->not->toBeNull();
});

it('setDifficulty updates the tier', function () {
    /** @var BotSpawnService $spawner */
    $spawner = app(BotSpawnService::class);

    $bot = $spawner->spawn('Tier1', 'easy');
    $spawner->setDifficulty($bot, 'hard');

    $bot->refresh();
    expect($bot->bot_difficulty)->toBe('hard');
});

it('destroy releases the base tile back to wasteland', function () {
    /** @var BotSpawnService $spawner */
    $spawner = app(BotSpawnService::class);

    $bot = $spawner->spawn('Doomed', 'normal');
    $baseTileId = $bot->base_tile_id;

    $spawner->destroy($bot);

    $tile = Tile::find($baseTileId);
    expect($tile->type)->toBe('wasteland');
    expect(Player::find($bot->id))->toBeNull();
});

it('refuses to destroy a non-bot player', function () {
    /** @var BotSpawnService $spawner */
    $spawner = app(BotSpawnService::class);

    $user = \App\Models\User::factory()->create(); // is_bot=false
    $player = app(WorldService::class)->spawnPlayer($user->id);

    expect(fn () => $spawner->destroy($player))
        ->toThrow(InvalidArgumentException::class);
});
