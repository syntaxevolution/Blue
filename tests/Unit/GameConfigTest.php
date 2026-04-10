<?php

use App\Domain\Config\GameConfigResolver;
use Illuminate\Config\Repository;

function makeResolver(): GameConfigResolver
{
    $repo = new Repository([
        'game' => require __DIR__.'/../../config/game.php',
    ]);

    return new GameConfigResolver($repo);
}

it('returns locked balance values from config/game.php', function () {
    $resolver = makeResolver();

    expect($resolver->get('stats.hard_cap'))->toBe(25);
    expect($resolver->get('stats.soft_plateau_start'))->toBe(15);
    expect($resolver->get('combat.loot_ceiling_pct'))->toBe(0.20);
    expect($resolver->get('combat.raid_cooldown_hours'))->toBe(12);
    expect($resolver->get('combat.spy_decay_hours'))->toBe(24);
    expect($resolver->get('moves.daily_regen'))->toBe(200);
    expect($resolver->get('new_player.immunity_hours'))->toBe(48);
    expect($resolver->get('new_player.starting_cash'))->toBe(5.00);
    expect($resolver->get('mdn.max_members'))->toBe(50);
    expect($resolver->get('mdn.same_mdn_attacks_blocked'))->toBeTrue();
    expect($resolver->get('bankruptcy.pity_stipend'))->toBe(0.25);
    expect($resolver->get('drilling.grid_size'))->toBe(5);
});

it('returns whole arrays when asked for a branch key', function () {
    $weights = makeResolver()->get('drilling.quality_weights');

    expect($weights)
        ->toBeArray()
        ->toHaveKeys(['dry', 'trickle', 'standard', 'gusher']);

    expect(array_sum($weights))->toEqualWithDelta(1.0, 0.0001);
});

it('returns the provided default when a key is missing', function () {
    expect(makeResolver()->get('does.not.exist', 'fallback'))->toBe('fallback');
    expect(makeResolver()->get('also.missing'))->toBeNull();
});

it('memoises reads in the per-request cache', function () {
    $resolver = makeResolver();

    $a = $resolver->get('stats.hard_cap');
    $b = $resolver->get('stats.hard_cap');

    expect($a)->toBe($b)->toBe(25);
});

it('flush() clears the cache so subsequent reads re-resolve', function () {
    $resolver = makeResolver();
    $resolver->get('stats.hard_cap');

    $resolver->flush();

    expect($resolver->get('stats.hard_cap'))->toBe(25);
});

it('set() stages an override that takes precedence over the static default', function () {
    $resolver = makeResolver();

    $resolver->set('stats.hard_cap', 30);

    expect($resolver->get('stats.hard_cap'))->toBe(30);
});
