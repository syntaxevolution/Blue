<?php

use App\Domain\Combat\SpyService;
use App\Domain\Config\GameConfigResolver;
use App\Domain\Config\RngService;
use App\Domain\World\WorldService;
use App\Models\SpyAttempt;
use App\Models\User;

/**
 * Verifies the fuzzed reveal payload written to spy_attempts.intel_payload
 * on a successful spy.
 *
 * The player never sees the true value — only the rolled low/high
 * bounds — so we only assert structural invariants:
 *   - All four fields are present
 *   - low <= high for every range
 *   - cash bounds are non-negative
 *   - win_chance bounds stay in [0, 1]
 *   - bigger stealth advantage produces a TIGHTER fortification range
 */
beforeEach(function () {
    app(WorldService::class)->generateInitialWorld(seed: 42);

    config([
        'game.combat.spy.cooldown_hours' => 12,
        // Force the spy success roll to always succeed for these tests.
        'game.combat.spy.success_chance_min' => 1.0,
        'game.combat.spy.success_chance_max' => 1.0,
    ]);
    app(GameConfigResolver::class)->flush();
});

afterEach(function () {
    app(RngService::class)->disableReplayMode();
});

function setupSpyTarget(int $stealth, int $security): array
{
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    $world = app(WorldService::class);
    $p1 = $world->spawnPlayer($u1->id);
    $p2 = $world->spawnPlayer($u2->id);

    $p1->update([
        'akzar_cash' => 100.00,
        'moves_current' => 100,
        'stealth' => $stealth,
        'strength' => 10,
        'current_tile_id' => $p2->base_tile_id,
        'immunity_expires_at' => null,
    ]);
    $p2->update([
        'akzar_cash' => 42.50,
        'fortification' => 14,
        'security' => $security,
        'strength' => 8,
        'immunity_expires_at' => null,
    ]);

    return [$p1->fresh(), $p2->fresh()];
}

it('writes a fuzzed payload to spy_attempts on a successful spy', function () {
    [$spy, $target] = setupSpyTarget(stealth: 5, security: 2);

    $result = app(SpyService::class)->spy($spy->id);
    expect($result['outcome'])->toBe('success');

    /** @var SpyAttempt $row */
    $row = SpyAttempt::query()->where('spy_player_id', $spy->id)->latest('created_at')->firstOrFail();
    $payload = $row->intel_payload;

    expect($payload)->toBeArray();
    expect($payload)->toHaveKeys(['fortification', 'security', 'cash', 'win_chance']);

    foreach (['fortification', 'security', 'cash'] as $field) {
        expect($payload[$field])->toHaveKeys(['low', 'high']);
        expect($payload[$field]['low'])->toBeLessThanOrEqual($payload[$field]['high']);
        expect($payload[$field]['low'])->toBeGreaterThanOrEqual(0);
    }

    expect($payload['win_chance']['low'])->toBeGreaterThanOrEqual(0.0);
    expect($payload['win_chance']['high'])->toBeLessThanOrEqual(1.0);
    expect($payload['win_chance']['low'])->toBeLessThanOrEqual($payload['win_chance']['high']);
});

it('does not write a payload on a failed spy', function () {
    config([
        'game.combat.spy.success_chance_min' => 0.0,
        'game.combat.spy.success_chance_max' => 0.0,
    ]);
    app(GameConfigResolver::class)->flush();

    [$spy] = setupSpyTarget(stealth: 0, security: 5);

    $result = app(SpyService::class)->spy($spy->id);
    expect($result['outcome'])->toBe('failure');

    /** @var SpyAttempt $row */
    $row = SpyAttempt::query()->where('spy_player_id', $spy->id)->latest('created_at')->firstOrFail();
    expect($row->intel_payload)->toBeNull();
});

it('produces a tighter fortification range as stealth advantage grows', function () {
    // High-stealth spy: stealth 25 vs sec 0 → noise floor.
    [$spyHi, $targetHi] = setupSpyTarget(stealth: 25, security: 0);
    app(SpyService::class)->spy($spyHi->id);
    /** @var SpyAttempt $hiRow */
    $hiRow = SpyAttempt::query()->where('spy_player_id', $spyHi->id)->latest('created_at')->firstOrFail();
    $hiWidth = $hiRow->intel_payload['fortification']['high'] - $hiRow->intel_payload['fortification']['low'];

    // Low-stealth spy: stealth 0 vs sec 5 → full base noise.
    [$spyLo, $targetLo] = setupSpyTarget(stealth: 0, security: 5);
    app(SpyService::class)->spy($spyLo->id);
    /** @var SpyAttempt $loRow */
    $loRow = SpyAttempt::query()->where('spy_player_id', $spyLo->id)->latest('created_at')->firstOrFail();
    $loWidth = $loRow->intel_payload['fortification']['high'] - $loRow->intel_payload['fortification']['low'];

    // High-stealth spy gets a range that's no wider than low-stealth.
    // (Both targets have fortification 14, so the underlying half-width
    // is computed from the same true value.)
    expect($hiWidth)->toBeLessThanOrEqual($loWidth);
});
