<?php

use App\Domain\Player\MoveRegenService;

/*
|--------------------------------------------------------------------------
| Unit tests — pure math only (no DB, no Laravel bootstrap)
|--------------------------------------------------------------------------
*/

it('computes zero ticks when elapsed is shorter than a tick', function () {
    expect(MoveRegenService::computeTicks(100, 432))->toBe(0);
});

it('computes one tick at exactly the tick boundary', function () {
    expect(MoveRegenService::computeTicks(432, 432))->toBe(1);
});

it('computes multiple full ticks and floors partials', function () {
    expect(MoveRegenService::computeTicks(864, 432))->toBe(2);
    expect(MoveRegenService::computeTicks(900, 432))->toBe(2);
    expect(MoveRegenService::computeTicks(1295, 432))->toBe(2);
    expect(MoveRegenService::computeTicks(1296, 432))->toBe(3);
});

it('returns zero for non-positive elapsed or tick', function () {
    expect(MoveRegenService::computeTicks(0, 432))->toBe(0);
    expect(MoveRegenService::computeTicks(-50, 432))->toBe(0);
    expect(MoveRegenService::computeTicks(432, 0))->toBe(0);
    expect(MoveRegenService::computeTicks(432, -1))->toBe(0);
});

it('handles a full day elapsed (~200 ticks at the default config)', function () {
    // 86400s / 432s = 200 ticks (default daily regen)
    expect(MoveRegenService::computeTicks(86400, 432))->toBe(200);
});
