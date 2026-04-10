<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempted reconnaissance of another player's base.
 *
 * Successful spies unlock attack rights for the next
 * combat.spy_decay_hours (default 24). The per-depth intel grants
 * are spec'd in gameplay-ultraplan §8 but Phase 3 MVP only uses a
 * single depth level — we'll extend depth tracking when the deep-spy
 * flow lands.
 */
class SpyAttempt extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'spy_player_id',
        'target_player_id',
        'target_base_tile_id',
        'success',
        'detected',
        'rng_seed',
        'rng_output',
        'created_at',
    ];

    protected $casts = [
        'success' => 'boolean',
        'detected' => 'boolean',
        'rng_seed' => 'integer',
        'created_at' => 'datetime',
    ];

    public function spyPlayer(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'spy_player_id');
    }

    public function targetPlayer(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'target_player_id');
    }
}
