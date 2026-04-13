<?php

use App\Domain\Bot\BotDecisionService;
use App\Domain\Bot\BotSpawnService;
use App\Domain\Config\GameConfigResolver;
use App\Domain\World\WorldService;
use App\Models\Player;
use App\Models\Tile;
use App\Models\TileCombat;
use App\Models\User;

beforeEach(function () {
    app(WorldService::class)->generateInitialWorld(seed: 42);
});

/**
 * Setup: a bot + a human target sharing a wasteland tile, both with
 * full moves, oil, and cleared immunity. The bot is given a balanced
 * stat line so its win-chance estimate for the human neighbour lands
 * safely inside the [min_win_chance, bully_cap] sweet spot.
 *
 * @return array{0: Player, 1: Player, 2: Tile}
 */
function setupBotNextToHuman(string $tier = 'normal'): array
{
    /** @var BotSpawnService $spawner */
    $spawner = app(BotSpawnService::class);
    $bot = $spawner->spawn('Prowler'.uniqid(), $tier);

    $user = User::factory()->create();
    $human = app(WorldService::class)->spawnPlayer($user->id);

    /** @var Tile $wasteland */
    $wasteland = Tile::query()->where('type', 'wasteland')->first();

    $bot->update([
        'current_tile_id' => $wasteland->id,
        'oil_barrels' => 1000,
        'strength' => 10,
        'immunity_expires_at' => null,
        'moves_current' => 200,
        'moves_updated_at' => now(),
    ]);
    $human->update([
        'current_tile_id' => $wasteland->id,
        'oil_barrels' => 1000,
        'strength' => 9, // slightly weaker → bot's est. win chance lands around 0.6–0.7
        'immunity_expires_at' => null,
        'moves_current' => 200,
    ]);

    return [$bot->refresh(), $human->refresh(), $wasteland->refresh()];
}

it('normal bot opportunistically engages a viable human neighbour', function () {
    [$bot, $human] = setupBotNextToHuman('normal');

    // Force the engagement coinflip to ALWAYS fire.
config([
        'game.bots.tile_combat.enabled' => true,
        'game.bots.difficulty.normal.tile_combat_engagement_prob' => 1.0,
        'game.bots.difficulty.normal.tile_combat_min_win_chance' => 0.4,
        'game.bots.tile_combat.bully_cap_win_chance' => 0.99,
    ]);
    app(GameConfigResolver::class)->flush();

    app(BotDecisionService::class)->tick($bot);

    // Expect a tile_combats row for this encounter — either attacker
    // or defender in the pair (the service is symmetric about direction).
    $fought = TileCombat::query()
        ->where(function ($q) use ($bot, $human) {
            $q->where('attacker_player_id', $bot->id)
              ->orWhere('defender_player_id', $bot->id);
        })
        ->where(function ($q) use ($human) {
            $q->where('attacker_player_id', $human->id)
              ->orWhere('defender_player_id', $human->id);
        })
        ->exists();

    expect($fought)->toBeTrue();
});

it('easy bot never initiates a wasteland duel', function () {
    [$bot] = setupBotNextToHuman('easy');

// Default easy config has engagement_prob = 0.
    app(BotDecisionService::class)->tick($bot);

    expect(TileCombat::query()->count())->toBe(0);
});

it('bot skips near-guaranteed wins (bully filter — no loot to gain)', function () {
    [$bot, $human] = setupBotNextToHuman('normal');

    // Make the bot overwhelmingly stronger so estimate → 1.0
    $bot->update(['strength' => 50]);
    $human->update(['strength' => 1]);

config([
        'game.bots.tile_combat.enabled' => true,
        'game.bots.difficulty.normal.tile_combat_engagement_prob' => 1.0,
        'game.bots.difficulty.normal.tile_combat_min_win_chance' => 0.0,
        'game.bots.tile_combat.bully_cap_win_chance' => 0.80,
    ]);
    app(GameConfigResolver::class)->flush();

    app(BotDecisionService::class)->tick($bot);

    // Bully filter should have blocked the engagement.
    expect(TileCombat::query()->count())->toBe(0);
});

it('bot skips targets inside the 48h new-player immunity window', function () {
    [$bot, $human] = setupBotNextToHuman('normal');

    $human->update(['immunity_expires_at' => now()->addHours(24)]);

config([
        'game.bots.tile_combat.enabled' => true,
        'game.bots.difficulty.normal.tile_combat_engagement_prob' => 1.0,
        'game.bots.difficulty.normal.tile_combat_min_win_chance' => 0.0,
        'game.bots.tile_combat.bully_cap_win_chance' => 0.99,
    ]);
    app(GameConfigResolver::class)->flush();

    app(BotDecisionService::class)->tick($bot);

    expect(TileCombat::query()->count())->toBe(0);
});
