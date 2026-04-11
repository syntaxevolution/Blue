<?php

namespace App\Domain\Combat;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Exceptions\CannotAttackException;
use App\Domain\Exceptions\InsufficientMovesException;
use App\Domain\Player\MoveRegenService;
use App\Events\BaseUnderAttack;
use App\Models\Attack;
use App\Models\Player;
use App\Models\SpyAttempt;
use App\Models\Tile;
use Illuminate\Support\Facades\DB;

/**
 * Raid another player's base.
 *
 * Gameplay contract:
 *   - Player must be on a tile of type 'base' that isn't their own
 *   - Target must exist and not be under new-player immunity
 *   - Player must have a successful spy attempt on the target within
 *     combat.spy_decay_hours (default 24)
 *   - Player must not have attacked this same target within
 *     combat.raid_cooldown_hours (default 12)
 *   - Costs actions.attack.move_cost (default 5) moves
 *   - Combat resolves via CombatFormula. If the defender is physically
 *     standing on their base at the moment of the attack AND the config
 *     flag combat.at_base_defense_bonus_enabled is true, their scaled
 *     strength is added to their defense (F2 — "be home to be safer").
 *   - On success, loot is capped at combat.loot_ceiling_pct (default 20%)
 *     of the defender's cash.
 *   - An attacks row is always recorded — the defender's attack log
 *     captures both successes and failures.
 *   - Immediately after commit, BaseUnderAttack + RaidCompleted are
 *     dispatched to the defender's private channel for toast + activity
 *     log. Dispatched OUTSIDE the transaction so a broadcast failure
 *     cannot roll back the raid.
 *
 * Defender's bankruptcy pity stipend is NOT handled here yet — that
 * fires on the next daily tick when BankruptcyService runs.
 */
class AttackService
{
    public function __construct(
        private readonly GameConfigResolver $config,
        private readonly MoveRegenService $moveRegen,
        private readonly CombatFormula $combat,
    ) {}

    /**
     * @return array{
     *     outcome: string,
     *     cash_stolen: float,
     *     attack_id: int,
     *     moves_remaining: int,
     *     final_score: float,
     *     defender_at_base: bool,
     * }
     */
    public function attack(int $attackerPlayerId): array
    {
        $cost = (int) $this->config->get('actions.attack.move_cost');
        $spyDecayHours = (int) $this->config->get('combat.spy_decay_hours');
        $raidCooldownHours = (int) $this->config->get('combat.raid_cooldown_hours');

        $result = DB::transaction(function () use ($attackerPlayerId, $cost, $spyDecayHours, $raidCooldownHours) {
            /** @var Player $attacker */
            $attacker = Player::query()->lockForUpdate()->findOrFail($attackerPlayerId);

            $this->moveRegen->reconcile($attacker);
            $attacker->refresh();

            if ($attacker->moves_current < $cost) {
                throw InsufficientMovesException::forAction('attack', $attacker->moves_current, $cost);
            }

            /** @var Tile $tile */
            $tile = Tile::query()->findOrFail($attacker->current_tile_id);
            if ($tile->type !== 'base') {
                throw CannotAttackException::notOnABase($tile->type);
            }

            if ($tile->id === $attacker->base_tile_id) {
                throw CannotAttackException::ownBase();
            }

            /** @var Player|null $defender */
            $defender = Player::query()->lockForUpdate()->where('base_tile_id', $tile->id)->first();
            if ($defender === null) {
                throw CannotAttackException::targetNotFound();
            }

            if ($defender->immunity_expires_at !== null && $defender->immunity_expires_at->isFuture()) {
                throw CannotAttackException::targetImmune();
            }

            // Must have a valid spy within the decay window.
            /** @var SpyAttempt|null $spy */
            $spy = SpyAttempt::query()
                ->where('spy_player_id', $attacker->id)
                ->where('target_player_id', $defender->id)
                ->where('success', true)
                ->where('created_at', '>=', now()->subHours($spyDecayHours))
                ->orderByDesc('created_at')
                ->first();

            if ($spy === null) {
                throw CannotAttackException::noSpy($spyDecayHours);
            }

            // Raid cooldown on this specific target.
            $recentAttack = Attack::query()
                ->where('attacker_player_id', $attacker->id)
                ->where('defender_player_id', $defender->id)
                ->where('created_at', '>=', now()->subHours($raidCooldownHours))
                ->exists();

            if ($recentAttack) {
                throw CannotAttackException::inCooldown($raidCooldownHours);
            }

            // F2: is the defender physically at their base right now?
            $defenderAtBase = $defender->current_tile_id !== null
                && (int) $defender->current_tile_id === (int) $defender->base_tile_id;

            $eventKey = 'atk-'.$attacker->id.'-'.$defender->id.'-'.now()->timestamp;
            $result = $this->combat->resolveAttack($attacker, $defender, $eventKey, $defenderAtBase);

            $cashStolen = (float) $result['cash_stolen'];

            if ($result['outcome'] === 'success' && $cashStolen > 0) {
                // Defender's cash may already be less than what the
                // formula computed (concurrent writes, etc.) — cap it.
                $cashStolen = min($cashStolen, (float) $defender->akzar_cash);

                $defender->update([
                    'akzar_cash' => (float) $defender->akzar_cash - $cashStolen,
                ]);

                $attacker->update([
                    'moves_current' => $attacker->moves_current - $cost,
                    'akzar_cash' => (float) $attacker->akzar_cash + $cashStolen,
                ]);
            } else {
                $attacker->update([
                    'moves_current' => $attacker->moves_current - $cost,
                ]);
            }

            /** @var Attack $attackRow */
            $attackRow = Attack::create([
                'attacker_player_id' => $attacker->id,
                'defender_player_id' => $defender->id,
                'defender_base_tile_id' => $tile->id,
                'relied_on_spy_id' => $spy->id,
                'outcome' => $result['outcome'],
                'cash_stolen' => $cashStolen,
                'attacker_escape' => false,
                'rng_seed' => crc32($eventKey),
                'rng_output' => (string) $result['final_score'],
                'created_at' => now(),
            ]);

            // Eager-load attacker's user name BEFORE leaving the transaction
            // so we don't hold the row lock any longer than needed.
            $attackerUsername = (string) ($attacker->user?->name ?? 'Unknown');

            return [
                'outcome' => $result['outcome'],
                'cash_stolen' => $cashStolen,
                'attack_id' => $attackRow->id,
                // update() already mutated moves_current in-memory.
                'moves_remaining' => (int) $attacker->moves_current,
                'final_score' => $result['final_score'],
                'defender_at_base' => $defenderAtBase,
                // Carry the user IDs out of the transaction so we can
                // dispatch broadcast events without re-querying.
                '_defender_user_id' => (int) $defender->user_id,
                '_attacker_username' => $attackerUsername,
            ];
        });

        // Dispatch broadcast events AFTER commit — Reverb failures must
        // not roll back the raid itself.
        // One event per raid: BaseUnderAttack already carries outcome +
        // loot, so dispatching RaidCompleted too would double-notify the
        // defender. RaidCompleted is kept as a class for future use
        // (e.g., an attacker-side confirmation channel) but is not
        // dispatched here.
        if (($this->config->get('notifications.broadcast_enabled'))) {
            BaseUnderAttack::dispatch(
                $result['_defender_user_id'],
                $result['_attacker_username'],
                $result['outcome'],
                (float) $result['cash_stolen'],
                (int) $result['attack_id'],
            );
        }

        unset($result['_defender_user_id'], $result['_attacker_username']);

        return $result;
    }
}
