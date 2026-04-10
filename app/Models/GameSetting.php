<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Dynamic override for a key in config/game.php.
 *
 * Consumed by GameConfigResolver to layer admin-panel-edited values on top
 * of the shipped defaults without a deploy. Every write fires a paired row
 * in game_settings_audit for the admin change log.
 */
class GameSetting extends Model
{
    protected $table = 'game_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'value',
        'type',
        'description',
        'updated_by_user_id',
    ];

    protected $casts = [
        'value' => 'array',
    ];
}
