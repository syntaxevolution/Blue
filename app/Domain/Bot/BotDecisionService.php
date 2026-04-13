<?php

namespace App\Domain\Bot;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Items\ItemBreakService;
use App\Domain\Player\MoveRegenService;
use App\Models\Player;
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

        $maxActions = max(1, (int) $this->config->get('bots.actions_per_tick_max', 3));
        $failThreshold = max(1, (int) $this->config->get('bots.goal_fail_clear_threshold', 3));
        $ttlMinutes = max(1, (int) $this->config->get('bots.goal_max_ttl_minutes', 60));

        // Load or pick a goal.
        $goal = $this->currentGoalOrNull($bot);
        if ($goal === null) {
            $goal = $this->planner->pickGoal($bot, $tierCfg);
            $this->persistGoal($bot, $goal, $ttlMinutes);
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
                    $this->persistGoal($bot, $goal, $ttlMinutes);
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
                // Persist the just-finished goal state so the debug
                // log matches what we actually did, then plan fresh.
                $goal = $this->planner->pickGoal($bot->refresh(), $tierCfg);
                $this->persistGoal($bot, $goal, $ttlMinutes);
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
}
