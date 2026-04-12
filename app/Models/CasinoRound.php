<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CasinoRound extends Model
{
    protected $fillable = [
        'casino_table_id',
        'game_type',
        'currency',
        'round_number',
        'state_snapshot',
        'rng_seed',
        'result_summary',
        'resolved_at',
    ];

    protected $casts = [
        'state_snapshot' => 'array',
        'result_summary' => 'array',
        'resolved_at' => 'datetime',
        'round_number' => 'integer',
    ];

    public function bets(): HasMany
    {
        return $this->hasMany(CasinoBet::class);
    }
}
