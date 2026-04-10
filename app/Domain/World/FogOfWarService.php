<?php

namespace App\Domain\World;

use App\Models\Tile;
use Illuminate\Support\Facades\DB;

/**
 * Per-player tile visibility service.
 *
 * Every tile a player has ever stood on, plus any tiles revealed by
 * Paper Map items, lives in tile_discoveries keyed by (player_id, tile_id).
 * Composite PK makes re-marking the same tile a free no-op via upsert.
 *
 * The domain uses DB::table directly rather than an Eloquent model because
 * the tech plan calls for a composite PK without an id column, and nothing
 * outside this service needs to reference individual discovery rows. If
 * that changes we can add a TileDiscovery model later without touching
 * callers.
 *
 * A Redis set cache (`discovered:{player_id}` → tile IDs) will slot in
 * later per technical-ultraplan §9.4 — the public API stays the same.
 */
class FogOfWarService
{
    /**
     * Mark a single tile as discovered for a player. Idempotent —
     * re-marking the same tile updates nothing.
     */
    public function markDiscovered(int $playerId, int $tileId): void
    {
        $this->markDiscoveredMany($playerId, [$tileId]);
    }

    /**
     * Bulk-mark multiple tiles as discovered. Idempotent via
     * INSERT IGNORE — duplicate (player_id, tile_id) rows are silently
     * skipped and the original discovered_at is preserved.
     *
     * (Note: Laravel's query-builder upsert() falls back to a plain
     * insert() when the update list is empty, which then throws on
     * duplicate keys — insertOrIgnore is the correct "do nothing on
     * conflict" primitive.)
     *
     * Returns the number of rows newly inserted.
     *
     * @param  list<int>  $tileIds
     */
    public function markDiscoveredMany(int $playerId, array $tileIds): int
    {
        if ($tileIds === []) {
            return 0;
        }

        $now = now();
        $rows = array_map(fn (int $id) => [
            'player_id' => $playerId,
            'tile_id' => $id,
            'discovered_at' => $now,
        ], array_values(array_unique($tileIds)));

        return DB::table('tile_discoveries')->insertOrIgnore($rows);
    }

    /**
     * Reveal every tile within $radius of $centerTileId for a player.
     * Used by Paper Map I/II/III items.
     *
     * Returns the number of (possibly new) rows touched by the upsert.
     */
    public function revealRadius(int $playerId, int $centerTileId, int $radius): int
    {
        $center = Tile::findOrFail($centerTileId);

        $revealedIds = Tile::query()
            ->whereRaw(
                '((x - ?) * (x - ?) + (y - ?) * (y - ?)) <= ?',
                [$center->x, $center->x, $center->y, $center->y, $radius * $radius],
            )
            ->pluck('id')
            ->all();

        return $this->markDiscoveredMany($playerId, $revealedIds);
    }

    /**
     * @return list<int>  Tile IDs a player has discovered, ordered by id.
     */
    public function getDiscoveredTileIds(int $playerId): array
    {
        return DB::table('tile_discoveries')
            ->where('player_id', $playerId)
            ->orderBy('tile_id')
            ->pluck('tile_id')
            ->all();
    }

    public function hasDiscovered(int $playerId, int $tileId): bool
    {
        return DB::table('tile_discoveries')
            ->where('player_id', $playerId)
            ->where('tile_id', $tileId)
            ->exists();
    }

    public function countDiscovered(int $playerId): int
    {
        return DB::table('tile_discoveries')
            ->where('player_id', $playerId)
            ->count();
    }
}
