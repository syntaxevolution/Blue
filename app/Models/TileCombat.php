<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One resolved wasteland duel between two players.
 *
 * Populated by TileCombatService after a successful engage(). Queried
 * from AttackLogService::recentAttacks() behind the Counter-Intel
 * Dossier unlock, same gating as base raids and sabotage triggers.
 */
class TileCombat extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'attacker_player_id',
        'defender_player_id',
        'tile_id',
        'outcome',
        'oil_stolen',
        'final_score',
        'rng_seed',
        'rng_output',
        'created_at',
    ];

    protected $casts = [
        'oil_stolen' => 'integer',
        'final_score' => 'decimal:4',
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

    public function tile(): BelongsTo
    {
        return $this->belongsTo(Tile::class, 'tile_id');
    }
}
