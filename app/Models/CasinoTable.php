<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CasinoTable extends Model
{
    protected $fillable = [
        'game_type',
        'currency',
        'label',
        'min_bet',
        'max_bet',
        'seats',
        'status',
        'state_json',
        'round_number',
        'round_started_at',
        'round_expires_at',
    ];

    protected $casts = [
        'state_json' => 'array',
        'min_bet' => 'decimal:2',
        'max_bet' => 'decimal:2',
        'seats' => 'integer',
        'round_number' => 'integer',
        'round_started_at' => 'datetime',
        'round_expires_at' => 'datetime',
    ];

    public function players(): HasMany
    {
        return $this->hasMany(CasinoTablePlayer::class);
    }

    public function activePlayers(): HasMany
    {
        return $this->hasMany(CasinoTablePlayer::class)->where('status', 'active');
    }

    public function rounds(): HasMany
    {
        return $this->hasMany(CasinoRound::class);
    }
}
