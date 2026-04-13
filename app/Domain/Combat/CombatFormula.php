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

        if ($finalScore > 0) {
            $lootPct = $this->resolveLootPct($finalScore);
            $cashStolen = $this->resolveCashStolen((float) $defender->akzar_cash, $lootPct);

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
     * Resolve a spontaneous wasteland duel between two players standing
     * on the same wasteland tile.
     *
     * Pure: no DB writes, no state mutation. TileCombatService is
     * responsible for all persistence, oil transfer, and broadcasting.
     *
     * Differs from resolveAttack() in two material ways:
     *   1. Strength vs Strength — there's no fortification on open
     *      wasteland. Both combatants fight with their muscle only.
     *   2. Oil loot instead of cash, with an UPSET-REWARD curve:
     *      a stronger winner gets a smaller slice; a weaker underdog
     *      pulling an upset gets the full ceiling. This pushes the
     *      meta toward fair fights — farming weaklings yields nothing.
     *
     * Pseudocode:
     *   atkPower   = scaledStat(attacker.strength)
     *   defPower   = scaledStat(defender.strength)
     *   baseOutcome = (atkPower - defPower) / (atkPower + defPower)
     *   randomBand  = rng.rollBand('combat', eventKey, min, max)
     *   finalScore  = baseOutcome + randomBand
     *   winner = finalScore > 0 ? attacker : defender
     *   oilPct = clamp(maxPct * scaled(loser) / scaled(winner), 0, maxPct)
     *
     * RNG band and scaled-stat curve share the same config keys as
     * base raids, so tuning one tunes both consistently.
     *
     * @return array{
     *   outcome: 'attacker_win'|'defender_win',
     *   winner_id: int,
     *   loser_id: int,
     *   oil_pct: float,
     *   final_score: float,
     *   base_outcome: float,
     *   random_band: float,
     *   atk_power: float,
     *   def_power: float,
     * }
     */
    public function resolveTileDuel(
        Player $attacker,
        Player $defender,
        string $eventKey,
    ): array {
        $atkPower = $this->scaledStat((int) $attacker->strength);
        $defPower = $this->scaledStat((int) $defender->strength);

        $denominator = $atkPower + $defPower;
        $baseOutcome = $denominator > 0 ? ($atkPower - $defPower) / $denominator : 0.0;

        $bandMin = (float) $this->config->get('combat.rng_band_min');
        $bandMax = (float) $this->config->get('combat.rng_band_max');
        $randomBand = $this->rng->rollBand('combat', $eventKey, $bandMin, $bandMax);

        $finalScore = $baseOutcome + $randomBand;

        $maxPct = (float) $this->config->get('combat.tile_duel.max_oil_loot_pct');
        if ($maxPct < 0.0) {
            $maxPct = 0.0;
        }

        if ($finalScore > 0) {
            $winnerStr = $atkPower;
            $loserStr = $defPower;
        } else {
            $winnerStr = $defPower;
            $loserStr = $atkPower;
        }

        // Upset reward: ratio of loser-scaled to winner-scaled strength,
        // clamped to [0, maxPct]. A stronger winner gets ~0%; a weaker
        // winner gets the full ceiling.
        $oilPct = 0.0;
        if ($winnerStr > 0) {
            $oilPct = max(0.0, min($maxPct, ($loserStr / $winnerStr) * $maxPct));
        }

        $outcome = $finalScore > 0 ? 'attacker_win' : 'defender_win';
        $winnerId = $finalScore > 0 ? (int) $attacker->id : (int) $defender->id;
        $loserId = $finalScore > 0 ? (int) $defender->id : (int) $attacker->id;

        return [
            'outcome' => $outcome,
            'winner_id' => $winnerId,
            'loser_id' => $loserId,
            'oil_pct' => $oilPct,
            'final_score' => $finalScore,
            'base_outcome' => $baseOutcome,
            'random_band' => $randomBand,
            'atk_power' => $atkPower,
            'def_power' => $defPower,
        ];
    }

    /**
     * Expected attacker win probability for a tile duel, ignoring RNG.
     * Used by the bot opportunistic hook to filter out near-guaranteed
     * wins (loot → 0) and near-guaranteed losses.
     *
     * Derivation: finalScore = baseOutcome + band, band uniform on
     * [min, max]. Attacker wins iff finalScore > 0, i.e. band > -baseOutcome.
     * Integrating the uniform distribution gives the closed-form below.
     */
    public function estimateTileDuelWinChance(Player $attacker, Player $defender): float
    {
        $atkPower = $this->scaledStat((int) $attacker->strength);
        $defPower = $this->scaledStat((int) $defender->strength);
        $denominator = $atkPower + $defPower;
        if ($denominator <= 0) {
            return 0.5;
        }
        $baseOutcome = ($atkPower - $defPower) / $denominator;

        $bandMin = (float) $this->config->get('combat.rng_band_min');
        $bandMax = (float) $this->config->get('combat.rng_band_max');
        $bandWidth = $bandMax - $bandMin;
        if ($bandWidth <= 0) {
            return $baseOutcome > 0 ? 1.0 : 0.0;
        }

        // P(band > -baseOutcome) where band ~ U(min, max).
        $threshold = -$baseOutcome;
        if ($threshold <= $bandMin) {
            return 1.0;
        }
        if ($threshold >= $bandMax) {
            return 0.0;
        }

        return ($bandMax - $threshold) / $bandWidth;
    }

    /**
     * Resolve the loot percentage from a positive final score.
     *
     * Pipeline:
     *   raw     = base + scale * finalScore
     *   clamped = clamp(raw, 0, ceiling)
     *   stepped = floor(clamped / quantum) * quantum
     *
     * The floor-quantize is intentional: it favors the defender when
     * the math lands between two 0.1% steps. A successful raid CAN
     * yield zero loot if finalScore is small enough that clamped is
     * below one quantum step — that's by design.
     */
    public function resolveLootPct(float $finalScore): float
    {
        $base = (float) $this->config->get('combat.loot_base_pct');
        $scale = (float) $this->config->get('combat.loot_scale_factor');
        $ceiling = (float) $this->config->get('combat.loot_ceiling_pct');
        $quantum = (float) $this->config->get('combat.loot_pct_quantum');

        $raw = $base + $scale * $finalScore;
        $clamped = max(0.0, min($ceiling, $raw));

        if ($quantum <= 0.0) {
            return $clamped;
        }

        return floor($clamped / $quantum) * $quantum;
    }

    /**
     * Apply a loot pct to defender cash, rounding UP to the nearest cent.
     *
     * Round-up is asymmetric on purpose: any nonzero loot pct on a
     * nonzero pool yields at least $0.01, so weak-but-positive raids
     * always feel like they did something. Pairs with the floor-quantize
     * in resolveLootPct() above to keep the bias from compounding.
     */
    public function resolveCashStolen(float $defenderCash, float $lootPct): float
    {
        if ($defenderCash <= 0.0 || $lootPct <= 0.0) {
            return 0.0;
        }

        return ceil($defenderCash * $lootPct * 100.0) / 100.0;
    }

    /**
     * Closed-form estimate of attacker raid win probability, ignoring
     * the random band (mirror of estimateTileDuelWinChance but for
     * fortification-based defense, plus the optional at-base strength
     * bonus).
     *
     * Used by SpyService to bake an "estimated win chance" into the
     * spy reveal payload — it's computed at spy time and snapshotted,
     * so the player sees the win odds AS OF the moment of the spy.
     * Defenders moving home/away after the spy isn't reflected; that's
     * intentional (intel goes stale).
     */
    public function estimateRaidWinChance(
        Player $attacker,
        Player $defender,
        bool $defenderAtBase = false,
    ): float {
        $atkPower = $this->scaledStat((int) $attacker->strength);
        $defPower = $this->scaledStat((int) $defender->fortification);

        $bonusEnabled = (bool) $this->config->get('combat.at_base_defense_bonus_enabled');
        if ($bonusEnabled && $defenderAtBase) {
            $defPower += $this->scaledStat((int) $defender->strength);
        }

        $denominator = $atkPower + $defPower;
        if ($denominator <= 0) {
            return 0.5;
        }
        $baseOutcome = ($atkPower - $defPower) / $denominator;

        $bandMin = (float) $this->config->get('combat.rng_band_min');
        $bandMax = (float) $this->config->get('combat.rng_band_max');
        $bandWidth = $bandMax - $bandMin;
        if ($bandWidth <= 0) {
            return $baseOutcome > 0 ? 1.0 : 0.0;
        }

        $threshold = -$baseOutcome;
        if ($threshold <= $bandMin) {
            return 1.0;
        }
        if ($threshold >= $bandMax) {
            return 0.0;
        }

        return ($bandMax - $threshold) / $bandWidth;
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
