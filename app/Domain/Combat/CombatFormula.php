<?php

namespace App\Domain\Combat;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Config\RngService;
use App\Models\Player;

/**
 * Pure combat resolver per technical-ultraplan §8.
 *
 * Deterministic core + ±10–15% RNG band. No game state mutation,
 * no DB writes — takes attacker/defender state and an RNG service,
 * returns the outcome shape. All balance numbers flow from
 * GameConfig so the whole formula is live-tunable.
 *
 * Pseudocode:
 *
 *   atkPower = scaledStat(attacker.strength)
 *   defPower = scaledStat(defender.fortification)
 *              + (defenderAtBase ? scaledStat(defender.strength) : 0)   [F2]
 *   baseOutcome = (atkPower - defPower) / (atkPower + defPower)   // [-1, 1]
 *   randomBand  = rng.rollBand(combat.band, min, max)
 *   finalScore  = baseOutcome + randomBand
 *
 * The at-base bonus is gated on combat.at_base_defense_bonus_enabled.
 * When true, a defender standing on their own base tile at the moment
 * of the attack gets their scaled strength added to their defense —
 * the first meaningful incentive to actually return home.
 *
 * Stat scaling is driven by config ranges (linear 1..15, partial 16..20
 * at 60%, prestige 21..50 at 30%) so raising the cap to 50 automatically
 * rescales without a code change.
 */
class CombatFormula
{
    public function __construct(
        private readonly GameConfigResolver $config,
        private readonly RngService $rng,
    ) {}

    /**
     * @return array{
     *     outcome: string,
     *     loot_pct: float,
     *     cash_stolen: float,
     *     final_score: float,
     *     base_outcome: float,
     *     random_band: float,
     *     atk_power: float,
     *     def_power: float,
     *     defender_at_base: bool,
     * }
     */
    public function resolveAttack(
        Player $attacker,
        Player $defender,
        string $eventKey,
        bool $defenderAtBase = false,
    ): array {
        $atkPower = $this->scaledStat((int) $attacker->strength);
        $defPower = $this->scaledStat((int) $defender->fortification);

        $bonusEnabled = (bool) $this->config->get('combat.at_base_defense_bonus_enabled');
        $appliedHomeBonus = $bonusEnabled && $defenderAtBase;

        if ($appliedHomeBonus) {
            $defPower += $this->scaledStat((int) $defender->strength);
        }

        // Guard against both sides being zero (division by zero).
        $denominator = $atkPower + $defPower;
        $baseOutcome = $denominator > 0 ? ($atkPower - $defPower) / $denominator : 0.0;

        $bandMin = (float) $this->config->get('combat.rng_band_min');
        $bandMax = (float) $this->config->get('combat.rng_band_max');
        $randomBand = $this->rng->rollBand('combat', $eventKey, $bandMin, $bandMax);

        $finalScore = $baseOutcome + $randomBand;

        $lootCeiling = (float) $this->config->get('combat.loot_ceiling_pct');
        $lootBase = (float) $this->config->get('combat.loot_base_pct');
        $lootScale = (float) $this->config->get('combat.loot_scale_factor');

        if ($finalScore > 0) {
            $lootPct = min($lootCeiling, $lootBase + $lootScale * $finalScore);
            $cashStolen = round((float) $defender->akzar_cash * $lootPct, 2);

            return [
                'outcome' => 'success',
                'loot_pct' => $lootPct,
                'cash_stolen' => $cashStolen,
                'final_score' => $finalScore,
                'base_outcome' => $baseOutcome,
                'random_band' => $randomBand,
                'atk_power' => $atkPower,
                'def_power' => $defPower,
                'defender_at_base' => $appliedHomeBonus,
            ];
        }

        return [
            'outcome' => 'failure',
            'loot_pct' => 0.0,
            'cash_stolen' => 0.0,
            'final_score' => $finalScore,
            'base_outcome' => $baseOutcome,
            'random_band' => $randomBand,
            'atk_power' => $atkPower,
            'def_power' => $defPower,
            'defender_at_base' => $appliedHomeBonus,
        ];
    }

    /**
     * Apply the soft plateau using config ranges:
     *   1..linear_range[1]           → linear (1:1)
     *   partial_range[0]..partial_range[1] → partial_efficiency per point
     *   prestige_range[0]..prestige_range[1] → prestige_efficiency per point
     *
     * Extending the cap just requires widening prestige_range in config —
     * no code change.
     */
    public function scaledStat(int $level): float
    {
        if ($level <= 0) {
            return 0.0;
        }

        $linearEnd = (int) ($this->config->get('stats.scaling.linear_range')[1] ?? 15);
        $partialRange = (array) $this->config->get('stats.scaling.partial_range');
        $prestigeRange = (array) $this->config->get('stats.scaling.prestige_range');
        $partialEfficiency = (float) $this->config->get('stats.scaling.partial_efficiency');
        $prestigeEfficiency = (float) $this->config->get('stats.scaling.prestige_efficiency');

        $partialStart = (int) ($partialRange[0] ?? ($linearEnd + 1));
        $partialEnd = (int) ($partialRange[1] ?? $partialStart);
        $prestigeStart = (int) ($prestigeRange[0] ?? ($partialEnd + 1));
        $prestigeEnd = (int) ($prestigeRange[1] ?? $prestigeStart);

        $partialWidth = max(0, $partialEnd - $partialStart + 1);
        $prestigeWidth = max(0, $prestigeEnd - $prestigeStart + 1);

        $linear = (float) min($level, $linearEnd);
        $partial = (float) max(0, min($level - ($partialStart - 1), $partialWidth)) * $partialEfficiency;
        $prestige = (float) max(0, min($level - ($prestigeStart - 1), $prestigeWidth)) * $prestigeEfficiency;

        return $linear + $partial + $prestige;
    }
}
