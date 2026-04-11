<?php

use App\Domain\Leaderboard\LeaderboardService;
use App\Domain\World\WorldService;
use App\Models\Player;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| LeaderboardService — dashboard top-5 boards
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    app(WorldService::class)->generateInitialWorld(seed: 42);
    Cache::flush();
});

function makePlayerWithStats(array $overrides = []): Player
{
    $user = User::factory()->create([
        'name' => $overrides['username'] ?? ('p'.fake()->unique()->numberBetween(100000, 999999)),
        'email' => $overrides['email'] ?? fake()->unique()->safeEmail(),
    ]);

    $player = app(WorldService::class)->spawnPlayer($user->id);

    $player->update(array_intersect_key($overrides, array_flip([
        'akzar_cash',
        'oil_barrels',
        'strength',
        'fortification',
        'stealth',
        'security',
    ])));

    return $player->fresh();
}

it('ranks players by akzar cash descending and caps at 5 rows', function () {
    // 7 players with distinct cash values so the top 5 are unambiguous.
    makePlayerWithStats(['akzar_cash' => 10.00]);
    makePlayerWithStats(['akzar_cash' => 500.00]);
    makePlayerWithStats(['akzar_cash' => 250.00]);
    makePlayerWithStats(['akzar_cash' => 999.99]);
    makePlayerWithStats(['akzar_cash' => 50.00]);
    makePlayerWithStats(['akzar_cash' => 1.00]);
    makePlayerWithStats(['akzar_cash' => 125.00]);

    $boards = app(LeaderboardService::class)->boards();

    expect($boards['akzar_cash'])->toHaveCount(5);
    expect($boards['akzar_cash'][0]['value'])->toBe(999.99);
    expect($boards['akzar_cash'][1]['value'])->toBe(500.00);
    expect($boards['akzar_cash'][2]['value'])->toBe(250.00);
    expect($boards['akzar_cash'][3]['value'])->toBe(125.00);
    expect($boards['akzar_cash'][4]['value'])->toBe(50.00);
    expect($boards['akzar_cash'][0]['rank'])->toBe(1);
    expect($boards['akzar_cash'][4]['rank'])->toBe(5);
});

it('ranks players by stored oil descending', function () {
    makePlayerWithStats(['oil_barrels' => 100]);
    makePlayerWithStats(['oil_barrels' => 5000]);
    makePlayerWithStats(['oil_barrels' => 2500]);

    $boards = app(LeaderboardService::class)->boards();

    expect($boards['stored_oil'][0]['value'])->toBe(5000);
    expect($boards['stored_oil'][1]['value'])->toBe(2500);
    expect($boards['stored_oil'][2]['value'])->toBe(100);
});

it('ranks players by sum of str + fort + stealth + security', function () {
    makePlayerWithStats(['strength' => 10, 'fortification' => 5, 'stealth' => 3, 'security' => 2]); // 20
    makePlayerWithStats(['strength' => 1, 'fortification' => 1, 'stealth' => 1, 'security' => 1]); //  4
    makePlayerWithStats(['strength' => 15, 'fortification' => 15, 'stealth' => 15, 'security' => 15]); // 60

    $boards = app(LeaderboardService::class)->boards();

    expect($boards['stat_total'][0]['value'])->toBe(60);
    expect($boards['stat_total'][1]['value'])->toBe(20);
    expect($boards['stat_total'][2]['value'])->toBe(4);
});

it('includes bots without flagging them as bots', function () {
    $bot = makePlayerWithStats([
        'username' => 'RustyJack',
        'email' => 'rustyjack@bots.cashclash.local',
        'akzar_cash' => 9999.99,
    ]);

    $boards = app(LeaderboardService::class)->boards();

    // Bot is the only player with cash — must sit at rank 1 and
    // the payload must not contain any bot marker.
    $top = $boards['akzar_cash'][0];
    expect($top['player_id'])->toBe($bot->id);
    expect($top['username'])->toBe('RustyJack');
    expect($top)->not->toHaveKey('is_bot');
});

it('memoizes results — a second call does not re-query the DB', function () {
    makePlayerWithStats(['akzar_cash' => 500.00]);

    $service = app(LeaderboardService::class);
    $first = $service->boards();

    // Add a richer player *after* the first call. Without cache busting
    // the second call should still return the stale top-5.
    makePlayerWithStats(['akzar_cash' => 99999.99]);

    $second = $service->boards();
    expect($second['akzar_cash'][0]['value'])->toBe(500.00);

    // After bust(), the new player appears.
    $service->bust();
    $third = $service->boards();
    expect($third['akzar_cash'][0]['value'])->toBe(99999.99);
});

it('dashboard response embeds leaderboards and current player id', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/map'); // auto-spawn
    $user->player->update(['akzar_cash' => 888.88]);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->has('leaderboards.akzar_cash')
        ->has('leaderboards.stored_oil')
        ->has('leaderboards.stat_total')
        ->where('currentPlayerId', $user->player->id)
    );
});
