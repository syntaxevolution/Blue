<?php

namespace App\Domain\Combat;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Config\RngService;
use App\Domain\Exceptions\CannotSpyException;
use App\Domain\Exceptions\InsufficientMovesException;
use App\Domain\Mdn\MdnService;
use App\Domain\Player\MoveRegenService;
use App\Events\SpyDetected;
use App\Models\Player;
use App\Models\SpyAttempt;
use App\Models\Tile;
use Illuminate\Support\Facades\DB;

/**
 * Spy action — scout another player's base.
 *
 * Phase 3 MVP: single-depth spying. A successful spy unlocks attack
 * rights on the target for the next combat.spy_decay_hours window
 * (default 24h) and grants intel.spy_depth_1 intel to the spy.
 *
 * Detection: regardless of success/failure, a separate roll decides
 * whether the target's security stack caught the spy. If detected,
 * the SpyAttempt.detected flag is set AND a SpyDetected event is
 * dispatched to the target's private channel (toast + activity log).
 *
 * Multi-depth spies (2 grants cash+fort visibility, 3 grants guaranteed
 * escape) are spec'd in gameplay-ultraplan §8 but come in a later pass.
 */
class SpyService
{
    public function __construct(
        private readonly GameConfigResolver $config,
        private readonly RngService $rng,
        private readonly MoveRegenService $moveRegen,
        private readonly MdnService $mdn,
    ) {}

    /**
     * @return array{
     *     outcome: string,
     *     intel_gained: int,
     *     spy_id: int,
     *     moves_remaining: int,
     *     detected: bool,
     * }
     */
    public function spy(int $spyPlayerId): array
    {
        $cost = (int) $this->config->get('actions.spy.move_cost');

        $result = DB::transaction(function () use ($spyPlayerId, $cost) {
            /** @var Player $spy */
            $spy = Player::query()->lockForUpdate()->findOrFail($spyPlayerId);

            $this->moveRegen->reconcile($spy);
            $spy->refresh();

            if ($spy->moves_current < $cost) {
                throw InsufficientMovesException::forAction('spy', $spy->moves_current, $cost);
            }

            /** @var Tile $tile */
            $tile = Tile::query()->findOrFail($spy->current_tile_id);
            if ($tile->type !== 'base') {
                throw CannotSpyException::notOnABase($tile->type);
            }

            if ($tile->id === $spy->base_tile_id) {
                throw CannotSpyException::ownBase();
            }

            /** @var Player|null $target */
            $target = Player::query()->lockForUpdate()->where('base_tile_id', $tile->id)->first();
            if ($target === null) {
                throw CannotSpyException::targetNotFound();
            }

            if ($target->immunity_expires_at !== null && $target->immunity_expires_at->isFuture()) {
                throw CannotSpyException::targetImmune();
            }

            // MDN rules: same-MDN spying blocked + 24h hop cooldown.
            $this->mdn->assertCanAttackOrSpy($spy, $target, 'spy');

            // Roll success. A zero-stealth spy still has ~30% baseline.
            // A max-stealth spy against zero security still caps at ~95%.
            $eventKey = 'spy-'.$spy->id.'-'.$target->id.'-'.now()->timestamp;
            $roll = $this->rng->rollFloat('combat.spy', $eventKey, 0.0, 1.0);

            $stealth = (int) $spy->stealth;
            $security = (int) $target->security;
            $successBase = (float) $this->config->get('combat.spy.success_base');
            $successPer = (float) $this->config->get('combat.spy.success_per_stealth_diff');
            $successMin = (float) $this->config->get('combat.spy.success_chance_min');
            $successMax = (float) $this->config->get('combat.spy.success_chance_max');
            $baseChance = $successBase + $successPer * max(0, $stealth - $security);
            $successChance = max($successMin, min($successMax, $baseChance));
            $success = $roll < $successChance;

            // Detection roll — independent of success.
            // Detection chance rises with target's security surplus over spy's stealth.
            $detectBase = (float) $this->config->get('combat.spy.detection_chance_base');
            $detectPer = (float) $this->config->get('combat.spy.detection_per_security_diff');
            $detectMin = (float) $this->config->get('combat.spy.detection_chance_min');
            $detectMax = (float) $this->config->get('combat.spy.detection_chance_max');

            $detectChance = $detectBase + $detectPer * max(0, $security - $stealth);
            $detectChance = max($detectMin, min($detectMax, $detectChance));
            $detected = $this->rng->rollBool('combat.spy.detect', $eventKey, $detectChance);

            $intelGained = 0;
            if ($success) {
                $intelGained = (int) $this->config->get('intel.earn.spy_depth_1');
            }

            /** @var SpyAttempt $spyRow */
            $spyRow = SpyAttempt::create([
                'spy_player_id' => $spy->id,
                'target_player_id' => $target->id,
                'target_base_tile_id' => $tile->id,
                'success' => $success,
                'detected' => $detected,
                'rng_seed' => (int) sprintf('%u', crc32($eventKey)),
                'rng_output' => (string) $roll,
                'created_at' => now(),
            ]);

            $updates = ['moves_current' => $spy->moves_current - $cost];
            if ($intelGained > 0) {
                $updates['intel'] = $spy->intel + $intelGained;
            }
            $spy->update($updates);

            $spyUsername = (string) ($spy->user?->name ?? 'Unknown');

            return [
                'outcome' => $success ? 'success' : 'failure',
                'intel_gained' => $intelGained,
                'spy_id' => $spyRow->id,
                // update() already applied the deduction.
                'moves_remaining' => (int) $spy->moves_current,
                'detected' => $detected,
                '_target_user_id' => (int) $target->user_id,
                '_spy_username' => $spyUsername,
                '_spy_succeeded' => $success,
            ];
        });

        // Dispatch detection broadcast AFTER commit.
        if ($result['detected'] && ($this->config->get('notifications.broadcast_enabled'))) {
            SpyDetected::dispatch(
                $result['_target_user_id'],
                $result['_spy_username'],
                (bool) $result['_spy_succeeded'],
            );
        }

        unset($result['_target_user_id'], $result['_spy_username'], $result['_spy_succeeded']);

        return $result;
    }
}
