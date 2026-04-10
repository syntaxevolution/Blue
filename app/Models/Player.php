<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gameplay-side shadow of a User account.
 *
 * Holds every piece of mutable in-game state for one player: currencies
 * (akzar_cash, oil_barrels, intel), move budget, four stats, drill tier,
 * MDN membership, immunity window, and bankruptcy history.
 *
 * One User has at most one Player (see User::player()). The split lets
 * us keep authentication concerns in User and gameplay state here.
 * Model is shape-only — all game logic lives in PlayerService and other
 * domain services.
 */
class Player extends Model
{
    protected $fillable = [
        'user_id',
        'base_tile_id',
        'current_tile_id',
        'akzar_cash',
        'oil_barrels',
        'intel',
        'moves_current',
        'moves_updated_at',
        'sponsor_moves_used_this_cycle',
        'strength',
        'fortification',
        'stealth',
        'security',
        'drill_tier',
        'mdn_id',
        'mdn_joined_at',
        'mdn_left_at',
        'immunity_expires_at',
        'last_bankruptcy_at',
    ];

    protected $casts = [
        'akzar_cash' => 'decimal:2',
        'oil_barrels' => 'integer',
        'intel' => 'integer',
        'moves_current' => 'integer',
        'moves_updated_at' => 'datetime',
        'sponsor_moves_used_this_cycle' => 'integer',
        'strength' => 'integer',
        'fortification' => 'integer',
        'stealth' => 'integer',
        'security' => 'integer',
        'drill_tier' => 'integer',
        'mdn_joined_at' => 'datetime',
        'mdn_left_at' => 'datetime',
        'immunity_expires_at' => 'datetime',
        'last_bankruptcy_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function baseTile(): BelongsTo
    {
        return $this->belongsTo(Tile::class, 'base_tile_id');
    }

    public function currentTile(): BelongsTo
    {
        return $this->belongsTo(Tile::class, 'current_tile_id');
    }
}
