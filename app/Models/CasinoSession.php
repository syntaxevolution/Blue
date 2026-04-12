<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CasinoSession extends Model
{
    protected $fillable = [
        'player_id',
        'entered_at',
        'expires_at',
        'fee_amount',
    ];

    protected $casts = [
        'entered_at' => 'datetime',
        'expires_at' => 'datetime',
        'fee_amount' => 'decimal:2',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function isActive(): bool
    {
        return $this->expires_at->isFuture();
    }
}
