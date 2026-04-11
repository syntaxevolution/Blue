<?php

use App\Domain\Player\MoveRegenService;
use App\Domain\World\WorldService;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Feature tests for MoveRegenService::reconcile against a real Player row
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    app(WorldService::class)->generateInitialWorld(seed: 42);
});

it('does nothing when no full tick has elapsed', function () {
    $user = User::factory()->create();
    $player = app(WorldService::class)->spawnPlayer($user->id);

    $before = $player->moves_current;
    $originalUpdatedAt = $player->moves_updated_at;

    $reconciled = app(MoveRegenService::class)->reconcile($player);

    expect($reconciled->moves_current)->toBe($before);
    expect($reconciled->moves_updated_at->timestamp)->toBe($originalUpdatedAt->timestamp);
});

it('adds one move per full tick elapsed', function () {
    $user = User::factory()->create();
    $player = app(WorldService::class)->spawnPlayer($user->id);

    // Simulate 3 full ticks ago (3 × 432 = 1296 seconds).
    $player->update([
        'moves_current' => 10,
        'moves_updated_at' => now()->subSeconds(1296),
    ]);

    $reconciled = app(MoveRegenService::class)->reconcile($player->fresh());

    expect($reconciled->moves_current)->toBe(13);
});

it('caps accumulation at bank_cap_multiplier × daily_regen', function () {
    $user = User::factory()->create();
    $player = app(WorldService::class)->spawnPlayer($user->id);

    // 1.75 × 200 = 350 bank cap.
    $player->update([
        'moves_current' => 349,
        'moves_updated_at' => now()->subDays(10),
    ]);

    $reconciled = app(MoveRegenService::class)->reconcile($player->fresh());

    expect($reconciled->moves_current)->toBe(350);
});

it('advances moves_updated_at only by consumed tick seconds', function () {
    $user = User::factory()->create();
    $player = app(WorldService::class)->spawnPlayer($user->id);

    // 2 full ticks + 100 leftover seconds ago.
    $originalUpdatedAt = now()->subSeconds(2 * 432 + 100);
    $player->update([
        'moves_current' => 10,
        'moves_updated_at' => $originalUpdatedAt,
    ]);

    $reconciled = app(MoveRegenService::class)->reconcile($player->fresh());

    // New updated_at should be original + 2 × 432 seconds (leftover 100 preserved).
    $expectedUpdatedAt = $originalUpdatedAt->copy()->addSeconds(2 * 432);
    expect($reconciled->moves_updated_at->timestamp)->toBe($expectedUpdatedAt->timestamp);
    expect($reconciled->moves_current)->toBe(12);
});

it('canAfford reconciles and reports correctly', function () {
    $user = User::factory()->create();
    $player = app(WorldService::class)->spawnPlayer($user->id);

    $player->update(['moves_current' => 5]);
    $fresh = $player->fresh();

    expect(app(MoveRegenService::class)->canAfford($fresh, 3))->toBeTrue();
    expect(app(MoveRegenService::class)->canAfford($fresh->fresh(), 10))->toBeFalse();
});

it('bankCap returns daily_regen × bank_cap_multiplier', function () {
    expect(app(MoveRegenService::class)->bankCap())->toBe(350);
});

it('preserves purchased overflow above the bank cap on reconcile', function () {
    $user = User::factory()->create();
    $player = app(WorldService::class)->spawnPlayer($user->id);

    // Simulate a player who just bought an extra_moves_pack at cap:
    // 350 (cap) + 10 (pack) = 360, and one full tick of wall clock time
    // has passed since the purchase. The regen reconcile must NOT clip
    // their overflow back down to 350.
    $player->update([
        'moves_current' => 360,
        'moves_updated_at' => now()->subSeconds(432),
    ]);

    $reconciled = app(MoveRegenService::class)->reconcile($player->fresh());

    expect($reconciled->moves_current)->toBe(360);
});

it('resumes normal regen once the player spends back below the cap', function () {
    $user = User::factory()->create();
    $player = app(WorldService::class)->spawnPlayer($user->id);

    // Player is at 340/350 with 3 full ticks of wall clock time elapsed.
    // They're below cap, so trickle should add 3 → 343 and stay under 350.
    $player->update([
        'moves_current' => 340,
        'moves_updated_at' => now()->subSeconds(3 * 432),
    ]);

    $reconciled = app(MoveRegenService::class)->reconcile($player->fresh());

    expect($reconciled->moves_current)->toBe(343);
});
