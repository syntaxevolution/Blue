<?php

namespace App\Domain\Combat;

use App\Domain\Config\GameConfigResolver;
use App\Models\Player;
use App\Models\Tile;
use App\Models\TileCombat;

/**
 * Read-only helper that answers "can player A fight player B on this
 * wasteland tile right now?" without actually starting a fight.
 *
 * Shared by:
 *   - MapStateBuilder (renders the occupants list with can_fight flags
 *     so the UI can disable Fight buttons preemptively)
 *   - BotDecisionService::maybeOpportunisticTileCombat() (skips targets
 *     that would fail the service's own guard chain)
 *
 * TileCombatService also runs the same rule-set inline inside its
 * transaction — the eligibility service is a pre-check, NOT a
 * substitute for the authoritative guard. Locking + move cost + RNG
 * stay in the service.
 *
 * Returns a `['ok' => bool, 'reason' => ?string]` struct so callers
 * can either check the flag or surface the reason directly.
 */
class TileCombatEligibilityService
{
    public function __construct(
        private readonly GameConfigResolver $config,
    ) {}

    /**
     * @return array{ok:bool, reason:string|null}
     */
    public function canFight(Player $attacker, Player $defender, Tile $tile): array
    {
        if ($attacker->id === $defender->id) {
            return ['ok' => false, 'reason' => 'self'];
        }

        if ($tile->type !== 'wasteland') {
            return ['ok' => false, 'reason' => 'not_wasteland'];
        }

        if ((int) ($attacker->current_tile_id ?? 0) !== (int) $tile->id) {
            return ['ok' => false, 'reason' => 'not_on_tile'];
        }

        if ((int) ($defender->current_tile_id ?? 0) !== (int) $tile->id) {
            return ['ok' => false, 'reason' => 'target_left'];
        }

        // Defender immunity — one-way gate. Immune attackers CAN
        // initiate (matches existing base-raid semantics); only the
        // defender side is protected.
        if ($defender->immunity_expires_at !== null && $defender->immunity_expires_at->isFuture()) {
            return ['ok' => false, 'reason' => 'target_immune'];
        }

        // Same-MDN block. Uses the same config key as base raids so
        // the admin panel flips both layers in one toggle.
        $sameMdnBlocked = (bool) $this->config->get('mdn.same_mdn_attacks_blocked', true);
        if ($sameMdnBlocked
            && $attacker->mdn_id !== null
            && $defender->mdn_id !== null
            && (int) $attacker->mdn_id === (int) $defender->mdn_id
        ) {
            return ['ok' => false, 'reason' => 'same_mdn'];
        }

        // MDN hop cooldown — defensive reuse of the raid rule so a
        // player who just left an MDN can't immediately tile-fight
        // their former allies.
        $hopCooldown = (int) $this->config->get('mdn.join_leave_cooldown_hours', 24);
        if ($hopCooldown > 0) {
            $mostRecent = null;
            if ($attacker->mdn_joined_at !== null) {
                $mostRecent = $attacker->mdn_joined_at;
            }
            if ($attacker->mdn_left_at !== null
                && ($mostRecent === null || $attacker->mdn_left_at->gt($mostRecent))
            ) {
                $mostRecent = $attacker->mdn_left_at;
            }
            if ($mostRecent !== null) {
                $unlockAt = $mostRecent->copy()->addHours($hopCooldown);
                if ($unlockAt->isFuture()) {
                    return ['ok' => false, 'reason' => 'mdn_hop_cooldown'];
                }
            }
        }

        // Per-participant per-tile 24h cooldown.
        $cooldownHours = (int) $this->config->get('combat.tile_duel.cooldown_hours', 24);

        if ($this->hasRecentCombatOnTile($attacker->id, $tile->id, $cooldownHours)) {
            return ['ok' => false, 'reason' => 'self_cooldown'];
        }
        if ($this->hasRecentCombatOnTile($defender->id, $tile->id, $cooldownHours)) {
            return ['ok' => false, 'reason' => 'target_cooldown'];
        }

        return ['ok' => true, 'reason' => null];
    }

    /**
     * True if the given player was involved in ANY tile combat on
     * the given tile within the cooldown window — as attacker OR
     * defender. This is the "strictest" cooldown mode the user
     * chose in the design phase.
     */
    public function hasRecentCombatOnTile(int $playerId, int $tileId, int $hours): bool
    {
        if ($hours <= 0) {
            return false;
        }

        return TileCombat::query()
            ->where('tile_id', $tileId)
            ->where('created_at', '>=', now()->subHours($hours))
            ->where(function ($q) use ($playerId) {
                $q->where('attacker_player_id', $playerId)
                  ->orWhere('defender_player_id', $playerId);
            })
            ->exists();
    }

    /**
     * Human-readable explanation for a `reason` slug. Used by the
     * UI tooltip on disabled Fight buttons so the player knows WHY
     * a target is off-limits.
     */
    public function reasonLabel(?string $reason, int $cooldownHours): string
    {
        return match ($reason) {
            null => '',
            'self' => 'You cannot fight yourself',
            'not_wasteland' => 'Tile combat only resolves on wasteland',
            'not_on_tile' => 'You must be standing on the tile',
            'target_left' => 'Target already walked away',
            'target_immune' => 'Target is under new-player immunity',
            'same_mdn' => 'Fellow MDN member — combat blocked',
            'mdn_hop_cooldown' => 'Recent MDN change — combat locked',
            'self_cooldown' => "You already fought on this tile in the last {$cooldownHours}h",
            'target_cooldown' => 'Target is catching their breath on this tile',
            default => ucfirst(str_replace('_', ' ', $reason)),
        };
    }
}
