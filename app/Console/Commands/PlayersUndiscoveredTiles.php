<?php

namespace App\Console\Commands;

use App\Models\Player;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Prints the map coordinates a player has not discovered yet.
 *
 * Examples:
 *   php artisan players:undiscovered-tiles DustBaron
 *   php artisan players:undiscovered-tiles player@example.com --json
 *   php artisan players:undiscovered-tiles DustBaron --count
 */
class PlayersUndiscoveredTiles extends Command
{
    protected $signature = 'players:undiscovered-tiles
        {user : User email or username}
        {--json : Output coordinates as JSON}
        {--count : Print only the undiscovered tile count}';

    protected $description = 'Print coordinates of tiles a player has not discovered.';

    public function handle(): int
    {
        $lookup = (string) $this->argument('user');

        $userQuery = User::query();
        if (str_contains($lookup, '@')) {
            $userQuery->where('email', $lookup);
        } else {
            $userQuery->where('name', $lookup);
        }

        /** @var User|null $user */
        $user = $userQuery->first();
        if ($user === null) {
            $this->error("No user found matching: {$lookup}");

            return self::FAILURE;
        }

        /** @var Player|null $player */
        $player = Player::query()->where('user_id', $user->id)->first();
        if ($player === null) {
            $this->error("User '{$user->name}' has no player record yet (never entered the map).");

            return self::FAILURE;
        }

        $query = DB::table('tiles')
            ->leftJoin('tile_discoveries', function ($join) use ($player) {
                $join->on('tiles.id', '=', 'tile_discoveries.tile_id')
                    ->where('tile_discoveries.player_id', '=', $player->id);
            })
            ->whereNull('tile_discoveries.tile_id');

        $count = (clone $query)->count();
        if ((bool) $this->option('count')) {
            $this->line((string) $count);

            return self::SUCCESS;
        }

        $tiles = $query
            ->orderByDesc('tiles.y')
            ->orderBy('tiles.x')
            ->get([
                'tiles.x',
                'tiles.y',
                'tiles.type',
                'tiles.subtype',
            ])
            ->map(fn (object $tile): array => [
                'x' => (int) $tile->x,
                'y' => (int) $tile->y,
                'type' => (string) $tile->type,
                'subtype' => $tile->subtype,
            ])
            ->values();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($tiles->all(), JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($tiles->isEmpty()) {
            $this->info("All {$this->totalTileCount()} tiles discovered for {$user->name}.");

            return self::SUCCESS;
        }

        $this->table(
            ['X', 'Y', 'Type', 'Subtype'],
            $tiles->map(fn (array $tile): array => [
                $tile['x'],
                $tile['y'],
                $tile['type'],
                $tile['subtype'] ?? '',
            ])->all(),
        );

        $this->info(sprintf(
            'Undiscovered tiles: %d / %d for %s.',
            $count,
            $this->totalTileCount(),
            $user->name,
        ));

        return self::SUCCESS;
    }

    private function totalTileCount(): int
    {
        return (int) DB::table('tiles')->count();
    }
}
