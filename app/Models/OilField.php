<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A drillable oil field attached to a single Tile of type 'oil_field'.
 *
 * Contains a drill_grid_rows × drill_grid_cols (default 5×5) sub-grid
 * of DrillPoint rows. Regen is lazy: when DrillService marks the last
 * undrilled cell as drilled, it sets `depleted_at = now()`. On the next
 * read (drill or map state build), OilFieldRegenService checks whether
 * `depleted_at + drilling.field_refill_hours` is in the past and, if so,
 * resets every drill point's `drilled_at` to null and clears `depleted_at`.
 */
class OilField extends Model
{
    protected $fillable = [
        'tile_id',
        'drill_grid_rows',
        'drill_grid_cols',
        'last_regen_at',
        'depleted_at',
    ];

    protected $casts = [
        'drill_grid_rows' => 'integer',
        'drill_grid_cols' => 'integer',
        'last_regen_at' => 'datetime',
        'depleted_at' => 'datetime',
    ];

    public function tile(): BelongsTo
    {
        return $this->belongsTo(Tile::class);
    }

    public function drillPoints(): HasMany
    {
        return $this->hasMany(DrillPoint::class);
    }
}
