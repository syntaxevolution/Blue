<?php

namespace App\Console\Commands;

use App\Domain\World\WorldService;
use App\Models\Tile;
use Illuminate\Console\Command;

/**
 * Seed (or re-seed) the initial world from a deterministic seed.
 *
 * Usage:
 *
 *     php artisan world:generate                     # seed=0, refuses if tiles exist
 *     php artisan world:generate --seed=2026         # specific seed
 *     php artisan world:generate --seed=42 --fresh   # wipe then regenerate
 *
 * The same --seed always produces the same world layout thanks to
 * WorldService::planInitialWorld being pure and RngService being seeded
 * per (category, eventKey). Runs inside a single DB::transaction — if
 * anything fails mid-way, nothing is left half-generated.
 */
class WorldGenerate extends Command
{
    protected $signature = 'world:generate
                            {--seed=0 : Deterministic world seed}
                            {--fresh : Wipe existing tiles before regenerating}';

    protected $description = 'Seed the initial world: tiles, oil fields, drill points, posts';

    public function handle(WorldService $world): int
    {
        $seed = (int) $this->option('seed');
        $fresh = (bool) $this->option('fresh');

        if (Tile::query()->exists()) {
            if (! $fresh) {
                $this->error('Tiles already exist. Pass --fresh to wipe and regenerate.');

                return self::FAILURE;
            }

            $this->warn('Wiping existing world — this cascades through oil_fields, drill_points, and posts.');
            Tile::query()->delete();
        }

        $this->info("Generating world with seed {$seed}...");

        $stats = $world->generateInitialWorld($seed);

        $this->newLine();
        $this->table(
            ['table', 'rows created'],
            [
                ['tiles', $stats['tiles']],
                ['oil_fields', $stats['oil_fields']],
                ['drill_points', $stats['drill_points']],
                ['posts', $stats['posts']],
            ],
        );

        $this->info('Done.');

        return self::SUCCESS;
    }
}
