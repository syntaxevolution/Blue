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

it('allows a second player to join and updates member_count', function () {
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    $p1 = app(WorldService::class)->spawnPlayer($u1->id);
    $p2 = app(WorldService::class)->spawnPlayer($u2->id);
    $p1->update(['akzar_cash' => 50]);

    $mdn = app(MdnService::class)->create($p1->id, 'Alpha', 'ALP', null);
    app(MdnService::class)->join($p2->id, $mdn->id);

    $mdn->refresh();
    expect($mdn->member_count)->toBe(2);
    expect(MdnMembership::where('mdn_id', $mdn->id)->count())->toBe(2);
});

it('enforces the member cap', function () {
    config(['game.mdn.max_members' => 2]);
    app(\App\Domain\Config\GameConfigResolver::class)->flush();

    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    $u3 = User::factory()->create();
    $svc = app(WorldService::class);
    $p1 = $svc->spawnPlayer($u1->id);
    $p2 = $svc->spawnPlayer($u2->id);
    $p3 = $svc->spawnPlayer($u3->id);
    $p1->update(['akzar_cash' => 50]);

    $mdn = app(MdnService::class)->create($p1->id, 'Full', 'FUL', null);
    app(MdnService::class)->join($p2->id, $mdn->id);

    expect(fn () => app(MdnService::class)->join($p3->id, $mdn->id))
        ->toThrow(MdnException::class, 'capacity');
});

it('leaves cleanly and nulls the player pointer', function () {
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    $p1 = app(WorldService::class)->spawnPlayer($u1->id);
    $p2 = app(WorldService::class)->spawnPlayer($u2->id);
    $p1->update(['akzar_cash' => 50]);

    $mdn = app(MdnService::class)->create($p1->id, 'Alpha', 'ALP', null);
    app(MdnService::class)->join($p2->id, $mdn->id);
    app(MdnService::class)->leave($p2->id);

    $p2->refresh();
    expect($p2->mdn_id)->toBeNull();
    expect($p2->mdn_left_at)->not->toBeNull();

    $mdn->refresh();
    expect($mdn->member_count)->toBe(1);
});

it('disbands when the last leader leaves solo', function () {
    $u = User::factory()->create();
    $p = app(WorldService::class)->spawnPlayer($u->id);
    $p->update(['akzar_cash' => 50]);

    $mdn = app(MdnService::class)->create($p->id, 'Solo', 'SOL', null);
    $id = $mdn->id;

    app(MdnService::class)->leave($p->id);

    expect(Mdn::find($id))->toBeNull();
});
