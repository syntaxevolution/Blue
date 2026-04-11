<?php

namespace App\Domain\Player;

use App\Domain\Config\GameConfigResolver;
use App\Models\Player;

/**
 * Continuous-trickle move regeneration per technical-ultraplan §10.
 *
 * Rather than running a global tick job that bumps every player's moves
 * on a schedule, we reconcile lazily on read: whenever any code path
 * needs a player's up-to-date move count, it calls reconcile() which
 * computes how many full ticks have elapsed since the last update,
 * adds that many moves, and advances moves_updated_at by exactly the
 * number of seconds consumed (preserving sub-tick precision).
 *
 * Bank cap = daily_regen × bank_cap_multiplier; accumulation stops
 * there and "time spent at cap" is effectively forfeited — standard
 * stamina-system behavior.
 *
 * This design has zero drift, no scheduled jobs, and scales trivially.
 */
class MoveRegenService
{
    public function __construct(
        private readonly GameConfigResolver $config,
    ) {}

    /**
     * Pure math: how many full move ticks fit into $elapsedSeconds.
     * Extracted so it can be unit-tested without hitting the DB.
     */
    public static function computeTicks(int $elapsedSeconds, int $tickSeconds): int
    {
        if ($elapsedSeconds <= 0 || $tickSeconds <= 0) {
            return 0;
        }

        return intdiv($elapsedSeconds, $tickSeconds);
    }

    /**
     * Bring a player's moves_current up to date. Idempotent: calling
     * reconcile twice in a row is equivalent to calling it once.
     *
     * Does NOT use lockForUpdate — two concurrent reconciles converge
     * on the same result (both compute the same ticks from the same
     * last-updated timestamp and both write the same new value).
     * Spenders (travel, drill, spy, attack) must take the lock.
     */
    public function reconcile(Player $player): Player
    {
        $now = now();
        $lastUpdated = $player->moves_updated_at ?? $now;
        $elapsed = $now->timestamp - $lastUpdated->timestamp;

        $tickSeconds = (int) $this->config->get('moves.regen_tick_seconds');
        $ticks = self::computeTicks($elapsed, $tickSeconds);

        if ($ticks === 0) {
            return $player;
        }

        $dailyRegen = (int) $this->config->get('moves.daily_regen');
        $bankCap = (int) ceil($dailyRegen * (float) $this->config->get('moves.bank_cap_multiplier'));

        // Preserve any overflow accumulated via purchases (extra_moves_pack,
        // emergency_ration, caffeine_tin). `allow_overflow_from_purchases`
        // lets those items push moves_current above the bank cap; natural
        // regen must NOT silently clip that back down on the next tick.
        // While the player is at or above cap, trickle ticks are forfeited
        // — the timestamp still advances so normal regen resumes cleanly
        // once they spend back below the cap.
        $current = (int) $player->moves_current;
        $newCurrent = $current >= $bankCap
            ? $current
            : min($current + $ticks, $bankCap);
        $consumedSeconds = $ticks * $tickSeconds;
        $newUpdatedAt = $lastUpdated->copy()->addSeconds($consumedSeconds);

        $player->update([
            'moves_current' => $newCurrent,
            'moves_updated_at' => $newUpdatedAt,
        ]);

        return $player->refresh();
    }

    /**
     * Convenience: reconcile + check whether the player can afford $cost.
     */
    public function canAfford(Player $player, int $cost): bool
    {
        $this->reconcile($player);

        return $player->moves_current >= $cost;
    }

    /**
     * Bank cap resolved from config. Exposed for UI display and tests.
     */
    public function bankCap(): int
    {
        $dailyRegen = (int) $this->config->get('moves.daily_regen');

        return (int) ceil($dailyRegen * (float) $this->config->get('moves.bank_cap_multiplier'));
    }
}
