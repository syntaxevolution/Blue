<?php

use App\Domain\Combat\AttackLogService;
use App\Domain\Combat\TileCombatService;
use App\Domain\World\WorldService;
use App\Models\Player;
use App\Models\Tile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// Outcome determinism: attacker str=20 vs defender str=4 →
// base_outcome ≈ +0.667, which beats the worst-case RNG band
// (combat.rng_band_min = -0.10) so attacker always wins. No replay
// mode needed.

beforeEach(function () {
    app(WorldService::class)->generateInitialWorld(seed: 42);
});

/**
 * @return array{0: Player, 1: Player}
 */
function setupFighters(): array
{
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    $p1 = app(WorldService::class)->spawnPlayer($u1->id);
    $p2 = app(WorldService::class)->spawnPlayer($u2->id);

    $wasteland = Tile::query()->where('type', 'wasteland')->first();

    $p1->update([
        'current_tile_id' => $wasteland->id,
        'oil_barrels' => 1000,
        'strength' => 20,
        'immunity_expires_at' => null,
        'moves_current' => 200,
        'moves_updated_at' => now(),
    ]);
    $p2->update([
        'current_tile_id' => $wasteland->id,
        'oil_barrels' => 1000,
        'strength' => 4,
        'immunity_expires_at' => null,
        'moves_current' => 200,
        'moves_updated_at' => now(),
    ]);

    return [$p1->refresh(), $p2->refresh()];
}

it('merges tile_combats into the AttackLogService feed', function () {
    [$p1, $p2] = setupFighters();

    app(TileCombatService::class)->engage($p1->id, $p2->id);

    $feed = app(AttackLogService::class)->recentAttacks($p2);

    // Defender (p2) should see the tile combat event in their feed.
    $tileCombatRow = collect($feed)->firstWhere('kind', 'tile_combat');
    expect($tileCombatRow)->not->toBeNull();
    expect($tileCombatRow['role'])->toBe('defender');
    // Attacker username surfaced (dossier-gated feed).
    expect($tileCombatRow['attacker_username'])->toBe($p1->user->name);
});

it('also surfaces tile combat on the attacker side as role=attacker', function () {
    [$p1, $p2] = setupFighters();

    app(TileCombatService::class)->engage($p1->id, $p2->id);

    $feed = app(AttackLogService::class)->recentAttacks($p1);

    $tileCombatRow = collect($feed)->firstWhere('kind', 'tile_combat');
    expect($tileCombatRow)->not->toBeNull();
    expect($tileCombatRow['role'])->toBe('attacker');
    // "Opponent" slot carries the defender's name for attacker-side rows.
    expect($tileCombatRow['attacker_username'])->toBe($p2->user->name);
});

it('sorts merged feed chronologically across all three kinds', function () {
    [$p1, $p2] = setupFighters();

    // Insert an older legacy raid row so we can assert ordering.
    DB::table('attacks')->insert([
        'attacker_player_id' => $p1->id,
        'defender_player_id' => $p2->id,
        'defender_base_tile_id' => $p2->base_tile_id,
        'outcome' => 'success',
        'cash_stolen' => 1.00,
        'attacker_escape' => false,
        'created_at' => now()->subDay(),
    ]);

    // New tile combat — should appear above the raid row.
    app(TileCombatService::class)->engage($p1->id, $p2->id);

    $feed = app(AttackLogService::class)->recentAttacks($p2);
    $kinds = collect($feed)->pluck('kind')->all();

    // Tile combat is newer → comes first in the descending feed.
    expect($kinds[0])->toBe('tile_combat');
    // Raid row is still present further down.
    expect(in_array('attack', $kinds, true))->toBeTrue();
});
