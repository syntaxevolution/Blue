<?php

namespace App\Console\Commands;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Config\RngService;
use App\Models\Casino;
use App\Models\Tile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CasinoRetrofit extends Command
{
    protected $signature = 'casino:retrofit
        {--dry-run : Show what would happen without changing the DB}';

    protected $description = 'Convert wasteland tiles to casino tiles in the existing world to reach the configured density';

    public function handle(GameConfigResolver $config, RngService $rng): int
    {
        $totalTiles = Tile::query()->count();

        if ($totalTiles === 0) {
            $this->error('No tiles exist. Run world:generate first.');

            return self::FAILURE;
        }

        $density = (float) $config->get('world.density.casinos_per_tile');
        $targetCount = (int) round($totalTiles * $density);
        $existingCount = Tile::query()->where('type', 'casino')->count();
        $needed = max(0, $targetCount - $existingCount);

        $this->info(sprintf(
            'Total tiles: %d | Target casinos: %d (%.2f%%) | Existing: %d | Needed: %d',
            $totalTiles,
            $targetCount,
            $density * 100,
            $existingCount,
            $needed,
        ));

        if ($needed === 0) {
            $this->info('World already has enough casino tiles. Nothing to do.');

            return self::SUCCESS;
        }

        $spawnRadius = (int) $config->get('world.spawn_band_radius');
        $spawnRadiusSq = $spawnRadius * $spawnRadius;

        $candidateIds = Tile::query()
            ->where('type', 'wasteland')
            ->whereRaw('(x * x + y * y) > ?', [$spawnRadiusSq])
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if (count($candidateIds) < $needed) {
            $this->error(sprintf(
                'Not enough wasteland tiles outside spawn band: have %d candidates, need %d.',
                count($candidateIds),
                $needed,
            ));

            return self::FAILURE;
        }

        $remaining = $candidateIds;
        $selectedIds = [];

        for ($i = 0; $i < $needed; $i++) {
            $index = $rng->rollInt(
                'casino.retrofit',
                "pick:{$i}",
                0,
                count($remaining) - 1,
            );
            $selectedIds[] = $remaining[$index];
            array_splice($remaining, $index, 1);
        }

        if ((bool) $this->option('dry-run')) {
            $sampleTiles = Tile::query()
                ->whereIn('id', array_slice($selectedIds, 0, 10))
                ->get(['id', 'x', 'y']);

            $this->info("Would convert {$needed} wasteland tiles to casinos. Sample coordinates:");
            $this->table(
                ['id', 'x', 'y'],
                $sampleTiles->map(fn (Tile $t) => [$t->id, $t->x, $t->y])->all(),
            );

            return self::SUCCESS;
        }

        $casinoNames = (array) $config->get('casino.names', ["Roughneck's Saloon"]);

        DB::transaction(function () use ($selectedIds, $rng, $casinoNames) {
            Tile::query()
                ->whereIn('id', $selectedIds)
                ->update(['type' => 'casino', 'subtype' => null]);

            $tiles = Tile::query()
                ->whereIn('id', $selectedIds)
                ->get(['id', 'x', 'y']);

            $now = now();
            $casinoRows = [];

            foreach ($tiles as $i => $tile) {
                $nameIndex = $rng->rollInt(
                    'casino.retrofit.name',
                    "{$tile->x}:{$tile->y}",
                    0,
                    count($casinoNames) - 1,
                );

                $casinoRows[] = [
                    'tile_id' => $tile->id,
                    'name' => $casinoNames[$nameIndex],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($casinoRows, 500) as $chunk) {
                Casino::insert($chunk);
            }
        });

        $this->info(sprintf('Done. Converted %d wasteland tiles to casino tiles.', $needed));

        $this->table(
            ['metric', 'value'],
            [
                ['tiles converted', $needed],
                ['total casinos now', $existingCount + $needed],
                ['target', $targetCount],
            ],
        );

        return self::SUCCESS;
    }
}
