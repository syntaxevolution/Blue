<?php

use App\Domain\Exceptions\MdnException;
use App\Domain\Mdn\MdnService;
use App\Domain\World\WorldService;
use App\Models\Mdn;
use App\Models\MdnMembership;
use App\Models\User;

beforeEach(function () {
    app(WorldService::class)->generateInitialWorld(seed: 42);
});

it('creates an MDN with the leader as first member', function () {
    $user = User::factory()->create();
    $player = app(WorldService::class)->spawnPlayer($user->id);
    // Give them enough cash for the creation fee.
    $player->update(['akzar_cash' => 50.00]);

    $mdn = app(MdnService::class)->create($player->id, 'Test Alliance', 'TEST', 'motto');

    expect($mdn)->toBeInstanceOf(Mdn::class);
    expect($mdn->name)->toBe('Test Alliance');
    expect($mdn->tag)->toBe('TEST');
    expect($mdn->member_count)->toBe(1);

    $membership = MdnMembership::query()->where('player_id', $player->id)->first();
    expect($membership->role)->toBe(MdnService::ROLE_LEADER);

    $player->refresh();
    expect((int) $player->mdn_id)->toBe($mdn->id);
    expect((float) $player->akzar_cash)->toBeLessThan(50.00);
});

it('rejects duplicate names (case-insensitive)', function () {
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    $svc = app(WorldService::class);
    $p1 = $svc->spawnPlayer($u1->id);
    $p2 = $svc->spawnPlayer($u2->id);
    $p1->update(['akzar_cash' => 50]);
    $p2->update(['akzar_cash' => 50]);

    app(MdnService::class)->create($p1->id, 'Clashers', 'CLA', null);

    expect(fn () => app(MdnService::class)->create($p2->id, 'clashers', 'CL2', null))
        ->toThrow(MdnException::class);
});

it('rejects insufficient cash', function () {
    config(['game.mdn.creation_cost_cash' => 100.00]);
    app()->forgetInstance(\App\Domain\Config\GameConfigResolver::class);
    app(\App\Domain\Config\GameConfigResolver::class)->flush();

    $user = User::factory()->create();
    $player = app(WorldService::class)->spawnPlayer($user->id);
    $player->update(['akzar_cash' => 5.00]);

    expect(fn () => app(MdnService::class)->create($player->id, 'Broke Inc', 'BRK', null))
        ->toThrow(MdnException::class, 'Creating an MDN costs');
});

it('prevents a player from being in two MDNs', function () {
    $user = User::factory()->create();
    $player = app(WorldService::class)->spawnPlayer($user->id);
    $player->update(['akzar_cash' => 100]);

    app(MdnService::class)->create($player->id, 'First', 'FIR', null);

    expect(fn () => app(MdnService::class)->create($player->id, 'Second', 'SEC', null))
        ->toThrow(MdnException::class);
});
