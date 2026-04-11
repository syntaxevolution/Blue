<?php

use App\Domain\Combat\AttackService;
use App\Domain\Combat\SpyService;
use App\Domain\Exceptions\CannotAttackException;
use App\Domain\Exceptions\CannotSpyException;
use App\Domain\Mdn\MdnService;
use App\Domain\World\WorldService;
use App\Models\Player;
use App\Models\SpyAttempt;
use App\Models\Tile;
use App\Models\User;

beforeEach(function () {
    app(WorldService::class)->generateInitialWorld(seed: 42);
});

function setupTwoPlayersAtSameBase(): array
{
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    $p1 = app(WorldService::class)->spawnPlayer($u1->id);
    $p2 = app(WorldService::class)->spawnPlayer($u2->id);

    // Move p1 onto p2's base tile so combat services can resolve them.
    $p1->update([
        'current_tile_id' => $p2->base_tile_id,
        'akzar_cash' => 100.00,
        'immunity_expires_at' => null,
    ]);
    $p2->update([
        'akzar_cash' => 100.00,
        'immunity_expires_at' => null,
    ]);

    return [$p1->refresh(), $p2->refresh()];
}

it('blocks spying on a fellow MDN member', function () {
    [$p1, $p2] = setupTwoPlayersAtSameBase();

    $mdn = app(MdnService::class)->create($p1->id, 'Clashers', 'CLA', null);
    app(MdnService::class)->join($p2->id, $mdn->id);

    // Join cooldown would also block — override the config for this
    // test so we isolate the same-MDN check.
    config(['game.mdn.join_leave_cooldown_hours' => 0]);
    app(\App\Domain\Config\GameConfigResolver::class)->flush();

    expect(fn () => app(SpyService::class)->spy($p1->id))
        ->toThrow(CannotSpyException::class, 'fellow MDN member');
});

it('blocks attacking a fellow MDN member', function () {
    [$p1, $p2] = setupTwoPlayersAtSameBase();

    $mdn = app(MdnService::class)->create($p1->id, 'Clashers', 'CLA', null);
    app(MdnService::class)->join($p2->id, $mdn->id);

    config(['game.mdn.join_leave_cooldown_hours' => 0]);
    app(\App\Domain\Config\GameConfigResolver::class)->flush();

    // Fake a successful spy so the combat service gets past the
    // "no spy in window" guard before hitting the MDN check.
    SpyAttempt::create([
        'spy_player_id' => $p1->id,
        'target_player_id' => $p2->id,
        'target_base_tile_id' => $p2->base_tile_id,
        'success' => true,
        'detected' => false,
        'rng_seed' => 0,
        'rng_output' => '0.1',
        'created_at' => now(),
    ]);

    expect(fn () => app(AttackService::class)->attack($p1->id))
        ->toThrow(CannotAttackException::class, 'fellow MDN member');
});

it('allows spying on a player in a different MDN', function () {
    [$p1, $p2] = setupTwoPlayersAtSameBase();

    $m1 = app(MdnService::class)->create($p1->id, 'Alpha', 'ALP', null);
    $m2 = app(MdnService::class)->create($p2->id, 'Beta', 'BET', null);

    config(['game.mdn.join_leave_cooldown_hours' => 0]);
    app(\App\Domain\Config\GameConfigResolver::class)->flush();

    $result = app(SpyService::class)->spy($p1->id);
    expect($result)->toHaveKey('outcome');
});
