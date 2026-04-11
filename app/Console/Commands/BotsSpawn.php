<?php

namespace App\Console\Commands;

use App\Domain\Bot\BotSpawnService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Spin up one or more bot players at random spawn-band locations.
 *
 * Examples:
 *   php artisan bots:spawn 5
 *   php artisan bots:spawn 3 --difficulty=hard
 *   php artisan bots:spawn 2 --name=Alpha --name=Beta --difficulty=normal
 *
 * Names are optional. Missing names are auto-generated from the word
 * pool in config/game.php `bots.name_pool`. Each bot receives a random
 * wasteland spawn tile via WorldService::spawnPlayer — same code path
 * humans take — so location is always legal and random.
 */
class BotsSpawn extends Command
{
    protected $signature = 'bots:spawn
        {count : Number of bots to spawn}
        {--difficulty=normal : Difficulty tier (easy|normal|hard)}
        {--name=* : Explicit names (repeatable). Missing entries are auto-generated.}';

    protected $description = 'Spawn one or more bot players.';

    public function handle(BotSpawnService $spawner): int
    {
        $count = (int) $this->argument('count');
        $difficulty = (string) $this->option('difficulty');
        /** @var array<int,string> $names */
        $names = (array) $this->option('name');

        if ($count <= 0) {
            $this->error('count must be > 0');
            return self::INVALID;
        }

        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $name = $names[$i] ?? null;
            try {
                $player = $spawner->spawn($name, $difficulty);
                $rows[] = [
                    'id' => $player->id,
                    'name' => $player->user?->name ?? '—',
                    'difficulty' => $difficulty,
                    'tile_id' => $player->base_tile_id,
                ];
            } catch (Throwable $e) {
                $this->error("Spawn failed: {$e->getMessage()}");
                return self::FAILURE;
            }
        }

        $this->table(['Player ID', 'Name', 'Difficulty', 'Base Tile'], $rows);
        $this->info(sprintf('Spawned %d bot%s.', count($rows), count($rows) === 1 ? '' : 's'));
        return self::SUCCESS;
    }
}
