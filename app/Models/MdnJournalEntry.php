<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MdnJournalEntry extends Model
{
    protected $fillable = [
        'mdn_id',
        'author_player_id',
        'tile_id',
        'body',
        'helpful_count',
        'unhelpful_count',
    ];

    protected $casts = [
        'helpful_count' => 'integer',
        'unhelpful_count' => 'integer',
    ];

    public function mdn(): BelongsTo
    {
        return $this->belongsTo(Mdn::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'author_player_id');
    }

    public function tile(): BelongsTo
    {
        return $this->belongsTo(Tile::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(MdnJournalVote::class, 'entry_id');
    }
}
