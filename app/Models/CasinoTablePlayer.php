<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CasinoTablePlayer extends Model
{
    protected $fillable = [
        'casino_table_id',
        'player_id',
        'seat_number',
        'stack',
        'status',
        'joined_at',
        'last_action_at',
    ];

    protected $casts = [
        'stack' => 'decimal:2',
        'seat_number' => 'integer',
        'joined_at' => 'datetime',
        'last_action_at' => 'datetime',
    ];

    public function table(): BelongsTo
    {
        return $this->belongsTo(CasinoTable::class, 'casino_table_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
