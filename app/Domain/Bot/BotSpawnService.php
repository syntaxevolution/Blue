<?php

namespace App\Domain\Bot;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Config\RngService;
use App\Domain\World\WorldService;
use App\Models\Player;
use App\Models\Tile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Bot lifecycle — spawn/destroy/set-difficulty.
 *
 * Bots are real User + Player rows. Spawn reuses the exact same
 * WorldService::spawnPlayer path a human takes, which guarantees the
 * location is a wasteland tile inside the spawn band (never a
 * non-playable type) and all starting loadout values come from
 * GameConfig. The only differences:
 *   - users.is_bot = true
 *   - synthetic email so Fortify/Socialite are never touched
 *   - name_claimed_at = now() so the broken-item + username middleware
 *     treats them as fully onboarded
 *   - players.bot_difficulty set to one of the tiers in
 *     config/game.php 'bots.difficulty'
 */
class BotSpawnService
{
    public function __construct(
        private readonly GameConfigResolver $config,
        private readonly WorldService $world,
        private readonly RngService $rng,
    ) {}

    public function availableDifficulties(): array
    {
        $tiers = $this->config->get('bots.difficulty', []);
        return is_array($tiers) ? array_keys($tiers) : [];
    }

    public function spawn(?string $name, string $difficulty): Player
    {
        $tiers = $this->availableDifficulties();
        if (! in_array($difficulty, $tiers, true)) {
            throw new InvalidArgumentException(
                "Unknown bot difficulty '{$difficulty}'. Expected one of: "
                    .implode(', ', $tiers),
            );
        }

        $name = $name !== null && trim($name) !== ''
            ? $this->sanitizeName(trim($name))
            : $this->generateName();

        $emailDomain = (string) $this->config->get('bots.email_domain', 'bots.cashclash.local');

        return DB::transaction(function () use ($name, $difficulty, $emailDomain) {
            $email = 'bot-'.Str::lower(Str::random(16)).'@'.$emailDomain;

            /** @var User $user */
            $user = User::create([
                'name' => $this->uniquifyName($name),
                'name_claimed_at' => now(),
                'email' => $email,
                'email_verified_at' => now(),
                'password' => Hash::make(Str::random(32)),
                'is_admin' => false,
                'is_bot' => true,
            ]);

            $player = $this->world->spawnPlayer($user->id);

            $player->update([
                'bot_difficulty' => $difficulty,
                'bot_last_tick_at' => null,
                'bot_moves_budget' => 0,
            ]);

            return $player->refresh();
        });
    }

    public function setDifficulty(Player $bot, string $difficulty): void
    {
        if (! $bot->isBot()) {
            throw new InvalidArgumentException('Target player is not a bot');
        }
        $tiers = $this->availableDifficulties();
        if (! in_array($difficulty, $tiers, true)) {
            throw new InvalidArgumentException(
                "Unknown bot difficulty '{$difficulty}'. Expected one of: "
                    .implode(', ', $tiers),
            );
        }
        $bot->update(['bot_difficulty' => $difficulty]);
    }

    /**
     * Destroy a bot cleanly: release the base tile back to wasteland,
     * delete the Player and User rows. Cascades via FK handle most
     * child rows (items, drill counts, spy attempts referencing this
     * player via cascadeOnDelete where configured).
     */
    public function destroy(Player $bot): void
    {
        if (! $bot->isBot()) {
            throw new InvalidArgumentException('Refusing to destroy a non-bot player');
        }

        DB::transaction(function () use ($bot) {
            $baseTile = Tile::query()->find($bot->base_tile_id);

            // Release MDN state first to avoid FK violations.
            if ($bot->mdn_id !== null) {
                \App\Models\MdnMembership::query()
                    ->where('player_id', $bot->id)
                    ->delete();
            }

            $userId = $bot->user_id;

            // Cascade-clear rows that don't have ON DELETE CASCADE.
            \App\Models\SpyAttempt::query()
                ->where('spy_player_id', $bot->id)
                ->orWhere('target_player_id', $bot->id)
                ->delete();
            \App\Models\Attack::query()
                ->where('attacker_player_id', $bot->id)
                ->orWhere('defender_player_id', $bot->id)
                ->delete();

            $bot->delete();

            if ($baseTile && $baseTile->type === 'base') {
                $baseTile->update(['type' => 'wasteland', 'subtype' => null]);
            }

            User::query()->whereKey($userId)->delete();
        });
    }

    /**
     * Build a random bot name from the configured adjective/noun pool.
     * Always 5..15 chars to pass the username rule and contains only
     * alphanumerics. All random picks go through RngService so name
     * generation stays auditable and replay-safe.
     */
    public function generateName(): string
    {
        $pool = $this->config->get('bots.name_pool', []);
        $adjectives = array_values((array) ($pool['adjectives'] ?? ['Rusty']));
        $nouns = array_values((array) ($pool['nouns'] ?? ['Drifter']));

        $eventKey = 'bot.name.'.microtime(true);
        $adj = $adjectives[$this->rng->rollInt('bot.name', $eventKey.'.adj', 0, count($adjectives) - 1)];
        $noun = $nouns[$this->rng->rollInt('bot.name', $eventKey.'.noun', 0, count($nouns) - 1)];
        $suffix = (string) $this->rng->rollInt('bot.name', $eventKey.'.suf', 10, 99);

        $candidate = $this->sanitizeName($adj.$noun.$suffix);
        return $this->uniquifyName($candidate);
    }

    private function sanitizeName(string $raw): string
    {
        $raw = preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '';
        if (mb_strlen($raw) < 5) {
            $pad = (string) $this->rng->rollInt('bot.name.pad', 'pad-'.microtime(true), 0, 9);
            $raw = str_pad($raw, 5, $pad);
        }
        if (mb_strlen($raw) > 15) {
            $raw = mb_substr($raw, 0, 15);
        }
        return $raw;
    }

    private function uniquifyName(string $base): string
    {
        $candidate = $base;
        $suffix = 0;

        while (User::query()->whereRaw('LOWER(name) = ?', [Str::lower($candidate)])->exists()) {
            $suffix++;
            $stem = mb_substr($base, 0, max(5, 15 - strlen((string) $suffix)));
            $candidate = $stem.$suffix;
            if ($suffix > 9999) {
                throw new RuntimeException('BotSpawnService: unable to generate unique name after 9999 tries');
            }
        }

        return $candidate;
    }
}
