<?php

use App\Domain\Config\RngService;
use App\Domain\Loot\LootWeightingService;
use App\Models\Item;
use Illuminate\Support\Collection;

function makeWeighting(): LootWeightingService
{
    return new LootWeightingService(new RngService);
}

it('lowWeightedRange is deterministic for the same eventKey', function () {
    $a = makeWeighting()->lowWeightedRange('loot.test', 'evt-1', 100, 10000, 2.5, asInt: true);
    $b = makeWeighting()->lowWeightedRange('loot.test', 'evt-1', 100, 10000, 2.5, asInt: true);

    expect($a)->toBe($b);
});

it('lowWeightedRange stays within the requested bounds for ints', function () {
    $svc = makeWeighting();

    for ($i = 0; $i < 300; $i++) {
        $v = $svc->lowWeightedRange('loot.test', "evt-{$i}", 100, 10000, 2.5, asInt: true);
        expect($v)->toBeGreaterThanOrEqual(100);
        expect($v)->toBeLessThanOrEqual(10000);
    }
});

it('lowWeightedRange is biased toward the minimum when exponent > 1', function () {
    $svc = makeWeighting();

    $sum = 0;
    $trials = 500;
    $min = 100;
    $max = 10000;
    $mid = ($min + $max) / 2;

    $belowMid = 0;
    for ($i = 0; $i < $trials; $i++) {
        $v = (int) $svc->lowWeightedRange('loot.skew.test', "evt-{$i}", $min, $max, 2.5, asInt: true);
        $sum += $v;
        if ($v < $mid) {
            $belowMid++;
        }
    }

    $mean = $sum / $trials;
    // Mean for exponent 2.5 over [100, 10000] is ~2900, well below
    // the midpoint of 5050.
    expect($mean)->toBeLessThan($mid);
    // Expect the vast majority of rolls to fall in the lower half.
    expect($belowMid)->toBeGreaterThan((int) ($trials * 0.75));
});

it('lowWeightedRange returns floats with 2 dp when asInt=false', function () {
    $svc = makeWeighting();

    $v = $svc->lowWeightedRange('loot.test.float', 'evt-1', 1.00, 10.00, 2.5, asInt: false);
    expect($v)->toBeFloat();
    // Two-decimal precision — reject anything with more significant
    // digits (rounding is done inside the service).
    expect(round($v, 2))->toBe($v);
    expect($v)->toBeGreaterThanOrEqual(1.00);
    expect($v)->toBeLessThanOrEqual(10.00);
});

it('pickOutcome respects the relative probability mass over many trials', function () {
    $svc = makeWeighting();

    $buckets = ['nothing' => 0, 'oil' => 0, 'cash' => 0, 'item' => 0];
    $weights = ['nothing' => 25, 'oil' => 10, 'cash' => 5, 'item' => 60];

    $trials = 2000;
    for ($i = 0; $i < $trials; $i++) {
        $pick = $svc->pickOutcome('loot.outcome.test', "evt-{$i}", $weights);
        $buckets[$pick]++;
    }

    // Tolerance bands. Item is heaviest so expect the most hits
    // there. These are loose enough to avoid flakes but tight enough
    // to catch a broken implementation.
    expect($buckets['item'])->toBeGreaterThan((int) ($trials * 0.48));
    expect($buckets['nothing'])->toBeGreaterThan((int) ($trials * 0.15));
    expect($buckets['cash'])->toBeGreaterThan(0);
    expect($buckets['oil'])->toBeGreaterThan(0);
});

it('pickOutcome never returns a zero-weight key', function () {
    $svc = makeWeighting();

    $weights = ['yes' => 10, 'no' => 0];

    for ($i = 0; $i < 50; $i++) {
        $pick = $svc->pickOutcome('loot.outcome.zeroweights', "evt-{$i}", $weights);
        expect($pick)->toBe('yes');
    }
});

it('inversePriceItemPick never picks excluded keys', function () {
    $svc = makeWeighting();

    $items = new Collection([
        new Item(['key' => 'cheap_a', 'post_type' => 'general', 'name' => 'Cheap A', 'price_barrels' => 10, 'price_cash' => 0, 'price_intel' => 0, 'effects' => null, 'sort_order' => 10]),
        new Item(['key' => 'cheap_b', 'post_type' => 'general', 'name' => 'Cheap B', 'price_barrels' => 20, 'price_cash' => 0, 'price_intel' => 0, 'effects' => null, 'sort_order' => 20]),
        new Item(['key' => 'expensive', 'post_type' => 'general', 'name' => 'Expensive', 'price_barrels' => 100000, 'price_cash' => 0, 'price_intel' => 0, 'effects' => null, 'sort_order' => 30]),
        new Item(['key' => 'excluded', 'post_type' => 'general', 'name' => 'Excluded', 'price_barrels' => 1, 'price_cash' => 0, 'price_intel' => 0, 'effects' => null, 'sort_order' => 40]),
    ]);

    for ($i = 0; $i < 100; $i++) {
        $pick = $svc->inversePriceItemPick(
            'loot.test.items',
            "evt-{$i}",
            $items,
            ['excluded'],
            cashFactor: 100,
            intelFactor: 5,
            weightingMode: 'inverse_price',
        );
        expect($pick)->not->toBeNull();
        expect($pick->key)->not->toBe('excluded');
    }
});

it('inversePriceItemPick favours cheaper items heavily', function () {
    $svc = makeWeighting();

    // Cheap item has weight 1/10, expensive has weight 1/100000.
    // Cheap should dominate by a factor of ~10000×.
    $items = new Collection([
        new Item(['key' => 'cheap', 'post_type' => 'general', 'name' => 'Cheap', 'price_barrels' => 10, 'price_cash' => 0, 'price_intel' => 0, 'effects' => null, 'sort_order' => 10]),
        new Item(['key' => 'expensive', 'post_type' => 'general', 'name' => 'Expensive', 'price_barrels' => 100000, 'price_cash' => 0, 'price_intel' => 0, 'effects' => null, 'sort_order' => 20]),
    ]);

    $cheapCount = 0;
    $trials = 500;
    for ($i = 0; $i < $trials; $i++) {
        $pick = $svc->inversePriceItemPick(
            'loot.test.items.skew',
            "evt-{$i}",
            $items,
            [],
            cashFactor: 100,
            intelFactor: 5,
            weightingMode: 'inverse_price',
        );
        if ($pick?->key === 'cheap') {
            $cheapCount++;
        }
    }

    // Essentially every roll should land on the cheap item.
    expect($cheapCount)->toBeGreaterThan((int) ($trials * 0.95));
});

it('inversePriceItemPick returns null on an empty filtered pool', function () {
    $svc = makeWeighting();

    $items = new Collection([
        new Item(['key' => 'a', 'post_type' => 'general', 'name' => 'A', 'price_barrels' => 10, 'price_cash' => 0, 'price_intel' => 0, 'effects' => null, 'sort_order' => 10]),
    ]);

    $pick = $svc->inversePriceItemPick(
        'loot.test.items.empty',
        'evt-1',
        $items,
        ['a'],
        cashFactor: 100,
        intelFactor: 5,
        weightingMode: 'inverse_price',
    );

    expect($pick)->toBeNull();
});

it('uniformFloat stays within its bounds', function () {
    $svc = makeWeighting();

    for ($i = 0; $i < 100; $i++) {
        $v = $svc->uniformFloat('loot.test.uniform', "evt-{$i}", 0.05, 0.20);
        expect($v)->toBeGreaterThanOrEqual(0.05);
        expect($v)->toBeLessThanOrEqual(0.20);
    }
});
