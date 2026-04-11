<?php

use App\Domain\Combat\SpyService;
use App\Domain\Exceptions\CannotSpyException;
use App\Domain\Mdn\MdnService;
use App\Domain\World\WorldService;
use App\Models\User;

beforeEach(function () {
    app(WorldService::class)->generateInitialWorld(seed: 42);
});

it('blocks offensive actions within the hop cooldown window after joining', function () {
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    $p1 = app(WorldService::class)->spawnPlayer($u1->id);
    $p2 = app(WorldService::class)->spawnPlayer($u2->id);

    // p1 creates an MDN just now — offensive actions should be blocked
    // for the next 24h (default config value).
    $p1->update(['akzar_cash' => 100, 'current_tile_id' => $p2->base_tile_id, 'immunity_expires_at' => null]);
    $p2->update(['akzar_cash' => 50, 'immunity_expires_at' => null]);

    app(MdnService::class)->create($p1->id, 'Alpha', 'ALP', null);

    // Use default 24h cooldown.
    config(['game.mdn.join_leave_cooldown_hours' => 24]);
    app(\App\Domain\Config\GameConfigResolver::class)->flush();

    expect(fn () => app(SpyService::class)->spy($p1->id))
        ->toThrow(CannotSpyException::class, 'Recent MDN change');
});
