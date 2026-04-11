<?php

namespace App\Domain\Items;

use App\Models\Player;
use Illuminate\Support\Facades\DB;

/**
 * Sums passive effect bonuses from every item the player owns actively.
 *
 * Items with passive effect keys (drill_yield_bonus_pct, daily_drill_limit_bonus,
 * break_chance_reduction_pct) contribute additively whenever the relevant
 * service queries. This avoids denormalizing bonuses onto the player row
 * at the cost of a small query per drill — acceptable for 100-user scale.
 *
 * Cached per-request via an in-memory map keyed by player_id so a single
 * request doesn't re-query for multiple lookups.
 */
class PassiveBonusService
{
    /** @var array<int,array<string,float>> */
    private array $cache = [];

    public function yieldBonusPct(Player $player): float
    {
        return $this->sum($player, 'drill_yield_bonus_pct');
    }

    public function drillLimitBonus(Player $player): int
    {
        return (int) $this->sum($player, 'daily_drill_limit_bonus');
    }

    public function breakChanceReductionPct(Player $player): float
    {
        return $this->sum($player, 'break_chance_reduction_pct');
    }

    private function sum(Player $player, string $effectKey): float
    {
        $pid = (int) $player->id;
        if (! isset($this->cache[$pid])) {
            $this->cache[$pid] = $this->load($pid);
        }

        return (float) ($this->cache[$pid][$effectKey] ?? 0);
    }

    /**
     * @return array<string,float>  keyed by effect key
     */
    private function load(int $playerId): array
    {
        $rows = DB::table('player_items')
            ->join('items_catalog', 'items_catalog.key', '=', 'player_items.item_key')
            ->where('player_items.player_id', $playerId)
            ->where('player_items.status', 'active')
            ->where('player_items.quantity', '>', 0)
            ->whereNotNull('items_catalog.effects')
            ->pluck('items_catalog.effects');

        $sums = [];
        foreach ($rows as $json) {
            $effects = is_string($json) ? json_decode($json, true) : (array) $json;
            if (! is_array($effects)) {
                continue;
            }
            foreach (['drill_yield_bonus_pct', 'daily_drill_limit_bonus', 'break_chance_reduction_pct'] as $key) {
                if (isset($effects[$key])) {
                    $sums[$key] = ($sums[$key] ?? 0) + (float) $effects[$key];
                }
            }
        }

        return $sums;
    }

    /** Clear per-request memoisation (mainly for tests). */
    public function flush(): void
    {
        $this->cache = [];
    }
}
