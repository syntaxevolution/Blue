<?php

namespace App\Console\Commands;

use App\Domain\Bot\BotGoalPlanner;
use App\Models\Player;
use Illuminate\Console\Command;

class BotsList extends Command
{
    protected $signature = 'bots:list {--difficulty=}';

    protected $description = 'List active bot players.';

    public function handle(BotGoalPlanner $planner): int
    {
        $difficulty = $this->option('difficulty');

        $query = Player::query()
            ->with(['user:id,name,is_bot', 'currentTile:id,x,y'])
            ->whereHas('user', fn ($q) => $q->where('is_bot', true));

        if ($difficulty !== null) {
            $query->where('bot_difficulty', $difficulty);
        }

        $bots = $query->orderBy('id')->get();

        if ($bots->isEmpty()) {
            $this->warn('No bots.');

            return self::SUCCESS;
        }

        $rows = $bots->map(function (Player $p) use ($planner) {
            $defensive = $planner->isInDefensiveMode($p);

            return [
                'id' => $p->id,
                'name' => $p->user?->name ?? '—',
                'difficulty' => $p->bot_difficulty ?? '—',
                'drill_tier' => 'T'.(int) $p->drill_tier,
                'stats' => $this->formatStats($p),
                'cash' => number_format((float) $p->akzar_cash, 2),
                'barrels' => $p->oil_barrels,
                'intel' => $p->intel,
                'moves' => $p->moves_current,
                'pos' => $p->currentTile ? "({$p->currentTile->x},{$p->currentTile->y})" : '—',
                'mode' => $defensive ? 'DEF' : '—',
                'goal' => $this->formatGoal($p),
                'expires' => $this->formatExpires($p),
                'fails' => (int) $p->bot_goal_fail_count > 0 ? (string) $p->bot_goal_fail_count : '',
                'last_tick' => $p->bot_last_tick_at?->diffForHumans() ?? 'never',
            ];
        })->all();

        $this->table(
            ['#', 'Name', 'Tier', 'DrT', 'S/F/St/Sc', 'Cash', 'Oil', 'Intel', 'Moves', 'Pos', 'Mode', 'Goal', 'Expires', 'Fails', 'Last tick'],
            $rows,
        );
        $this->info("Total bots: {$bots->count()}");

        return self::SUCCESS;
    }

    /**
     * Compact four-stat line: strength/fortification/stealth/security.
     * Stat cap is 25 per ultraplan, so a single slash-joined cell stays
     * ≤11 characters wide even at max.
     */
    private function formatStats(Player $p): string
    {
        return implode('/', [
            (int) $p->strength,
            (int) $p->fortification,
            (int) $p->stealth,
            (int) $p->security,
        ]);
    }

    /**
     * Short relative expiry for the current goal. Empty string when no
     * goal / no expiry is set so the column doesn't scream '—' next to
     * every fresh bot. Past-dated values (shouldn't normally appear —
     * the tick loop replans on load) are flagged with a † so stale
     * rows are visible during debugging.
     */
    private function formatExpires(Player $p): string
    {
        $expires = $p->bot_goal_expires_at;
        if ($expires === null) {
            return '';
        }
        if ($expires->isPast()) {
            return '† '.$expires->diffForHumans();
        }

        return $expires->diffForHumans();
    }

    /**
     * Render a compact one-cell summary of the bot's persisted goal.
     * Returns '—' when no goal is set (fresh bot, or just replanned to
     * null between ticks). Format intentionally terse so the table row
     * stays readable:
     *
     *   drill #412          — heading for oil field tile 412
     *   shop/tech:heavy     — saving for 'heavy_drill' at a tech post
     *   raid p=17           — raiding player 17
     *   spy p=17 (revenge)  — defensive-mode revenge spy
     *   sabotage #412 @2,3  — planting on field tile 412 cell (2,3)
     *   explore e 12/15     — walking east, 12 tiles of budget left
     */
    private function formatGoal(Player $p): string
    {
        $goal = $p->bot_current_goal;
        if (! is_array($goal) || ! isset($goal['kind'])) {
            return '—';
        }

        $revenge = ($goal['defensive_revenge'] ?? false) === true ? ' (revenge)' : '';

        return match ($goal['kind']) {
            'drill'    => 'drill #'.($goal['tile_id'] ?? '?'),
            'shop'     => 'shop/'.($goal['post_type'] ?? '?').':'.($goal['want_item'] ?? '?'),
            'spy'      => 'spy p='.($goal['target_player_id'] ?? '?').$revenge,
            'raid'     => 'raid p='.($goal['target_player_id'] ?? '?').$revenge,
            'sabotage' => 'sabotage #'.($goal['tile_id'] ?? '?').' @'.($goal['grid_x'] ?? '?').','.($goal['grid_y'] ?? '?'),
            'explore'  => 'explore '.($goal['heading'] ?? '?').' '.($goal['tiles_remaining'] ?? '?').'/'.config('game.bots.explore_budget_tiles'),
            default    => (string) $goal['kind'],
        };
    }
}
