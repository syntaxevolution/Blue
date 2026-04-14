<?php

namespace App\Domain\Loot;

use App\Domain\Config\RngService;
use App\Models\Item;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Pure weighting helpers for loot crate payouts.
 *
 * Every random draw is routed through RngService so outcomes are
 * auditable (record mode) and replayable (replay mode in tests).
 *
 * All math is deterministic given the same (category, event_key)
 * pair — e.g. a test that force-replays the same queue twice gets
 * the same rolls back.
 */
class LootWeightingService
{
    public function __construct(
        private readonly RngService $rng,
    ) {}

    /**
     * Roll a value in [$min, $max] with a low-end bias controlled by
     * $exponent. Exponent 1.0 is uniform; values > 1 push the mass
     * toward the minimum, < 1 toward the maximum.
     *
     * Formula: min + (max - min) * rand^exponent, where rand is
     * uniform on [0, 1). An exponent of 2.5 (spec default) gives:
     *   - mean ≈ min + 0.29 * (max - min)
     *   - 80% of rolls below min + 0.40 * (max - min)
     *   - 5% of rolls above min + 0.85 * (max - min)
     *
     * Both int and float variants round to int if $asInt=true.
     */
    public function lowWeightedRange(
        string $category,
        int|string $eventKey,
        float $min,
        float $max,
        float $exponent,
        bool $asInt,
    ): int|float {
        if ($max < $min) {
            throw new RuntimeException("lowWeightedRange: max ({$max}) < min ({$min})");
        }
        if ($exponent <= 0) {
            throw new RuntimeException("lowWeightedRange: exponent must be > 0, got {$exponent}");
        }

        // rollFloat returns [min, max) — pass 0..1 and raise to the
        // power so large exponents cluster near zero, which translates
        // to "near the minimum" in the output range.
        $rand = $this->rng->rollFloat($category, $eventKey, 0.0, 1.0);
        $curved = $rand ** $exponent;
        $value = $min + ($max - $min) * $curved;

        if ($asInt) {
            return (int) floor($value);
        }

        // Two-decimal rounding for cash — the game treats akzar_cash
        // as decimal(12,2) so anything smaller rounds away downstream
        // and looks inconsistent in tests. Doing the rounding here
        // keeps the source of truth for cash amounts in one place.
        return round($value, 2);
    }

    /**
     * Weighted pick across the real-crate outcome categories
     * (nothing/oil/cash/item). Returns the selected category key.
     *
     * Delegates to RngService::rollWeighted which normalises the
     * weights internally, so the caller can pass raw 25/10/5/60
     * from the config.
     *
     * @param  array<string, int|float>  $outcomes
     */
    public function pickOutcome(string $category, int|string $eventKey, array $outcomes): string
    {
        if ($outcomes === []) {
            throw new RuntimeException('pickOutcome: outcomes array is empty');
        }

        // Filter out zero/negative weights so a config override of
        // `oil: 0` cleanly removes oil from the pool instead of
        // throwing later on the "non-negative weights" guard.
        $clean = array_filter($outcomes, fn ($w) => (is_int($w) || is_float($w)) && $w > 0);
        if ($clean === []) {
            throw new RuntimeException('pickOutcome: no positive weights');
        }

        return (string) $this->rng->rollWeighted($category, $eventKey, $clean);
    }

    /**
     * Pick one item from the given collection using inverse-price
     * weighting (cheaper items more likely to be rolled). Returns
     * null if the collection is empty after excluding filtered keys.
     *
     * Price model:
     *   barrel_equivalent = price_barrels
     *                     + price_cash  * cash_to_barrels_factor
     *                     + price_intel * intel_to_barrels_factor
     *   weight = 1 / max(barrel_equivalent, 1)
     *
     * The max(_, 1) floor prevents zero-price items from dominating
     * (an Explorer's Atlas at 30 barrels otherwise gets the same
     * weight as a hypothetical 0-barrel item, which would skew the
     * distribution).
     *
     * @param  Collection<int, Item>  $items
     * @param  list<string>  $excludeKeys
     */
    public function inversePriceItemPick(
        string $category,
        int|string $eventKey,
        Collection $items,
        array $excludeKeys,
        float $cashFactor,
        float $intelFactor,
        string $weightingMode,
    ): ?Item {
        $pool = $items->filter(fn (Item $item) => ! in_array($item->key, $excludeKeys, true));
        if ($pool->isEmpty()) {
            return null;
        }

        $weights = [];
        foreach ($pool as $item) {
            if ($weightingMode === 'uniform') {
                $weights[$item->key] = 1.0;

                continue;
            }
            // Default: inverse_price
            $equiv = (float) $item->price_barrels
                + ((float) $item->price_cash) * $cashFactor
                + ((float) $item->price_intel) * $intelFactor;
            $weights[$item->key] = 1.0 / max($equiv, 1.0);
        }

        $picked = (string) $this->rng->rollWeighted($category, $eventKey, $weights);

        return $pool->firstWhere('key', $picked);
    }

    /**
     * Roll a uniform float in [$min, $max] — used for the sabotage
     * siphon percentage roll. Wraps RngService::rollFloat so the
     * service has one stable pure-helper surface for all three
     * loot-specific RNG shapes.
     */
    public function uniformFloat(string $category, int|string $eventKey, float $min, float $max): float
    {
        if ($max < $min) {
            throw new RuntimeException("uniformFloat: max ({$max}) < min ({$min})");
        }

        return $this->rng->rollFloat($category, $eventKey, $min, $max);
    }
}
