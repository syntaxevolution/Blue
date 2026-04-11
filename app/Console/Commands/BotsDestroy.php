<?php

namespace App\Console\Commands;

use App\Domain\Bot\BotSpawnService;
use App\Models\Player;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Tear down one or more bot players.
 *
 * Examples:
 *   php artisan bots:destroy 12
 *   php artisan bots:destroy 12 13 14
 *   php artisan bots:destroy --all
 *   php artisan bots:destroy --difficulty=easy
 *
 * --force skips the y/N confirmation prompt. Every removed bot releases
 * its base tile back to 'wasteland', which keeps the spawn pool healthy
 * and allows a new human player to take over the same coordinates.
 */
class BotsDestroy extends Command
{
    protected $signature = 'bots:destroy
        {id?* : One or more bot player IDs to destroy}
        {--all : Destroy every bot}
        {--difficulty= : Destroy every bot of this difficulty}
        {--force : Skip confirmation prompt}';

    protected $description = 'Destroy bot players and release their base tiles back to wasteland.';

    public function handle(BotSpawnService $spawner): int
    {
        $ids = (array) $this->argument('id');
        $all = (bool) $this->option('all');
        $difficulty = $this->option('difficulty');

        $query = Player::query()->whereHas('user', fn ($q) => $q->where('is_bot', true));

        if (! $all && $difficulty === null && $ids === []) {
            $this->error('Provide at least one bot id, or use --all / --difficulty.');
            return self::INVALID;
        }

        if (! $all) {
            if ($ids !== []) {
                $query->whereIn('id', $ids);
            }
            if ($difficulty !== null) {
                $query->where('bot_difficulty', $difficulty);
            }
        }

        $bots = $query->with('user')->get();
        if ($bots->isEmpty()) {
            $this->warn('No matching bots.');
            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->line('About to destroy:');
            foreach ($bots as $b) {
                $this->line("  #{$b->id}  {$b->user?->name}  ({$b->bot_difficulty})");
            }
            if (! $this->confirm('Proceed?', false)) {
                $this->info('Aborted.');
                return self::SUCCESS;
            }
        }

        $destroyed = 0;
        foreach ($bots as $bot) {
            $spawner->destroy($bot);
            $destroyed++;
        }

        $this->info("Destroyed {$destroyed} bot(s).");
        return self::SUCCESS;
    }
}
