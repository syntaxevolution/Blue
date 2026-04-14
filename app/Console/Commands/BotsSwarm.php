<?php

namespace App\Console\Commands;

use App\Models\Player;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Admin-only console command: order every bot in the world to
 * march on a specific player's base and attack. Bots drop their
 * current goal, ignore tier gates (easy bots raid too under a
 * swarm), and pursue spy → raid on the victim until they've fired
 * at least one attack. After the first attack (win or loss) each
 * bot is released back into normal planner behaviour.
 *
 * Safety rails:
 *   - Target immunity is checked at command time; immune targets
 *     are rejected with a clear error.
 *   - Same-MDN rule still respected on a per-bot basis (a bot that
 *     happens to share an MDN with the victim is skipped).
 *   - Swarm carries a configurable TTL (default 24h) so a stale
 *     target can never freeze bots forever — BotDecisionService's
 *     tick sweep clears expired columns on the next tick.
 *   - Re-running the command overwrites any active swarm on every
 *     bot with the new target. Use `bots:swarm --clear` (or pass
 *     no target) to disband without issuing a new one.
 *
 * Examples:
 *   php artisan bots:swarm DustBaron
 *   php artisan bots:swarm 42 --ttl=6
 *   php artisan bots:swarm player@example.com
 *   php artisan bots:swarm --clear
 */
class BotsSwarm extends Command
{
    protected $signature = 'bots:swarm
        {target? : Target player — accepts username, email, or raw player_id}
        {--ttl=24 : Hours before the swarm auto-expires}
        {--clear : Disband any active swarm on all bots (no new target)}';

    protected $description = 'Command every bot to drop their goal and raid a specific player\'s base.';

    public function handle(): int
    {
        if ((bool) $this->option('clear')) {
            return $this->disband();
        }

        $targetArg = $this->argument('target');
        if ($targetArg === null || $targetArg === '') {
            $this->error('Missing target. Pass a username, email, or player ID — or use --clear to disband an active swarm.');

            return self::INVALID;
        }

        $ttlHours = (int) $this->option('ttl');
        if ($ttlHours <= 0) {
            $this->error('--ttl must be a positive integer (hours).');

            return self::INVALID;
        }

        $target = $this->resolveTarget((string) $targetArg);
        if ($target === null) {
            $this->error("No player found matching: {$targetArg}");

            return self::FAILURE;
        }

        // Target must be raidable right now. Immune players would
        // cause every bot to stall spinning on spy retries until
        // their immunity expires. We reject the command explicitly
        // instead of letting the planner quietly no-op.
        if ($target->immunity_expires_at !== null && $target->immunity_expires_at->isFuture()) {
            $this->error(sprintf(
                '%s is under new-player immunity until %s — swarm refused.',
                $target->user->name ?? ('player#'.$target->id),
                $target->immunity_expires_at->toDateTimeString(),
            ));

            return self::FAILURE;
        }

        // Same MDN as the target? That bot can't attack per spec,
        // so it gets skipped. Query is narrowed to bots only (via
        // users.is_bot).
        $expiresAt = now()->addHours($ttlHours);
        $targetMdnId = $target->mdn_id;

        $affected = DB::transaction(function () use ($target, $expiresAt, $targetMdnId) {
            $bots = Player::query()
                ->whereHas('user', fn ($q) => $q->where('is_bot', true))
                ->where('id', '!=', $target->id)
                ->when($targetMdnId !== null, fn ($q) => $q->where(function ($q2) use ($targetMdnId) {
                    $q2->whereNull('mdn_id')->orWhere('mdn_id', '!=', $targetMdnId);
                }))
                ->lockForUpdate()
                ->get();

            foreach ($bots as $bot) {
                // Stamping the forced target AND clearing the
                // in-flight goal forces the planner to re-pick on
                // the next tick. Without the goal clear, a bot
                // mid-drill would finish that drill step before the
                // planner reads the new forced target.
                $bot->forceFill([
                    'bot_forced_raid_target_player_id' => $target->id,
                    'bot_forced_raid_expires_at' => $expiresAt,
                    'bot_current_goal' => null,
                    'bot_goal_expires_at' => null,
                    'bot_goal_fail_count' => 0,
                ])->save();
            }

            return $bots->count();
        });

        $this->info(sprintf(
            'Swarm issued: %d bot(s) now targeting %s (expires %s).',
            $affected,
            $target->user->name ?? ('player#'.$target->id),
            $expiresAt->toDateTimeString(),
        ));

        if ($affected === 0) {
            $this->warn('No bots were eligible — either none exist yet, or every bot is in the same MDN as the target.');
        } else {
            $this->line('Bots will spy first if they lack in-window intel, then attack. Each bot reverts to normal planning after one attack (success or failure).');
        }

        return self::SUCCESS;
    }

    /**
     * Resolve the target argument to a Player. Accepts:
     *   - integer player_id (raw numeric string)
     *   - email (contains @)
     *   - username (anything else, matched against users.name)
     */
    private function resolveTarget(string $arg): ?Player
    {
        // Numeric → player id direct lookup.
        if (ctype_digit($arg)) {
            /** @var Player|null $byId */
            $byId = Player::query()->with('user')->find((int) $arg);
            if ($byId !== null) {
                return $byId;
            }
        }

        $userQuery = User::query();
        if (str_contains($arg, '@')) {
            $userQuery->where('email', $arg);
        } else {
            $userQuery->where('name', $arg);
        }

        /** @var User|null $user */
        $user = $userQuery->first();
        if ($user === null) {
            return null;
        }

        /** @var Player|null $player */
        $player = Player::query()->with('user')->where('user_id', $user->id)->first();

        return $player;
    }

    /**
     * Disband: clear bot_forced_raid_* on every bot. Does not
     * touch in-flight goals — bots will naturally re-plan on the
     * next tick when their current goal completes.
     */
    private function disband(): int
    {
        $count = DB::transaction(function () {
            return Player::query()
                ->whereHas('user', fn ($q) => $q->where('is_bot', true))
                ->whereNotNull('bot_forced_raid_target_player_id')
                ->lockForUpdate()
                ->update([
                    'bot_forced_raid_target_player_id' => null,
                    'bot_forced_raid_expires_at' => null,
                ]);
        });

        $this->info("Disbanded swarm: cleared forced target on {$count} bot(s).");

        return self::SUCCESS;
    }
}
