<?php

namespace App\Domain\Combat;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Exceptions\CannotAttackException;
use App\Domain\Exceptions\InsufficientMovesException;
use App\Domain\Mdn\MdnService;
use App\Domain\Player\MoveRegenService;
use App\Events\TileCombatResolved;
use App\Models\Player;
use App\Models\Tile;
use App\Models\TileCombat;
use Illuminate\Support\Facades\DB;

/**
 * Wasteland duels — spontaneous PvP on a shared wasteland tile.
 *
 * Gameplay contract:
 *   - Both players must be standing on the same tile, type='wasteland'
 *   - Attacker cannot fight themselves
 *   - Target cannot be under 48h new-player immunity
 *     (attacker CAN be immune and still initiate — one-way gate,
 *     same as base raids)
 *   - Same-MDN members cannot fight each other
 *   - 24h MDN hop cooldown applies same as base raids
 *   - Neither participant may have been involved in ANY tile combat
 *     on this same tile within combat.tile_duel.cooldown_hours (24h),
 *     as attacker OR defender — strictest cooldown shape per design
 *   - Costs actions.tile_combat.move_cost (default 5) moves, deducted
 *     from the INITIATOR win or lose
 *   - No spy requirement — encounters are spontaneous
 *
 * Resolution:
 *   - CombatFormula::resolveTileDuel() returns winner + upset-reward
 *     oil_pct in [0, combat.tile_duel.max_oil_loot_pct]
 *   - Winner takes floor(loser.oil_barrels * oil_pct) barrels
 *   - Loser's stock is the authoritative cap — concurrent writes
 *     can't produce negative balances
 *   - Move cost is always deducted from the attacker (they chose to
 *     engage — auto-defense is free)
 *
 * Persistence + notification:
 *   - One tile_combats row per resolved engagement (win or lose)
 *   - TileCombatResolved dispatched AFTER commit to defender's private
 *     channel — anonymous payload, Counter-Intel Dossier unmasks the
 *     attacker on /attack-log via AttackLogService::recentAttacks()
 *   - Broadcast failure never rolls back the fight (same as AttackService)
 */
class TileCombatService
{
    public function __construct(
        private readonly GameConfigResolver $config,
        private readonly MoveRegenService $moveRegen,
        private readonly CombatFormula $combat,
        private readonly MdnService $mdn,
        private readonly TileCombatEligibilityService $eligibility,
    ) {}

    /**
     * Start a fight between the given attacker and defender.
     *
     * @return array{
     *   outcome: 'attacker_win'|'defender_win',
     *   oil_stolen: int,
     *   tile_combat_id: int,
     *   moves_remaining: int,
     *   final_score: float,
     *   attacker_won: bool,
     *   attacker_oil_delta: int,
     *   defender_oil_delta: int,
     * }
     */
    public function engage(int $attackerPlayerId, int $defenderPlayerId): array
    {
        if ($attackerPlayerId === $defenderPlayerId) {
            throw CannotAttackException::cannotFightSelf();
        }

        $cost          = (int) $this->config->get('actions.tile_combat.move_cost');
        $cooldownHours = (int) $this->config->get('combat.tile_duel.cooldown_hours');
        $broadcastOn   = (bool) $this->config->get('notifications.broadcast_enabled');

        $result = DB::transaction(function () use ($attackerPlayerId, $defenderPlayerId, $cost, $cooldownHours) {

            // Lock both player rows in id-ascending order to avoid
            // deadlocks against concurrent engagements / base raids.
            // We lock each row individually so the returned model
            // references ARE the locked rows — a separate whereIn
            // lock followed by an unlocked re-fetch would give us
            // stale snapshots that the guard chain would operate on.
            [$lowId, $highId] = $attackerPlayerId < $defenderPlayerId
                ? [$attackerPlayerId, $defenderPlayerId]
                : [$defenderPlayerId, $attackerPlayerId];

            /** @var Player $firstLocked */
            $firstLocked = Player::query()->lockForUpdate()->findOrFail($lowId);
            /** @var Player $secondLocked */
            $secondLocked = Player::query()->lockForUpdate()->findOrFail($highId);

            /** @var Player $attacker */
            $attacker = $firstLocked->id === $attackerPlayerId ? $firstLocked : $secondLocked;
            /** @var Player $defender */
            $defender = $firstLocked->id === $defenderPlayerId ? $firstLocked : $secondLocked;

            $this->moveRegen->reconcile($attacker);
            $attacker->refresh();

            if ((int) $attacker->moves_current < $cost) {
                throw InsufficientMovesException::forAction('tile_combat', (int) $attacker->moves_current, $cost);
            }

            // Guard: both on the same tile
            if ($attacker->current_tile_id === null
                || (int) $attacker->current_tile_id !== (int) $defender->current_tile_id
            ) {
                throw CannotAttackException::notOnSameTile();
            }

            /** @var Tile $tile */
            $tile = Tile::query()->findOrFail($attacker->current_tile_id);

            // Guard: wasteland only
            if ($tile->type !== 'wasteland') {
                throw CannotAttackException::tileCombatRequiresWasteland($tile->type);
            }

            // Guard: defender immunity — one-way
            if ($defender->immunity_expires_at !== null && $defender->immunity_expires_at->isFuture()) {
                throw CannotAttackException::targetImmune();
            }

            // Guard: MDN rules (same-MDN block + hop cooldown). Delegates
            // to the shared gate used by base raids / spying so tuning
            // one layer tunes both.
            $this->mdn->assertCanAttackOrSpy($attacker, $defender, 'attack');

            // Guard: per-participant per-tile 24h cooldown — strictest
            // mode. Either side having fought on this exact tile within
            // the window blocks a new engagement.
            if ($this->eligibility->hasRecentCombatOnTile($attacker->id, $tile->id, $cooldownHours)) {
                throw CannotAttackException::tileCombatSelfCooldown($cooldownHours);
            }
            if ($this->eligibility->hasRecentCombatOnTile($defender->id, $tile->id, $cooldownHours)) {
                throw CannotAttackException::tileCombatTargetCooldown($cooldownHours);
            }

            // ------ Resolve via the pure formula ------
            // Event key carries a per-call nonce so two fights in the
            // same wall-clock second (even by the same pair) can never
            // seed an identical RNG sequence. Tests pass explicit
            // event keys and never rely on this format.
            $eventKey = 'tile-'.$attacker->id.'-'.$defender->id.'-'.$tile->id
                .'-'.now()->timestamp.'-'.random_int(100000, 999999);
            $res = $this->combat->resolveTileDuel($attacker, $defender, $eventKey);

            // ------ Oil transfer ------
            $attackerWon = $res['outcome'] === 'attacker_win';
            $winner = $attackerWon ? $attacker : $defender;
            $loser  = $attackerWon ? $defender : $attacker;

            $rawStolen = (int) floor(((int) $loser->oil_barrels) * (float) $res['oil_pct']);
            $oilStolen = max(0, min($rawStolen, (int) $loser->oil_barrels));

            if ($oilStolen > 0) {
                $loser->update(['oil_barrels' => (int) $loser->oil_barrels - $oilStolen]);
                $winner->update(['oil_barrels' => (int) $winner->oil_barrels + $oilStolen]);
            }

            // Move cost always comes off the attacker, win or lose.
            $attacker->update(['moves_current' => (int) $attacker->moves_current - $cost]);

            // ------ Persistence ------
            /** @var TileCombat $row */
            $row = TileCombat::create([
                'attacker_player_id' => $attacker->id,
                'defender_player_id' => $defender->id,
                'tile_id' => $tile->id,
                'outcome' => $res['outcome'],
                'oil_stolen' => $oilStolen,
                'final_score' => round((float) $res['final_score'], 4),
                'rng_seed' => (int) sprintf('%u', crc32($eventKey)),
                'rng_output' => (string) $res['final_score'],
                'created_at' => now(),
            ]);

            // Carry primitives out of the transaction for the post-
            // commit broadcast. Defender user id + tile coords are all
            // we need for the toast — attacker identity stays out of
            // the payload by design.
            return [
                'outcome' => $res['outcome'],
                'oil_stolen' => $oilStolen,
                'tile_combat_id' => (int) $row->id,
                'moves_remaining' => (int) $attacker->moves_current,
                'final_score' => (float) $res['final_score'],
                'attacker_won' => $attackerWon,
                'attacker_oil_delta' => $attackerWon ? $oilStolen : -$oilStolen,
                'defender_oil_delta' => $attackerWon ? -$oilStolen : $oilStolen,
                '_defender_user_id' => (int) $defender->user_id,
                '_tile_x' => (int) $tile->x,
                '_tile_y' => (int) $tile->y,
            ];
        });

        // Broadcast after commit so a Reverb failure never rolls back
        // the fight itself. Activity log row is written by the listener
        // registered in AppServiceProvider::boot().
        if ($broadcastOn) {
            TileCombatResolved::dispatch(
                $result['_defender_user_id'],
                $result['outcome'],
                $result['oil_stolen'],
                $result['tile_combat_id'],
                $result['_tile_x'],
                $result['_tile_y'],
            );
        }

        unset($result['_defender_user_id'], $result['_tile_x'], $result['_tile_y']);

        return $result;
    }
}
