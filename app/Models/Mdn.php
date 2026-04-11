<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Mutual Defense Network — the game's guild/alliance primitive.
 *
 * Shape-only model. All lifecycle logic (create/join/leave/kick/promote/
 * disband) lives in App\Domain\Mdn\MdnService. Same-MDN attack blocking
 * and the 24h hop cooldown are enforced via
 * MdnService::assertCanAttackOrSpy() from AttackService and SpyService.
 */
class Mdn extends Model
{
    protected $fillable = [
        'name',
        'tag',
        'leader_player_id',
        'member_count',
        'motto',
    ];

    protected $casts = [
        'member_count' => 'integer',
    ];

    public function leader(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'leader_player_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(MdnMembership::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(Player::class, 'mdn_id');
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(MdnJournalEntry::class);
    }

    public function alliancesAsA(): HasMany
    {
        return $this->hasMany(MdnAlliance::class, 'mdn_a_id');
    }

    public function alliancesAsB(): HasMany
    {
        return $this->hasMany(MdnAlliance::class, 'mdn_b_id');
    }
}
