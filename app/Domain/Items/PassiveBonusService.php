<?php

namespace App\Domain\Items;

use App\Models\Player;
use Illuminate\Support\Facades\DB;

/**
 * Sums passive effect bonuses from every item the player owns actively.
 *
 * Items with passive effect keys (drill_yield_bonus_pct, daily_drill_limit_bonus,
 * break_chance_reduction_pct, bank_cap_bonus) contribute additively whenever
 * the relevant service queries. This avoids denormalizing bonuses onto the
 * player row at the cost of a small query per drill — acceptable for 100-user
 * scale.
 *
 * Each row's effect value is multiplied by player_items.quantity so that
 * stackable items (e.g. Iron Lungs, bank_cap_bonus) compound correctly.
 * Single-purchase items always have quantity=1, so the multiplication is a
 * no-op for them.
 *
 * Cached per-request via an in-memory map keyed by player_id so a single
 * request doesn't re-query for multiple lookups. ShopService::purchase must
 * call flush() after a successful purchase so subsequent reads in the same
 * request see the new inventory.
 */
class PassiveBonusService
{
    private const TRACKED_KEYS = [
        'drill_yield_bonus_pct',
        'daily_drill_limit_bonus',
        'break_chance_reduction_pct',
        'bank_cap_bonus',
    ];

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

    /**
     * Extra bank-cap moves granted by owned cap-bonus items (Iron Lungs).
     * Stackable: each copy contributes its full value.
     */
    public function bankCapBonus(Player $player): int
    {
        return (int) $this->sum($player, 'bank_cap_bonus');
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
            ->get(['items_catalog.effects', 'player_items.quantity']);

        $sums = [];
        foreach ($rows as $row) {
            $raw = $row->effects;
            $effects = is_string($raw) ? json_decode($raw, true) : (array) $raw;
            if (! is_array($effects)) {
                continue;
            }
            $qty = max(1, (int) $row->quantity);
            foreach (self::TRACKED_KEYS as $key) {
                if (isset($effects[$key])) {
                    $sums[$key] = ($sums[$key] ?? 0) + ((float) $effects[$key] * $qty);
                }
            }
        }

        return $sums;
    }

    /** Clear per-request memoisation — must be called after inventory writes. */
    public function flush(): void
    {
        $this->cache = [];
    }
}
