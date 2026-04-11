<?php

namespace App\Console\Commands;

use App\Domain\Player\MoveRegenService;
use App\Models\Player;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Admin-only console command: grant bonus moves to a single player.
 *
 * Examples:
 *   php artisan players:grant-moves you@example.com 200
 *   php artisan players:grant-moves DustBaron 50
 *   php artisan players:grant-moves you@example.com 500 --uncapped
 *
 * The lookup argument is matched against users.email first (if it
 * contains '@') and then users.name. The player is reconciled first
 * so the grant is added on top of the current regen state. By default
 * the resulting total is clamped to the bank cap; pass --uncapped to
 * let the grant exceed it (useful for prize drops or event payouts).
 */
class PlayersGrantMoves extends Command
{
    protected $signature = 'players:grant-moves
        {user : User email or username}
        {amount : Number of moves to add (positive integer)}
        {--uncapped : Allow the total to exceed the bank cap}';

    protected $description = 'Grant bonus moves to a single player by email or username.';

    public function handle(MoveRegenService $moveRegen): int
    {
        $lookup = (string) $this->argument('user');
        $amount = (int) $this->argument('amount');
        $uncapped = (bool) $this->option('uncapped');

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

        $bankCap = $moveRegen->bankCap();

        $result = DB::transaction(function () use ($player, $amount, $uncapped, $moveRegen, $bankCap) {
            /** @var Player $locked */
            $locked = Player::query()->lockForUpdate()->findOrFail($player->id);

            // Reconcile first so the grant is layered on the live value,
            // not a stale snapshot.
            $moveRegen->reconcile($locked);
            $locked->refresh();

            $before = (int) $locked->moves_current;
            $target = $before + $amount;
            if (! $uncapped) {
                $target = min($target, $bankCap);
            }

            $locked->update(['moves_current' => $target]);

            return [
                'before' => $before,
                'after' => $target,
                'granted' => $target - $before,
            ];
        });

        $this->table(
            ['User', 'Player ID', 'Before', 'Granted', 'After', 'Bank Cap', 'Uncapped'],
            [[
                $user->name,
                $player->id,
                $result['before'],
                $result['granted'],
                $result['after'],
                $bankCap,
                $uncapped ? 'yes' : 'no',
            ]],
        );

        if ($result['granted'] < $amount) {
            $this->warn(sprintf(
                'Only %d of %d moves granted — player was at or near bank cap. Pass --uncapped to override.',
                $result['granted'],
                $amount,
            ));
        } else {
            $this->info(sprintf('Granted %d moves to %s.', $result['granted'], $user->name));
        }

        return self::SUCCESS;
    }
}
