<?php

namespace App\Domain\Items;

use App\Domain\Config\GameConfigResolver;
use App\Models\Player;

/**
 * Handles the stat hard-cap overflow buffer.
 *
 * When a purchased stat-add item would push a stat above stats.hard_cap,
 * the excess is stored in a {stat}_banked column on the player row rather
 * than rejected. If the cap is later raised (e.g., 25 → 50), drainBank()
 * moves as much of the banked overflow as will fit back into the live
 * stat without loss.
 *
 * Design: overflow banking never fails or rejects — callers can always
 * apply() and the overflow vanishes only if the player never re-visits
 * drainBank() in the future. MapStateBuilder calls drainBank() on every
 * payload build so the invariant holds.
 */
class StatOverflowService
{
    /** @var list<string> */
    public const STATS = ['strength', 'fortification', 'stealth', 'security'];

    public function __construct(
        private readonly GameConfigResolver $config,
    ) {}

    /**
     * Apply a stat delta in-memory on $player: as much as fits under
     * the hard cap goes to the live stat, the rest goes to the banked
     * counterpart. Does NOT save — caller decides when to persist.
     *
     * @param  array<string,int>  $deltas  keyed by stat name
     * @return array<string,array{applied:int,banked:int}>  before→after summary for logging/tests
     */
    public function apply(Player $player, array $deltas): array
    {
        $cap = (int) $this->config->get('stats.hard_cap');
        $summary = [];

        foreach (self::STATS as $stat) {
            $delta = (int) ($deltas[$stat] ?? 0);
            if ($delta <= 0) {
                continue;
            }

            $current = (int) $player->{$stat};
            $room = max(0, $cap - $current);
            $applied = min($delta, $room);
            $banked = $delta - $applied;

            $player->{$stat} = $current + $applied;
            $bankedKey = $stat.'_banked';
            $player->{$bankedKey} = (int) $player->{$bankedKey} + $banked;

            $summary[$stat] = ['applied' => $applied, 'banked' => $banked];
        }

        return $summary;
    }

    /**
     * Move banked overflow back into live stats if the current cap
     * provides room. Called on every map state build so a cap raise
     * is retroactively applied on the player's next interaction.
     *
     * Returns true if any drainage happened (caller may then ->save()).
     */
    public function drainBank(Player $player): bool
    {
        $cap = (int) $this->config->get('stats.hard_cap');
        $changed = false;

        foreach (self::STATS as $stat) {
            $bankedKey = $stat.'_banked';
            $banked = (int) $player->{$bankedKey};
            if ($banked <= 0) {
                continue;
            }

            $current = (int) $player->{$stat};
            $room = max(0, $cap - $current);
            if ($room <= 0) {
                continue;
            }

            $drain = min($banked, $room);
            $player->{$stat} = $current + $drain;
            $player->{$bankedKey} = $banked - $drain;
            $changed = true;
        }

        return $changed;
    }
}
