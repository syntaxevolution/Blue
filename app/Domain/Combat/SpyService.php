<?php

namespace App\Domain\Combat;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Config\RngService;
use App\Domain\Exceptions\CannotSpyException;
use App\Domain\Exceptions\InsufficientMovesException;
use App\Domain\Mdn\MdnService;
use App\Domain\Notifications\ActivityLogService;
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
        private readonly ActivityLogService $activityLog,
        private readonly CombatFormula $combat,
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

            // Per-target per-attacker spy cooldown. Counts ALL prior
            // attempts (success or failure) so a botched spy still
            // locks the target for the cooldown window — otherwise
            // failure would be free and players would just spam-spy.
            $cooldownHours = (int) $this->config->get('combat.spy.cooldown_hours');
            if ($cooldownHours > 0) {
                /** @var SpyAttempt|null $recentSpy */
                $recentSpy = SpyAttempt::query()
                    ->where('spy_player_id', $spy->id)
                    ->where('target_player_id', $target->id)
                    ->where('created_at', '>=', now()->subHours($cooldownHours))
                    ->orderByDesc('created_at')
                    ->first();

                if ($recentSpy !== null) {
                    $remaining = (int) ceil(
                        $cooldownHours - $recentSpy->created_at->diffInMinutes(now()) / 60,
                    );
                    if ($remaining < 1) {
                        $remaining = 1;
                    }
                    throw CannotSpyException::inCooldown($remaining);
                }
            }

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
            $intelPayload = null;
            if ($success) {
                $intelGained = (int) $this->config->get('intel.earn.spy_depth_1');
                $intelPayload = $this->buildRevealPayload($spy, $target, $eventKey);
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
                'intel_payload' => $intelPayload,
                'created_at' => now(),
            ]);

            $updates = ['moves_current' => $spy->moves_current - $cost];
            if ($intelGained > 0) {
                $updates['intel'] = $spy->intel + $intelGained;
            }
            $spy->update($updates);

            $spyUsername = (string) ($spy->user?->name ?? 'Unknown');
            $targetUsername = (string) ($target->user?->name ?? 'Unknown');

            // Actor-side activity log. The target side already gets an
            // entry via the SpyDetected event → RecordActivityLog
            // listener, but only when detection fires. The spy
            // themselves gets a persistent record here regardless of
            // outcome so their /activity feed reflects every action
            // they took. Inside the tx for atomicity with the
            // spy_attempts row.
            $spyTitle = match (true) {
                $success && $detected => "You spied on {$targetUsername} — +{$intelGained} intel, but you were detected",
                $success => "You spied on {$targetUsername} — +{$intelGained} intel",
                $detected => "Your spy on {$targetUsername} failed — and you were detected",
                default => "Your spy on {$targetUsername} failed",
            };

            $this->activityLog->record(
                (int) $spy->user_id,
                'spy.committed',
                $spyTitle,
                [
                    'outcome' => $success ? 'success' : 'failure',
                    'intel_gained' => $intelGained,
                    'detected' => $detected,
                    'spy_id' => $spyRow->id,
                    'target_username' => $targetUsername,
                ],
            );

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

    /**
     * Build the fuzzed intel snapshot for a successful spy.
     *
     * Numeric fields (fortification, security, cash) get a symmetric
     * noise band whose width shrinks as the spy's stealth advantage
     * over target security grows: a max-stealth spy gets tight ranges,
     * an outmatched spy gets wide ones. The player NEVER sees the true
     * center value — only the rolled low/high bounds — so even a
     * fuzz-band wide enough to contain the truth doesn't reveal it.
     *
     * Win chance gets an absolute ±pp noise band (not a multiplicative
     * one) since it's already a probability.
     *
     * Snapshotting happens once at spy time and is frozen on the
     * spy_attempts row — the player sees these values "as of Xh ago"
     * regardless of how the target's true stats drift afterwards.
     *
     * @return array{
     *   fortification: array{low: int, high: int},
     *   security: array{low: int, high: int},
     *   cash: array{low: float, high: float},
     *   win_chance: array{low: float, high: float},
     * }
     */
    private function buildRevealPayload(Player $spy, Player $target, string $eventKey): array
    {
        $baseNoise = (float) $this->config->get('combat.spy.reveal_fuzz_numeric_pct');
        $minNoise = (float) $this->config->get('combat.spy.reveal_fuzz_min_numeric_pct');
        $advantageScale = (float) $this->config->get('combat.spy.reveal_fuzz_advantage_scale');
        $winChanceAbs = (float) $this->config->get('combat.spy.reveal_fuzz_win_chance_abs');

        $advantage = max(0, (int) $spy->stealth - (int) $target->security);
        $shrink = $advantageScale > 0 ? max(0.0, 1.0 - ($advantage / $advantageScale)) : 1.0;
        $noisePct = max($minNoise, min($baseNoise, $baseNoise * $shrink));

        $fortRange = $this->fuzzInt(
            (int) $target->fortification,
            $noisePct,
            'combat.spy.reveal.fort',
            $eventKey,
        );
        $secRange = $this->fuzzInt(
            (int) $target->security,
            $noisePct,
            'combat.spy.reveal.sec',
            $eventKey,
        );
        $cashRange = $this->fuzzFloat(
            (float) $target->akzar_cash,
            $noisePct,
            'combat.spy.reveal.cash',
            $eventKey,
        );

        // Win chance is computed for the neutral case (defender NOT
        // physically at base). The at-base strength bonus is a moving
        // target — defenders may travel after the spy — so we report
        // the lower-defense estimate and let the player price in the
        // possibility that the defender comes home before the raid.
        $trueWinChance = $this->combat->estimateRaidWinChance($spy, $target, false);
        $winLow = max(0.0, $trueWinChance - $winChanceAbs);
        $winHigh = min(1.0, $trueWinChance + $winChanceAbs);
        $winNoise = $this->rng->rollFloat(
            'combat.spy.reveal.win',
            $eventKey,
            -$winChanceAbs,
            $winChanceAbs,
        );
        $winCenter = max(0.0, min(1.0, $trueWinChance + $winNoise));
        // Center the displayed band on the rolled estimate, but keep
        // the half-width fixed at $winChanceAbs so the displayed range
        // doesn't accidentally collapse near 0 or 1.
        $winLowDisplay = round(max(0.0, $winCenter - $winChanceAbs), 2);
        $winHighDisplay = round(min(1.0, $winCenter + $winChanceAbs), 2);
        if ($winHighDisplay < $winLowDisplay) {
            $winHighDisplay = $winLowDisplay;
        }
        unset($winLow, $winHigh);

        return [
            'fortification' => $fortRange,
            'security' => $secRange,
            'cash' => $cashRange,
            'win_chance' => [
                'low' => $winLowDisplay,
                'high' => $winHighDisplay,
            ],
        ];
    }

    /**
     * @return array{low: int, high: int}
     */
    private function fuzzInt(int $trueValue, float $noisePct, string $category, string $eventKey): array
    {
        $halfWidth = max(1, (int) round(abs($trueValue) * $noisePct));
        $offset = (int) round($this->rng->rollFloat($category, $eventKey, -$halfWidth, $halfWidth));
        $center = max(0, $trueValue + $offset);

        $low = max(0, $center - $halfWidth);
        $high = max($low, $center + $halfWidth);

        return ['low' => $low, 'high' => $high];
    }

    /**
     * @return array{low: float, high: float}
     */
    private function fuzzFloat(float $trueValue, float $noisePct, string $category, string $eventKey): array
    {
        $halfWidth = max(0.01, abs($trueValue) * $noisePct);
        $offset = $this->rng->rollFloat($category, $eventKey, -$halfWidth, $halfWidth);
        $center = max(0.0, $trueValue + $offset);

        $low = max(0.0, $center - $halfWidth);
        $high = max($low, $center + $halfWidth);

        return [
            'low' => round($low, 2),
            'high' => round($high, 2),
        ];
    }
}
