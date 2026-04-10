<?php

use App\Domain\Config\RngService;

it('is deterministic: same category+eventKey yields the same int', function () {
    $a = (new RngService())->rollInt('drilling.standard', 'field-42-point-3', 4, 8);
    $b = (new RngService())->rollInt('drilling.standard', 'field-42-point-3', 4, 8);

    expect($a)->toBe($b);
});

it('is deterministic: same category+eventKey yields the same float', function () {
    $a = (new RngService())->rollFloat('combat.band', 'attack-101', -0.10, 0.15);
    $b = (new RngService())->rollFloat('combat.band', 'attack-101', -0.10, 0.15);

    expect($a)->toBe($b);
});

it('different eventKeys produce different outputs', function () {
    $rng = new RngService();

    $a = $rng->rollInt('drilling.standard', 'point-1', 0, 1_000_000);
    $b = $rng->rollInt('drilling.standard', 'point-2', 0, 1_000_000);

    expect($a)->not->toBe($b);
});

it('rollInt respects inclusive bounds over many event keys', function () {
    $rng = new RngService();

    for ($i = 0; $i < 200; $i++) {
        $v = $rng->rollInt('test', "evt-{$i}", 5, 10);
        expect($v)->toBeGreaterThanOrEqual(5)->toBeLessThanOrEqual(10);
    }
});

it('rollFloat respects half-open bounds over many event keys', function () {
    $rng = new RngService();

    for ($i = 0; $i < 200; $i++) {
        $v = $rng->rollFloat('test', "evt-{$i}", -0.5, 1.5);
        expect($v)->toBeGreaterThanOrEqual(-0.5)->toBeLessThan(1.5);
    }
});

it('rollBool approximates the requested true chance', function () {
    $rng = new RngService();

    $trues = 0;
    $trials = 500;
    for ($i = 0; $i < $trials; $i++) {
        if ($rng->rollBool('test', "flip-{$i}", 0.25)) {
            $trues++;
        }
    }

    // Expect ~25% ± generous slack given the small sample size.
    expect($trues / $trials)->toBeGreaterThan(0.15)->toBeLessThan(0.35);
});

it('rollWeighted roughly tracks the configured distribution', function () {
    $rng = new RngService();
    $weights = ['dry' => 0.30, 'trickle' => 0.40, 'standard' => 0.25, 'gusher' => 0.05];

    $counts = ['dry' => 0, 'trickle' => 0, 'standard' => 0, 'gusher' => 0];
    $trials = 2000;
    for ($i = 0; $i < $trials; $i++) {
        $counts[$rng->rollWeighted('drilling.quality', "pt-{$i}", $weights)]++;
    }

    // Every bucket hit at least once.
    foreach ($counts as $bucket => $n) {
        expect($n)->toBeGreaterThan(0, "bucket {$bucket} was never rolled");
    }

    // Ordering should reflect weights (gusher is rarest, trickle most common).
    expect($counts['gusher'])->toBeLessThan($counts['dry']);
    expect($counts['gusher'])->toBeLessThan($counts['standard']);
    expect($counts['trickle'])->toBeGreaterThan($counts['gusher']);
});

it('rollWeighted rejects invalid input', function () {
    $rng = new RngService();

    expect(fn () => $rng->rollWeighted('x', 1, []))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $rng->rollWeighted('x', 1, ['a' => 0, 'b' => 0]))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $rng->rollWeighted('x', 1, ['a' => -1, 'b' => 1]))
        ->toThrow(InvalidArgumentException::class);
});

it('rollBand namespaces the event under the .band suffix', function () {
    $rng = new RngService();

    // Same category+eventKey in rollBand vs rollFloat should diverge
    // because rollBand suffixes the category internally.
    $band = $rng->rollBand('combat', 'atk-1', -0.1, 0.15);
    $flat = $rng->rollFloat('combat', 'atk-1', -0.1, 0.15);

    expect($band)->not->toBe($flat);
});

it('rollInt rejects inverted bounds', function () {
    expect(fn () => (new RngService())->rollInt('x', 1, 10, 5))
        ->toThrow(InvalidArgumentException::class);
});

it('replay mode returns queued values in FIFO order', function () {
    $rng = new RngService();
    $rng->enableReplayMode([
        'combat.band:atk-1' => [0.05, 0.12],
    ]);

    expect($rng->rollFloat('combat.band', 'atk-1', -0.1, 0.15))->toBe(0.05);
    expect($rng->rollFloat('combat.band', 'atk-1', -0.1, 0.15))->toBe(0.12);
});

it('replay mode throws when the queue is exhausted', function () {
    $rng = new RngService();
    $rng->enableReplayMode(['x:1' => [42]]);

    $rng->rollInt('x', 1, 0, 100);

    expect(fn () => $rng->rollInt('x', 1, 0, 100))
        ->toThrow(RuntimeException::class);
});

it('replay mode throws for unknown keys', function () {
    $rng = new RngService();
    $rng->enableReplayMode(['a:1' => [0]]);

    expect(fn () => $rng->rollInt('nope', 1, 0, 1))
        ->toThrow(RuntimeException::class);
});

it('record mode captures rolls with their metadata', function () {
    $rng = new RngService();
    $rng->enableRecordMode();

    $rng->rollInt('drill', 'pt-1', 4, 8);
    $rng->rollFloat('combat.band', 'atk-1', -0.1, 0.15);

    $rolls = $rng->takeRecordedRolls();

    expect($rolls)->toHaveCount(2);
    expect($rolls[0]['category'])->toBe('drill');
    expect($rolls[0]['event_key'])->toBe('pt-1');
    expect($rolls[0]['meta']['type'])->toBe('int');
    expect($rolls[1]['category'])->toBe('combat.band');
    expect($rolls[1]['meta']['type'])->toBe('float');
});

it('takeRecordedRolls empties the buffer', function () {
    $rng = new RngService();
    $rng->enableRecordMode();
    $rng->rollInt('x', 1, 0, 10);

    expect($rng->takeRecordedRolls())->toHaveCount(1);
    expect($rng->takeRecordedRolls())->toHaveCount(0);
});
