<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stat post attached to a single Tile of type 'post'. One of:
 * strength, stealth, fort, tech, general, or auction (central endgame).
 *
 * Post sells items from items_catalog filtered by post_type. Players
 * must travel to the post tile to shop — no remote purchasing.
 */
class Post extends Model
{
    protected $fillable = [
        'tile_id',
        'post_type',
        'name',
    ];

    public function tile(): BelongsTo
    {
        return $this->belongsTo(Tile::class);
    }
}
