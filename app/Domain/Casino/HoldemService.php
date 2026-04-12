<?php

namespace App\Domain\Casino;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Config\RngService;
use App\Domain\Exceptions\CasinoException;
use App\Jobs\HoldemTurnTimeout;
use App\Models\CasinoBet;
use App\Models\CasinoRound;
use App\Models\CasinoTable;
use App\Models\CasinoTablePlayer;
use App\Models\Player;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

class HoldemService
{
    public function __construct(
        private readonly GameConfigResolver $config,
        private readonly RngService $rng,
        private readonly CasinoService $casinoService,
        private readonly HandEvaluator $handEvaluator,
    ) {}

    public function joinTable(int $playerId, int $tableId, float $buyIn): array
    {
        $this->casinoService->requireActiveSession($playerId);

        return DB::transaction(function () use ($playerId, $tableId, $buyIn) {
            /** @var CasinoTable $table */
            $table = CasinoTable::query()->lockForUpdate()->findOrFail($tableId);

            if ($table->game_type !== 'holdem') {
                throw CasinoException::invalidAction('not a holdem table');
            }

            $state = $table->state_json ?? $this->freshState($table->currency);
            $blindLevel = $state['blind_level'] ?? $this->defaultBlinds($table->currency);
            $bigBlind = $blindLevel['big'];

            $minMultiplier = (int) $this->config->get('casino.holdem.min_buy_in_multiplier', 20);
            $maxMultiplier = (int) $this->config->get('casino.holdem.max_buy_in_multiplier', 100);
            $minBuyIn = $bigBlind * $minMultiplier;
            $maxBuyIn = $bigBlind * $maxMultiplier;

            if ($buyIn < $minBuyIn || $buyIn > $maxBuyIn) {
                throw CasinoException::invalidBuyIn($buyIn, $minBuyIn, $maxBuyIn);
            }

            $existing = CasinoTablePlayer::query()
                ->where('casino_table_id', $tableId)
                ->where('player_id', $playerId)
                ->where('status', 'active')
                ->first();

            if ($existing) {
                throw CasinoException::alreadySeated();
            }

            $maxSeats = (int) $this->config->get('casino.holdem.max_seats', 6);
            $activeCount = CasinoTablePlayer::query()
                ->where('casino_table_id', $tableId)
                ->where('status', 'active')
                ->count();

            if ($activeCount >= $maxSeats) {
                throw CasinoException::tableIsFull($maxSeats);
            }

            /** @var Player $player */
            $player = Player::query()->lockForUpdate()->findOrFail($playerId);
            $this->assertAffordable($player, $table->currency, $buyIn);
            $this->deductCurrency($player, $table->currency, $buyIn);

            $takenSeats = CasinoTablePlayer::query()
                ->where('casino_table_id', $tableId)
                ->where('status', 'active')
                ->pluck('seat_number')
                ->all();

            $seatNumber = 0;
            for ($i = 0; $i < $maxSeats; $i++) {
                if (! in_array($i, $takenSeats, true)) {
                    $seatNumber = $i;
                    break;
                }
            }

            CasinoTablePlayer::create([
                'casino_table_id' => $tableId,
                'player_id' => $playerId,
                'seat_number' => $seatNumber,
                'stack' => $buyIn,
                'status' => 'active',
                'joined_at' => now(),
            ]);

            return ['seat' => $seatNumber, 'stack' => $buyIn];
        });
    }

    public function leaveTable(int $playerId, int $tableId): array
    {
        return DB::transaction(function () use ($playerId, $tableId) {
            /** @var CasinoTable $table */
            $table = CasinoTable::query()->lockForUpdate()->findOrFail($tableId);
            $state = $table->state_json ?? $this->freshState($table->currency);

            $seat = CasinoTablePlayer::query()
                ->where('casino_table_id', $tableId)
                ->where('player_id', $playerId)
                ->where('status', 'active')
                ->first();

            if (! $seat) {
                throw CasinoException::notSeated();
            }

            $cashOut = (float) $seat->stack;
            $seat->update(['status' => 'left', 'stack' => 0]);

            if ($cashOut > 0) {
                /** @var Player $player */
                $player = Player::query()->lockForUpdate()->findOrFail($playerId);
                $this->creditCurrency($player, $table->currency, $cashOut);
            }

            if (in_array($state['phase'], ['pre_flop', 'flop', 'turn', 'river'], true)) {
                $this->foldPlayer($table, $state, $playerId);
            }

            return ['cash_out' => $cashOut];
        });
    }

    public function startHand(int $tableId): array
    {
        return DB::transaction(function () use ($tableId) {
            /** @var CasinoTable $table */
            $table = CasinoTable::query()->lockForUpdate()->findOrFail($tableId);
            $state = $table->state_json ?? $this->freshState();

            $minPlayers = (int) $this->config->get('casino.holdem.min_players', 2);

            $seated = CasinoTablePlayer::query()
                ->where('casino_table_id', $tableId)
                ->where('status', 'active')
                ->where('stack', '>', 0)
                ->orderBy('seat_number')
                ->get();

            if ($seated->count() < $minPlayers) {
                throw CasinoException::minimumPlayersRequired($minPlayers, $seated->count());
            }

            $table->round_number++;
            $blindLevel = $state['blind_level'] ?? $this->defaultBlinds($table->currency);
            $dealerSeat = (($state['dealer_seat'] ?? -1) + 1) % $seated->count();

            $deck = CardDeck::shuffle(
                CardDeck::fresh(1),
                $this->rng,
                'casino.holdem.deck',
                "{$tableId}:{$table->round_number}",
            );

            $players = [];
            foreach ($seated as $i => $seatRow) {
                $players[] = [
                    'player_id' => $seatRow->player_id,
                    'seat' => $seatRow->seat_number,
                    'stack' => (float) $seatRow->stack,
                    'hole_cards' => [array_shift($deck), array_shift($deck)],
                    'bet_this_round' => 0.0,
                    'total_bet' => 0.0,
                    'folded' => false,
                    'all_in' => false,
                ];
            }

            $sbIndex = ($dealerSeat + 1) % count($players);
            $bbIndex = ($dealerSeat + 2) % count($players);

            $sb = min($blindLevel['small'], $players[$sbIndex]['stack']);
            $bb = min($blindLevel['big'], $players[$bbIndex]['stack']);

            $players[$sbIndex]['stack'] -= $sb;
            $players[$sbIndex]['bet_this_round'] = $sb;
            $players[$sbIndex]['total_bet'] = $sb;
            if ($players[$sbIndex]['stack'] <= 0) {
                $players[$sbIndex]['all_in'] = true;
            }

            $players[$bbIndex]['stack'] -= $bb;
            $players[$bbIndex]['bet_this_round'] = $bb;
            $players[$bbIndex]['total_bet'] = $bb;
            if ($players[$bbIndex]['stack'] <= 0) {
                $players[$bbIndex]['all_in'] = true;
            }

            foreach ($players as $p) {
                CasinoTablePlayer::query()
                    ->where('casino_table_id', $tableId)
                    ->where('player_id', $p['player_id'])
                    ->update(['stack' => $p['stack']]);
            }

            // Heads-up exception: the dealer (SB) acts first pre-flop.
            // Otherwise UTG = 3 seats left of dealer.
            $utg = count($players) === 2
                ? $sbIndex
                : ($dealerSeat + 3) % count($players);

            $state = [
                'phase' => 'pre_flop',
                'deck' => $deck,
                'community' => [],
                'players' => $players,
                'pot' => $sb + $bb,
                'side_pots' => [],
                'current_bet' => $bb,
                'last_raise_size' => $bb,      // minimum future raise increment
                'action_on' => $utg,
                // last_voluntary_raiser tracks the last player who actually
                // chose to raise this street. On pre-flop it is null until
                // someone raises, giving the big blind their option.
                'last_voluntary_raiser' => null,
                'bb_option_pending' => true,   // pre-flop BB gets to act last
                'bb_index' => $bbIndex,
                'dealer_seat' => $dealerSeat,
                'blind_level' => $blindLevel,
                'actions_this_round' => 0,
            ];

            $table->status = 'active';
            $table->state_json = $state;
            $table->round_started_at = now();
            $table->save();

            $this->dispatchTurnTimeout($table, $state);

            return ['action' => 'hand_started', 'round_number' => $table->round_number];
        });
    }

    public function playerAction(int $playerId, int $tableId, string $action, float $amount = 0): array
    {
        return DB::transaction(function () use ($playerId, $tableId, $action, $amount) {
            /** @var CasinoTable $table */
            $table = CasinoTable::query()->lockForUpdate()->findOrFail($tableId);
            $state = $table->state_json;

            if (! in_array($state['phase'], ['pre_flop', 'flop', 'turn', 'river'], true)) {
                throw CasinoException::invalidAction('no active betting round');
            }

            $actionOn = $state['action_on'];
            $player = &$state['players'][$actionOn];

            if ($player['player_id'] !== $playerId) {
                throw CasinoException::notYourTurn();
            }

            if ($player['folded'] || $player['all_in']) {
                throw CasinoException::invalidAction('already folded or all-in');
            }

            $toCall = $state['current_bet'] - $player['bet_this_round'];

            switch ($action) {
                case 'fold':
                    $player['folded'] = true;
                    break;

                case 'check':
                    if ($toCall > 0) {
                        throw CasinoException::invalidAction('cannot check, must call or raise');
                    }
                    break;

                case 'call':
                    $callAmount = min($toCall, $player['stack']);
                    $player['stack'] -= $callAmount;
                    $player['bet_this_round'] += $callAmount;
                    $player['total_bet'] += $callAmount;
                    $state['pot'] += $callAmount;
                    if ($player['stack'] <= 0) {
                        $player['all_in'] = true;
                    }
                    break;

                case 'raise':
                    // Min raise = current bet + last raise increment.
                    // On pre-flop, last_raise_size starts at BB.
                    $minRaiseTotal = $state['current_bet'] + $state['last_raise_size'];
                    $raiseTotal = max($amount, $minRaiseTotal);
                    $raiseAmount = $raiseTotal - $player['bet_this_round'];
                    $raiseAmount = min($raiseAmount, $player['stack']);
                    $player['stack'] -= $raiseAmount;
                    $player['bet_this_round'] += $raiseAmount;
                    $player['total_bet'] += $raiseAmount;
                    $state['pot'] += $raiseAmount;

                    $raiseIncrement = $player['bet_this_round'] - $state['current_bet'];
                    if ($raiseIncrement >= $state['last_raise_size']) {
                        // Full raise — resets action for everyone behind.
                        $state['last_raise_size'] = $raiseIncrement;
                    }
                    $state['current_bet'] = $player['bet_this_round'];
                    $state['last_voluntary_raiser'] = $actionOn;
                    $state['bb_option_pending'] = false;
                    if ($player['stack'] <= 0) {
                        $player['all_in'] = true;
                    }
                    break;

                case 'all_in':
                    $allInAmount = $player['stack'];
                    $player['bet_this_round'] += $allInAmount;
                    $player['total_bet'] += $allInAmount;
                    $state['pot'] += $allInAmount;
                    $player['stack'] = 0;
                    $player['all_in'] = true;
                    if ($player['bet_this_round'] > $state['current_bet']) {
                        $raiseIncrement = $player['bet_this_round'] - $state['current_bet'];
                        if ($raiseIncrement >= $state['last_raise_size']) {
                            $state['last_raise_size'] = $raiseIncrement;
                        }
                        $state['current_bet'] = $player['bet_this_round'];
                        $state['last_voluntary_raiser'] = $actionOn;
                        $state['bb_option_pending'] = false;
                    }
                    break;

                default:
                    throw CasinoException::invalidAction($action);
            }

            $state['actions_this_round']++;
            $state['players'][$actionOn] = $player;

            // If the BB just acted pre-flop (any action), clear their option.
            if ($state['phase'] === 'pre_flop'
                && ($state['bb_option_pending'] ?? false)
                && $actionOn === ($state['bb_index'] ?? -1)
            ) {
                $state['bb_option_pending'] = false;
            }

            $state = $this->advanceAction($state);

            foreach ($state['players'] as $p) {
                CasinoTablePlayer::query()
                    ->where('casino_table_id', $tableId)
                    ->where('player_id', $p['player_id'])
                    ->update(['stack' => $p['stack'], 'last_action_at' => now()]);
            }

            $table->state_json = $state;
            $table->save();

            if ($state['phase'] === 'showdown') {
                return $this->resolveShowdown($table);
            }

            if ($this->shouldAutoAdvance($state)) {
                return $this->advanceToNextStreet($table, $state);
            }

            $this->dispatchTurnTimeout($table, $state);

            return ['action' => $action, 'phase' => $state['phase']];
        });
    }

    public function handleTimeout(int $tableId, int $playerId, int $roundNumber): void
    {
        DB::transaction(function () use ($tableId, $playerId, $roundNumber) {
            /** @var CasinoTable $table */
            $table = CasinoTable::query()->lockForUpdate()->findOrFail($tableId);

            if ($table->round_number !== $roundNumber) {
                return;
            }

            $state = $table->state_json;
            $actionOn = $state['action_on'] ?? null;

            if ($actionOn === null) {
                return;
            }

            $player = $state['players'][$actionOn] ?? null;
            if ($player === null || $player['player_id'] !== $playerId) {
                return;
            }

            $toCall = $state['current_bet'] - $player['bet_this_round'];
            if ($toCall > 0) {
                $state['players'][$actionOn]['folded'] = true;
            }

            $state['actions_this_round']++;
            $state = $this->advanceAction($state);

            $table->state_json = $state;
            $table->save();

            if ($state['phase'] === 'showdown') {
                $this->resolveShowdown($table);
            }
        });
    }

    /**
     * @return array<string,mixed>
     */
    public function tableState(int $tableId, int $playerId): array
    {
        $table = CasinoTable::query()->findOrFail($tableId);
        $state = $table->state_json ?? $this->freshState($table->currency);

        return $this->sanitizedState($state, $playerId, $table);
    }

    /**
     * Advance action to the next player who can act. If the betting round
     * is complete (all non-folded non-all-in players have called the
     * current bet AND either voluntarily acted or had the BB option), move
     * to the next street.
     */
    private function advanceAction(array $state): array
    {
        $players = $state['players'];
        $total = count($players);

        // Only one non-folded player left → showdown.
        $activeNotFolded = array_filter($players, fn ($p) => ! $p['folded']);
        if (count($activeNotFolded) <= 1) {
            $state['phase'] = 'showdown';

            return $state;
        }

        // Round complete when all non-folded, non-all-in players have
        // matched the current bet AND either someone raised (and action
        // returned to them) OR everyone checked around (and BB option is
        // no longer pending).
        if ($this->bettingRoundComplete($state)) {
            return $this->advanceStreet($state);
        }

        // Find the next player to act.
        $next = ($state['action_on'] + 1) % $total;
        for ($checked = 0; $checked < $total; $checked++) {
            $p = $players[$next];
            if (! $p['folded'] && ! $p['all_in']) {
                $state['action_on'] = $next;

                return $state;
            }
            $next = ($next + 1) % $total;
        }

        // No one else can act — round is done.
        return $this->advanceStreet($state);
    }

    private function bettingRoundComplete(array $state): bool
    {
        $players = $state['players'];
        $currentBet = $state['current_bet'];

        // Every non-folded player must have either matched the current
        // bet or be all-in for less.
        foreach ($players as $p) {
            if ($p['folded']) {
                continue;
            }
            if ($p['all_in']) {
                continue;
            }
            if ($p['bet_this_round'] < $currentBet) {
                return false;
            }
        }

        // At least one player must be able to voluntarily close the action.
        // Pre-flop special case: if nobody has raised and the BB still has
        // their option, the round cannot end until the BB acts.
        if ($state['phase'] === 'pre_flop' && ($state['bb_option_pending'] ?? false)) {
            $bbIdx = $state['bb_index'] ?? null;
            if ($bbIdx !== null) {
                $bb = $players[$bbIdx] ?? null;
                if ($bb !== null && ! $bb['folded'] && ! $bb['all_in']) {
                    // BB hasn't had their turn yet. Round cannot end unless
                    // action is currently on them and they just acted.
                    // We detect "just acted" by the action_on having moved
                    // past the BB in a complete loop.
                    if ($state['action_on'] !== $bbIdx) {
                        return false;
                    }
                    // action_on is BB — check if they have voluntarily acted.
                    // The simplest proxy: once advanceAction is called after
                    // the BB's action, bb_option_pending will be cleared by
                    // the post-action path. We set it false on any BB action
                    // via the action-taken branch.
                    return $state['bb_option_pending'] === false;
                }
            }
        }

        // Post-flop / after a raise: if someone voluntarily raised, action
        // must return to them (they can then check, not act again). When
        // the next-to-act loop finds them, the round is complete.
        $lastRaiser = $state['last_voluntary_raiser'] ?? null;
        if ($lastRaiser !== null) {
            // Round is complete when the action would return to the raiser.
            // We detect this by checking whether all subsequent non-folded
            // non-all-in players between action_on and the raiser have matched.
            // Since we already verified all bets are equal above, and the
            // raiser is by definition matched, the round is effectively done
            // once action has cycled back.
            $total = count($players);
            $current = $state['action_on'];
            $steps = 0;
            $next = ($current + 1) % $total;
            while ($next !== $lastRaiser && $steps < $total) {
                $p = $players[$next];
                if (! $p['folded'] && ! $p['all_in'] && $p['bet_this_round'] < $currentBet) {
                    return false;
                }
                $next = ($next + 1) % $total;
                $steps++;
            }

            return true;
        }

        // No raise yet this street and it's not pre-flop BB option: round
        // is done once everyone who can act has checked. We approximate
        // this by requiring actions_this_round >= number of still-active
        // (not folded, not all-in) players.
        $stillActing = count(array_filter($players, fn ($p) => ! $p['folded'] && ! $p['all_in']));

        return ($state['actions_this_round'] ?? 0) >= $stillActing;
    }

    private function advanceStreet(array $state): array
    {
        $state['phase'] = $this->nextStreet($state['phase']);
        $state['action_on'] = $this->firstActiveAfterDealer($state);
        $state['current_bet'] = 0;
        $state['last_raise_size'] = $state['blind_level']['big'] ?? 1;
        $state['last_voluntary_raiser'] = null;
        $state['bb_option_pending'] = false;
        $state['actions_this_round'] = 0;
        foreach ($state['players'] as &$pl) {
            $pl['bet_this_round'] = 0;
        }
        unset($pl);

        return $state;
    }

    private function shouldAutoAdvance(array $state): bool
    {
        $active = array_filter($state['players'], fn ($p) => ! $p['folded'] && ! $p['all_in']);

        return count($active) <= 1 && $state['phase'] !== 'showdown';
    }

    private function advanceToNextStreet(CasinoTable $table, array $state): array
    {
        while (in_array($state['phase'], ['flop', 'turn', 'river'], true)) {
            $state = $this->dealCommunityCards($state);

            $active = array_filter($state['players'], fn ($p) => ! $p['folded'] && ! $p['all_in']);
            if (count($active) > 1) {
                break;
            }

            $state['phase'] = $this->nextStreet($state['phase']);
        }

        if ($state['phase'] === 'showdown') {
            $table->state_json = $state;
            $table->save();

            return $this->resolveShowdown($table);
        }

        $table->state_json = $state;
        $table->save();

        return ['action' => 'street_advanced', 'phase' => $state['phase']];
    }

    private function dealCommunityCards(array $state): array
    {
        $phase = $state['phase'];

        // Burn card
        array_shift($state['deck']);

        if ($phase === 'flop') {
            for ($i = 0; $i < 3; $i++) {
                $state['community'][] = array_shift($state['deck']);
            }
        } else {
            $state['community'][] = array_shift($state['deck']);
        }

        return $state;
    }

    private function nextStreet(string $phase): string
    {
        return match ($phase) {
            'pre_flop' => 'flop',
            'flop' => 'turn',
            'turn' => 'river',
            'river' => 'showdown',
            default => 'showdown',
        };
    }

    private function firstActiveAfterDealer(array $state): int
    {
        $total = count($state['players']);
        $start = ($state['dealer_seat'] + 1) % $total;

        for ($i = 0; $i < $total; $i++) {
            $idx = ($start + $i) % $total;
            $p = $state['players'][$idx];
            if (! $p['folded'] && ! $p['all_in']) {
                return $idx;
            }
        }

        return $start;
    }

    private function resolveShowdown(CasinoTable $table): array
    {
        $state = $table->state_json;

        // Fill remaining community cards WITHOUT burning extra cards when
        // called mid-street. Burn cards only happen during voluntary street
        // transitions in dealCommunityCards(). If someone went all-in early
        // and we need to deal more community cards to reach river, we fill
        // straight from the deck — the burns that would have happened at
        // street transitions were not performed (this is acceptable and
        // matches live-poker "run it out" conventions).
        while (count($state['community']) < 5) {
            $state['community'][] = array_shift($state['deck']);
        }

        $activePlayers = array_filter($state['players'], fn ($p) => ! $p['folded']);

        // Only one player left → they win everything (rake still applies).
        if (count($activePlayers) === 1) {
            $winner = array_values($activePlayers)[0];
            $rake = $this->calculateRake($table, $state['pot']);
            $winnings = $state['pot'] - $rake;

            $this->awardPot($table, $winner['player_id'], $winnings);

            return $this->finishHand($table, $state, [[
                'player_id' => $winner['player_id'],
                'amount' => $winnings,
                'hand' => null,
            ]]);
        }

        // Evaluate hands for all active players.
        $evaluations = [];
        foreach ($activePlayers as $idx => $p) {
            $allCards = array_merge($p['hole_cards'], $state['community']);
            $eval = $this->handEvaluator->evaluate($allCards);
            $evaluations[$idx] = [
                'idx' => $idx,
                'player' => $p,
                'eval' => $eval,
            ];
        }

        // Compute side pots from total_bet contributions. Side pot logic:
        // sort contributions ascending, pots are layered at each distinct
        // contribution level. Folded players contribute but cannot win.
        $pots = $this->computeSidePots($state['players']);

        // Apply rake once on the combined pot total, scaled proportionally
        // across the layered pots so each pot contributes its share.
        $totalPot = array_sum(array_column($pots, 'amount'));
        $totalRake = $this->calculateRake($table, $totalPot);

        $playerPayouts = [];
        foreach ($pots as $pot) {
            if ($pot['amount'] <= 0) {
                continue;
            }

            // Rake for this pot is proportional to its share of the total.
            $potRake = $totalPot > 0
                ? round($totalRake * ($pot['amount'] / $totalPot), 2)
                : 0.0;
            $distributable = max(0, $pot['amount'] - $potRake);

            // Eligible winners are the evaluated players whose idx is in
            // this pot's eligible set (i.e., they contributed to this layer).
            $eligibleEvals = array_values(array_filter(
                $evaluations,
                fn ($e) => in_array($e['idx'], $pot['eligible'], true),
            ));

            if (count($eligibleEvals) === 0) {
                continue;
            }

            usort($eligibleEvals, fn ($a, $b) => $b['eval']['rank'] <=> $a['eval']['rank']);
            $bestRank = $eligibleEvals[0]['eval']['rank'];
            $winners = array_values(array_filter(
                $eligibleEvals,
                fn ($e) => $e['eval']['rank'] === $bestRank,
            ));

            $share = round($distributable / count($winners), 2);
            foreach ($winners as $w) {
                $pid = $w['player']['player_id'];
                $playerPayouts[$pid] = ($playerPayouts[$pid] ?? 0) + $share;
                $playerPayouts[$pid.'_hand'] = $w['eval']['category_name'];
            }
        }

        // Apply the credits.
        $results = [];
        foreach ($playerPayouts as $key => $value) {
            if (str_ends_with((string) $key, '_hand')) {
                continue;
            }
            $pid = (int) $key;
            $amount = (float) $value;
            $this->awardPot($table, $pid, $amount);
            $results[] = [
                'player_id' => $pid,
                'amount' => $amount,
                'hand' => $playerPayouts[$pid.'_hand'] ?? null,
            ];
        }

        return $this->finishHand($table, $state, $results);
    }

    /**
     * Compute layered side pots from player total_bet contributions.
     * Each pot has an amount and a list of eligible player indices.
     *
     * @param  list<array<string,mixed>>  $players
     * @return list<array{amount: float, eligible: list<int>}>
     */
    private function computeSidePots(array $players): array
    {
        // Contribution levels from all players (including folded — their
        // chips are in the pot).
        $contributions = [];
        foreach ($players as $idx => $p) {
            if ($p['total_bet'] > 0) {
                $contributions[$idx] = (float) $p['total_bet'];
            }
        }

        if (empty($contributions)) {
            return [];
        }

        // Sorted distinct contribution levels.
        $levels = array_values(array_unique(array_values($contributions)));
        sort($levels);

        $pots = [];
        $previousLevel = 0.0;

        foreach ($levels as $level) {
            $layerSize = $level - $previousLevel;
            if ($layerSize <= 0) {
                continue;
            }

            // Every player who contributed at least this level puts
            // $layerSize into this layer.
            $contributors = array_keys(array_filter(
                $contributions,
                fn ($c) => $c >= $level,
            ));

            $potAmount = round($layerSize * count($contributors), 2);

            // Eligible winners = contributors who are NOT folded.
            $eligible = array_values(array_filter(
                $contributors,
                fn ($idx) => ! $players[$idx]['folded'],
            ));

            $pots[] = [
                'amount' => $potAmount,
                'eligible' => $eligible,
            ];

            $previousLevel = $level;
        }

        return $pots;
    }

    private function calculateRake(CasinoTable $table, float $potAmount): float
    {
        $rakePct = (float) $this->config->get('casino.holdem.rake_pct', 0.05);
        $rakeCap = $table->currency === 'akzar_cash'
            ? (float) $this->config->get('casino.holdem.rake_cap_cash', 5.0)
            : (float) $this->config->get('casino.holdem.rake_cap_barrels', 500);

        return round(min($potAmount * $rakePct, $rakeCap), 2);
    }

    private function finishHand(CasinoTable $table, array $state, array $results): array
    {
        $round = CasinoRound::create([
            'casino_table_id' => $table->id,
            'game_type' => 'holdem',
            'currency' => $table->currency,
            'round_number' => $table->round_number,
            'rng_seed' => "{$table->id}:{$table->round_number}",
            'state_snapshot' => $state,
            'result_summary' => ['results' => $results, 'community' => CardDeck::toDisplayArray($state['community'])],
            'resolved_at' => now(),
        ]);

        foreach ($state['players'] as $p) {
            CasinoBet::create([
                'casino_round_id' => $round->id,
                'player_id' => $p['player_id'],
                'bet_type' => $p['folded'] ? 'fold' : 'play',
                'amount' => $p['total_bet'],
                'payout' => collect($results)->where('player_id', $p['player_id'])->sum('amount'),
            ]);
        }

        $state['phase'] = 'waiting';
        $state['players'] = [];
        $state['community'] = [];
        $state['deck'] = [];
        $state['pot'] = 0;
        $state['side_pots'] = [];
        $state['current_bet'] = 0;
        $state['action_on'] = null;
        $state['actions_this_round'] = 0;

        $table->status = 'waiting';
        $table->state_json = $state;
        $table->save();

        return [
            'action' => 'showdown',
            'results' => $results,
            'community' => CardDeck::toDisplayArray($state['community'] ?? []),
        ];
    }

    private function awardPot(CasinoTable $table, int $playerId, float $amount): void
    {
        CasinoTablePlayer::query()
            ->where('casino_table_id', $table->id)
            ->where('player_id', $playerId)
            ->increment('stack', $amount);
    }

    private function foldPlayer(CasinoTable $table, array $state, int $playerId): void
    {
        foreach ($state['players'] as $i => &$p) {
            if ($p['player_id'] === $playerId && ! $p['folded']) {
                $p['folded'] = true;
                break;
            }
        }
        unset($p);

        $state = $this->advanceAction($state);
        $table->state_json = $state;
        $table->save();

        if ($state['phase'] === 'showdown') {
            $this->resolveShowdown($table);
        }
    }

    private function dispatchTurnTimeout(CasinoTable $table, array $state): void
    {
        $timerSeconds = (int) $this->config->get('casino.holdem.turn_timer_seconds', 30);
        $actionOn = $state['action_on'] ?? null;

        if ($actionOn === null || ! isset($state['players'][$actionOn])) {
            return;
        }

        $pid = $state['players'][$actionOn]['player_id'];

        Bus::dispatch(
            (new HoldemTurnTimeout($table->id, $pid, $table->round_number))
                ->delay($timerSeconds)
        );
    }

    private function sanitizedState(array $state, int $playerId, CasinoTable $table): array
    {
        $players = [];
        foreach ($state['players'] ?? [] as $i => $p) {
            $isMe = $p['player_id'] === $playerId;
            $isShowdown = $state['phase'] === 'showdown';

            $players[] = [
                'seat' => $p['seat'],
                'player_id' => $p['player_id'],
                'stack' => $p['stack'],
                'bet_this_round' => $p['bet_this_round'],
                'total_bet' => $p['total_bet'],
                'folded' => $p['folded'],
                'all_in' => $p['all_in'],
                'hole_cards' => ($isMe || $isShowdown) && ! $p['folded']
                    ? CardDeck::toDisplayArray($p['hole_cards'])
                    : null,
            ];
        }

        return [
            'table_id' => $table->id,
            'currency' => $table->currency,
            'phase' => $state['phase'],
            'round_number' => $table->round_number,
            'pot' => $state['pot'] ?? 0,
            'current_bet' => $state['current_bet'] ?? 0,
            'community' => CardDeck::toDisplayArray($state['community'] ?? []),
            'players' => $players,
            'action_on' => $state['action_on'] ?? null,
            'dealer_seat' => $state['dealer_seat'] ?? null,
            'blind_level' => $state['blind_level'] ?? null,
            'is_my_turn' => $this->isPlayerTurn($state, $playerId),
            'my_seat' => $this->findPlayerSeat($state, $playerId),
        ];
    }

    private function isPlayerTurn(array $state, int $playerId): bool
    {
        if (! in_array($state['phase'], ['pre_flop', 'flop', 'turn', 'river'], true)) {
            return false;
        }
        $on = $state['action_on'] ?? null;

        return $on !== null && isset($state['players'][$on]) && $state['players'][$on]['player_id'] === $playerId;
    }

    private function findPlayerSeat(array $state, int $playerId): ?int
    {
        foreach ($state['players'] ?? [] as $i => $p) {
            if ($p['player_id'] === $playerId) {
                return $i;
            }
        }

        return null;
    }

    private function freshState(string $currency = 'akzar_cash'): array
    {
        return [
            'phase' => 'waiting',
            'deck' => [],
            'community' => [],
            'players' => [],
            'pot' => 0,
            'side_pots' => [],
            'current_bet' => 0,
            'last_raise_size' => 0,
            'action_on' => null,
            'last_voluntary_raiser' => null,
            'bb_option_pending' => false,
            'bb_index' => null,
            'dealer_seat' => -1,
            'blind_level' => $this->defaultBlinds($currency),
            'actions_this_round' => 0,
        ];
    }

    /**
     * @return array{small: float, big: float}
     */
    private function defaultBlinds(string $currency): array
    {
        $levels = (array) $this->config->get('casino.holdem.default_blinds', []);
        if (isset($levels[$currency]) && is_array($levels[$currency])) {
            return [
                'small' => (float) $levels[$currency]['small'],
                'big' => (float) $levels[$currency]['big'],
            ];
        }

        return $currency === 'akzar_cash'
            ? ['small' => 0.05, 'big' => 0.10]
            : ['small' => 5.0, 'big' => 10.0];
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
