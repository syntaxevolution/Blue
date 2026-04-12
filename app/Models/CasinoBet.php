<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CasinoBet extends Model
{
    protected $fillable = [
        'casino_round_id',
        'player_id',
        'bet_type',
        'amount',
        'payout',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payout' => 'decimal:2',
        'net' => 'decimal:2',
    ];

    public function round(): BelongsTo
    {
        return $this->belongsTo(CasinoRound::class, 'casino_round_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
