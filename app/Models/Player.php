<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Gameplay-side shadow of a User account.
 *
 * Holds every piece of mutable in-game state for one player: currencies
 * (akzar_cash, oil_barrels, intel), move budget, four stats, drill tier,
 * MDN membership, immunity window, transport mode, stat bank overflow,
 * and the broken-item lockout pointer.
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
        'strength_banked',
        'fortification_banked',
        'stealth_banked',
        'security_banked',
        'drill_tier',
        'active_transport',
        'broken_item_key',
        'hostility_log_last_viewed_at',
        'mdn_id',
        'mdn_joined_at',
        'mdn_left_at',
        'immunity_expires_at',
        'last_bankruptcy_at',
        'bot_difficulty',
        'bot_last_tick_at',
        'bot_moves_budget',
        'bot_current_goal',
        'bot_goal_expires_at',
        'bot_goal_fail_count',
        'bot_consecutive_drill_count',
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
        'strength_banked' => 'integer',
        'fortification_banked' => 'integer',
        'stealth_banked' => 'integer',
        'security_banked' => 'integer',
        'drill_tier' => 'integer',
        'hostility_log_last_viewed_at' => 'datetime',
        'mdn_joined_at' => 'datetime',
        'mdn_left_at' => 'datetime',
        'immunity_expires_at' => 'datetime',
        'last_bankruptcy_at' => 'datetime',
        'bot_last_tick_at' => 'datetime',
        'bot_moves_budget' => 'integer',
        'bot_current_goal' => 'array',
        'bot_goal_expires_at' => 'datetime',
        'bot_goal_fail_count' => 'integer',
        'bot_consecutive_drill_count' => 'integer',
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

    public function items(): HasMany
    {
        return $this->hasMany(PlayerItem::class);
    }

    public function mdn(): BelongsTo
    {
        return $this->belongsTo(Mdn::class);
    }

    public function mdnMembership(): HasOne
    {
        return $this->hasOne(MdnMembership::class);
    }

    /**
     * Is this player a bot? Read from the owning User row.
     */
    public function isBot(): bool
    {
        return (bool) ($this->user?->is_bot ?? false);
    }

    /**
     * Is this player standing on their own base tile right now?
     * Used by CombatFormula for the at-base defense bonus and
     * elsewhere anywhere the "at home" check matters.
     */
    public function isAtBase(): bool
    {
        return $this->current_tile_id !== null
            && $this->current_tile_id === $this->base_tile_id;
    }

    public function hasBrokenItem(): bool
    {
        return $this->broken_item_key !== null;
    }
}
