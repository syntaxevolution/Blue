<?php

use App\Domain\World\WorldService;
use App\Events\BaseUnderAttack;
use App\Events\SpyDetected;
use App\Models\ActivityLog;
use App\Models\SpyAttempt;
use App\Models\User;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    app(WorldService::class)->generateInitialWorld(seed: 42);
});

it('BaseUnderAttack dispatches when an attack resolves', function () {
    Event::fake([BaseUnderAttack::class]);

    $attacker = User::factory()->create();
    $defender = User::factory()->create();
    $attackerPlayer = app(WorldService::class)->spawnPlayer($attacker->id);
    $defenderPlayer = app(WorldService::class)->spawnPlayer($defender->id);

    // Move attacker onto defender's base.
    $attackerPlayer->update([
        'current_tile_id' => $defenderPlayer->base_tile_id,
        'moves_current' => 100,
        'strength' => 20,
    ]);
    // Clear defender immunity so the attack is allowed.
    $defenderPlayer->update(['immunity_expires_at' => null]);

    // Seed a successful spy attempt so the pre-check passes.
    SpyAttempt::create([
        'spy_player_id' => $attackerPlayer->id,
        'target_player_id' => $defenderPlayer->id,
        'target_base_tile_id' => $defenderPlayer->base_tile_id,
        'success' => true,
        'detected' => false,
        'rng_seed' => 0,
        'rng_output' => '0',
        'created_at' => now(),
    ]);

    app(\App\Domain\Combat\AttackService::class)->attack($attackerPlayer->id);

    Event::assertDispatched(BaseUnderAttack::class);
});

it('ActivityLog records a row when BaseUnderAttack is handled', function () {
    $defender = User::factory()->create();

    // Trigger the listener directly — it's the deterministic surface.
    app(\App\Listeners\RecordActivityLog::class)->handleBaseUnderAttack(
        new BaseUnderAttack(
            defenderUserId: $defender->id,
            attackerUsername: 'Attacker1',
            outcome: 'success',
            cashStolen: 5.00,
            attackId: 1,
        ),
    );

    expect(ActivityLog::where('user_id', $defender->id)->count())->toBe(1);
    $row = ActivityLog::where('user_id', $defender->id)->first();
    expect($row->type)->toBe('attack.incoming');
});

it('SpyDetected event persists to activity log via listener', function () {
    $target = User::factory()->create();

    app(\App\Listeners\RecordActivityLog::class)->handleSpyDetected(
        new SpyDetected(
            defenderUserId: $target->id,
            spyUsername: 'Sneaky1',
            spySucceeded: true,
        ),
    );

    $row = ActivityLog::where('user_id', $target->id)->first();
    expect($row)->not->toBeNull();
    expect($row->type)->toBe('spy.detected');
});
