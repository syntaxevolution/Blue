<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A persistent notification row. Written by listeners responding to
 * broadcast events (BaseUnderAttack, SpyDetected, RaidCompleted, ...)
 * so players can scroll back through what they missed offline.
 */
class ActivityLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'body',
        'read_at',
        'created_at',
    ];

    protected $casts = [
        'body' => 'array',
        'read_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
