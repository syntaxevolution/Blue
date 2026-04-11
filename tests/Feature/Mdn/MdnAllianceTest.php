<?php

use App\Domain\Combat\SpyService;
use App\Domain\Exceptions\MdnException;
use App\Domain\Mdn\MdnAllianceService;
use App\Domain\Mdn\MdnService;
use App\Domain\World\WorldService;
use App\Models\MdnAlliance;
use App\Models\User;

beforeEach(function () {
    app(WorldService::class)->generateInitialWorld(seed: 42);
});

it('declares and revokes alliances between two MDNs', function () {
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    $p1 = app(WorldService::class)->spawnPlayer($u1->id);
    $p2 = app(WorldService::class)->spawnPlayer($u2->id);
    $p1->update(['akzar_cash' => 100]);
    $p2->update(['akzar_cash' => 100]);

    $m1 = app(MdnService::class)->create($p1->id, 'Alpha', 'A', null);
    $m2 = app(MdnService::class)->create($p2->id, 'Beta', 'B', null);

    $alliance = app(MdnAllianceService::class)->declare($p1->id, $m2->id);
    expect($alliance)->toBeInstanceOf(MdnAlliance::class);
    expect(MdnAlliance::count())->toBe(1);

    app(MdnAllianceService::class)->revoke($p1->id, $alliance->id);
    expect(MdnAlliance::count())->toBe(0);
});

it('prevents duplicate alliances regardless of direction', function () {
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    $p1 = app(WorldService::class)->spawnPlayer($u1->id);
    $p2 = app(WorldService::class)->spawnPlayer($u2->id);
    $p1->update(['akzar_cash' => 100]);
    $p2->update(['akzar_cash' => 100]);

    $m1 = app(MdnService::class)->create($p1->id, 'Alpha', 'A', null);
    $m2 = app(MdnService::class)->create($p2->id, 'Beta', 'B', null);

    app(MdnAllianceService::class)->declare($p1->id, $m2->id);

    expect(fn () => app(MdnAllianceService::class)->declare($p2->id, $m1->id))
        ->toThrow(MdnException::class);
});

it('alliances do NOT block combat (declarative-only contract)', function () {
    config(['game.mdn.formal_alliances_prevent_attacks' => false]);
    config(['game.mdn.join_leave_cooldown_hours' => 0]);
    app(\App\Domain\Config\GameConfigResolver::class)->flush();

    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    $p1 = app(WorldService::class)->spawnPlayer($u1->id);
    $p2 = app(WorldService::class)->spawnPlayer($u2->id);
    $p1->update([
        'akzar_cash' => 100,
        'current_tile_id' => $p2->base_tile_id,
        'immunity_expires_at' => null,
    ]);
    $p2->update(['akzar_cash' => 100, 'immunity_expires_at' => null]);

    $m1 = app(MdnService::class)->create($p1->id, 'Alpha', 'A', null);
    $m2 = app(MdnService::class)->create($p2->id, 'Beta', 'B', null);
    app(MdnAllianceService::class)->declare($p1->id, $m2->id);

    // Allied-but-different MDNs should still be spyable.
    $result = app(SpyService::class)->spy($p1->id);
    expect($result)->toHaveKey('outcome');
});
