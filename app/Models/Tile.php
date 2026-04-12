<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One grid cell in the world of Akzar.
 *
 * Tiles are the atomic unit of the map: every player base, oil field,
 * shop post, landmark, and wasteland square is a row here. Keyed by
 * (x, y) with a unique composite index — coordinates are authoritative,
 * the id is just a convenience FK target.
 *
 * All game logic touching tiles lives in WorldService — this model is
 * shape-only.
 */
class Tile extends Model
{
    protected $fillable = [
        'x',
        'y',
        'type',
        'subtype',
        'flavor_text',
        'seed',
    ];

    protected $casts = [
        'x' => 'integer',
        'y' => 'integer',
        'seed' => 'integer',
    ];

    public function oilField(): HasOne
    {
        return $this->hasOne(OilField::class);
    }

    public function post(): HasOne
    {
        return $this->hasOne(Post::class);
    }

    public function casino(): HasOne
    {
        return $this->hasOne(Casino::class);
    }
}
