<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One loot crate sitting on a wasteland tile.
 *
 * placed_by_player_id NULL  → real crate (world-spawned)
 * placed_by_player_id SET   → sabotage crate (player-deployed)
 *
 * Rows persist past open so the Hostility Log can reconstruct sabotage
 * hits after the fact. "Active" crates are ones whose opened_at is
 * still null — see the `active` query scope.
 */
class TileLootCrate extends Model
{
    protected $fillable = [
        'tile_x',
        'tile_y',
        'placed_by_player_id',
        'device_key',
        'placed_at',
        'opened_at',
        'opened_by_player_id',
        'outcome',
    ];

    protected function casts(): array
    {
        return [
            'tile_x' => 'integer',
            'tile_y' => 'integer',
            'placed_at' => 'datetime',
            'opened_at' => 'datetime',
            'outcome' => 'array',
        ];
    }

    public function placedBy(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'placed_by_player_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'opened_by_player_id');
    }

    /**
     * A sabotage crate is one placed by a player (not world-spawned).
     */
    public function isSabotage(): bool
    {
        return $this->placed_by_player_id !== null;
    }

    /**
     * Scope: only crates that haven't been opened yet.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('opened_at');
    }
}
