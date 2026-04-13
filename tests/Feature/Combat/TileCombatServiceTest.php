<?php

use App\Domain\Combat\TileCombatService;
use App\Domain\Config\GameConfigResolver;
use App\Domain\Exceptions\CannotAttackException;
use App\Domain\Exceptions\InsufficientMovesException;
use App\Domain\Mdn\MdnService;
use App\Domain\World\WorldService;
use App\Models\ActivityLog;
use App\Models\Player;
use App\Models\Tile;
use App\Models\TileCombat;
use App\Models\User;

beforeEach(function () {
    app(WorldService::class)->generateInitialWorld(seed: 42);
});

// NOTE: These tests do NOT lock RNG in replay mode because the
// TileCombatService generates its event key with a runtime timestamp,
// which makes replay key prediction impractical. Instead, tests use
// stat differentials large enough that the outcome is deterministic
// across the full combat band (±0.10/0.15): atk=10 vs def=5 has
// base_outcome ≈ +0.333 which stays positive even with the worst
// negative band. That's enough to guarantee "attacker wins" without
// any RNG knobs.

/**
 * Spin up two human players, drop them on the same wasteland tile,
 * wipe their immunity and give them full move budgets + oil.
 *
 * @return array{0: Player, 1: Player, 2: Tile}
 */
function setupTwoPlayersOnWasteland(int $attackerStr = 20, int $defenderStr = 4): array
{
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    $p1 = app(WorldService::class)->spawnPlayer($u1->id);
    $p2 = app(WorldService::class)->spawnPlayer($u2->id);

    /** @var Tile $wasteland */
    $wasteland = Tile::query()->where('type', 'wasteland')->first();
    if ($wasteland === null) {
        throw new RuntimeException('Test fixture: no wasteland tiles in seeded world');
    }

    $now = now();
    $p1->update([
        'current_tile_id' => $wasteland->id,
        'oil_barrels' => 1000,
        'strength' => $attackerStr,
        'immunity_expires_at' => null,
        'moves_current' => 200,
        'moves_updated_at' => $now,
    ]);
    $p2->update([
        'current_tile_id' => $wasteland->id,
        'oil_barrels' => 1000,
        'strength' => $defenderStr,
        'immunity_expires_at' => null,
        'moves_current' => 200,
        'moves_updated_at' => $now,
    ]);

    return [$p1->refresh(), $p2->refresh(), $wasteland->refresh()];
}

it('transfers oil from loser to winner on a happy-path engagement', function () {
    [$p1, $p2, $tile] = setupTwoPlayersOnWasteland(attackerStr: 20, defenderStr: 4);

    $p1BarrelsBefore = (int) $p1->oil_barrels;
    $p2BarrelsBefore = (int) $p2->oil_barrels;
    $p1MovesBefore = (int) $p1->moves_current;

    $result = app(TileCombatService::class)->engage($p1->id, $p2->id);

    expect($result)->toHaveKey('outcome');
    expect($result['outcome'])->toBe('attacker_win');
    expect($result['tile_combat_id'])->toBeInt()->toBeGreaterThan(0);
    expect($result['attacker_won'])->toBeTrue();

    $p1->refresh();
    $p2->refresh();

    // Oil delta conservation
    expect((int) $p1->oil_barrels - $p1BarrelsBefore)->toBe($result['oil_stolen']);
    expect($p2BarrelsBefore - (int) $p2->oil_barrels)->toBe($result['oil_stolen']);

    // Move cost always deducted from attacker
    $moveCost = (int) config('game.actions.tile_combat.move_cost');
    expect($p1MovesBefore - (int) $p1->moves_current)->toBe($moveCost);

    // tile_combats row written
    $row = TileCombat::query()->find($result['tile_combat_id']);
    expect($row)->not->toBeNull();
    expect((int) $row->attacker_player_id)->toBe($p1->id);
    expect((int) $row->defender_player_id)->toBe($p2->id);
    expect((int) $row->tile_id)->toBe($tile->id);
});

it('writes an anonymous activity log entry on the defender side', function () {
    [$p1, $p2] = setupTwoPlayersOnWasteland();

    $result = app(TileCombatService::class)->engage($p1->id, $p2->id);

    // Pin the outcome — stat differential (20 vs 4) is wide enough
    // to guarantee attacker_win across the full RNG band, so the
    // anonymity check below is testing the ambushed-title path
    // rather than the counter-defence path.
    expect($result['outcome'])->toBe('attacker_win');

    $log = ActivityLog::query()
        ->where('user_id', $p2->user_id)
        ->where('type', 'tile_combat.received')
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull();
    // Title is anonymous — no attacker username leaks via the log —
    // and must land on the defender-loses branch ("ambushed") since
    // we asserted attacker_win above.
    expect($log->title)->toContain('ambushed');
    expect($log->title)->not->toContain($p1->user->name);
    // Body is anonymous too — no attacker_username key in the payload
    expect($log->body)->toBeArray();
    expect($log->body)->not->toHaveKey('attacker_username');
});

it('rejects engagements when the two players are on different tiles', function () {
    [$p1, $p2] = setupTwoPlayersOnWasteland();

    /** @var Tile $other */
    $other = Tile::query()->where('type', 'wasteland')->where('id', '!=', $p1->current_tile_id)->first();
    $p2->update(['current_tile_id' => $other->id]);

    expect(fn () => app(TileCombatService::class)->engage($p1->id, $p2->id))
        ->toThrow(CannotAttackException::class, 'same tile');
});

it('rejects engagements on non-wasteland tiles', function () {
    [$p1, $p2] = setupTwoPlayersOnWasteland();

    // Move both onto an oil field tile
    /** @var Tile $oil */
    $oil = Tile::query()->where('type', 'oil_field')->first();
    $p1->update(['current_tile_id' => $oil->id]);
    $p2->update(['current_tile_id' => $oil->id]);

    expect(fn () => app(TileCombatService::class)->engage($p1->id, $p2->id))
        ->toThrow(CannotAttackException::class, 'wasteland');
});

it('rejects engagements against an immune defender (one-way immunity)', function () {
    [$p1, $p2] = setupTwoPlayersOnWasteland();

    $p2->update(['immunity_expires_at' => now()->addHours(24)]);

    expect(fn () => app(TileCombatService::class)->engage($p1->id, $p2->id))
        ->toThrow(CannotAttackException::class, 'immunity');
});

it('allows an immune attacker to initiate (one-way immunity)', function () {
    [$p1, $p2] = setupTwoPlayersOnWasteland();

    // Attacker immune, defender not — should still be allowed to fight.
    $p1->update(['immunity_expires_at' => now()->addHours(24)]);

    $result = app(TileCombatService::class)->engage($p1->id, $p2->id);
    expect($result)->toHaveKey('outcome');
});

it('rejects a self-fight', function () {
    [$p1] = setupTwoPlayersOnWasteland();

    expect(fn () => app(TileCombatService::class)->engage($p1->id, $p1->id))
        ->toThrow(CannotAttackException::class, 'yourself');
});

it('blocks same-MDN members from tile-fighting each other', function () {
    [$p1, $p2] = setupTwoPlayersOnWasteland();

    $mdn = app(MdnService::class)->create($p1->id, 'Clashers', 'CLA', null);
    app(MdnService::class)->join($p2->id, $mdn->id);

    config(['game.mdn.join_leave_cooldown_hours' => 0]);
    app(GameConfigResolver::class)->flush();

    // Reload players to pick up the MDN assignment
    $p1->refresh();
    $p2->refresh();
    $p1->update(['current_tile_id' => $p2->current_tile_id]);

    expect(fn () => app(TileCombatService::class)->engage($p1->id, $p2->id))
        ->toThrow(CannotAttackException::class, 'fellow MDN');
});

it('enforces a 24h per-tile cooldown on BOTH participants after a fight', function () {
    [$p1, $p2, $tile] = setupTwoPlayersOnWasteland();

    app(TileCombatService::class)->engage($p1->id, $p2->id);

    // Second engagement on the same tile must be blocked — the service
    // scans for ANY combat involving either party within 24h.
    $p1->refresh();
    $p2->refresh();
    // Refund moves so the cooldown is the only guard that can fire.
    $p1->update(['moves_current' => 200]);

    expect(fn () => app(TileCombatService::class)->engage($p1->id, $p2->id))
        ->toThrow(CannotAttackException::class); // could be self or target cooldown

    // Pull in a fresh third player on the same tile. Even though they
    // have no prior combat, p2 is still in cooldown, so the fight is
    // still blocked (target cooldown).
    $u3 = User::factory()->create();
    $p3 = app(WorldService::class)->spawnPlayer($u3->id);
    $p3->update([
        'current_tile_id' => $tile->id,
        'immunity_expires_at' => null,
        'oil_barrels' => 1000,
        'strength' => 10,
        'moves_current' => 200,
    ]);

    expect(fn () => app(TileCombatService::class)->engage($p3->id, $p2->id))
        ->toThrow(CannotAttackException::class, 'catching their breath');
});

it('rejects engagements when attacker has insufficient moves', function () {
    [$p1, $p2] = setupTwoPlayersOnWasteland();
    $p1->update(['moves_current' => 0, 'moves_updated_at' => now()]);

    expect(fn () => app(TileCombatService::class)->engage($p1->id, $p2->id))
        ->toThrow(InsufficientMovesException::class);
});

it('floors zero-barrel loot cleanly (no negative balances)', function () {
    [$p1, $p2] = setupTwoPlayersOnWasteland();

    // Defender with zero oil — loot must be 0, fight still resolves.
    $p2->update(['oil_barrels' => 0]);

    $result = app(TileCombatService::class)->engage($p1->id, $p2->id);

    expect($result['oil_stolen'])->toBe(0);
    $p2->refresh();
    expect((int) $p2->oil_barrels)->toBe(0);
});
