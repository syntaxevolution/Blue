<?php

use App\Domain\Bot\BotDecisionService;
use App\Domain\Bot\BotGoalPlanner;
use App\Domain\Bot\BotSpawnService;
use App\Domain\World\WorldService;
use App\Models\Attack;
use App\Models\Player;

beforeEach(function () {
    app(WorldService::class)->generateInitialWorld(seed: 42);
});

it('runs a tick without bubbling exceptions', function () {
    /** @var BotSpawnService $spawner */
    $spawner = app(BotSpawnService::class);
    $bot = $spawner->spawn('Ticker', 'normal');

    /** @var BotDecisionService $decisions */
    $decisions = app(BotDecisionService::class);
    $result = $decisions->tick($bot);

    expect($result)->toHaveKey('actions');
    expect($result['actions'])->toBeArray();

    $bot->refresh();
    expect($bot->bot_last_tick_at)->not->toBeNull();
});

it('persists a goal across a tick so subsequent ticks resume it', function () {
    /** @var BotSpawnService $spawner */
    $spawner = app(BotSpawnService::class);
    $bot = $spawner->spawn('Planner', 'normal');

    /** @var BotDecisionService $decisions */
    $decisions = app(BotDecisionService::class);
    $decisions->tick($bot);

    $bot->refresh();
    // A planner always has at least an explore fallback, so a goal
    // should exist unless the tick fully completed + replanned to null
    // which itself implies a valid goal was picked.
    expect($bot->bot_current_goal)->toBeArray();
    expect($bot->bot_current_goal)->toHaveKey('kind');
});

it('enters defensive mode once the defender-side attack threshold is met', function () {
    /** @var BotSpawnService $spawner */
    $spawner = app(BotSpawnService::class);
    $bot = $spawner->spawn('Victim', 'normal');
    $attacker = $spawner->spawn('Bruiser', 'normal');

    /** @var BotGoalPlanner $planner */
    $planner = app(BotGoalPlanner::class);

    expect($planner->isInDefensiveMode($bot))->toBeFalse();

    // Two recent attacks inside the default 24h window should flip
    // the flag (threshold = 2).
    foreach (range(1, 2) as $_) {
        Attack::create([
            'attacker_player_id' => $attacker->id,
            'defender_player_id' => $bot->id,
            'defender_base_tile_id' => $bot->base_tile_id,
            'outcome' => 'success',
            'cash_stolen' => 1.00,
            'attacker_escape' => false,
            'created_at' => now()->subHours(1),
        ]);
    }

    expect($planner->isInDefensiveMode($bot))->toBeTrue();
});

it('never selects a casino tile as a goal target', function () {
    /** @var BotSpawnService $spawner */
    $spawner = app(BotSpawnService::class);
    $bot = $spawner->spawn('CasinoAvoider', 'hard');

    /** @var BotGoalPlanner $planner */
    $planner = app(BotGoalPlanner::class);
    $tierCfg = config('game.bots.difficulty.hard');

    $goal = $planner->pickGoal($bot, $tierCfg);
    if ($goal === null) {
        $this->markTestSkipped('No goal produced on an empty-discovery map.');
    }

    if (isset($goal['tile_id'])) {
        $tile = \App\Models\Tile::find($goal['tile_id']);
        expect($tile?->type)->not->toBe('casino');
    }
});
