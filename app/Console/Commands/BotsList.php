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
            'last_tick' => $p->bot_last_tick_at?->diffForHumans() ?? 'never',
        ])->all();

        $this->table(
            ['#', 'Name', 'Tier', 'Cash', 'Oil', 'Moves', 'Pos', 'Last tick'],
            $rows,
        );
        $this->info("Total bots: {$bots->count()}");
        return self::SUCCESS;
    }
}
