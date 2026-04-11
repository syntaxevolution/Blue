<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MdnJournalVote extends Model
{
    protected $fillable = [
        'entry_id',
        'player_id',
        'vote',
    ];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(MdnJournalEntry::class, 'entry_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
