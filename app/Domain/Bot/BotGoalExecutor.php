<?php

namespace App\Domain\Bot;

use App\Domain\Combat\AttackService;
use App\Domain\Combat\SpyService;
use App\Domain\Config\GameConfigResolver;
use App\Domain\Config\RngService;
use App\Domain\Drilling\DrillService;
use App\Domain\Economy\ShopService;
use App\Domain\Loot\LootCrateService;
use App\Domain\Player\TravelService;
use App\Domain\Sabotage\SabotageService;
use App\Models\DrillPoint;
use App\Models\OilField;
use App\Models\Player;
use App\Models\Tile;
use Throwable;

/**
 * Executes one step of a bot's active goal.
 *
 * Unlike the old BotDecisionService::doXxx methods, an executor step
 * is always oriented toward a single goal target and consumes a
 * predictable amount of moves. The planner and executor stay strictly
 * separated: the executor never replans, never picks a new target —
 * if the goal becomes invalid mid-step it returns STATUS_INVALIDATED
 * and lets the caller (BotDecisionService::tick) call the planner
 * again.
 *
 * Status contract:
 *
 *   STATUS_PROGRESSED  — step did useful work, goal is still live
 *                        (e.g. travelled one tile, or drilled one
 *                        point but the field still has headroom)
 *   STATUS_COMPLETED   — goal is fully satisfied, planner should pick
 *                        a new one next call
 *   STATUS_INVALIDATED — goal cannot be completed from this state
 *                        (target tile became casino, field depleted,
 *                        target gained immunity, pathing blocked),
 *                        planner should pick a new one
 *
 * Every public exit point goes through a log entry so BotDecisionService
 * has a uniform trace of what each step tried to do.
 */
class BotGoalExecutor
{
    public const STATUS_PROGRESSED = 'progressed';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_INVALIDATED = 'invalidated';

    public function __construct(
        private readonly GameConfigResolver $config,
        private readonly TravelService $travel,
        private readonly DrillService $drillSvc,
        private readonly ShopService $shop,
        private readonly SpyService $spySvc,
        private readonly AttackService $attackSvc,
        private readonly SabotageService $sabotageSvc,
        private readonly LootCrateService $lootCrates,
        private readonly RngService $rng,
    ) {}

    /**
     * Execute a single action-worth of progress against $goal. Must
     * be called with a reconciled bot (moves already topped up).
     *
     * @param  array<string,mixed>  $goal
     * @return array{status:string, log:array{kind:string, detail?:string, error?:string}, goal?:array<string,mixed>}
     */
    public function step(Player $bot, array $goal): array
    {
        $kind = (string) ($goal['kind'] ?? '');

        return match ($kind) {
            BotGoalPlanner::KIND_DRILL => $this->stepDrill($bot, $goal),
            BotGoalPlanner::KIND_SHOP => $this->stepShop($bot, $goal),
            BotGoalPlanner::KIND_SPY => $this->stepSpy($bot, $goal),
            BotGoalPlanner::KIND_RAID => $this->stepRaid($bot, $goal),
            BotGoalPlanner::KIND_SABOTAGE => $this->stepSabotage($bot, $goal),
            BotGoalPlanner::KIND_EXPLORE => $this->stepExplore($bot, $goal),
            default => [
                'status' => self::STATUS_INVALIDATED,
                'log' => ['kind' => 'noop', 'detail' => "unknown_goal({$kind})"],
            ],
        };
    }

    // ------------------------------------------------------------------
    // Travel-to-target actions
    // ------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $goal
     * @return array{status:string, log:array<string,mixed>}
     */
    private function stepDrill(Player $bot, array $goal): array
    {
        $targetTileId = (int) ($goal['tile_id'] ?? 0);
        $target = Tile::query()->find($targetTileId);
        if ($target === null || $target->type !== 'oil_field') {
            return $this->invalidated('drill', 'target_tile_gone');
        }

        if ((int) $bot->current_tile_id !== $targetTileId) {
            return $this->travelStep($bot, $target, 'drill_travel');
        }

        // On-tile: pick and drill a cell.
        /** @var OilField|null $field */
        $field = OilField::query()->where('tile_id', $target->id)->first();
        if ($field === null) {
            return $this->invalidated('drill', 'field_gone');
        }

        $point = $this->pickBestDrillPoint($field->id);
        if ($point === null) {
            // Field empty — completed (planner will pick next).
            return [
                'status' => self::STATUS_COMPLETED,
                'log' => ['kind' => 'drill', 'detail' => 'field_empty'],
            ];
        }

        try {
            $this->drillSvc->drill($bot->id, (int) $point->grid_x, (int) $point->grid_y);
        } catch (Throwable $e) {
            // Daily limit or similar — treat as completed so we replan
            // to another field. Hard failures surface via the caller's
            // fail counter.
            return [
                'status' => self::STATUS_COMPLETED,
                'log' => ['kind' => 'drill', 'error' => $e->getMessage()],
            ];
        }

        // Check if there's still headroom on this field for the next
        // step. If not, complete the goal so the planner looks
        // elsewhere.
        $hasMore = DrillPoint::query()
            ->where('oil_field_id', $field->id)
            ->whereNull('drilled_at')
            ->exists();

        return [
            'status' => $hasMore ? self::STATUS_PROGRESSED : self::STATUS_COMPLETED,
            'log' => ['kind' => 'drill', 'detail' => "{$point->grid_x},{$point->grid_y}"],
        ];
    }

    /**
     * @param  array<string,mixed>  $goal
     * @return array{status:string, log:array<string,mixed>}
     */
    private function stepShop(Player $bot, array $goal): array
    {
        $targetTileId = (int) ($goal['tile_id'] ?? 0);
        $target = Tile::query()->find($targetTileId);
        if ($target === null || $target->type !== 'post') {
            return $this->invalidated('shop', 'target_tile_gone');
        }

        if ((int) $bot->current_tile_id !== $targetTileId) {
            return $this->travelStep($bot, $target, 'shop_travel');
        }

        $wantKey = (string) ($goal['want_item'] ?? '');
        if ($wantKey === '') {
            return $this->invalidated('shop', 'no_target_item');
        }

        try {
            $this->shop->purchase($bot->id, $wantKey);
        } catch (Throwable $e) {
            // Couldn't buy (already owned / cost changed / post type
            // mismatch). Invalidate so planner picks a fresh upgrade
            // instead of retrying forever.
            return $this->invalidated('shop', $e->getMessage());
        }

        return [
            'status' => self::STATUS_COMPLETED,
            'log' => ['kind' => 'shop', 'detail' => $wantKey],
        ];
    }

    /**
     * @param  array<string,mixed>  $goal
     * @return array{status:string, log:array<string,mixed>}
     */
    private function stepSpy(Player $bot, array $goal): array
    {
        $targetTileId = (int) ($goal['tile_id'] ?? 0);
        $target = Tile::query()->find($targetTileId);
        if ($target === null || $target->type === 'casino') {
            return $this->invalidated('spy', 'target_tile_gone');
        }

        if ((int) $bot->current_tile_id !== $targetTileId) {
            return $this->travelStep($bot, $target, 'spy_travel');
        }

        try {
            $this->spySvc->spy($bot->id);
        } catch (Throwable $e) {
            return $this->invalidated('spy', $e->getMessage());
        }

        return [
            'status' => self::STATUS_COMPLETED,
            'log' => ['kind' => 'spy', 'detail' => (string) ($goal['target_player_id'] ?? '?')],
        ];
    }

    /**
     * @param  array<string,mixed>  $goal
     * @return array{status:string, log:array<string,mixed>}
     */
    private function stepRaid(Player $bot, array $goal): array
    {
        $targetTileId = (int) ($goal['tile_id'] ?? 0);
        $target = Tile::query()->find($targetTileId);
        if ($target === null || $target->type === 'casino') {
            return $this->invalidated('raid', 'target_tile_gone');
        }

        if ((int) $bot->current_tile_id !== $targetTileId) {
            return $this->travelStep($bot, $target, 'raid_travel');
        }

        try {
            $this->attackSvc->attack($bot->id);
        } catch (Throwable $e) {
            return $this->invalidated('raid', $e->getMessage());
        }

        return [
            'status' => self::STATUS_COMPLETED,
            'log' => ['kind' => 'raid', 'detail' => (string) ($goal['target_player_id'] ?? '?')],
        ];
    }

    /**
     * @param  array<string,mixed>  $goal
     * @return array{status:string, log:array<string,mixed>}
     */
    private function stepSabotage(Player $bot, array $goal): array
    {
        $targetTileId = (int) ($goal['tile_id'] ?? 0);
        $target = Tile::query()->find($targetTileId);
        if ($target === null || $target->type !== 'oil_field') {
            return $this->invalidated('sabotage', 'target_tile_gone');
        }

        if ((int) $bot->current_tile_id !== $targetTileId) {
            return $this->travelStep($bot, $target, 'sabotage_travel');
        }

        $gridX = (int) ($goal['grid_x'] ?? 0);
        $gridY = (int) ($goal['grid_y'] ?? 0);
        $deviceKey = (string) ($goal['device_key'] ?? '');

        if ($deviceKey === '') {
            return $this->invalidated('sabotage', 'no_device');
        }

        try {
            $this->sabotageSvc->place($bot->id, $gridX, $gridY, $deviceKey);
        } catch (Throwable $e) {
            // Point may have been drilled or rigged in the meantime —
            // let the planner pick another point/field next tick.
            return $this->invalidated('sabotage', $e->getMessage());
        }

        return [
            'status' => self::STATUS_COMPLETED,
            'log' => ['kind' => 'sabotage', 'detail' => "{$gridX},{$gridY}:{$deviceKey}"],
        ];
    }

    /**
     * Explore goal: walk one tile in the committed heading, decrement
     * the remaining budget. Casino tiles are never walked onto — if
     * the heading would step onto one, rotate 90° and retry once;
     * second failure invalidates so the planner picks a new heading.
     *
     * @param  array<string,mixed>  $goal
     * @return array{status:string, log:array<string,mixed>, goal?:array<string,mixed>}
     */
    private function stepExplore(Player $bot, array $goal): array
    {
        $heading = (string) ($goal['heading'] ?? 'n');
        $remaining = (int) ($goal['tiles_remaining'] ?? 0);

        if ($remaining <= 0) {
            return [
                'status' => self::STATUS_COMPLETED,
                'log' => ['kind' => 'explore', 'detail' => 'budget_spent'],
            ];
        }

        // Pre-check the next tile for casino.
        $current = Tile::query()->find($bot->current_tile_id);
        if ($current === null) {
            return $this->invalidated('explore', 'no_current_tile');
        }

        $attempts = [$heading, $this->perpendicular($heading)];
        $stepped = null;
        foreach ($attempts as $dir) {
            $next = $this->neighbourTile($current, $dir);
            if ($next !== null && $next->type === 'casino') {
                continue;
            }
            try {
                $this->travel->travel($bot->id, $dir);
                $stepped = $dir;
                break;
            } catch (Throwable) {
                // Hit edge / insufficient moves / etc — try next.
                continue;
            }
        }

        if ($stepped === null) {
            return $this->invalidated('explore', 'stepping_blocked');
        }

        $goal['heading'] = $stepped;
        $goal['tiles_remaining'] = $remaining - 1;

        // Loot crate arrival hook — same path as travelStep() so a
        // bot in explore mode also rolls for crates on each step.
        $bot->refresh();
        $this->maybeEngageLootCrate($bot);

        // If we stepped onto anything interesting (oil field, post,
        // base, auction, ruin, landmark) complete the goal so the
        // planner picks a real target instead of burning more explore
        // budget.
        $newTile = Tile::query()->find($bot->current_tile_id);
        $interesting = $newTile !== null && in_array(
            $newTile->type,
            ['oil_field', 'post', 'base', 'auction', 'ruin', 'landmark'],
            true,
        );

        return [
            'status' => $interesting || $goal['tiles_remaining'] <= 0
                ? self::STATUS_COMPLETED
                : self::STATUS_PROGRESSED,
            'log' => ['kind' => 'explore', 'detail' => "{$stepped}({$goal['tiles_remaining']}left)"],
            'goal' => $goal,
        ];
    }

    // ------------------------------------------------------------------
    // Shared helpers
    // ------------------------------------------------------------------

    /**
     * Walk one step toward $target, avoiding casino tiles. Used by
     * every travel-to-tile goal. If the primary direction is blocked
     * by a casino, tries the secondary (perpendicular) axis. If both
     * are blocked or both throw, invalidates.
     *
     * @return array{status:string, log:array<string,mixed>}
     */
    private function travelStep(Player $bot, Tile $target, string $logKind): array
    {
        $current = Tile::query()->find($bot->current_tile_id);
        if ($current === null) {
            return $this->invalidated($logKind, 'no_current_tile');
        }

        $primary = $this->directionToward($current, $target);
        if ($primary === null) {
            return $this->invalidated($logKind, 'already_there_but_not_on_tile');
        }

        $secondary = $this->secondaryAxis($current, $target, $primary);
        $tried = [$primary];
        if ($secondary !== null && $secondary !== $primary) {
            $tried[] = $secondary;
        }

        foreach ($tried as $dir) {
            $next = $this->neighbourTile($current, $dir);
            if ($next !== null && $next->type === 'casino') {
                continue;
            }
            try {
                $this->travel->travel($bot->id, $dir);

                // Loot crate arrival hook for bots. Mirrors the
                // human post-travel call from Map controllers. The
                // onArrival call is a no-op on non-wasteland tiles
                // and transactional, so it's safe to always invoke.
                // If a crate is present or spawned, roll the bot's
                // per-difficulty open chance; on hit, call open()
                // via the same service path a human uses.
                $bot->refresh();
                $this->maybeEngageLootCrate($bot);

                return [
                    'status' => self::STATUS_PROGRESSED,
                    'log' => ['kind' => $logKind, 'detail' => $dir],
                ];
            } catch (Throwable) {
                continue;
            }
        }

        return $this->invalidated($logKind, 'path_blocked');
    }

    /**
     * Run the loot-crate onArrival hook for a bot and, if a crate
     * surfaces, roll the per-difficulty open chance to decide whether
     * the bot opens it or walks past. Bots never place sabotage
     * crates in v1 (config flag `loot.bots.place_sabotage`) and
     * never open their own (rejected by the service anyway). Any
     * exception from open() is swallowed and logged — a race with
     * another bot racing for the same crate must never kill the tick.
     */
    private function maybeEngageLootCrate(Player $bot): void
    {
        /** @var Tile|null $tile */
        $tile = Tile::query()->find($bot->current_tile_id);
        if ($tile === null) {
            return;
        }

        $crate = $this->lootCrates->onArrival($bot, $tile);
        if ($crate === null) {
            return;
        }

        // Bots never open their own sabotage crate (service would
        // reject) — skip the roll and continue the tick.
        if ($crate->placed_by_player_id !== null
            && (int) $crate->placed_by_player_id === (int) $bot->id) {
            return;
        }

        $difficulty = (string) ($bot->bot_difficulty ?? 'normal');
        $chanceKey = 'loot.bots.open_chance.'.$difficulty;
        $chance = (float) $this->config->get($chanceKey, 0.75);

        // Deterministic roll keyed on bot+crate so replay-mode tests
        // can force the outcome without stubbing the whole service.
        $eventKey = 'bot-loot-'.$bot->id.'-c'.$crate->id;
        if (! $this->rng->rollBool('loot.bots.open', $eventKey, max(0.0, min(1.0, $chance)))) {
            return;
        }

        try {
            $this->lootCrates->open($bot->id, (int) $crate->id);
        } catch (Throwable) {
            // Race or state-drift (another visitor opened it first,
            // bot walked off-tile mid-call, etc.). Swallow — the bot's
            // primary goal continues unaffected.
        }
    }

    /**
     * One-step direction from $from toward $to along the larger delta.
     */
    private function directionToward(Tile $from, Tile $to): ?string
    {
        $dx = (int) $to->x - (int) $from->x;
        $dy = (int) $to->y - (int) $from->y;
        if ($dx === 0 && $dy === 0) {
            return null;
        }
        if (abs($dx) >= abs($dy)) {
            return $dx > 0 ? 'e' : 'w';
        }

        return $dy > 0 ? 'n' : 's';
    }

    /**
     * Perpendicular-axis direction toward the same target. Lets the
     * travel fallback step around a casino on the primary axis without
     * losing sight of the goal. Returns null if the target is on the
     * primary axis only (no secondary delta to exploit).
     */
    private function secondaryAxis(Tile $from, Tile $to, string $primary): ?string
    {
        $dx = (int) $to->x - (int) $from->x;
        $dy = (int) $to->y - (int) $from->y;

        if (in_array($primary, ['e', 'w'], true)) {
            if ($dy === 0) {
                return null;
            }

            return $dy > 0 ? 'n' : 's';
        }

        if ($dx === 0) {
            return null;
        }

        return $dx > 0 ? 'e' : 'w';
    }

    /**
     * Perpendicular direction (either one) for the explore fallback.
     * Deterministic — no RNG needed, rotates clockwise.
     */
    private function perpendicular(string $heading): string
    {
        return match ($heading) {
            'n' => 'e',
            'e' => 's',
            's' => 'w',
            'w' => 'n',
            default => 'n',
        };
    }

    private function neighbourTile(Tile $from, string $direction): ?Tile
    {
        [$dx, $dy] = match ($direction) {
            'n' => [0, 1],
            's' => [0, -1],
            'e' => [1, 0],
            'w' => [-1, 0],
            default => [0, 0],
        };

        return Tile::query()
            ->where('x', $from->x + $dx)
            ->where('y', $from->y + $dy)
            ->first();
    }

    private function pickBestDrillPoint(int $oilFieldId): ?DrillPoint
    {
        return DrillPoint::query()
            ->where('oil_field_id', $oilFieldId)
            ->whereNull('drilled_at')
            ->orderByRaw("FIELD(quality, 'gusher', 'standard', 'trickle', 'dry')")
            ->first();
    }

    /**
     * @return array{status:string, log:array{kind:string, detail:string}}
     */
    private function invalidated(string $kind, string $reason): array
    {
        return [
            'status' => self::STATUS_INVALIDATED,
            'log' => ['kind' => $kind, 'detail' => "invalidated:{$reason}"],
        ];
    }
}
