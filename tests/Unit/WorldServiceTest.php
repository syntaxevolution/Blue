<?php

use App\Domain\Config\GameConfigResolver;
use App\Domain\Config\RngService;
use App\Domain\World\FogOfWarService;
use App\Domain\World\WorldService;
use Illuminate\Config\Repository;

function makeWorldService(): WorldService
{
    $config = new Repository([
        'game' => require __DIR__.'/../../config/game.php',
    ]);

    return new WorldService(
        new GameConfigResolver($config),
        new RngService(),
        new FogOfWarService(),
    );
}

/*
|--------------------------------------------------------------------------
| getWorldInfo — config snapshot
|--------------------------------------------------------------------------
*/

it('reports the configured initial radius', function () {
    expect(makeWorldService()->getWorldInfo()['initial_radius'])->toBe(25);
});

it('exposes the density targets from config', function () {
    $density = makeWorldService()->getWorldInfo()['density'];

    expect($density)
        ->toHaveKeys(['oil_fields_per_tile', 'posts_per_tile', 'landmarks_per_tile']);

    expect($density['oil_fields_per_tile'])->toBe(0.125);
    expect($density['posts_per_tile'])->toBe(0.025);
    expect($density['landmarks_per_tile'])->toBe(0.005);
});

it('exposes the growth trigger parameters from config', function () {
    $growth = makeWorldService()->getWorldInfo()['growth'];

    expect($growth['trigger_players_per_tile'])->toBe(0.015);
    expect($growth['expansion_ring_width'])->toBe(10);
});

it('exposes the abandonment decay parameters from config', function () {
    $abandonment = makeWorldService()->getWorldInfo()['abandonment'];

    expect($abandonment['days_inactive'])->toBe(30);
    expect($abandonment['ruin_loot_min'])->toBe(0.5);
    expect($abandonment['ruin_loot_max'])->toBe(2.0);
});

it('marks deferred-phase methods as not yet implemented', function () {
    $svc = makeWorldService();

    expect(fn () => $svc->expandWorld())->toThrow(RuntimeException::class);
    expect(fn () => $svc->decayAbandoned())->toThrow(RuntimeException::class);
});

/*
|--------------------------------------------------------------------------
| planInitialWorld — deterministic tile plan
|--------------------------------------------------------------------------
*/

it('plans a non-empty list of tile specs', function () {
    $tiles = makeWorldService()->planInitialWorld(seed: 42);

    expect($tiles)->toBeArray()->not->toBeEmpty();
    expect($tiles[0])->toHaveKeys(['x', 'y', 'type', 'subtype', 'seed']);
});

it('keeps every planned tile inside the radius disc', function () {
    $tiles = makeWorldService()->planInitialWorld(seed: 1);
    $radius = 25;
    $radiusSq = $radius * $radius;

    foreach ($tiles as $tile) {
        $distSq = $tile['x'] ** 2 + $tile['y'] ** 2;
        expect($distSq)->toBeLessThanOrEqual($radiusSq);
    }
});

it('plans roughly the area of the disc (π × r²)', function () {
    $tiles = makeWorldService()->planInitialWorld(seed: 1);

    // Radius 25 disc ≈ 1963 tiles (π × 625). The integer-grid approximation
    // lands within a few percent of that.
    expect(count($tiles))->toBeGreaterThan(1900)->toBeLessThan(2050);
});

it('always places the_landing at origin', function () {
    $tiles = makeWorldService()->planInitialWorld(seed: 7);

    $origin = collect($tiles)->firstWhere(fn ($t) => $t['x'] === 0 && $t['y'] === 0);

    expect($origin)->not->toBeNull();
    expect($origin['type'])->toBe('landmark');
    expect($origin['subtype'])->toBe('the_landing');
});

it('is deterministic: same seed yields identical plans', function () {
    $svc = makeWorldService();

    $a = $svc->planInitialWorld(seed: 1234);
    $b = $svc->planInitialWorld(seed: 1234);

    expect($a)->toEqual($b);
});

it('differs between seeds (at least some tiles change type)', function () {
    $svc = makeWorldService();

    $a = $svc->planInitialWorld(seed: 1);
    $b = $svc->planInitialWorld(seed: 2);

    $differences = 0;
    foreach ($a as $i => $tile) {
        if ($tile['type'] !== $b[$i]['type'] || $tile['subtype'] !== $b[$i]['subtype']) {
            $differences++;
        }
    }

    // With radius 25 (~2000 tiles) and per-tile rolls, expect a lot of diffs.
    expect($differences)->toBeGreaterThan(100);
});

it('every post tile has a valid post subtype', function () {
    $tiles = makeWorldService()->planInitialWorld(seed: 99);

    $validSubtypes = ['strength', 'stealth', 'fort', 'tech', 'general'];

    foreach ($tiles as $tile) {
        if ($tile['type'] === 'post') {
            expect($tile['subtype'])->toBeIn($validSubtypes);
        }
    }
});

it('density roughly matches config within tolerance', function () {
    $tiles = makeWorldService()->planInitialWorld(seed: 2026);
    $total = count($tiles);

    $counts = array_count_values(array_column($tiles, 'type'));

    // Target densities from config/game.php:
    //   posts_per_tile     = 0.025
    //   oil_fields_per_tile = 0.125
    //   landmarks_per_tile  = 0.005 (+ the_landing which is landmark)
    //
    // Radius-25 disc has ~2000 tiles so absolute counts are small; use
    // generous bands (±50% of target) to avoid flaky sampling failures.
    $postRatio = ($counts['post'] ?? 0) / $total;
    expect($postRatio)->toBeGreaterThan(0.012)->toBeLessThan(0.04);

    $oilFieldRatio = ($counts['oil_field'] ?? 0) / $total;
    expect($oilFieldRatio)->toBeGreaterThan(0.085)->toBeLessThan(0.170);

    // Wasteland dominates — should be the majority of tiles.
    $wastelandRatio = ($counts['wasteland'] ?? 0) / $total;
    expect($wastelandRatio)->toBeGreaterThan(0.75);
});

it('post subtypes are distributed across all five categories', function () {
    $tiles = makeWorldService()->planInitialWorld(seed: 2026);

    $postSubtypes = collect($tiles)
        ->where('type', 'post')
        ->pluck('subtype')
        ->unique()
        ->values()
        ->all();

    // Over ~50 posts in a radius-25 disc, all five subtypes should appear.
    expect($postSubtypes)->toContain('strength')
        ->toContain('stealth')
        ->toContain('fort')
        ->toContain('tech')
        ->toContain('general');
});
