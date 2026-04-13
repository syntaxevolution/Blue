<?php

use App\Domain\Combat\CombatFormula;
use App\Domain\Config\GameConfigResolver;
use App\Domain\Config\RngService;
use App\Models\Player;

/**
 * Pure-math tests for CombatFormula::resolveTileDuel() and
 * estimateTileDuelWinChance(). No DB — Player rows are constructed
 * via newFromBuilder so the formula can be exercised in isolation.
 *
 * RNG is locked via replay mode with a known event key so the band
 * never adds noise to assertions.
 */
beforeEach(function () {
    $this->config = app(GameConfigResolver::class);
    $this->rng = app(RngService::class);
    // Zero band → finalScore = baseOutcome exactly. Queue a generous
    // number of zeros so multi-roll tests don't exhaust the replay.
    $this->rng->enableReplayMode([
        'combat.band:test-key' => array_fill(0, 10, 0.0),
    ]);
});

afterEach(function () {
    $this->rng->disableReplayMode();
});

function makeDuelPlayer(int $id, int $strength): Player
{
    return (new Player)->newFromBuilder([
        'id' => $id,
        'user_id' => $id,
        'base_tile_id' => 10,
        'current_tile_id' => 10,
        'oil_barrels' => 1000,
        'akzar_cash' => 100.00,
        'strength' => $strength,
        'fortification' => 0,
        'stealth' => 0,
        'security' => 0,
        'drill_tier' => 1,
    ]);
}

it('resolves a tile duel deterministically given a fixed band', function () {
    $formula = app(CombatFormula::class);

    $attacker = makeDuelPlayer(1, 10);
    $defender = makeDuelPlayer(2, 5);

    $res = $formula->resolveTileDuel($attacker, $defender, 'test-key');

    // Attacker stronger — should win with zero band.
    expect($res['outcome'])->toBe('attacker_win');
    expect($res['winner_id'])->toBe(1);
    expect($res['loser_id'])->toBe(2);
    expect($res['atk_power'])->toBe(10.0);
    expect($res['def_power'])->toBe(5.0);
    // base_outcome = (10 - 5) / 15 = 1/3
    expect($res['base_outcome'])->toBeGreaterThan(0.0);
    expect($res['random_band'])->toBe(0.0);
});

it('produces an UPSET-REWARD loot curve: stronger winner gets smaller slice', function () {
    $formula = app(CombatFormula::class);

    // Bully: Str 20 vs Str 2 → attacker wins, but loot_pct should be TINY.
    $bully = makeDuelPlayer(1, 20);
    $weak  = makeDuelPlayer(2, 2);
    $bullyResult = $formula->resolveTileDuel($bully, $weak, 'test-key');
    expect($bullyResult['outcome'])->toBe('attacker_win');
    $bullyPct = (float) $bullyResult['oil_pct'];

    // Clear cache for the next scenario (replay mode is idempotent).
    // Even fight: Str 10 vs Str 10 — draw tips to defender with zero band,
    // but the loot ratio here should be the maximum (equal strengths).
    $even1 = makeDuelPlayer(3, 10);
    $even2 = makeDuelPlayer(4, 10);
    $evenResult = $formula->resolveTileDuel($even1, $even2, 'test-key');
    $evenPct = (float) $evenResult['oil_pct'];

    // Bully ratio should be much smaller than the even ratio.
    expect($bullyPct)->toBeLessThan($evenPct);

    // And both must respect the ceiling.
    $ceiling = (float) config('game.combat.tile_duel.max_oil_loot_pct');
    expect($bullyPct)->toBeLessThanOrEqual($ceiling);
    expect($evenPct)->toBeLessThanOrEqual($ceiling);
});

it('upset winner (weaker aggressor) gets max loot ceiling', function () {
    // Push the band to a large positive value so the weak attacker
    // actually wins against a stronger defender.
    $this->rng->enableReplayMode([
        'combat.band:upset-key' => [0.40],
    ]);

    $formula = app(CombatFormula::class);

    // Attacker Str 5 vs Defender Str 10 — base_outcome = -1/3. With
    // a +0.4 band, finalScore = +0.067 > 0 → attacker wins the upset.
    $weakAttacker = makeDuelPlayer(1, 5);
    $strongDefender = makeDuelPlayer(2, 10);

    $res = $formula->resolveTileDuel($weakAttacker, $strongDefender, 'upset-key');

    expect($res['outcome'])->toBe('attacker_win');
    // Upset ratio: loser(10) / winner(5) is clamped to 1.0, so loot hits the ceiling.
    $ceiling = (float) config('game.combat.tile_duel.max_oil_loot_pct');
    expect((float) $res['oil_pct'])->toBe($ceiling);
});

it('defender auto-counterattacks and wins when attacker is weaker', function () {
    $formula = app(CombatFormula::class);

    $attacker = makeDuelPlayer(1, 5);
    $defender = makeDuelPlayer(2, 10);

    $res = $formula->resolveTileDuel($attacker, $defender, 'test-key');

    // Zero band, defender stronger → defender wins.
    expect($res['outcome'])->toBe('defender_win');
    expect($res['winner_id'])->toBe(2);
    expect($res['loser_id'])->toBe(1);
});

it('oil_pct is zero when both players have zero strength', function () {
    $formula = app(CombatFormula::class);

    $a = makeDuelPlayer(1, 0);
    $b = makeDuelPlayer(2, 0);

    $res = $formula->resolveTileDuel($a, $b, 'test-key');
    expect((float) $res['oil_pct'])->toBe(0.0);
});

it('estimateTileDuelWinChance returns 0.5 for equal strengths under a symmetric band', function () {
    // Override to a symmetric band for this test.
    config([
        'game.combat.rng_band_min' => -0.10,
        'game.combat.rng_band_max' => 0.10,
    ]);
    app()->forgetInstance(GameConfigResolver::class);
    app()->forgetInstance(CombatFormula::class);

    $formula = app(CombatFormula::class);

    $a = makeDuelPlayer(1, 10);
    $b = makeDuelPlayer(2, 10);

    // base_outcome = 0, symmetric band → P(attacker wins) = 0.5
    $p = $formula->estimateTileDuelWinChance($a, $b);
    expect($p)->toBe(0.5);
});

it('estimateTileDuelWinChance reaches 1.0 for overwhelming attacker', function () {
    $formula = app(CombatFormula::class);

    $bully = makeDuelPlayer(1, 50);
    $weak  = makeDuelPlayer(2, 1);

    $p = $formula->estimateTileDuelWinChance($bully, $weak);
    expect($p)->toBeGreaterThan(0.99);
});
