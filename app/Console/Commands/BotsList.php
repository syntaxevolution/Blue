<?php

namespace App\Console\Commands;

use App\Models\Player;
use Illuminate\Console\Command;

class BotsList extends Command
{
    protected $signature = 'bots:list {--difficulty=}';

    protected $description = 'List active bot players.';

    public function handle(): int
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

        $rows = $bots->map(fn (Player $p) => [
            'id' => $p->id,
            'name' => $p->user?->name ?? '—',
            'difficulty' => $p->bot_difficulty ?? '—',
            'cash' => number_format((float) $p->akzar_cash, 2),
            'barrels' => $p->oil_barrels,
            'moves' => $p->moves_current,
            'pos' => $p->currentTile ? "({$p->currentTile->x},{$p->currentTile->y})" : '—',
            'goal' => $this->formatGoal($p),
            'last_tick' => $p->bot_last_tick_at?->diffForHumans() ?? 'never',
        ])->all();

        $this->table(
            ['#', 'Name', 'Tier', 'Cash', 'Oil', 'Moves', 'Pos', 'Goal', 'Last tick'],
            $rows,
        );
        $this->info("Total bots: {$bots->count()}");
        return self::SUCCESS;
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
