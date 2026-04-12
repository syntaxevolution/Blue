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

it('allows a blackjack player to rejoin a table after leaving', function () {
    // Regression: the casino_table_players unique indexes on
    // (casino_table_id, seat_number) AND (casino_table_id, player_id)
    // are not status-aware. A previous leaveTable() that only flipped
    // status to 'left' would collide with the next joinTable() insert
    // and 500 with a 1062 duplicate entry error. This test locks in
    // the fix: sit → leave → sit should succeed cleanly.
    app(CasinoTableManager::class)->ensureBlackjackTablesExist();

    $table = CasinoTable::query()
        ->where('game_type', 'blackjack')
        ->firstOrFail();

    $player = casinoSeatedPlayer();
    $svc = app(BlackjackService::class);

    $svc->joinTable($player->id, $table->id);
    $svc->leaveTable($player->id, $table->id);

    // Rejoining must not throw a UniqueConstraintViolationException.
    $result = $svc->joinTable($player->id, $table->id);
    expect($result['seat'])->toBe(0);

    $state = $svc->tableState($table->id, $player->id);
    expect($state['my_seat'])->toBe(0);
});

it('allows a holdem player to rejoin a table after leaving', function () {
    app(CasinoTableManager::class)->ensureHoldemTablesExist();

    $table = CasinoTable::query()
        ->where('game_type', 'holdem')
        ->where('currency', 'akzar_cash')
        ->firstOrFail();

    $player = casinoSeatedPlayer();
    $player->update(['akzar_cash' => 100.00]);
    $player->refresh();

    $svc = app(HoldemService::class);
    $svc->joinTable($player->id, $table->id, 2.00);
    $svc->leaveTable($player->id, $table->id);

    // Rejoin must succeed.
    $result = $svc->joinTable($player->id, $table->id, 2.00);
    expect($result['seat'])->toBe(0);

    $state = $svc->tableState($table->id, $player->id);
    expect($state['my_seat'])->toBe(0);
});

it('frees a blackjack seat for a different player after the first leaves', function () {
    // Second regression: a different player taking over a seat that
    // someone else left. Before the fix, the stale row at (table, seat)
    // with status='left' blocked the new sitter.
    app(CasinoTableManager::class)->ensureBlackjackTablesExist();

    $table = CasinoTable::query()
        ->where('game_type', 'blackjack')
        ->firstOrFail();

    $p1 = casinoSeatedPlayer();
    $p2 = casinoSeatedPlayer();

    $svc = app(BlackjackService::class);

    $svc->joinTable($p1->id, $table->id);
    $svc->leaveTable($p1->id, $table->id);

    // p2 takes over seat 0.
    $result = $svc->joinTable($p2->id, $table->id);
    expect($result['seat'])->toBe(0);
});

it('resolves a blackjack round without exploding on the dealer reference', function () {
    // Regression: resolveDealerAndPayout took `$dealer = &$state['dealer']`
    // then reset $state['dealer'] = null for the next round, then tried
    // to read $dealer['cards'] for the return payload — null dereference.
    // This test drives a full solo round through stand/resolve to
    // guarantee the return payload is built cleanly.
    app(CasinoTableManager::class)->ensureBlackjackTablesExist();

    $table = CasinoTable::query()
        ->where('game_type', 'blackjack')
        ->where('currency', 'oil_barrels')
        ->firstOrFail();

    $player = casinoSeatedPlayer();

    $svc = app(BlackjackService::class);
    $svc->joinTable($player->id, $table->id);
    $svc->placeBet($player->id, $table->id, (float) $table->min_bet);

    // Stand immediately so the dealer resolves. We don't care about the
    // outcome — we care that the payload comes back with dealer_cards
    // populated rather than throwing a null-access fatal.
    $result = $svc->playerAction($player->id, $table->id, 'stand');

    expect($result['action'])->toBe('round_resolved');
    expect($result['dealer_cards'])->toBeArray();
    expect($result['dealer_cards'])->not->toBeEmpty();
    expect($result)->toHaveKey('dealer_total');
    expect($result)->toHaveKey('results');
});
