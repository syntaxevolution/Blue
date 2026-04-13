<?php

namespace App\Console\Commands;

use App\Models\Player;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Admin-only console command: grant bonus oil barrels to a single player.
 *
 * Examples:
 *   php artisan players:grant-oil you@example.com 500
 *   php artisan players:grant-oil DustBaron 100
 *
 * The lookup argument is matched against users.email first (if it
 * contains '@') and then users.name. Oil has no soft cap and no
 * regen, so the grant is a straight add — no reconcile pass needed
 * unlike players:grant-moves.
 */
class PlayersGrantOil extends Command
{
    protected $signature = 'players:grant-oil
        {user : User email or username}
        {amount : Number of barrels to add (positive integer)}';

    protected $description = 'Grant bonus oil barrels to a single player by email or username.';

    public function handle(): int
    {
        $lookup = (string) $this->argument('user');
        $amount = (int) $this->argument('amount');

        if ($amount <= 0) {
            $this->error('amount must be a positive integer');

            return self::INVALID;
        }

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

        $result = DB::transaction(function () use ($player, $amount) {
            /** @var Player $locked */
            $locked = Player::query()->lockForUpdate()->findOrFail($player->id);

            $before = (int) $locked->oil_barrels;
            $after = $before + $amount;

            $locked->update(['oil_barrels' => $after]);

            return [
                'before' => $before,
                'after' => $after,
                'granted' => $after - $before,
            ];
        });

        $this->table(
            ['User', 'Player ID', 'Before', 'Granted', 'After'],
            [[
                $user->name,
                $player->id,
                $result['before'],
                $result['granted'],
                $result['after'],
            ]],
        );

        $this->info(sprintf('Granted %d barrels of oil to %s.', $result['granted'], $user->name));

        return self::SUCCESS;
    }
}
