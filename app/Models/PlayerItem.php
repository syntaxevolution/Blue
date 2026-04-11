<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ownership row: which items a player owns, how many, and what
 * their lifecycle state is (active vs broken).
 *
 * We add a broken state here rather than deleting the row so the
 * repair path can cleanly restore the item without re-issuing it.
 */
class PlayerItem extends Model
{
    protected $fillable = [
        'player_id',
        'item_key',
        'quantity',
        'status',
        'broken_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'broken_at' => 'datetime',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_key', 'key');
    }
}
