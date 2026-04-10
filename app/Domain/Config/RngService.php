<?php

namespace App\Domain\Config;

use InvalidArgumentException;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use RuntimeException;

/**
 * Seeded, deterministic, auditable random number service.
 *
 * Every random roll in game code MUST go through this service — never
 * `rand()`, `mt_rand()`, or `random_int()` directly. This lets us:
 *
 *   - Reproduce any roll in tests (replay mode with fixed values)
 *   - Audit player disputes ("I drilled 10 gushers in a row")
 *   - Swap the underlying PRNG without changing calling code
 *
 * Seed derivation: sha256(category:eventKey) → 32 raw bytes →
 * Xoshiro256** engine. Same (category, eventKey) pair always yields
 * the same sequence, regardless of call order.
 */
class RngService
{
    private bool $recordMode = false;
    private bool $replayMode = false;

    /** @var array<string,list<mixed>> */
    private array $replayQueue = [];

    /** @var list<array{category:string,event_key:int|string,value:mixed,meta:array<string,mixed>,at:float}> */
    private array $recordedRolls = [];

    /**
     * Roll an integer in [$min, $max] inclusive.
     */
    public function rollInt(string $category, int|string $eventKey, int $min, int $max): int
    {
        if ($min > $max) {
            throw new InvalidArgumentException("rollInt: min ({$min}) > max ({$max})");
        }

        if ($this->replayMode) {
            return (int) $this->popReplay($category, $eventKey);
        }

        $value = $this->randomizerFor($category, $eventKey)->getInt($min, $max);
        $this->record($category, $eventKey, $value, ['type' => 'int', 'min' => $min, 'max' => $max]);

        return $value;
    }

    /**
     * Roll a float in [$min, $max).
     * Uses PHP 8.3+ Randomizer::getFloat().
     */
    public function rollFloat(string $category, int|string $eventKey, float $min = 0.0, float $max = 1.0): float
    {
        if ($min > $max) {
            throw new InvalidArgumentException("rollFloat: min ({$min}) > max ({$max})");
        }

        if ($this->replayMode) {
            return (float) $this->popReplay($category, $eventKey);
        }

        $value = $this->randomizerFor($category, $eventKey)->getFloat($min, $max);
        $this->record($category, $eventKey, $value, ['type' => 'float', 'min' => $min, 'max' => $max]);

        return $value;
    }

    /**
     * Roll true with probability $trueChance (0..1).
     */
    public function rollBool(string $category, int|string $eventKey, float $trueChance = 0.5): bool
    {
        if ($trueChance < 0.0 || $trueChance > 1.0) {
            throw new InvalidArgumentException("rollBool: trueChance must be in [0, 1], got {$trueChance}");
        }

        if ($this->replayMode) {
            return (bool) $this->popReplay($category, $eventKey);
        }

        $f = $this->randomizerFor($category, $eventKey)->getFloat(0.0, 1.0);
        $value = $f < $trueChance;
        $this->record($category, $eventKey, $value, ['type' => 'bool', 'true_chance' => $trueChance, 'roll' => $f]);

        return $value;
    }

    /**
     * Weighted selection across $weights: ['key' => positive_weight, ...].
     * Returns the selected key.
     */
    public function rollWeighted(string $category, int|string $eventKey, array $weights): int|string
    {
        if ($weights === []) {
            throw new InvalidArgumentException('rollWeighted: weights array is empty');
        }

        $total = 0.0;
        foreach ($weights as $w) {
            if (!is_int($w) && !is_float($w)) {
                throw new InvalidArgumentException('rollWeighted: all weights must be numeric');
            }
            if ($w < 0) {
                throw new InvalidArgumentException('rollWeighted: weights must be non-negative');
            }
            $total += $w;
        }

        if ($total <= 0.0) {
            throw new InvalidArgumentException('rollWeighted: total weight must be > 0');
        }

        if ($this->replayMode) {
            return $this->popReplay($category, $eventKey);
        }

        $target = $this->randomizerFor($category, $eventKey)->getFloat(0.0, $total);

        $running = 0.0;
        $selected = array_key_last($weights);
        foreach ($weights as $key => $w) {
            $running += $w;
            if ($target < $running) {
                $selected = $key;
                break;
            }
        }

        $this->record($category, $eventKey, $selected, ['type' => 'weighted', 'weights' => $weights, 'target' => $target]);

        return $selected;
    }

    /**
     * Roll a value in a band [$min, $max] — used for the combat ±10–15% RNG band.
     * Convenience alias over rollFloat with a "band" suffix on the category so
     * record/replay can distinguish it from other floats sharing the same event key.
     */
    public function rollBand(string $category, int|string $eventKey, float $min, float $max): float
    {
        return $this->rollFloat($category.'.band', $eventKey, $min, $max);
    }

    public function enableRecordMode(): void
    {
        $this->recordMode = true;
    }

    public function disableRecordMode(): void
    {
        $this->recordMode = false;
    }

    /**
     * @param  array<string,list<mixed>>  $queue  Keyed by "category:eventKey", FIFO list of values.
     */
    public function enableReplayMode(array $queue): void
    {
        $this->replayMode = true;
        $this->replayQueue = $queue;
    }

    public function disableReplayMode(): void
    {
        $this->replayMode = false;
        $this->replayQueue = [];
    }

    /**
     * Drain recorded rolls and reset the buffer.
     *
     * @return list<array{category:string,event_key:int|string,value:mixed,meta:array<string,mixed>,at:float}>
     */
    public function takeRecordedRolls(): array
    {
        $rolls = $this->recordedRolls;
        $this->recordedRolls = [];

        return $rolls;
    }

    private function randomizerFor(string $category, int|string $eventKey): Randomizer
    {
        $seed = hash('sha256', $category.':'.$eventKey, true);

        return new Randomizer(new Xoshiro256StarStar($seed));
    }

    private function record(string $category, int|string $eventKey, mixed $value, array $meta): void
    {
        if (!$this->recordMode) {
            return;
        }

        $this->recordedRolls[] = [
            'category' => $category,
            'event_key' => $eventKey,
            'value' => $value,
            'meta' => $meta,
            'at' => microtime(true),
        ];
    }

    private function popReplay(string $category, int|string $eventKey): mixed
    {
        $key = $category.':'.$eventKey;

        if (!array_key_exists($key, $this->replayQueue)) {
            throw new RuntimeException("RngService replay: no queued values for key '{$key}'");
        }

        if (empty($this->replayQueue[$key])) {
            throw new RuntimeException("RngService replay: queue exhausted for key '{$key}'");
        }

        return array_shift($this->replayQueue[$key]);
    }
}
