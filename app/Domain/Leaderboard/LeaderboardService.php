<?php

namespace App\Domain\Leaderboard;

use App\Models\Player;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard leaderboards — three boards:
 *   - Akzar Cash       : richest players by akzar_cash
 *   - Stored Oil       : most oil barrels hoarded (player.oil_barrels IS the
 *                        base vault; there's no separate storage column)
 *   - Stat Total       : sum of strength + fortification + stealth + security
 *
 * Each board is returned as { top: [top N rows], viewer: {row}|null }.
 * The `top` list is cached for 5 minutes because it doesn't change often
 * and serves every player. The `viewer` row is computed UNCACHED per
 * request so a player who just earned/spent cash sees their own rank
 * update immediately, even though the top-5 snapshot is stale.
 *
 * Design notes:
 *   - Bots are INCLUDED (per product decision) but never flagged as such
 *     in the payload — callers get username + MDN tag only.
 *   - Immunity-era players are INCLUDED — no filtering on immunity_expires_at.
 *   - Ties are broken deterministically by players.id ASC in both the
 *     cached top-N query AND the viewer-rank counting query, so the rank
 *     computation is stable and consistent across the two code paths.
 *   - Write paths (purchase, raid, deposit) can call bust() to force
 *     refresh of the top-N between TTL windows.
 */
class LeaderboardService
{
    // v2: bumped after fixing the stat_total column-merge bug and
    // restructuring boards() to return { top, viewer } per board.
    // Orphans any v1 cache holding the old broken row shape.
    private const CACHE_KEY = 'leaderboard:dashboard:v2';
    private const CACHE_TTL_SECONDS = 300;
    private const TOP_N = 5;

    /**
     * @return array{
     *     akzar_cash: array{top: list<array<string,mixed>>, viewer: ?array<string,mixed>},
     *     stored_oil: array{top: list<array<string,mixed>>, viewer: ?array<string,mixed>},
     *     stat_total: array{top: list<array<string,mixed>>, viewer: ?array<string,mixed>},
     * }
     */
    public function boards(?Player $viewer = null): array
    {
        $cached = Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            fn () => [
                'akzar_cash' => $this->loadAkzarCash(),
                'stored_oil' => $this->loadStoredOil(),
                'stat_total' => $this->loadStatTotal(),
            ],
        );

        return [
            'akzar_cash' => [
                'top' => $cached['akzar_cash'],
                'viewer' => $viewer ? $this->viewerRowAkzarCash($viewer, $cached['akzar_cash']) : null,
            ],
            'stored_oil' => [
                'top' => $cached['stored_oil'],
                'viewer' => $viewer ? $this->viewerRowStoredOil($viewer, $cached['stored_oil']) : null,
            ],
            'stat_total' => [
                'top' => $cached['stat_total'],
                'viewer' => $viewer ? $this->viewerRowStatTotal($viewer, $cached['stat_total']) : null,
            ],
        ];
    }

    /** Force-invalidate the cached top-N snapshots. */
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
        // IMPORTANT: the derived column MUST be passed inside get([...]).
        // Laravel's ->get($columns) temporarily replaces the select list,
        // so a separate ->selectRaw() call before ->get() is silently
        // discarded. Merging it into the column array keeps the alias.
        $rows = $this->baseQuery()
            ->orderByDesc(new Expression('(players.strength + players.fortification + players.stealth + players.security)'))
            ->orderBy('players.id')
            ->limit(self::TOP_N)
            ->get([
                'players.id as player_id',
                'users.name as username',
                'mdns.tag as mdn_tag',
                new Expression('(players.strength + players.fortification + players.stealth + players.security) as value'),
            ]);

        return $this->rank($rows, fn ($row) => (int) $row->value);
    }

    /**
     * Returns the viewer's row on the Akzar Cash board — or null if they
     * already appear in the cached top-N (the Vue layer highlights that
     * existing row instead).
     *
     * @param  list<array<string,mixed>>  $top
     * @return ?array{rank:int, player_id:int, username:string, mdn_tag:?string, value:float}
     */
    private function viewerRowAkzarCash(Player $viewer, array $top): ?array
    {
        if ($this->viewerInTop($viewer, $top)) {
            return null;
        }

        $value = (float) $viewer->akzar_cash;
        $rank = $this->rankByScalar('players.akzar_cash', $value, (int) $viewer->id);

        return $this->viewerPayload($viewer, $rank, $value);
    }

    /**
     * @param  list<array<string,mixed>>  $top
     * @return ?array{rank:int, player_id:int, username:string, mdn_tag:?string, value:int}
     */
    private function viewerRowStoredOil(Player $viewer, array $top): ?array
    {
        if ($this->viewerInTop($viewer, $top)) {
            return null;
        }

        $value = (int) $viewer->oil_barrels;
        $rank = $this->rankByScalar('players.oil_barrels', $value, (int) $viewer->id);

        return $this->viewerPayload($viewer, $rank, $value);
    }

    /**
     * @param  list<array<string,mixed>>  $top
     * @return ?array{rank:int, player_id:int, username:string, mdn_tag:?string, value:int}
     */
    private function viewerRowStatTotal(Player $viewer, array $top): ?array
    {
        if ($this->viewerInTop($viewer, $top)) {
            return null;
        }

        $value = (int) $viewer->strength
            + (int) $viewer->fortification
            + (int) $viewer->stealth
            + (int) $viewer->security;

        // Tiebreaker-aware rank count via a raw expression on the derived sum.
        $derivedExpr = new Expression('(players.strength + players.fortification + players.stealth + players.security)');
        $rank = 1 + (int) DB::table('players')
            ->where(function ($q) use ($derivedExpr, $value, $viewer) {
                $q->where($derivedExpr, '>', $value)
                    ->orWhere(function ($q2) use ($derivedExpr, $value, $viewer) {
                        $q2->where($derivedExpr, '=', $value)
                            ->where('players.id', '<', (int) $viewer->id);
                    });
            })
            ->count();

        return $this->viewerPayload($viewer, $rank, $value);
    }

    /**
     * Compute "how many players rank above $viewer" for a simple column
     * metric, using the same (value DESC, id ASC) tiebreak the cached
     * queries use. The final rank is that count + 1.
     */
    private function rankByScalar(string $column, float|int $value, int $viewerId): int
    {
        return 1 + (int) DB::table('players')
            ->where(function ($q) use ($column, $value, $viewerId) {
                $q->where($column, '>', $value)
                    ->orWhere(function ($q2) use ($column, $value, $viewerId) {
                        $q2->where($column, '=', $value)
                            ->where('players.id', '<', $viewerId);
                    });
            })
            ->count();
    }

    /**
     * @param  list<array<string,mixed>>  $top
     */
    private function viewerInTop(Player $viewer, array $top): bool
    {
        foreach ($top as $row) {
            if ((int) $row['player_id'] === (int) $viewer->id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the viewer-row payload, loading the needed relationships
     * lazily so callers that skip the viewer branch don't pay for them.
     */
    private function viewerPayload(Player $viewer, int $rank, float|int $value): array
    {
        $viewer->loadMissing(['user:id,name', 'mdn:id,tag']);

        return [
            'rank' => $rank,
            'player_id' => (int) $viewer->id,
            'username' => (string) ($viewer->user->name ?? ''),
            'mdn_tag' => $viewer->mdn?->tag,
            'value' => $value,
        ];
    }

    /**
     * Shared join spine for every cached top-N query. Joins users
     * (required, for username) and mdns (optional, may be null) so a
     * single query returns everything the dashboard needs.
     */
    private function baseQuery(): Builder
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
