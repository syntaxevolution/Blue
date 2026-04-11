<?php

namespace App\Console\Commands;

use App\Domain\Bot\BotDecisionService;
use App\Models\Player;
use Illuminate\Console\Command;
use Throwable;

/**
 * Advance every bot (or a subset) by one tick. Wired to the Laravel
 * scheduler in routes/console.php at the cadence defined by
 * `bots.tick_interval_minutes` (default: every 5 minutes).
 *
 * --limit caps how many bots are processed in one invocation so a full
 * tick never blocks the scheduler. The command sorts by
 * bot_last_tick_at ASC NULLS FIRST so the starvation floor keeps every
 * bot moving.
 */
class BotsTick extends Command
{
    protected $signature = 'bots:tick
        {--id=* : Only tick these specific bot IDs}
        {--limit=50 : Maximum number of bots to tick in one invocation}';

    protected $description = 'Run one decision tick for each eligible bot.';

    public function handle(BotDecisionService $decisions): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $onlyIds = (array) $this->option('id');

        $query = Player::query()
            ->with('user:id,name,is_bot')
            ->whereHas('user', fn ($q) => $q->where('is_bot', true))
            ->orderByRaw('bot_last_tick_at IS NULL DESC')
            ->orderBy('bot_last_tick_at')
            ->limit($limit);

        if ($onlyIds !== []) {
            $query->whereIn('id', $onlyIds);
        }

        $bots = $query->get();
        if ($bots->isEmpty()) {
            $this->line('No bots to tick.');
            return self::SUCCESS;
        }

        $processed = 0;
        foreach ($bots as $bot) {
            try {
                $result = $decisions->tick($bot);
                $processed++;
                if ($this->output->isVerbose()) {
                    $summary = collect($result['actions'])
                        ->map(fn ($a) => ($a['kind'] ?? 'noop').(isset($a['detail']) ? ":{$a['detail']}" : ''))
                        ->join(', ');
                    $this->line("#{$bot->id} {$bot->user?->name}: {$summary}");
                }
            } catch (Throwable $e) {
                $this->warn("Bot #{$bot->id} tick failed: {$e->getMessage()}");
            }
        }

        $this->info("Ticked {$processed}/{$bots->count()} bot(s).");
        return self::SUCCESS;
    }
}
