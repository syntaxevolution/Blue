<?php

namespace App\Domain\Casino;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Config\RngService;
use App\Domain\Exceptions\CasinoException;
use App\Jobs\ResolveRouletteRound;
use App\Models\CasinoBet;
use App\Models\CasinoRound;
use App\Models\CasinoTable;
use App\Models\Player;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

class RouletteService
{
    private const RED_NUMBERS = [1,3,5,7,9,12,14,16,18,19,21,23,25,27,30,32,34,36];

    private const VALID_BET_TYPES = [
        'straight', 'split', 'street', 'corner', 'line',
        'column_1', 'column_2', 'column_3',
        'dozen_1', 'dozen_2', 'dozen_3',
        'red', 'black', 'odd', 'even', 'low', 'high',
    ];

    public function __construct(
        private readonly GameConfigResolver $config,
        private readonly RngService $rng,
        private readonly CasinoService $casinoService,
    ) {}

    public function findOrCreateTable(string $currency): CasinoTable
    {
        $table = CasinoTable::query()
            ->where('game_type', 'roulette')
            ->where('currency', $currency)
            ->whereIn('status', ['waiting', 'active'])
            ->first();

        if ($table !== null) {
            return $table;
        }

        $minBet = $currency === 'akzar_cash'
            ? (float) $this->config->get('casino.roulette.min_bet_cash')
            : (float) $this->config->get('casino.roulette.min_bet_barrels');
        $maxBet = $currency === 'akzar_cash'
            ? (float) $this->config->get('casino.roulette.max_bet_cash')
            : (float) $this->config->get('casino.roulette.max_bet_barrels');

        return CasinoTable::create([
            'game_type' => 'roulette',
            'currency' => $currency,
            'label' => $currency === 'akzar_cash' ? 'Cash Roulette' : 'Oil Roulette',
            'min_bet' => $minBet,
            'max_bet' => $maxBet,
            'seats' => 0,
            'status' => 'waiting',
            'state_json' => ['bets' => [], 'phase' => 'idle'],
        ]);
    }

    /**
     * @return array{bet_id: string, table_id: int, round_number: int, expires_at: string|null}
     */
    public function placeBet(int $playerId, int $tableId, string $betType, array $numbers, float $amount): array
    {
        if (! (bool) $this->config->get('casino.roulette.enabled')) {
            throw CasinoException::gameNotEnabled('roulette');
        }

        $this->casinoService->requireActiveSession($playerId);
        $this->validateBetType($betType, $numbers);

        return DB::transaction(function () use ($playerId, $tableId, $betType, $numbers, $amount) {
            /** @var CasinoTable $table */
            $table = CasinoTable::query()->lockForUpdate()->findOrFail($tableId);

            if ($table->game_type !== 'roulette') {
                throw CasinoException::invalidAction('not a roulette table');
            }

            $state = $table->state_json ?? ['bets' => [], 'phase' => 'idle'];

            if ($state['phase'] === 'spinning') {
                throw CasinoException::bettingWindowClosed();
            }

            $this->validateBetAmount($table->currency, $amount, (float) $table->min_bet, (float) $table->max_bet);

            $maxBets = (int) $this->config->get('casino.roulette.max_bets_per_round', 20);
            $playerBetCount = count(array_filter($state['bets'], fn ($b) => $b['player_id'] === $playerId));
            if ($playerBetCount >= $maxBets) {
                throw CasinoException::invalidAction("Max {$maxBets} bets per round reached");
            }

            /** @var Player $player */
            $player = Player::query()->lockForUpdate()->findOrFail($playerId);
            $this->assertAffordable($player, $table->currency, $amount);
            $this->deductBet($player, $table->currency, $amount);

            $betId = uniqid('rb_', true);
            $state['bets'][] = [
                'id' => $betId,
                'player_id' => $playerId,
                'bet_type' => $betType,
                'numbers' => $numbers,
                'amount' => $amount,
            ];

            if ($state['phase'] === 'idle') {
                $windowSeconds = (int) $this->config->get('casino.roulette.betting_window_seconds', 60);
                $state['phase'] = 'betting';
                $table->round_number = $table->round_number + 1;
                $table->round_started_at = now();
                $table->round_expires_at = now()->addSeconds($windowSeconds);
                $table->status = 'active';

                Bus::dispatch(
                    (new ResolveRouletteRound($table->id, $table->round_number))
                        ->delay($windowSeconds)
                );
            }

            $table->state_json = $state;
            $table->save();

            return [
                'bet_id' => $betId,
                'table_id' => $table->id,
                'round_number' => $table->round_number,
                'expires_at' => $table->round_expires_at?->toIso8601String(),
            ];
        });
    }

    /**
     * @return array{number: int, color: string, payouts: list<array{player_id: int, amount: float, net: float}>}
     */
    public function resolveSpin(int $tableId, int $expectedRoundNumber): array
    {
        return DB::transaction(function () use ($tableId, $expectedRoundNumber) {
            /** @var CasinoTable $table */
            $table = CasinoTable::query()->lockForUpdate()->findOrFail($tableId);

            if ($table->round_number !== $expectedRoundNumber) {
                return ['number' => -1, 'color' => 'stale', 'payouts' => []];
            }

            $state = $table->state_json ?? ['bets' => [], 'phase' => 'idle'];

            if ($state['phase'] !== 'betting') {
                return ['number' => -1, 'color' => 'stale', 'payouts' => []];
            }

            $state['phase'] = 'spinning';
            $table->state_json = $state;
            $table->save();

            $number = $this->rng->rollInt(
                'casino.roulette.spin',
                "{$tableId}:{$table->round_number}",
                0,
                36,
            );

            $color = $this->numberColor($number);
            $bets = $state['bets'];
            $payoutsByPlayer = [];

            $round = CasinoRound::create([
                'casino_table_id' => $tableId,
                'game_type' => 'roulette',
                'currency' => $table->currency,
                'round_number' => $table->round_number,
                'rng_seed' => "{$tableId}:{$table->round_number}",
                'state_snapshot' => ['bets' => $bets, 'result' => $number, 'color' => $color],
                'result_summary' => null,
                'resolved_at' => now(),
            ]);

            $payouts = [];
            foreach ($bets as $bet) {
                $winNumbers = $this->betCoveredNumbers($bet['bet_type'], $bet['numbers']);
                $won = in_array($number, $winNumbers, true);
                $multiplier = $won ? $this->payoutMultiplier($bet['bet_type']) : 0;
                $payout = $won ? round($bet['amount'] * $multiplier, 2) + $bet['amount'] : 0.0;

                CasinoBet::create([
                    'casino_round_id' => $round->id,
                    'player_id' => $bet['player_id'],
                    'bet_type' => $bet['bet_type'],
                    'amount' => $bet['amount'],
                    'payout' => $payout,
                ]);

                if ($payout > 0) {
                    $payoutsByPlayer[$bet['player_id']] = ($payoutsByPlayer[$bet['player_id']] ?? 0) + $payout;
                }

                $payouts[] = [
                    'player_id' => $bet['player_id'],
                    'amount' => $payout,
                    'net' => $payout - $bet['amount'],
                ];
            }

            // Sort player IDs ascending to guarantee a consistent lock
            // ordering across concurrent table resolutions — prevents
            // deadlocks when two tables resolve simultaneously and share
            // winning players.
            $sortedPids = array_keys($payoutsByPlayer);
            sort($sortedPids);
            foreach ($sortedPids as $pid) {
                $totalPayout = $payoutsByPlayer[$pid];
                /** @var Player $player */
                $player = Player::query()->lockForUpdate()->findOrFail($pid);
                $this->creditWinnings($player, $table->currency, $totalPayout);
            }

            $round->update(['result_summary' => ['number' => $number, 'color' => $color, 'payouts' => $payouts]]);

            $table->update([
                'state_json' => ['bets' => [], 'phase' => 'idle'],
                'status' => 'waiting',
                'round_started_at' => null,
                'round_expires_at' => null,
            ]);

            return ['number' => $number, 'color' => $color, 'payouts' => $payouts];
        });
    }

    /**
     * @return array{id: int, currency: string, min_bet: float, max_bet: float, phase: string, round_number: int, expires_at: string|null, bets: list<array{bet_type: string, numbers: list<int>, amount: float}>}
     */
    public function tableState(int $tableId, int $playerId): array
    {
        $table = CasinoTable::query()->findOrFail($tableId);
        $state = $table->state_json ?? ['bets' => [], 'phase' => 'idle'];

        $myBets = array_values(array_filter(
            $state['bets'],
            fn ($b) => $b['player_id'] === $playerId,
        ));

        return [
            'id' => $table->id,
            'currency' => $table->currency,
            'min_bet' => (float) $table->min_bet,
            'max_bet' => (float) $table->max_bet,
            'phase' => $state['phase'],
            'round_number' => $table->round_number,
            'expires_at' => $table->round_expires_at?->toIso8601String(),
            'total_bets' => count($state['bets']),
            'my_bets' => array_map(fn ($b) => [
                'id' => $b['id'],
                'bet_type' => $b['bet_type'],
                'numbers' => $b['numbers'],
                'amount' => $b['amount'],
            ], $myBets),
        ];
    }

    private function validateBetType(string $betType, array $numbers): void
    {
        if (! in_array($betType, self::VALID_BET_TYPES, true)) {
            throw CasinoException::invalidBetType($betType);
        }

        $expectedCount = match ($betType) {
            'straight' => 1,
            'split' => 2,
            'street' => 3,
            'corner' => 4,
            'line' => 6,
            default => 0,
        };

        if ($expectedCount > 0 && count($numbers) !== $expectedCount) {
            throw CasinoException::invalidBetType("{$betType} requires {$expectedCount} numbers");
        }

        foreach ($numbers as $n) {
            if (! is_int($n) || $n < 0 || $n > 36) {
                throw CasinoException::invalidBetType("Number {$n} is out of range [0, 36]");
            }
        }
    }

    private function validateBetAmount(string $currency, float $amount, float $min, float $max): void
    {
        if ($amount < $min || $amount > $max) {
            throw CasinoException::invalidBetAmount($amount, $min, $max);
        }
    }

    /**
     * @return list<int>
     */
    private function betCoveredNumbers(string $betType, array $numbers): array
    {
        return match ($betType) {
            'straight', 'split', 'street', 'corner', 'line' => $numbers,
            'column_1' => [1,4,7,10,13,16,19,22,25,28,31,34],
            'column_2' => [2,5,8,11,14,17,20,23,26,29,32,35],
            'column_3' => [3,6,9,12,15,18,21,24,27,30,33,36],
            'dozen_1' => range(1, 12),
            'dozen_2' => range(13, 24),
            'dozen_3' => range(25, 36),
            'red' => self::RED_NUMBERS,
            'black' => array_values(array_diff(range(1, 36), self::RED_NUMBERS)),
            'odd' => array_filter(range(1, 36), fn ($n) => $n % 2 === 1),
            'even' => array_filter(range(1, 36), fn ($n) => $n % 2 === 0),
            'low' => range(1, 18),
            'high' => range(19, 36),
            default => [],
        };
    }

    private function payoutMultiplier(string $betType): float
    {
        $payouts = (array) $this->config->get('casino.roulette.payouts');

        return match ($betType) {
            'straight' => (float) ($payouts['straight'] ?? 35),
            'split' => (float) ($payouts['split'] ?? 17),
            'street' => (float) ($payouts['street'] ?? 11),
            'corner' => (float) ($payouts['corner'] ?? 8),
            'line' => (float) ($payouts['line'] ?? 5),
            'column_1', 'column_2', 'column_3' => (float) ($payouts['column'] ?? 2),
            'dozen_1', 'dozen_2', 'dozen_3' => (float) ($payouts['dozen'] ?? 2),
            'red', 'black', 'odd', 'even', 'low', 'high' => (float) ($payouts['even_money'] ?? 1),
            default => 0,
        };
    }

    private function numberColor(int $number): string
    {
        if ($number === 0) {
            return 'green';
        }

        return in_array($number, self::RED_NUMBERS, true) ? 'red' : 'black';
    }

    private function assertAffordable(Player $player, string $currency, float $amount): void
    {
        if ($currency === 'akzar_cash') {
            if ((float) $player->akzar_cash < $amount) {
                throw CasinoException::insufficientCash((float) $player->akzar_cash, $amount);
            }
        } else {
            if ($player->oil_barrels < (int) $amount) {
                throw CasinoException::insufficientBarrels($player->oil_barrels, (int) $amount);
            }
        }
    }

    private function deductBet(Player $player, string $currency, float $amount): void
    {
        if ($currency === 'akzar_cash') {
            $player->update(['akzar_cash' => (float) $player->akzar_cash - $amount]);
        } else {
            $player->update(['oil_barrels' => $player->oil_barrels - (int) $amount]);
        }
    }

    private function creditWinnings(Player $player, string $currency, float $amount): void
    {
        if ($currency === 'akzar_cash') {
            $player->update(['akzar_cash' => (float) $player->akzar_cash + $amount]);
        } else {
            $player->update(['oil_barrels' => $player->oil_barrels + (int) $amount]);
        }
    }
}
