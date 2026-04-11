<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Declarative alliance between two MDNs. Purely informational:
 * config/game.php `mdn.formal_alliances_prevent_attacks` is false by
 * default, so allied MDNs can still raid each other. The table exists
 * so UI can display standing relationships and future "MDN warfare"
 * bonus-cash mechanics have a foothold.
 */
class MdnAlliance extends Model
{
    protected $fillable = [
        'mdn_a_id',
        'mdn_b_id',
        'declared_at',
    ];

    protected $casts = [
        'declared_at' => 'datetime',
    ];

    public function mdnA(): BelongsTo
    {
        return $this->belongsTo(Mdn::class, 'mdn_a_id');
    }

    public function mdnB(): BelongsTo
    {
        return $this->belongsTo(Mdn::class, 'mdn_b_id');
    }
}
