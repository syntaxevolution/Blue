<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Join row for an MDN. Composite PK (mdn_id, player_id) plus a unique
 * index on player_id alone — a player belongs to at most one MDN.
 */
class MdnMembership extends Model
{
    public $incrementing = false;

    protected $primaryKey = null;

    public $timestamps = false;

    protected $fillable = [
        'mdn_id',
        'player_id',
        'role',
        'joined_at',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
    ];

    public function mdn(): BelongsTo
    {
        return $this->belongsTo(Mdn::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
