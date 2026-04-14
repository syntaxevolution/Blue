<?php

namespace App\Domain\Bot;

use App\Domain\Combat\CombatFormula;
use App\Domain\Combat\TileCombatEligibilityService;
use App\Domain\Combat\TileCombatService;
use App\Domain\Config\GameConfigResolver;
use App\Domain\Config\RngService;
use App\Domain\Items\ItemBreakService;
use App\Domain\Player\MoveRegenService;
use App\Models\Player;
use App\Models\Tile;
use Throwable;

/**
 * Autonomous decision loop for a single bot player.
 *
 * Contract:
 *   - Bots call the exact same domain services a human calls. No
 *     rule-bending, no private APIs.
 *   - Primary objective across all difficulty tiers: maximise Akzar
 *     Cash.
 *   - Decision-making is split:
 *       BotGoalPlanner  → picks WHAT to pursue (goal descriptor)
 *       BotGoalExecutor → makes ONE STEP of progress toward the goal
 *     BotDecisionService orchestrates the two: load-or-pick, loop
 *     step() until the move budget is spent or the goal completes,
 *     persist the goal state back to the player row.
 *   - A persistent goal means the three per-tick actions all push the
 *     same target, so bots actually converge instead of zig-zagging
 *     between drill/shop/spy.
 *   - Exceptions from executor steps increment bot_goal_fail_count;
 *     at bots.goal_fail_clear_threshold the goal is torn down and
 *     replanned so a broken target never wedges a bot forever.
 *   - Casino tiles are never valid targets (planner filters) and
 *     never stepped onto (executor rotates around them).
 */
class BotDecisionService
{
    public function __construct(
        private readonly GameConfigResolver $config,
        private readonly MoveRegenService $moveRegen,
        private readonly ItemBreakService $itemBreak,
        private readonly BotGoalPlanner $planner,
        private readonly BotGoalExecutor $executor,
        private readonly TileCombatService $tileCombatSvc,
        private readonly TileCombatEligibilityService $tileCombatEligibility,
        private readonly CombatFormula $combatFormula,
        private readonly RngService $rng,
    ) {}

    /**
     * Run one tick for the given bot. Returns a summary of actions
     * performed for logging.
     *
     * @return array{actions: list<array<string,mixed>>, ended_with: string}
     */
    public function tick(Player $bot): array
    {
        $actions = [];

        // Reconcile regen so we're making decisions with the current
        // pool.
        $this->moveRegen->reconcile($bot);
        $bot->refresh();

        // Swarm TTL sweep: if an admin-issued forced raid target
        // has aged out, clear both columns before the planner runs
        // so a stale target can't freeze a bot in spy-retry hell.
        if ($bot->bot_forced_raid_target_player_id !== null
            && $bot->bot_forced_raid_expires_at !== null
            && $bot->bot_forced_raid_expires_at->isPast()) {
            $bot->forceFill([
                'bot_forced_raid_target_player_id' => null,
                'bot_forced_raid_expires_at' => null,
            ])->save();
            $bot->refresh();
            $actions[] = ['kind' => 'swarm_expired', 'detail' => 'ttl_reached'];
        }

        // Self-heal: auto-abandon a broken rig before picking a goal.
        // Humans get a modal prompting repair-or-abandon; bots have no
        // UI, so we abandon (which drops drill_tier to the next-owned
        // tier) and continue. Without this the drill domain guard
        // would block every future drill.
        if ($bot->broken_item_key !== null) {
            try {
                $this->itemBreak->abandon($bot);
                $bot->refresh();
                $actions[] = ['kind' => 'auto_abandon', 'detail' => 'broken_rig_cleared'];
            } catch (Throwable $e) {
                $actions[] = ['kind' => 'auto_abandon', 'error' => $e->getMessage()];
            }
        }

        $tier = (string) ($bot->bot_difficulty ?? 'normal');
        $tierCfg = $this->config->get('bots.difficulty.'.$tier, null);
        if (! is_array($tierCfg)) {
            return ['actions' => [], 'ended_with' => 'unknown_difficulty'];
        }

        // Opportunistic wasteland duel BEFORE the goal executor runs.
        // Fires at most once per tick and never counts against the
        // action budget — the bot still gets its full goal-driven
        // actions afterward. If a fight fires we just log it and
        // continue; exceptions are swallowed so a race never kills
        // the whole tick.
        $tileCombatLog = $this->maybeOpportunisticTileCombat($bot, $tier, $tierCfg);
        if ($tileCombatLog !== null) {
            $actions[] = $tileCombatLog;
            $bot->refresh();
        }

        $maxActions = max(1, (int) $this->config->get('bots.actions_per_tick_max', 3));
        $failThreshold = max(1, (int) $this->config->get('bots.goal_fail_clear_threshold', 3));
        $ttlMinutes = max(1, (int) $this->config->get('bots.goal_max_ttl_minutes', 60));

        // Load or pick a goal. A resumed goal does NOT touch the
        // consecutive-drill counter (already counted when originally
        // picked) — only fresh picks go through commitFreshGoal().
        $goal = $this->currentGoalOrNull($bot);
        if ($goal === null) {
            $goal = $this->planner->pickGoal($bot, $tierCfg);
            $this->commitFreshGoal($bot, $goal, $ttlMinutes);
        }

        $budget = min((int) $bot->moves_current, $maxActions);
        $endedWith = 'ok';

        for ($i = 0; $i < $budget; $i++) {
            if ((int) $bot->moves_current <= 0) {
                $endedWith = 'no_moves';
                $actions[] = ['kind' => 'noop', 'detail' => 'no_moves'];
                break;
            }

            if ($goal === null) {
                // Planner couldn't produce anything — shouldn't happen
                // (explore is always available) but guard against a
                // degenerate map.
                $endedWith = 'no_viable_goal';
                $actions[] = ['kind' => 'noop', 'detail' => 'no_viable_goal'];
                break;
            }

            $result = ['status' => BotGoalExecutor::STATUS_INVALIDATED, 'log' => ['kind' => 'noop']];
            try {
                $result = $this->executor->step($bot, $goal);
                // Success → reset fail counter.
                if ($bot->bot_goal_fail_count > 0) {
                    $bot->bot_goal_fail_count = 0;
                }
            } catch (Throwable $e) {
                $bot->bot_goal_fail_count = (int) $bot->bot_goal_fail_count + 1;
                // Persist the bump immediately — a later step inside
                // the same tick may refresh the bot row (DrillService,
                // etc), which would wipe the in-memory counter and
                // defeat the 3-strikes rule.
                $bot->save();

                $actions[] = ['kind' => $goal['kind'] ?? 'unknown', 'error' => $e->getMessage()];

                if ($bot->bot_goal_fail_count >= $failThreshold) {
                    $goal = null;
                    $bot->bot_goal_fail_count = 0;
                    $this->persistGoal($bot, null, $ttlMinutes);
                    $goal = $this->planner->pickGoal($bot->refresh(), $tierCfg);
                    $this->commitFreshGoal($bot, $goal, $ttlMinutes);
                }

                continue;
            }

            // Executor may return an updated goal (explore decrements
            // its budget in-place). Pick that up before persisting.
            if (isset($result['goal']) && is_array($result['goal'])) {
                $goal = $result['goal'];
            }

            $actions[] = $result['log'] ?? ['kind' => 'unknown'];

            if ($result['status'] === BotGoalExecutor::STATUS_COMPLETED
                || $result['status'] === BotGoalExecutor::STATUS_INVALIDATED) {
                // Swarm sweep: if the just-resolved goal was a
                // forced-swarm raid (or the spy-to-unlock-raid step
                // that preceded it), clear the forced target column
                // on COMPLETED raids. Per user spec: bots attack
                // once and then return to normal. Spy completions
                // leave the column set so the next tick re-plans
                // into a raid against the same target.
                $this->clearForcedRaidIfApplicable($bot, $goal, $result['status']);

                // Persist the just-finished goal state so the debug
                // log matches what we actually did, then plan fresh.
                $goal = $this->planner->pickGoal($bot->refresh(), $tierCfg);
                $this->commitFreshGoal($bot, $goal, $ttlMinutes);

                continue;
            }

            // STATUS_PROGRESSED — persist any in-place goal mutation
            // (explore) and keep going.
            $this->persistGoal($bot, $goal, $ttlMinutes, resetExpiry: false);
            $bot->refresh();
        }

        if ((int) $bot->moves_current <= 0 && $endedWith === 'ok') {
            $endedWith = 'no_moves';
        }

        $bot->bot_last_tick_at = now();
        $bot->save();

        return ['actions' => $actions, 'ended_with' => $endedWith];
    }

    /**
     * Clear the forced-raid swarm columns on the bot once the
     * forced goal has reached its termination condition:
     *
     *   - COMPLETED raid goal against the forced target → the bot
     *     fired an attack (success OR failure). Per user spec, one
     *     attack is enough — the swarm commitment is discharged.
     *
     *   - INVALIDATED raid goal against the forced target → the
     *     target became unreachable mid-march (went immune, moved
     *     into the bot's MDN, base tile deleted). Clear so the bot
     *     drops back into the normal ladder instead of re-trying
     *     a doomed target every tick.
     *
     *   - A spy goal (forced_swarm=true) completing against the
     *     forced target is NOT a termination — the bot now HAS an
     *     in-window spy and will raid next tick. Leave the column
     *     set so the raid happens.
     *
     *   - Any goal kind OTHER than raid/spy → never clears. Shouldn't
     *     happen because pickForcedRaidGoal only emits raid or spy,
     *     but we defend against future planner changes.
     *
     * @param  array<string,mixed>  $goal
     */
    private function clearForcedRaidIfApplicable(Player $bot, array $goal, string $status): void
    {
        $forcedTargetId = $bot->bot_forced_raid_target_player_id;
        if ($forcedTargetId === null) {
            return;
        }

        $goalKind = (string) ($goal['kind'] ?? '');
        $goalTargetId = (int) ($goal['target_player_id'] ?? 0);

        if ($goalTargetId !== (int) $forcedTargetId) {
            return;
        }

        // Raid goal (success OR invalidation both count as "done")
        // always clears. Spy goal only clears on invalidation (the
        // bot couldn't reach the target); spy success continues the
        // swarm so the bot raids next tick.
        $shouldClear = false;
        if ($goalKind === BotGoalPlanner::KIND_RAID) {
            $shouldClear = true;
        } elseif ($goalKind === BotGoalPlanner::KIND_SPY
            && $status === BotGoalExecutor::STATUS_INVALIDATED) {
            $shouldClear = true;
        }

        if (! $shouldClear) {
            return;
        }

        $bot->forceFill([
            'bot_forced_raid_target_player_id' => null,
            'bot_forced_raid_expires_at' => null,
        ])->save();
    }

    /**
     * Load the bot's persisted goal if it's still within TTL; return
     * null if expired, missing, or malformed (so the caller replans).
     *
     * @return array<string,mixed>|null
     */
    private function currentGoalOrNull(Player $bot): ?array
    {
        $raw = $bot->bot_current_goal;
        if (! is_array($raw) || $raw === []) {
            return null;
        }
        if (! isset($raw['kind']) || ! is_string($raw['kind'])) {
            return null;
        }
        if ($bot->bot_goal_expires_at !== null && $bot->bot_goal_expires_at->isPast()) {
            return null;
        }

        return $raw;
    }

    /**
     * Commit a freshly-planned goal: update the consecutive-drill
     * counter before persisting. Only called when the planner has
     * actually produced a new goal (not on resume, not on in-place
     * explore-budget decrement) so the counter reflects "planner
     * decisions" rather than "tick wall-clock."
     *
     *   - drill goal  → increment counter
     *   - any other   → reset to 0
     *   - null goal   → leave counter alone (no decision made)
     *
     * @param  array<string,mixed>|null  $goal
     */
    private function commitFreshGoal(Player $bot, ?array $goal, int $ttlMinutes): void
    {
        if ($goal !== null) {
            $kind = (string) ($goal['kind'] ?? '');
            if ($kind === BotGoalPlanner::KIND_DRILL) {
                $bot->bot_consecutive_drill_count = (int) $bot->bot_consecutive_drill_count + 1;
            } else {
                $bot->bot_consecutive_drill_count = 0;
            }
        }

        $this->persistGoal($bot, $goal, $ttlMinutes);
    }

    /**
     * Write a goal (or null) back to the player row. By default
     * refreshes the TTL clock; pass resetExpiry=false for in-place
     * updates like explore-budget decrement so a goal doesn't get a
     * renewed lease just for taking another step.
     *
     * @param  array<string,mixed>|null  $goal
     */
    private function persistGoal(Player $bot, ?array $goal, int $ttlMinutes, bool $resetExpiry = true): void
    {
        $bot->bot_current_goal = $goal;
        if ($goal === null) {
            $bot->bot_goal_expires_at = null;
        } elseif ($resetExpiry) {
            $bot->bot_goal_expires_at = now()->addMinutes($ttlMinutes);
        }
        $bot->save();
    }

    /**
     * Opportunistic wasteland duel check. Runs once at the top of a
     * tick, AFTER moves are reconciled but BEFORE the goal executor
     * steps, so the fight never competes with the action budget.
     *
     * Returns an action-log entry if a fight fired (for the tick
     * summary) or null if nothing happened.
     *
     * Heuristic:
     *   - Master switch: bots.tile_combat.enabled
     *   - Per-tier engagement_prob gates the coinflip. Easy bots
     *     have prob=0 and never engage.
     *   - Win-chance estimate (from CombatFormula) must be in the
     *     sweet spot: above min_win_chance (don't lose on purpose)
     *     AND below bully_cap (don't bully — upset curve gives 0%).
     *   - Target must pass TileCombatEligibilityService (wasteland,
     *     not immune, not same-MDN, not in cooldown, etc.)
     *   - Only fires if the bot has enough moves for the fight AND
     *     at least one more move for its goal (so we don't strand
     *     it with zero budget after tile combat).
     *
     * @param  array<string,mixed>  $tierCfg
     * @return array<string,mixed>|null
     */
    private function maybeOpportunisticTileCombat(Player $bot, string $tier, array $tierCfg): ?array
    {
        if (! (bool) $this->config->get('bots.tile_combat.enabled', true)) {
            return null;
        }

        $engagementProb = (float) ($tierCfg['tile_combat_engagement_prob'] ?? 0.0);
        if ($engagementProb <= 0.0) {
            return null;
        }

        $minWinChance = (float) ($tierCfg['tile_combat_min_win_chance'] ?? 0.65);
        $bullyCap = (float) $this->config->get('bots.tile_combat.bully_cap_win_chance', 0.92);
        $moveCost = (int) $this->config->get('actions.tile_combat.move_cost', 5);

        // Need budget for the fight itself — don't pop into combat
        // with exactly 5 moves and strand the goal loop on 0.
        if ((int) $bot->moves_current < $moveCost + 1) {
            return null;
        }

        /** @var Tile|null $tile */
        $tile = Tile::query()->find($bot->current_tile_id);
        if ($tile === null || $tile->type !== 'wasteland') {
            return null;
        }

        $targets = Player::query()
            ->with(['user:id,name,is_bot'])
            ->where('current_tile_id', $tile->id)
            ->where('id', '!=', $bot->id)
            ->get();

        if ($targets->isEmpty()) {
            return null;
        }

        foreach ($targets as $target) {
            // Strict eligibility gate — same rules as the service
            // will enforce, but run here so we don't waste moves on
            // a sure-rejection.
            $elig = $this->tileCombatEligibility->canFight($bot, $target, $tile);
            if (! $elig['ok']) {
                continue;
            }

            $winChance = $this->combatFormula->estimateTileDuelWinChance($bot, $target);

            // Skip near-guaranteed losses.
            if ($winChance < $minWinChance) {
                continue;
            }
            // Skip near-guaranteed wins (bully filter — upset-reward
            // curve makes loot ≈ 0 at this ratio, so the move spend
            // is wasted).
            if ($winChance > $bullyCap) {
                continue;
            }

            // Weighted coinflip so bots don't engage every viable
            // encounter. Seeded on bot+target+timestamp so replay-mode
            // tests can force the roll.
            $rngKey = 'bot-tc-'.$bot->id.'-'.$target->id.'-'.$tile->id.'-'.now()->timestamp;
            if (! $this->rng->rollBool('bots.tile_combat', $rngKey, $engagementProb)) {
                continue;
            }

            try {
                $res = $this->tileCombatSvc->engage($bot->id, $target->id);

                return [
                    'kind' => 'tile_combat',
                    'detail' => ($res['attacker_won'] ? 'won' : 'lost')
                        .':'.$res['oil_stolen'].'b'
                        .':t'.$target->id,
                ];
            } catch (Throwable $e) {
                // Race (target walked away, immunity flipped, etc.) —
                // log and keep scanning for another eligible target.
                return [
                    'kind' => 'tile_combat',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return null;
    }
}
