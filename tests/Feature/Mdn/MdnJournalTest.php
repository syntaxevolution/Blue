<?php

use App\Domain\Exceptions\MdnException;
use App\Domain\Mdn\MdnJournalService;
use App\Domain\Mdn\MdnService;
use App\Domain\World\WorldService;
use App\Models\User;

beforeEach(function () {
    app(WorldService::class)->generateInitialWorld(seed: 42);
});

it('adds entries and sorts by helpful_count', function () {
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    $p1 = app(WorldService::class)->spawnPlayer($u1->id);
    $p2 = app(WorldService::class)->spawnPlayer($u2->id);
    $p1->update(['akzar_cash' => 50]);

    $mdn = app(MdnService::class)->create($p1->id, 'J', 'J', null);
    app(MdnService::class)->join($p2->id, $mdn->id);

    $e1 = app(MdnJournalService::class)->addEntry($p1->id, null, 'First tip');
    $e2 = app(MdnJournalService::class)->addEntry($p1->id, null, 'Second tip');

    app(MdnJournalService::class)->vote($p2->id, $e2->id, 'helpful');

    $sorted = app(MdnJournalService::class)->list($mdn->id, 'helpful');
    expect($sorted->first()->id)->toBe($e2->id);
    expect($sorted->first()->helpful_count)->toBe(1);
});

it('switching a vote updates both counters', function () {
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    $p1 = app(WorldService::class)->spawnPlayer($u1->id);
    $p2 = app(WorldService::class)->spawnPlayer($u2->id);
    $p1->update(['akzar_cash' => 50]);

    $mdn = app(MdnService::class)->create($p1->id, 'J', 'J', null);
    app(MdnService::class)->join($p2->id, $mdn->id);

    $entry = app(MdnJournalService::class)->addEntry($p1->id, null, 'Tip');
    app(MdnJournalService::class)->vote($p2->id, $entry->id, 'helpful');
    $entry->refresh();
    expect($entry->helpful_count)->toBe(1);

    app(MdnJournalService::class)->vote($p2->id, $entry->id, 'unhelpful');
    $entry->refresh();
    expect($entry->helpful_count)->toBe(0);
    expect($entry->unhelpful_count)->toBe(1);
});

it('rejects entries from non-members', function () {
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    $p1 = app(WorldService::class)->spawnPlayer($u1->id);
    $p2 = app(WorldService::class)->spawnPlayer($u2->id);
    $p1->update(['akzar_cash' => 50]);

    app(MdnService::class)->create($p1->id, 'Alpha', 'A', null);

    expect(fn () => app(MdnJournalService::class)->addEntry($p2->id, null, 'sneaky'))
        ->toThrow(MdnException::class);
});
