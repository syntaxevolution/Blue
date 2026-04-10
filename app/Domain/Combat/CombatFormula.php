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
 * Pseudocode from the spec, adapted:
 *
 *   atkPower = scaledStat(attacker.strength)
 *   defPower = scaledStat(defender.fortification)
 *   baseOutcome = (atkPower - defPower) / (atkPower + defPower)   // [-1, 1]
 *   randomBand  = rng.rollFloat(combat.band, min, max)
 *   finalScore  = baseOutcome + randomBand
 *
 *   if finalScore > 0:
 *     lootPct    = min(loot_ceiling, 0.05 + 0.15 * finalScore)
 *     cashStolen = defender.cash * lootPct
 *     outcome    = 'success'
 *   else:
 *     outcome    = 'failure', cashStolen = 0
 *
 * The soft-stat plateau (linear 1..15, 60% 16..20, 30% 21..25) is
 * applied by scaledStat so every rank beyond 15 still matters but
 * not linearly.
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
     * }
     */
    public function resolveAttack(Player $attacker, Player $defender, string $eventKey): array
    {
        $atkPower = $this->scaledStat((int) $attacker->strength);
        $defPower = $this->scaledStat((int) $defender->fortification);

        // Guard against both sides being zero (division by zero).
        $denominator = $atkPower + $defPower;
        $baseOutcome = $denominator > 0 ? ($atkPower - $defPower) / $denominator : 0.0;

        $bandMin = (float) $this->config->get('combat.rng_band_min');
        $bandMax = (float) $this->config->get('combat.rng_band_max');
        $randomBand = $this->rng->rollBand('combat', $eventKey, $bandMin, $bandMax);

        $finalScore = $baseOutcome + $randomBand;

        $lootCeiling = (float) $this->config->get('combat.loot_ceiling_pct');

        if ($finalScore > 0) {
            $lootPct = min($lootCeiling, 0.05 + 0.15 * $finalScore);
            $cashStolen = round((float) $defender->akzar_cash * $lootPct, 2);

            return [
                'outcome' => 'success',
                'loot_pct' => $lootPct,
                'cash_stolen' => $cashStolen,
                'final_score' => $finalScore,
                'base_outcome' => $baseOutcome,
                'random_band' => $randomBand,
            ];
        }

        return [
            'outcome' => 'failure',
            'loot_pct' => 0.0,
            'cash_stolen' => 0.0,
            'final_score' => $finalScore,
            'base_outcome' => $baseOutcome,
            'random_band' => $randomBand,
        ];
    }

    /**
     * Apply the soft plateau: 1..15 linear, 16..20 at 60%, 21..25 at 30%.
     *
     *   scaledStat(level):
     *     linear   = min(level, 15)
     *     partial  = clamp(level - 15, 0, 5) * 0.6
     *     prestige = clamp(level - 20, 0, 5) * 0.3
     *     return linear + partial + prestige
     */
    public function scaledStat(int $level): float
    {
        if ($level <= 0) {
            return 0.0;
        }

        $partialEfficiency = (float) $this->config->get('stats.scaling.partial_efficiency');
        $prestigeEfficiency = (float) $this->config->get('stats.scaling.prestige_efficiency');

        $linear = (float) min($level, 15);
        $partial = (float) max(0, min($level - 15, 5)) * $partialEfficiency;
        $prestige = (float) max(0, min($level - 20, 5)) * $prestigeEfficiency;

        return $linear + $partial + $prestige;
    }
}
