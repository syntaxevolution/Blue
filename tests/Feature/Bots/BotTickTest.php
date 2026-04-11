<?php

use App\Domain\Bot\BotDecisionService;
use App\Domain\Bot\BotSpawnService;
use App\Domain\World\WorldService;

beforeEach(function () {
    app(WorldService::class)->generateInitialWorld(seed: 42);
});

it('runs a tick without bubbling exceptions', function () {
    /** @var BotSpawnService $spawner */
    $spawner = app(BotSpawnService::class);
    $bot = $spawner->spawn('Ticker', 'normal');
    $movesBefore = $bot->moves_current;

    /** @var BotDecisionService $decisions */
    $decisions = app(BotDecisionService::class);
    $result = $decisions->tick($bot);

    expect($result)->toHaveKey('actions');
    expect($result['actions'])->toBeArray();

    $bot->refresh();
    // At least one action should have been attempted — moves may have
    // dropped OR bot_last_tick_at should be set.
    expect($bot->bot_last_tick_at)->not->toBeNull();
});
