<?php

use App\Domain\Config\GameConfigResolver;
use App\Domain\Items\StatOverflowService;
use App\Models\Player;

beforeEach(function () {
    $this->svc = app(StatOverflowService::class);
});

function newPlayerInMemory(array $attrs = []): Player
{
    return (new Player)->newFromBuilder(array_merge([
        'id' => 1,
        'strength' => 0,
        'fortification' => 0,
        'stealth' => 0,
        'security' => 0,
        'strength_banked' => 0,
        'fortification_banked' => 0,
        'stealth_banked' => 0,
        'security_banked' => 0,
    ], $attrs));
}

it('applies the delta in full when below the cap', function () {
    $player = newPlayerInMemory(['strength' => 10]);
    $summary = $this->svc->apply($player, ['strength' => 5]);

    expect($player->strength)->toBe(15);
    expect($player->strength_banked)->toBe(0);
    expect($summary['strength'])->toBe(['applied' => 5, 'banked' => 0]);
});

it('banks the overflow when exceeding the cap', function () {
    // stats.hard_cap default is 50 in Batch 1
    $player = newPlayerInMemory(['strength' => 48]);
    $summary = $this->svc->apply($player, ['strength' => 5]);

    expect($player->strength)->toBe(50);
    expect($player->strength_banked)->toBe(3);
    expect($summary['strength'])->toBe(['applied' => 2, 'banked' => 3]);
});

it('drains banked stats when the cap is raised', function () {
    $player = newPlayerInMemory(['strength' => 50, 'strength_banked' => 10]);

    // Temporarily raise the cap to 100
    config(['game.stats.hard_cap' => 100]);
    app()->forgetInstance(GameConfigResolver::class);
    $svc = app(StatOverflowService::class);

    $changed = $svc->drainBank($player);

    expect($changed)->toBeTrue();
    expect($player->strength)->toBe(60);
    expect($player->strength_banked)->toBe(0);
});

it('leaves bank alone when cap has not moved', function () {
    $player = newPlayerInMemory(['strength' => 50, 'strength_banked' => 10]);

    $changed = $this->svc->drainBank($player);

    expect($changed)->toBeFalse();
    expect($player->strength)->toBe(50);
    expect($player->strength_banked)->toBe(10);
});
