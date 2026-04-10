<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit log of every game_settings write.
 *
 * One row per change (even if the value reverted to default), with the
 * before/after JSON and the admin user who made it.
 */
class GameSettingAudit extends Model
{
    protected $table = 'game_settings_audit';

    public $timestamps = false;

    protected $fillable = [
        'key',
        'old_value',
        'new_value',
        'changed_by_user_id',
        'changed_at',
    ];

    protected $casts = [
        'old_value' => 'array',
        'new_value' => 'array',
        'changed_at' => 'datetime',
    ];
}
