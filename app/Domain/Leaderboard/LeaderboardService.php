<?php

namespace App\Domain\Leaderboard;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard leaderboards — three top-5 boards:
 *   - Akzar Cash       : richest players by akzar_cash
 *   - Stored Oil       : most oil barrels hoarded (player.oil_barrels IS the
 *                        base vault; there's no separate storage column)
 *   - Stat Total       : sum of strength + fortification + stealth + security
 *
 * Design notes:
 *   - Bots are INCLUDED (per product decision) but never flagged as such in
 *     the payload. Callers get username + MDN tag; whether that row is a
 *     bot is opaque to the UI.
 *   - Immunity-era players are INCLUDED — no filtering on immunity_expires_at.
 *   - Results are memoised for 5 minutes via `Cache::remember`. For 100 users
 *     this is more than fast enough and keeps the dashboard query-light even
 *     under hot-reload spam. Any write that would meaningfully change the
 *     standings (purchase, raid, deposit) can call LeaderboardService::bust()
 *     to force-refresh, but the 5-min TTL is the primary freshness guarantee.
 *   - Returned payload embeds `player_id` on every row so the Vue layer can
 *     highlight the viewer's own entry without doing any ID crosswalk.
 */
class LeaderboardService
{
    private const CACHE_KEY = 'leaderboard:dashboard:v1';
    private const CACHE_TTL_SECONDS = 300;
    private const TOP_N = 5;

    /**
     * @return array{
     *     akzar_cash: list<array{rank:int, player_id:int, username:string, mdn_tag:?string, value:float}>,
     *     stored_oil: list<array{rank:int, player_id:int, username:string, mdn_tag:?string, value:int}>,
     *     stat_total: list<array{rank:int, player_id:int, username:string, mdn_tag:?string, value:int}>,
     * }
     */
    public function boards(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            fn () => [
                'akzar_cash' => $this->loadAkzarCash(),
                'stored_oil' => $this->loadStoredOil(),
                'stat_total' => $this->loadStatTotal(),
            ],
        );
    }

    /** Force-invalidate the cached boards. */
    public function bust(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return list<array{rank:int, player_id:int, username:string, mdn_tag:?string, value:float}>
     */
    private function loadAkzarCash(): array
    {
        $rows = $this->baseQuery()
            ->orderByDesc('players.akzar_cash')
            ->orderBy('players.id')
            ->limit(self::TOP_N)
            ->get([
                'players.id as player_id',
                'users.name as username',
                'mdns.tag as mdn_tag',
                'players.akzar_cash as value',
            ]);

        return $this->rank($rows, fn ($row) => (float) $row->value);
    }

    /**
     * @return list<array{rank:int, player_id:int, username:string, mdn_tag:?string, value:int}>
     */
    private function loadStoredOil(): array
    {
        $rows = $this->baseQuery()
            ->orderByDesc('players.oil_barrels')
            ->orderBy('players.id')
            ->limit(self::TOP_N)
            ->get([
                'players.id as player_id',
                'users.name as username',
                'mdns.tag as mdn_tag',
                'players.oil_barrels as value',
            ]);

        return $this->rank($rows, fn ($row) => (int) $row->value);
    }

    /**
     * @return list<array{rank:int, player_id:int, username:string, mdn_tag:?string, value:int}>
     */
    private function loadStatTotal(): array
    {
        // Derived column computed in-SQL so the ORDER BY / LIMIT runs
        // against the DB rather than pulling every player into PHP.
        $rows = $this->baseQuery()
            ->selectRaw('(players.strength + players.fortification + players.stealth + players.security) as value')
            ->orderByDesc('value')
            ->orderBy('players.id')
            ->limit(self::TOP_N)
            ->get([
                'players.id as player_id',
                'users.name as username',
                'mdns.tag as mdn_tag',
            ]);

        return $this->rank($rows, fn ($row) => (int) $row->value);
    }

    /**
     * Shared join spine for every board. Left-joins users (required,
     * for username) and mdns (optional, may be null) so a single query
     * returns everything the dashboard needs.
     */
    private function baseQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('players')
            ->join('users', 'users.id', '=', 'players.user_id')
            ->leftJoin('mdns', 'mdns.id', '=', 'players.mdn_id');
    }

    /**
     * Assign 1-based ranks and coerce value to the caller-specified type.
     *
     * @param  iterable<object>  $rows
     * @param  callable(object):int|float  $valueFn
     * @return list<array{rank:int, player_id:int, username:string, mdn_tag:?string, value:int|float}>
     */
    private function rank(iterable $rows, callable $valueFn): array
    {
        $result = [];
        $rank = 1;
        foreach ($rows as $row) {
            $result[] = [
                'rank' => $rank++,
                'player_id' => (int) $row->player_id,
                'username' => (string) $row->username,
                'mdn_tag' => $row->mdn_tag !== null ? (string) $row->mdn_tag : null,
                'value' => $valueFn($row),
            ];
        }

        return $result;
    }
}
