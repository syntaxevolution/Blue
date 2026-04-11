<?php

namespace App\Console\Commands;

use App\Domain\Bot\BotSpawnService;
use App\Models\Player;
use Illuminate\Console\Command;

class BotsSetDifficulty extends Command
{
    protected $signature = 'bots:set-difficulty {id : Bot player id} {difficulty : easy|normal|hard}';

    protected $description = 'Change a running bot\'s difficulty tier.';

    public function handle(BotSpawnService $spawner): int
    {
        $id = (int) $this->argument('id');
        $difficulty = (string) $this->argument('difficulty');

        /** @var Player|null $bot */
        $bot = Player::query()->with('user')->find($id);
        if ($bot === null || ! $bot->isBot()) {
            $this->error("Player #{$id} not found or is not a bot.");
            return self::FAILURE;
        }

        $spawner->setDifficulty($bot, $difficulty);
        $this->info("Bot #{$id} is now {$difficulty}.");
        return self::SUCCESS;
    }
}
