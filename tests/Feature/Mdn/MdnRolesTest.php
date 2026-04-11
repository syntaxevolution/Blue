<?php

use App\Domain\Exceptions\MdnException;
use App\Domain\Mdn\MdnService;
use App\Domain\World\WorldService;
use App\Models\MdnMembership;
use App\Models\User;

beforeEach(function () {
    app(WorldService::class)->generateInitialWorld(seed: 42);
});

function freshMdnTrio(): array
{
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    $u3 = User::factory()->create();
    $svc = app(WorldService::class);
    $p1 = $svc->spawnPlayer($u1->id);
    $p2 = $svc->spawnPlayer($u2->id);
    $p3 = $svc->spawnPlayer($u3->id);
    $p1->update(['akzar_cash' => 100]);

    $mdn = app(MdnService::class)->create($p1->id, 'Roles', 'ROL', null);
    app(MdnService::class)->join($p2->id, $mdn->id);
    app(MdnService::class)->join($p3->id, $mdn->id);

    return [$p1->refresh(), $p2->refresh(), $p3->refresh(), $mdn];
}

it('allows the leader to promote a member to officer', function () {
    [$leader, $m2, $m3, $mdn] = freshMdnTrio();

    app(MdnService::class)->promote($leader->id, $m2->id, MdnService::ROLE_OFFICER);

    $role = MdnMembership::query()
        ->where('mdn_id', $mdn->id)
        ->where('player_id', $m2->id)
        ->value('role');
    expect($role)->toBe(MdnService::ROLE_OFFICER);
});

it('rejects non-leader promotion attempts', function () {
    [$leader, $m2, $m3, $mdn] = freshMdnTrio();

    expect(fn () => app(MdnService::class)->promote($m2->id, $m3->id, MdnService::ROLE_OFFICER))
        ->toThrow(MdnException::class, 'leader');
});

it('kicks a member and clears their mdn pointer', function () {
    [$leader, $m2, $m3, $mdn] = freshMdnTrio();

    app(MdnService::class)->kick($leader->id, $m2->id);

    $m2->refresh();
    expect($m2->mdn_id)->toBeNull();
    expect(MdnMembership::where('mdn_id', $mdn->id)->where('player_id', $m2->id)->exists())->toBeFalse();

    $mdn->refresh();
    expect($mdn->member_count)->toBe(2);
});

it('rejects non-leader kicks', function () {
    [$leader, $m2, $m3, $mdn] = freshMdnTrio();

    expect(fn () => app(MdnService::class)->kick($m2->id, $m3->id))
        ->toThrow(MdnException::class, 'leader');
});

it('transfers leadership to the oldest remaining officer when the leader leaves', function () {
    [$leader, $m2, $m3, $mdn] = freshMdnTrio();

    // Promote m2 to officer so they're the clear successor.
    app(MdnService::class)->promote($leader->id, $m2->id, MdnService::ROLE_OFFICER);

    app(MdnService::class)->leave($leader->id);

    $mdn->refresh();
    expect($mdn->member_count)->toBe(2);
    expect($mdn->leader_player_id)->toBe($m2->id);

    $role = MdnMembership::query()
        ->where('mdn_id', $mdn->id)
        ->where('player_id', $m2->id)
        ->value('role');
    expect($role)->toBe(MdnService::ROLE_LEADER);
});
