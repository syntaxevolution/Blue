<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A drillable oil field attached to a single Tile of type 'oil_field'.
 *
 * Contains a drill_grid_rows × drill_grid_cols (default 5×5) sub-grid
 * of DrillPoint rows. Point qualities are reshuffled by
 * OilFieldRegenJob every `drilling.drill_point_regen_hours` config hours.
 */
class OilField extends Model
{
    protected $fillable = [
        'tile_id',
        'drill_grid_rows',
        'drill_grid_cols',
        'last_regen_at',
    ];

    protected $casts = [
        'drill_grid_rows' => 'integer',
        'drill_grid_cols' => 'integer',
        'last_regen_at' => 'datetime',
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
