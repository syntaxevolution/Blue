<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One resolved raid on another player's base.
 *
 * Populated by AttackService. Queried by AttackLogController when a
 * defender views their attack log — that view is gated behind the
 * high-cost "Counter-Intel Dossier" item purchased at the Fort post,
 * so only players who've invested in security can see who hit them.
 */
class Attack extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'attacker_player_id',
        'defender_player_id',
        'defender_base_tile_id',
        'relied_on_spy_id',
        'outcome',
        'cash_stolen',
        'attacker_escape',
        'rng_seed',
        'rng_output',
        'created_at',
    ];

    protected $casts = [
        'cash_stolen' => 'decimal:2',
        'attacker_escape' => 'boolean',
        'rng_seed' => 'integer',
        'created_at' => 'datetime',
    ];

    public function attacker(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'attacker_player_id');
    }

    public function defender(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'defender_player_id');
    }
}
