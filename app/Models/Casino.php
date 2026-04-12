<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Casino extends Model
{
    protected $fillable = [
        'tile_id',
        'name',
    ];

    public function tile(): BelongsTo
    {
        return $this->belongsTo(Tile::class);
    }
}
