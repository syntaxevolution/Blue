<?php

use App\Domain\Bot\BotGoalPlanner;
use App\Domain\Config\GameConfigResolver;
use App\Domain\Loot\LootCrateService;
use App\Domain\World\FogOfWarService;
use App\Domain\World\WorldService;
use App\Models\Attack;
use App\Models\Player;
use App\Models\Tile;
use App\Models\User;
use Database\Seeders\ItemsCatalogSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    app(WorldService::class)->generateInitialWorld(seed: 42);
    $this->seed(ItemsCatalogSeeder::class);
    Cache::flush();
});

/**
 * Spawn a bot-flavoured player with controllable stat baseline +
 * recent attack history so we can drive the strength gate from
 * tests without touching combat.
 */
function botMakePlayer(int $strength = 1, int $fortification = 0, string $difficulty = 'normal'): Player
{
    $user = User::factory()->create(['is_bot' => true]);
    $player = app(WorldService::class)->spawnPlayer($user->id);
    $player->update([
        'strength' => $strength,
        'fortification' => $fortification,
        'oil_barrels' => 5000,
        'moves_current' => 200,
        'bot_difficulty' => $difficulty,
    ]);

    return $player->fresh();
}

function botSeedAttackHistory(Player $bot, int $wins, int $losses): void
{
    $defender = User::factory()->create();
    $defenderPlayer = app(WorldService::class)->spawnPlayer($defender->id);

    for ($i = 0; $i < $wins; $i++) {
        Attack::create([
            'attacker_player_id' => $bot->id,
            'defender_player_id' => $defenderPlayer->id,
            'outcome' => 'success',
            'cash_stolen' => 1.00,
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);
    }
    for ($i = 0; $i < $losses; $i++) {
        Attack::create([
            'attacker_player_id' => $bot->id,
            'defender_player_id' => $defenderPlayer->id,
            'outcome' => 'failed',
            'cash_stolen' => 0,
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);
    }
}

/*
|--------------------------------------------------------------------------
| Strength gate
|--------------------------------------------------------------------------
*/

it('blocks weak bots with no recent attack history from sabotage', function () {
    $bot = botMakePlayer(strength: 1, fortification: 0);

    expect(app(BotGoalPlanner::class)->isStrongEnoughForSabotage($bot))->toBeFalse();
});

it('allows bots with high stat baseline to sabotage even without recent fights', function () {
    // strength + fortification = 10, default min_stat_total_fallback = 8.
    $bot = botMakePlayer(strength: 6, fortification: 4);

    expect(app(BotGoalPlanner::class)->isStrongEnoughForSabotage($bot))->toBeTrue();
});

it('blocks bots with a losing recent attack record from sabotage', function () {
    $bot = botMakePlayer(strength: 1, fortification: 0);
    botSeedAttackHistory($bot, wins: 1, losses: 4);

    // Win rate = 0.2, below min_recent_win_rate = 0.5
    expect(app(BotGoalPlanner::class)->isStrongEnoughForSabotage($bot))->toBeFalse();
});

it('allows bots with a winning recent attack record to sabotage', function () {
    $bot = botMakePlayer(strength: 1, fortification: 0);
    botSeedAttackHistory($bot, wins: 4, losses: 1);

    // Win rate = 0.8, above min_recent_win_rate = 0.5
    expect(app(BotGoalPlanner::class)->isStrongEnoughForSabotage($bot))->toBeTrue();
});

it('treats fewer than min_recent_attacks as the fallback case', function () {
    $bot = botMakePlayer(strength: 1, fortification: 0);
    // Only 2 attacks — below min_recent_attacks = 3 — so we fall back to
    // the stat baseline check, which fails for a 1/0 bot.
    botSeedAttackHistory($bot, wins: 2, losses: 0);

    expect(app(BotGoalPlanner::class)->isStrongEnoughForSabotage($bot))->toBeFalse();
});

it('respects config overrides for the gate thresholds', function () {
    $bot = botMakePlayer(strength: 4, fortification: 3);
    // Default min_stat_total_fallback = 8 → 4+3=7 fails.
    expect(app(BotGoalPlanner::class)->isStrongEnoughForSabotage($bot))->toBeFalse();

    // Lower the threshold to 6 → 7 passes.
    app(GameConfigResolver::class)->set('bots.sabotage_gate.min_stat_total_fallback', 6);

    expect(app(BotGoalPlanner::class)->isStrongEnoughForSabotage($bot))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Loot trap planner path
|--------------------------------------------------------------------------
*/

it('weak normal bots fall through past the loot trap layer to shop/explore', function () {
    $bot = botMakePlayer(strength: 1, fortification: 0, difficulty: 'normal');

    // Give the bot a loot crate so the only thing keeping it from
    // planting is the strength gate.
    DB::table('player_items')->insert([
        'player_id' => $bot->id,
        'item_key' => 'crate_siphon_oil',
        'quantity' => 3,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Move bot onto a wasteland tile so the planner has eligible
    // wasteland in fog (otherwise the loot trap path bails on
    // "no discovered wasteland").
    $wasteland = Tile::query()->where('type', 'wasteland')->first();
    if ($wasteland === null) {
        test()->markTestSkipped('No wasteland tile.');
    }
    $bot->update(['current_tile_id' => $wasteland->id]);
    app(FogOfWarService::class)->markDiscovered($bot->id, $wasteland->id);

    $tierCfg = (array) app(GameConfigResolver::class)->get('bots.difficulty.normal');
    $goal = app(BotGoalPlanner::class)->pickGoal($bot->fresh(), $tierCfg);

    // Goal must NOT be loot_trap or sabotage — the strength gate
    // should have skipped both layers.
    expect($goal['kind'] ?? '')->not->toBeIn([BotGoalPlanner::KIND_LOOT_TRAP, BotGoalPlanner::KIND_SABOTAGE]);
});

it('strong bots with crates and eligible wasteland pick a loot trap goal', function () {
    $bot = botMakePlayer(strength: 6, fortification: 4, difficulty: 'normal');

    DB::table('player_items')->insert([
        'player_id' => $bot->id,
        'item_key' => 'crate_siphon_oil',
        'quantity' => 3,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $wasteland = Tile::query()->where('type', 'wasteland')->first();
    if ($wasteland === null) {
        test()->markTestSkipped('No wasteland tile.');
    }
    $bot->update(['current_tile_id' => $wasteland->id]);

    // Reveal a few wasteland tiles in range so the planner has
    // candidates. We discover the current tile and a few neighbours.
    $fog = app(FogOfWarService::class);
    $fog->markDiscovered($bot->id, $wasteland->id);
    $nearbyWasteland = Tile::query()
        ->where('type', 'wasteland')
        ->where('id', '!=', $wasteland->id)
        ->limit(5)
        ->get();
    foreach ($nearbyWasteland as $t) {
        $fog->markDiscovered($bot->id, $t->id);
    }

    // Tier flag must be on so the planner enters the loot_trap branch.
    app(GameConfigResolver::class)->set('bots.difficulty.normal.can_place_loot_traps', true);
    // Force the bot to skip raid + spy + drill point sabotage layers
    // so the loot trap layer is the only sabotage candidate.
    app(GameConfigResolver::class)->set('bots.difficulty.normal.can_raid', false);
    app(GameConfigResolver::class)->set('bots.difficulty.normal.can_sabotage', false);

    $tierCfg = (array) app(GameConfigResolver::class)->get('bots.difficulty.normal');
    $goal = app(BotGoalPlanner::class)->pickGoal($bot->fresh(), $tierCfg);

    expect($goal)->not->toBeNull();
    expect($goal['kind'])->toBe(BotGoalPlanner::KIND_LOOT_TRAP);
    expect($goal['item_key'])->toBe('crate_siphon_oil');
});

it('skips loot trap planning when fewer than min_free_slots remain', function () {
    $bot = botMakePlayer(strength: 6, fortification: 4, difficulty: 'normal');

    DB::table('player_items')->insert([
        'player_id' => $bot->id,
        'item_key' => 'crate_siphon_oil',
        'quantity' => 3,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Push the cap floor down so the bot has no breathing room.
    app(GameConfigResolver::class)->set('loot.sabotage.max_deployed_base', 1);
    app(GameConfigResolver::class)->set('loot.sabotage.tiles_per_cap_step', 10_000_000);
    app(GameConfigResolver::class)->set('bots.loot_trap.min_free_slots', 1);
    Cache::flush();

    // Pre-deploy one crate so the bot is already at cap.
    $tile = Tile::query()->where('type', 'wasteland')->first();
    if ($tile === null) {
        test()->markTestSkipped('No wasteland tile.');
    }
    $bot->update(['current_tile_id' => $tile->id]);

    // Deploy via the service so the cap counter sees it.
    app(LootCrateService::class)->place($bot->id, 'crate_siphon_oil');

    // Move bot to a fresh wasteland tile so it isn't blocked by
    // the per-tile uniqueness rule.
    $other = Tile::query()
        ->where('type', 'wasteland')
        ->where('id', '!=', $tile->id)
        ->first();
    if ($other === null) {
        test()->markTestSkipped('Need a second wasteland tile.');
    }
    $bot->update(['current_tile_id' => $other->id]);
    app(FogOfWarService::class)->markDiscovered($bot->id, $other->id);

    app(GameConfigResolver::class)->set('bots.difficulty.normal.can_place_loot_traps', true);
    app(GameConfigResolver::class)->set('bots.difficulty.normal.can_raid', false);
    app(GameConfigResolver::class)->set('bots.difficulty.normal.can_sabotage', false);

    $tierCfg = (array) app(GameConfigResolver::class)->get('bots.difficulty.normal');
    $goal = app(BotGoalPlanner::class)->pickGoal($bot->fresh(), $tierCfg);

    expect($goal['kind'] ?? '')->not->toBe(BotGoalPlanner::KIND_LOOT_TRAP);
});
