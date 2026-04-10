<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of the 25 drill points inside an OilField (5×5 sub-grid).
 *
 * Quality is rolled via RngService against `drilling.quality_weights`
 * config and reshuffled every regen window. `drilled_at` marks a point
 * as depleted until the next regen tick clears it.
 */
class DrillPoint extends Model
{
    protected $fillable = [
        'oil_field_id',
        'grid_x',
        'grid_y',
        'quality',
        'drilled_at',
    ];

    protected $casts = [
        'grid_x' => 'integer',
        'grid_y' => 'integer',
        'drilled_at' => 'datetime',
    ];

    public function oilField(): BelongsTo
    {
        return $this->belongsTo(OilField::class);
    }
}
