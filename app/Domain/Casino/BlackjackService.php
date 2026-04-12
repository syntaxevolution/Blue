<?php

namespace App\Domain\Casino;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Config\RngService;
use App\Domain\Exceptions\CasinoException;
use App\Models\CasinoBet;
use App\Models\CasinoRound;
use App\Models\CasinoTable;
use App\Models\CasinoTablePlayer;
use App\Models\Player;
use Illuminate\Support\Facades\DB;

class BlackjackService
{
    public function __construct(
        private readonly GameConfigResolver $config,
        private readonly RngService $rng,
        private readonly CasinoService $casinoService,
    ) {}

    public function joinTable(int $playerId, int $tableId): array
    {
        $this->casinoService->requireActiveSession($playerId);

        return DB::transaction(function () use ($playerId, $tableId) {
            /** @var CasinoTable $table */
            $table = CasinoTable::query()->lockForUpdate()->findOrFail($tableId);

            if ($table->game_type !== 'blackjack') {
                throw CasinoException::invalidAction('not a blackjack table');
            }

            $existing = CasinoTablePlayer::query()
                ->where('casino_table_id', $tableId)
                ->where('player_id', $playerId)
                ->where('status', 'active')
                ->first();

            if ($existing) {
                return ['seat' => $existing->seat_number, 'already_seated' => true];
            }

            // Stale-row cleanup. The casino_table_players table has two
            // non-status-aware unique indexes:
            //   (casino_table_id, seat_number)
            //   (casino_table_id, player_id)
            // A previous leaveTable() that only flipped status to 'left'
            // would leave a tombstone row that now collides with the
            // insert below (1062 duplicate entry). Delete any stale
            // non-active rows for THIS player at this table, and any
            // non-active rows sitting on a seat we might choose. Nothing
            // else reads from non-active rows (verified via grep), so
            // deletion is safe.
            CasinoTablePlayer::query()
                ->where('casino_table_id', $tableId)
                ->where('player_id', $playerId)
                ->where('status', '!=', 'active')
                ->delete();

            $activeCount = CasinoTablePlayer::query()
                ->where('casino_table_id', $tableId)
                ->where('status', 'active')
                ->count();

            if ($activeCount >= $table->seats) {
                throw CasinoException::tableIsFull($table->seats);
            }

            $takenSeats = CasinoTablePlayer::query()
                ->where('casino_table_id', $tableId)
                ->where('status', 'active')
                ->pluck('seat_number')
                ->all();

            $seatNumber = 0;
            for ($i = 0; $i < $table->seats; $i++) {
                if (! in_array($i, $takenSeats, true)) {
                    $seatNumber = $i;
                    break;
                }
            }

            // Free any lingering non-active row on the chosen seat
            // (could be a different player who left it vacant).
            CasinoTablePlayer::query()
                ->where('casino_table_id', $tableId)
                ->where('seat_number', $seatNumber)
                ->where('status', '!=', 'active')
                ->delete();

            CasinoTablePlayer::create([
                'casino_table_id' => $tableId,
                'player_id' => $playerId,
                'seat_number' => $seatNumber,
                'status' => 'active',
                'joined_at' => now(),
            ]);

            return ['seat' => $seatNumber, 'already_seated' => false];
        });
    }

    public function leaveTable(int $playerId, int $tableId): void
    {
        // Delete outright rather than marking 'left'. The unique indexes
        // on (casino_table_id, seat_number) and (casino_table_id, player_id)
        // are not status-aware, so a tombstone row blocks every future
        // rejoin. Nothing in the codebase reads from left-status rows.
        CasinoTablePlayer::query()
            ->where('casino_table_id', $tableId)
            ->where('player_id', $playerId)
            ->delete();
    }

    /**
     * Place a bet and if all seated players have bet, deal the hand.
     */
    public function placeBet(int $playerId, int $tableId, float $amount): array
    {
        if (! (bool) $this->config->get('casino.blackjack.enabled')) {
            throw CasinoException::gameNotEnabled('blackjack');
        }

        $this->casinoService->requireActiveSession($playerId);

        return DB::transaction(function () use ($playerId, $tableId, $amount) {
            /** @var CasinoTable $table */
            $table = CasinoTable::query()->lockForUpdate()->findOrFail($tableId);
            $state = $table->state_json ?? $this->freshState();

            if (! in_array($state['phase'], ['waiting', 'betting'], true)) {
                throw CasinoException::invalidAction('hand in progress');
            }

            $this->validateBetAmount($table->currency, $amount, (float) $table->min_bet, (float) $table->max_bet);

            /** @var Player $player */
            $player = Player::query()->lockForUpdate()->findOrFail($playerId);
            $this->assertAffordable($player, $table->currency, $amount);
            $this->deductCurrency($player, $table->currency, $amount);

            $state['phase'] = 'betting';
            $state['player_bets'][$playerId] = $amount;

            $activePlayers = CasinoTablePlayer::query()
                ->where('casino_table_id', $tableId)
                ->where('status', 'active')
                ->pluck('player_id')
                ->all();

            $allBet = true;
            foreach ($activePlayers as $pid) {
                if (! isset($state['player_bets'][$pid])) {
                    $allBet = false;
                    break;
                }
            }

            $table->state_json = $state;
            $table->save();

            if ($allBet && count($activePlayers) > 0) {
                return $this->dealHand($table, $activePlayers);
            }

            return ['action' => 'bet_placed', 'amount' => $amount, 'waiting_for_others' => ! $allBet];
        });
    }

    public function playerAction(int $playerId, int $tableId, string $action): array
    {
        return DB::transaction(function () use ($playerId, $tableId, $action) {
            /** @var CasinoTable $table */
            $table = CasinoTable::query()->lockForUpdate()->findOrFail($tableId);
            $state = $table->state_json;

            if ($state['phase'] !== 'player_turns') {
                throw CasinoException::invalidAction('not in player turns phase');
            }

            $currentSeat = $state['current_seat'] ?? null;
            $hands = $state['hands'] ?? [];
            $playerSeat = null;

            foreach ($hands as $i => $hand) {
                if (($hand['player_id'] ?? null) === $playerId && $i === $currentSeat) {
                    $playerSeat = $i;
                    break;
                }
            }

            if ($playerSeat === null) {
                throw CasinoException::notYourTurn();
            }

            $hand = &$state['hands'][$playerSeat];

            switch ($action) {
                case 'hit':
                    $card = array_shift($state['deck']);
                    $hand['cards'][] = $card;
                    $val = CardDeck::blackjackHandValue($hand['cards']);
                    $hand['total'] = $val['total'];
                    $hand['soft'] = $val['soft'];

                    if ($val['total'] > 21) {
                        $hand['status'] = 'bust';
                        $state = $this->advanceToNextHand($state);
                    }
                    break;

                case 'stand':
                    $hand['status'] = 'stood';
                    $state = $this->advanceToNextHand($state);
                    break;

                case 'double':
                    $bet = $hand['bet'];
                    /** @var Player $player */
                    $player = Player::query()->lockForUpdate()->findOrFail($playerId);
                    $this->assertAffordable($player, $table->currency, $bet);
                    $this->deductCurrency($player, $table->currency, $bet);
                    $hand['bet'] *= 2;

                    $card = array_shift($state['deck']);
                    $hand['cards'][] = $card;
                    $val = CardDeck::blackjackHandValue($hand['cards']);
                    $hand['total'] = $val['total'];
                    $hand['soft'] = $val['soft'];
                    $hand['status'] = $val['total'] > 21 ? 'bust' : 'stood';
                    $state = $this->advanceToNextHand($state);
                    break;

                case 'surrender':
                    if (! (bool) $this->config->get('casino.blackjack.surrender_enabled')) {
                        throw CasinoException::invalidAction('surrender not enabled');
                    }
                    if (count($hand['cards']) !== 2) {
                        throw CasinoException::invalidAction('can only surrender on first two cards');
                    }
                    $hand['status'] = 'surrendered';
                    $state = $this->advanceToNextHand($state);
                    break;

                case 'split':
                    if (count($hand['cards']) !== 2) {
                        throw CasinoException::invalidAction('can only split on first two cards');
                    }
                    // Same rank (both 10-value cards count as splittable for simplicity)
                    $r0 = CardDeck::blackjackValue($hand['cards'][0]);
                    $r1 = CardDeck::blackjackValue($hand['cards'][1]);
                    // Allow split if values match (e.g., two 10s, two Jacks, or two Aces)
                    $aces = CardDeck::isAce($hand['cards'][0]) && CardDeck::isAce($hand['cards'][1]);
                    if (! $aces && $r0 !== $r1) {
                        throw CasinoException::invalidAction('can only split a pair');
                    }

                    // Check split count limit
                    $currentSplits = $hand['split_count'] ?? 0;
                    $maxSplits = (int) $this->config->get('casino.blackjack.max_splits', 3);
                    if ($currentSplits >= $maxSplits) {
                        throw CasinoException::invalidAction("maximum {$maxSplits} splits reached");
                    }

                    // Deduct a second bet equal to the original
                    $bet = $hand['bet'];
                    /** @var Player $player */
                    $player = Player::query()->lockForUpdate()->findOrFail($playerId);
                    $this->assertAffordable($player, $table->currency, $bet);
                    $this->deductCurrency($player, $table->currency, $bet);

                    // Create two new hands, each with one of the original cards.
                    $card1 = $hand['cards'][0];
                    $card2 = $hand['cards'][1];

                    $hand['cards'] = [$card1, array_shift($state['deck'])];
                    $hand['split_count'] = $currentSplits + 1;
                    $val = CardDeck::blackjackHandValue($hand['cards']);
                    $hand['total'] = $val['total'];
                    $hand['soft'] = $val['soft'];

                    // Aces split → auto-stand (industry standard).
                    if ($aces) {
                        $hand['status'] = 'stood';
                    }

                    $newHand = [
                        'player_id' => $playerId,
                        'cards' => [$card2, array_shift($state['deck'])],
                        'bet' => $bet,
                        'total' => 0,
                        'soft' => false,
                        'status' => $aces ? 'stood' : 'playing',
                        'split_count' => $currentSplits + 1,
                    ];
                    $val2 = CardDeck::blackjackHandValue($newHand['cards']);
                    $newHand['total'] = $val2['total'];
                    $newHand['soft'] = $val2['soft'];

                    // Insert the new hand right after the current seat
                    array_splice($state['hands'], $playerSeat + 1, 0, [$newHand]);

                    if ($aces) {
                        $state = $this->advanceToNextHand($state);
                    }
                    break;

                case 'insurance':
                    if (! (bool) $this->config->get('casino.blackjack.insurance_enabled')) {
                        throw CasinoException::invalidAction('insurance not enabled');
                    }
                    if (count($hand['cards']) !== 2) {
                        throw CasinoException::invalidAction('insurance only on initial deal');
                    }
                    $dealerUp = $state['dealer']['cards'][0] ?? null;
                    if ($dealerUp === null || ! CardDeck::isAce($dealerUp)) {
                        throw CasinoException::invalidAction('insurance only when dealer shows Ace');
                    }
                    if (! empty($hand['insurance_bet'])) {
                        throw CasinoException::invalidAction('insurance already placed');
                    }

                    $insuranceBet = round($hand['bet'] / 2, 2);
                    /** @var Player $player */
                    $player = Player::query()->lockForUpdate()->findOrFail($playerId);
                    $this->assertAffordable($player, $table->currency, $insuranceBet);
                    $this->deductCurrency($player, $table->currency, $insuranceBet);

                    $hand['insurance_bet'] = $insuranceBet;
                    break;

                default:
                    throw CasinoException::invalidAction($action);
            }

            $table->state_json = $state;
            $table->save();

            if ($state['phase'] === 'dealer_turn') {
                return $this->resolveDealerAndPayout($table);
            }

            return $this->sanitizedState($state, $playerId, $table);
        });
    }

    public function tableState(int $tableId, int $playerId): array
    {
        $table = CasinoTable::query()->findOrFail($tableId);

        return $this->sanitizedState($table->state_json ?? $this->freshState(), $playerId, $table);
    }

    private function dealHand(CasinoTable $table, array $playerIds): array
    {
        $state = $table->state_json;
        $deckCount = (int) $this->config->get('casino.blackjack.deck_count', 6);
        $table->round_number = $table->round_number + 1;

        if (empty($state['deck']) || $this->shouldReshuffle($state['deck'], $deckCount)) {
            $state['deck'] = CardDeck::shuffle(
                CardDeck::fresh($deckCount),
                $this->rng,
                'casino.blackjack.deck',
                "{$table->id}:{$table->round_number}",
            );
        }

        $hands = [];
        foreach ($playerIds as $pid) {
            $bet = $state['player_bets'][$pid] ?? 0;
            $cards = [array_shift($state['deck']), array_shift($state['deck'])];
            $val = CardDeck::blackjackHandValue($cards);

            $hands[] = [
                'player_id' => $pid,
                'cards' => $cards,
                'bet' => $bet,
                'total' => $val['total'],
                'soft' => $val['soft'],
                'status' => 'playing',
            ];
        }

        $dealerCards = [array_shift($state['deck']), array_shift($state['deck'])];
        $dealerVal = CardDeck::blackjackHandValue($dealerCards);

        $state['hands'] = $hands;
        $state['dealer'] = [
            'cards' => $dealerCards,
            'total' => $dealerVal['total'],
            'soft' => $dealerVal['soft'],
        ];
        $state['phase'] = 'player_turns';
        $state['current_seat'] = 0;
        $state['player_bets'] = [];

        $bjPayout = (float) $this->config->get('casino.blackjack.blackjack_payout_ratio', 1.5);
        foreach ($hands as $i => &$hand) {
            if ($hand['total'] === 21) {
                $hand['status'] = 'blackjack';
            }
        }
        unset($hand);

        $state['hands'] = $hands;
        $state = $this->advanceToNextPlayable($state);

        $table->status = 'active';
        $table->state_json = $state;
        $table->save();

        if ($state['phase'] === 'dealer_turn') {
            return $this->resolveDealerAndPayout($table);
        }

        return ['action' => 'dealt', 'round_number' => $table->round_number];
    }

    private function resolveDealerAndPayout(CasinoTable $table): array
    {
        $state = $table->state_json;
        $dealer = &$state['dealer'];
        $standsOn = (int) $this->config->get('casino.blackjack.dealer_stands_on', 17);
        $hitSoft17 = (bool) $this->config->get('casino.blackjack.dealer_hits_soft_17', false);

        $anyActive = false;
        foreach ($state['hands'] as $h) {
            if (in_array($h['status'], ['stood', 'blackjack'], true)) {
                $anyActive = true;
                break;
            }
        }

        if ($anyActive) {
            while (true) {
                $val = CardDeck::blackjackHandValue($dealer['cards']);
                $dealer['total'] = $val['total'];
                $dealer['soft'] = $val['soft'];

                if ($val['total'] > 21) {
                    break;
                }
                if ($val['total'] > $standsOn) {
                    break;
                }
                if ($val['total'] === $standsOn && ! ($hitSoft17 && $val['soft'])) {
                    break;
                }

                $dealer['cards'][] = array_shift($state['deck']);
            }
        }

        $dealerTotal = CardDeck::blackjackHandValue($dealer['cards'])['total'];
        $dealerBust = $dealerTotal > 21;
        $dealerBJ = count($dealer['cards']) === 2 && $dealerTotal === 21;
        $bjPayout = (float) $this->config->get('casino.blackjack.blackjack_payout_ratio', 1.5);

        $round = CasinoRound::create([
            'casino_table_id' => $table->id,
            'game_type' => 'blackjack',
            'currency' => $table->currency,
            'round_number' => $table->round_number,
            'rng_seed' => "{$table->id}:{$table->round_number}",
            'resolved_at' => now(),
        ]);

        $results = [];
        foreach ($state['hands'] as &$hand) {
            $payout = 0.0;
            $outcome = 'loss';

            if ($hand['status'] === 'surrendered') {
                $payout = round($hand['bet'] / 2, 2);
                $outcome = 'surrendered';
            } elseif ($hand['status'] === 'bust') {
                $outcome = 'bust';
            } elseif ($hand['status'] === 'blackjack') {
                if ($dealerBJ) {
                    $payout = $hand['bet'];
                    $outcome = 'push';
                } else {
                    $payout = round($hand['bet'] + $hand['bet'] * $bjPayout, 2);
                    $outcome = 'blackjack';
                }
            } elseif ($hand['status'] === 'stood') {
                $playerTotal = $hand['total'];
                if ($dealerBust || $playerTotal > $dealerTotal) {
                    $payout = round($hand['bet'] * 2, 2);
                    $outcome = 'win';
                } elseif ($playerTotal === $dealerTotal) {
                    $payout = $hand['bet'];
                    $outcome = 'push';
                }
            }

            // Insurance side bet: pays 2:1 if dealer has blackjack, else lost.
            $insuranceBet = (float) ($hand['insurance_bet'] ?? 0);
            $insurancePayout = 0.0;
            if ($insuranceBet > 0) {
                if ($dealerBJ) {
                    // Stake returned + 2x winnings = 3x the insurance bet.
                    $insurancePayout = round($insuranceBet * 3, 2);
                }
            }

            $hand['payout'] = $payout + $insurancePayout;
            $hand['outcome'] = $outcome;
            $hand['insurance_payout'] = $insurancePayout;

            $totalCredit = $payout + $insurancePayout;
            if ($totalCredit > 0) {
                /** @var Player $player */
                $player = Player::query()->lockForUpdate()->findOrFail($hand['player_id']);
                $this->creditCurrency($player, $table->currency, $totalCredit);
            }

            CasinoBet::create([
                'casino_round_id' => $round->id,
                'player_id' => $hand['player_id'],
                'bet_type' => $outcome,
                'amount' => $hand['bet'] + $insuranceBet,
                'payout' => $totalCredit,
            ]);

            $results[] = [
                'player_id' => $hand['player_id'],
                'outcome' => $outcome,
                'bet' => $hand['bet'],
                'payout' => $payout,
            ];
        }
        unset($hand);

        $round->update([
            'state_snapshot' => $state,
            'result_summary' => ['dealer_total' => $dealerTotal, 'dealer_bust' => $dealerBust, 'results' => $results],
        ]);

        $state['phase'] = 'waiting';
        $state['hands'] = [];
        $state['dealer'] = null;
        $state['current_seat'] = null;
        $table->status = 'waiting';
        $table->state_json = $state;
        $table->save();

        return [
            'action' => 'round_resolved',
            'dealer_total' => $dealerTotal,
            'dealer_bust' => $dealerBust,
            'dealer_cards' => CardDeck::toDisplayArray($dealer['cards']),
            'results' => $results,
        ];
    }

    private function advanceToNextHand(array $state): array
    {
        $state['current_seat'] = ($state['current_seat'] ?? 0) + 1;

        return $this->advanceToNextPlayable($state);
    }

    private function advanceToNextPlayable(array $state): array
    {
        $hands = $state['hands'];

        while (isset($hands[$state['current_seat']])) {
            if ($hands[$state['current_seat']]['status'] === 'playing') {
                return $state;
            }
            $state['current_seat']++;
        }

        $state['phase'] = 'dealer_turn';

        return $state;
    }

    private function sanitizedState(array $state, int $playerId, CasinoTable $table): array
    {
        $hands = [];
        foreach ($state['hands'] ?? [] as $i => $hand) {
            $hands[] = [
                'seat' => $i,
                'player_id' => $hand['player_id'],
                'cards' => CardDeck::toDisplayArray($hand['cards']),
                'total' => $hand['total'],
                'soft' => $hand['soft'],
                'bet' => $hand['bet'],
                'status' => $hand['status'],
                'payout' => $hand['payout'] ?? null,
                'outcome' => $hand['outcome'] ?? null,
            ];
        }

        $dealer = null;
        if (isset($state['dealer'])) {
            $dealerCards = $state['dealer']['cards'];
            $showAll = in_array($state['phase'], ['dealer_turn', 'waiting'], true)
                || (isset($state['last_resolved']) && $state['last_resolved']);

            $dealer = [
                'cards' => $showAll
                    ? CardDeck::toDisplayArray($dealerCards)
                    : [CardDeck::toDisplayArray([$dealerCards[0]])[0], ['rank' => '?', 'suit' => '?', 'display' => '??']],
                'total' => $showAll ? CardDeck::blackjackHandValue($dealerCards)['total'] : null,
            ];
        }

        return [
            'table_id' => $table->id,
            'currency' => $table->currency,
            'min_bet' => (float) $table->min_bet,
            'max_bet' => (float) $table->max_bet,
            'phase' => $state['phase'],
            'round_number' => $table->round_number,
            'current_seat' => $state['current_seat'] ?? null,
            'hands' => $hands,
            'dealer' => $dealer,
            'my_seat' => $this->findPlayerSeat($state, $playerId, $table->id),
            'is_my_turn' => $this->isPlayerTurn($state, $playerId),
        ];
    }

    /**
     * Locate the viewer's "seat" for UI gating purposes.
     *
     * Priority:
     *   1. The index of the viewer's hand in the current round, if a
     *      hand is in progress. This keeps the Vue's hand highlighting
     *      consistent with existing indexing (my_seat === current_seat
     *      when it's your turn, etc.).
     *   2. Otherwise, fall back to the player's stable CasinoTablePlayer
     *      seat_number. This is the critical fix for the "clicked Sit
     *      Down and nothing happened" bug: between rounds (and before
     *      the very first round) `state['hands']` is empty, so the old
     *      implementation returned null and the Vue kept rendering the
     *      Sit Down button indefinitely.
     *   3. Null only if the player has no active seat row at all.
     */
    private function findPlayerSeat(array $state, int $playerId, ?int $tableId = null): ?int
    {
        foreach ($state['hands'] ?? [] as $i => $hand) {
            if (($hand['player_id'] ?? null) === $playerId) {
                return $i;
            }
        }

        if ($tableId === null) {
            return null;
        }

        $seatRow = CasinoTablePlayer::query()
            ->where('casino_table_id', $tableId)
            ->where('player_id', $playerId)
            ->where('status', 'active')
            ->first();

        return $seatRow !== null ? (int) $seatRow->seat_number : null;
    }

    private function isPlayerTurn(array $state, int $playerId): bool
    {
        if ($state['phase'] !== 'player_turns') {
            return false;
        }
        $seat = $state['current_seat'] ?? null;
        if ($seat === null || ! isset($state['hands'][$seat])) {
            return false;
        }

        return $state['hands'][$seat]['player_id'] === $playerId;
    }

    private function shouldReshuffle(array $deck, int $deckCount): bool
    {
        $total = $deckCount * 52;
        $penetration = (float) $this->config->get('casino.blackjack.reshuffle_penetration_pct', 0.75);

        return count($deck) < $total * (1 - $penetration);
    }

    private function freshState(): array
    {
        return ['phase' => 'waiting', 'deck' => [], 'hands' => [], 'dealer' => null, 'player_bets' => [], 'current_seat' => null];
    }

    private function validateBetAmount(string $currency, float $amount, float $min, float $max): void
    {
        if ($amount < $min || $amount > $max) {
            throw CasinoException::invalidBetAmount($amount, $min, $max);
        }
    }

    private function assertAffordable(Player $player, string $currency, float $amount): void
    {
        if ($currency === 'akzar_cash' && (float) $player->akzar_cash < $amount) {
            throw CasinoException::insufficientCash((float) $player->akzar_cash, $amount);
        }
        if ($currency === 'oil_barrels' && $player->oil_barrels < (int) $amount) {
            throw CasinoException::insufficientBarrels($player->oil_barrels, (int) $amount);
        }
    }

    private function deductCurrency(Player $player, string $currency, float $amount): void
    {
        if ($currency === 'akzar_cash') {
            $player->update(['akzar_cash' => (float) $player->akzar_cash - $amount]);
        } else {
            $player->update(['oil_barrels' => $player->oil_barrels - (int) $amount]);
        }
    }

    private function creditCurrency(Player $player, string $currency, float $amount): void
    {
        if ($currency === 'akzar_cash') {
            $player->update(['akzar_cash' => (float) $player->akzar_cash + $amount]);
        } else {
            $player->update(['oil_barrels' => $player->oil_barrels + (int) $amount]);
        }
    }
}
