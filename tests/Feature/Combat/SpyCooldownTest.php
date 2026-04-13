<?php

use App\Domain\Combat\SpyService;
use App\Domain\Config\GameConfigResolver;
use App\Domain\Exceptions\CannotSpyException;
use App\Domain\World\WorldService;
use App\Models\SpyAttempt;
use App\Models\User;

/**
 * Verifies the per-attacker per-target spy cooldown.
 *
 * Counts ALL prior attempts (success or failure). A failed spy still
 * locks the target for the cooldown window — otherwise spamming spies
 * on a target until one succeeds would be free.
 */
beforeEach(function () {
    app(WorldService::class)->generateInitialWorld(seed: 42);

    config(['game.combat.spy.cooldown_hours' => 12]);
    app(GameConfigResolver::class)->flush();
});

function spawnPair(): array
{
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    $world = app(WorldService::class);
    $p1 = $world->spawnPlayer($u1->id);
    $p2 = $world->spawnPlayer($u2->id);

    // Move spy onto target's base, give them moves and stats, drop immunity.
    $p1->update([
        'akzar_cash' => 100.00,
        'moves_current' => 100,
        'stealth' => 5,
        'current_tile_id' => $p2->base_tile_id,
        'immunity_expires_at' => null,
    ]);
    $p2->update([
        'akzar_cash' => 50.00,
        'fortification' => 5,
        'security' => 2,
        'immunity_expires_at' => null,
    ]);

    return [$p1->fresh(), $p2->fresh()];
}

it('blocks a second spy on the same target within the cooldown window', function () {
    [$spy, $target] = spawnPair();

    // Plant a fresh spy_attempts row 1 hour ago — well inside 12h.
    SpyAttempt::create([
        'spy_player_id' => $spy->id,
        'target_player_id' => $target->id,
        'target_base_tile_id' => $target->base_tile_id,
        'success' => true,
        'detected' => false,
        'rng_seed' => 0,
        'rng_output' => '0',
        'created_at' => now()->subHour(),
    ]);

    expect(fn () => app(SpyService::class)->spy($spy->id))
        ->toThrow(CannotSpyException::class, 'Spy cooldown active');
});

it('also blocks when the prior spy was a failure', function () {
    [$spy, $target] = spawnPair();

    SpyAttempt::create([
        'spy_player_id' => $spy->id,
        'target_player_id' => $target->id,
        'target_base_tile_id' => $target->base_tile_id,
        'success' => false,
        'detected' => true,
        'rng_seed' => 0,
        'rng_output' => '0',
        'created_at' => now()->subHours(2),
    ]);

    expect(fn () => app(SpyService::class)->spy($spy->id))
        ->toThrow(CannotSpyException::class, 'Spy cooldown active');
});

it('allows a fresh spy once the cooldown has elapsed', function () {
    [$spy, $target] = spawnPair();

    SpyAttempt::create([
        'spy_player_id' => $spy->id,
        'target_player_id' => $target->id,
        'target_base_tile_id' => $target->base_tile_id,
        'success' => true,
        'detected' => false,
        'rng_seed' => 0,
        'rng_output' => '0',
        'created_at' => now()->subHours(13),
    ]);

    $result = app(SpyService::class)->spy($spy->id);

    expect($result['outcome'])->toBeIn(['success', 'failure']);
});

it('does not block a spy on a different target', function () {
    [$spy, $targetA] = spawnPair();
    $u3 = User::factory()->create();
    $targetB = app(WorldService::class)->spawnPlayer($u3->id);
    $targetB->update(['immunity_expires_at' => null, 'akzar_cash' => 50.00]);

    SpyAttempt::create([
        'spy_player_id' => $spy->id,
        'target_player_id' => $targetA->id,
        'target_base_tile_id' => $targetA->base_tile_id,
        'success' => true,
        'detected' => false,
        'rng_seed' => 0,
        'rng_output' => '0',
        'created_at' => now()->subHour(),
    ]);

    // Move spy to target B's base.
    $spy->update(['current_tile_id' => $targetB->base_tile_id]);

    $result = app(SpyService::class)->spy($spy->id);
    expect($result['outcome'])->toBeIn(['success', 'failure']);
});
