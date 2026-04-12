<?php

use App\Domain\Casino\BlackjackService;
use App\Domain\Casino\CasinoService;
use App\Domain\Casino\CasinoTableManager;
use App\Domain\Casino\HoldemService;
use App\Domain\World\WorldService;
use App\Models\CasinoTable;
use App\Models\User;

/**
 * Regression tests for the "click Sit Down and nothing happens" bug.
 *
 * Before the fix, BlackjackService::findPlayerSeat and HoldemService::
 * findPlayerSeat only consulted state_json['hands'] / ['players'], which
 * are empty until a round is dealt. A freshly-seated player with a
 * casino_table_players row was reported as unseated by tableState(),
 * so the Vue kept rendering the Sit Down button and the user thought
 * their click did nothing.
 *
 * These tests lock in the contract: after joinTable(), tableState must
 * return my_seat !== null even when no round is in progress yet.
 */
beforeEach(function () {
    app(WorldService::class)->generateInitialWorld(seed: 99);
});

/** Spawn a player, give them barrels, and open a casino session. */
function casinoSeatedPlayer(int $barrels = 100000): \App\Models\Player
{
    $user = User::factory()->create();
    $player = app(WorldService::class)->spawnPlayer($user->id);
    $player->update(['oil_barrels' => $barrels]);
    $player->refresh();

    app(CasinoService::class)->enter($player->id);

    return $player->fresh();
}

it('reports my_seat after sitting at a blackjack table with no hand in progress', function () {
    app(CasinoTableManager::class)->ensureBlackjackTablesExist();

    $table = CasinoTable::query()
        ->where('game_type', 'blackjack')
        ->firstOrFail();

    $player = casinoSeatedPlayer();

    $svc = app(BlackjackService::class);

    // Sanity: before sitting, my_seat is null.
    $before = $svc->tableState($table->id, $player->id);
    expect($before['my_seat'])->toBeNull();

    // Sit down.
    $svc->joinTable($player->id, $table->id);

    // After sitting — even though no hand has been dealt — my_seat
    // must reflect the casino_table_players row so the Vue hides
    // the Sit Down button and shows the Place Bet UI.
    $after = $svc->tableState($table->id, $player->id);
    expect($after['my_seat'])->not->toBeNull();
    expect($after['my_seat'])->toBe(0);
});

it('reports my_seat after sitting at a holdem table with no hand in progress', function () {
    app(CasinoTableManager::class)->ensureHoldemTablesExist();

    $table = CasinoTable::query()
        ->where('game_type', 'holdem')
        ->where('currency', 'akzar_cash')
        ->firstOrFail();

    $player = casinoSeatedPlayer();
    // Give them cash too for the buy-in.
    $player->update(['akzar_cash' => 100.00]);
    $player->refresh();

    $svc = app(HoldemService::class);

    $before = $svc->tableState($table->id, $player->id);
    expect($before['my_seat'])->toBeNull();
    // Before anyone sits, the synthesized players array is empty.
    expect($before['players'])->toBe([]);

    // Buy in at the minimum (big blind × min multiplier).
    $blindLevel = $before['blind_level'] ?? ['big' => 0.10];
    $buyIn = $blindLevel['big'] * 20;

    $svc->joinTable($player->id, $table->id, $buyIn);

    $after = $svc->tableState($table->id, $player->id);

    expect($after['my_seat'])->not->toBeNull();
    expect($after['my_seat'])->toBe(0);

    // And the synthesized players array must include the new seat so
    // the Vue renders a visible chair for the lone sitter while they
    // wait for a second player.
    expect($after['players'])->toHaveCount(1);
    expect($after['players'][0]['seat'])->toBe(0);
    expect($after['players'][0]['player_id'])->toBe($player->id);
});

it('still reports my_seat for holdem between hands after a player has been through a hand', function () {
    // Covers the "old code used array index, breaks when seats aren't
    // contiguous from 0" latent bug — seat 0 is always returned by
    // seat_number regardless of player ordering in state['players'].
    app(CasinoTableManager::class)->ensureHoldemTablesExist();

    $table = CasinoTable::query()
        ->where('game_type', 'holdem')
        ->where('currency', 'akzar_cash')
        ->firstOrFail();

    $p1 = casinoSeatedPlayer();
    $p1->update(['akzar_cash' => 100.00]);
    $p1->refresh();

    $svc = app(HoldemService::class);
    $svc->joinTable($p1->id, $table->id, 2.00);

    // Sitting alone — tableState still returns the correct seat.
    $state = $svc->tableState($table->id, $p1->id);
    expect($state['my_seat'])->toBe(0);
});
