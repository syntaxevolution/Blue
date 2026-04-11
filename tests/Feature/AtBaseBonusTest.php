<?php

use App\Domain\Combat\CombatFormula;
use App\Domain\Config\GameConfigResolver;
use App\Domain\Config\RngService;
use App\Models\Player;

/**
 * Verifies the F2 at-base defense bonus.
 *
 * Since CombatFormula is a pure resolver, we can instantiate it directly
 * with real services and pass in Player instances constructed by hand —
 * no DB required. Player is built via `newFromBuilder` so we can set
 * stats without triggering fillable guards.
 */
beforeEach(function () {
    $this->config = app(GameConfigResolver::class);
    $this->rng = app(RngService::class);
    // Replay mode with a fixed RNG output so finalScore is deterministic.
    $this->rng->enableReplayMode([
        'combat.band:test-key' => [0.0],
    ]);
});

afterEach(function () {
    $this->rng->disableReplayMode();
});

function makePlayer(array $attrs): Player
{
    $player = (new Player)->newFromBuilder(array_merge([
        'id' => 1,
        'user_id' => 1,
        'base_tile_id' => 10,
        'current_tile_id' => 10,
        'akzar_cash' => 100.00,
        'strength' => 10,
        'fortification' => 10,
        'stealth' => 0,
        'security' => 0,
        'drill_tier' => 1,
    ], $attrs));

    return $player;
}

it('applies the at-base bonus when defender is home', function () {
    $formula = app(CombatFormula::class);

    $attacker = makePlayer(['id' => 1, 'strength' => 10, 'fortification' => 0]);
    $defender = makePlayer(['id' => 2, 'strength' => 10, 'fortification' => 10, 'current_tile_id' => 10, 'base_tile_id' => 10]);

    $result = $formula->resolveAttack($attacker, $defender, 'test-key', true);

    expect($result['defender_at_base'])->toBeTrue();
    // defPower should be fortification(10) + strength(10) = 20
    expect($result['def_power'])->toBe(20.0);
    // atkPower = 10, defPower = 20 -> baseOutcome negative
    expect($result['base_outcome'])->toBeLessThan(0.0);
});

it('does not apply the bonus when defender is away', function () {
    $formula = app(CombatFormula::class);

    $attacker = makePlayer(['id' => 1, 'strength' => 10, 'fortification' => 0]);
    $defender = makePlayer(['id' => 2, 'strength' => 10, 'fortification' => 10]);

    $result = $formula->resolveAttack($attacker, $defender, 'test-key', false);

    expect($result['defender_at_base'])->toBeFalse();
    expect($result['def_power'])->toBe(10.0);
});

it('respects the config flag to disable the bonus entirely', function () {
    // Temporarily override the config flag
    config(['game.combat.at_base_defense_bonus_enabled' => false]);
    app()->forgetInstance(GameConfigResolver::class);
    app()->forgetInstance(CombatFormula::class);

    $formula = app(CombatFormula::class);

    $attacker = makePlayer(['id' => 1, 'strength' => 10, 'fortification' => 0]);
    $defender = makePlayer(['id' => 2, 'strength' => 10, 'fortification' => 10]);

    $result = $formula->resolveAttack($attacker, $defender, 'test-key', true);

    expect($result['defender_at_base'])->toBeFalse();
    expect($result['def_power'])->toBe(10.0);
});
