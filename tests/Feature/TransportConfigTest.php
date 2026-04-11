<?php

use App\Domain\Economy\TransportService;
use App\Domain\Config\GameConfigResolver;

it('resolves all five purchasable transports plus walking default', function () {
    $svc = app(TransportService::class);

    $keys = $svc->allKeys();

    expect($keys)->toContain('walking');
    expect($keys)->toContain('bicycle');
    expect($keys)->toContain('motorcycle');
    expect($keys)->toContain('sand_runner');
    expect($keys)->toContain('helicopter');
    expect($keys)->toContain('airplane');
});

it('configFor returns concrete params for every known key', function () {
    $svc = app(TransportService::class);

    foreach (['bicycle', 'motorcycle', 'sand_runner', 'helicopter', 'airplane'] as $key) {
        $cfg = $svc->configFor($key);
        expect($cfg)->not->toBeNull();
        expect($cfg)->toHaveKeys(['cost_barrels', 'spaces', 'fuel', 'flags']);
        expect($cfg['spaces'])->toBeGreaterThan(0);
    }
});

it('walking has zero cost and 1-space stride', function () {
    $svc = app(TransportService::class);
    $cfg = $svc->configFor('walking');

    expect($cfg['spaces'])->toBe(1);
    expect($cfg['fuel'])->toBe(0);
    expect($cfg['cost_barrels'])->toBe(0);
});

it('airplane carries the reveal_path flag', function () {
    $svc = app(TransportService::class);
    $cfg = $svc->configFor('airplane');

    expect($cfg['flags'])->toContain('reveal_path');
});

it('sand_runner carries the reveal_cardinal_neighbours flag', function () {
    $svc = app(TransportService::class);
    $cfg = $svc->configFor('sand_runner');

    expect($cfg['flags'])->toContain('reveal_cardinal_neighbours');
});
