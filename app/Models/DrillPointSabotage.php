<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sabotage device planted on a specific drill point.
 *
 * Rows persist after trigger (updated in place with outcome + siphoned
 * barrels) so the attack log, activity log, and RNG audit trail can
 * reconstruct incidents after the fact. "Active" traps are ones whose
 * triggered_at is still null — see the `active` query scope.
 */
class DrillPointSabotage extends Model
{
    protected $fillable = [
        'drill_point_id',
        'oil_field_id',
        'device_key',
        'placed_by_player_id',
        'placed_at',
        'triggered_at',
        'triggered_by_player_id',
        'outcome',
        'siphoned_barrels',
    ];

    protected $casts = [
        'placed_at' => 'datetime',
        'triggered_at' => 'datetime',
        'siphoned_barrels' => 'integer',
    ];

    public function drillPoint(): BelongsTo
    {
        return $this->belongsTo(DrillPoint::class);
    }

    public function oilField(): BelongsTo
    {
        return $this->belongsTo(OilField::class);
    }

    public function placedBy(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'placed_by_player_id');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'triggered_by_player_id');
    }

    /**
     * Scope: only traps that haven't fired yet.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('triggered_at');
    }
}
