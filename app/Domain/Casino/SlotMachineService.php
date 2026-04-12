<?php

namespace App\Domain\Casino;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Config\RngService;
use App\Domain\Exceptions\CasinoException;
use App\Models\CasinoBet;
use App\Models\CasinoRound;
use App\Models\Player;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SlotMachineService
{
    public function __construct(
        private readonly GameConfigResolver $config,
        private readonly RngService $rng,
        private readonly CasinoService $casinoService,
    ) {}

    /**
     * @return array{reels: list<string>, payout: float, net: float, balance: float|int, multiplier: float, win_line: string|null}
     */
    public function spin(int $playerId, string $currency, float $betAmount): array
    {
        if (! (bool) $this->config->get('casino.slots.enabled')) {
            throw CasinoException::gameNotEnabled('slots');
        }

        $this->casinoService->requireActiveSession($playerId);

        $this->validateBet($currency, $betAmount);
        $this->enforceSpinInterval($playerId);

        return DB::transaction(function () use ($playerId, $currency, $betAmount) {
            /** @var Player $player */
            $player = Player::query()->lockForUpdate()->findOrFail($playerId);

            $this->assertAffordable($player, $currency, $betAmount);

            $this->deductBet($player, $currency, $betAmount);

            $spinCount = $this->playerSpinCount($playerId);
            $reels = $this->rollReels($playerId, $spinCount);
            $payResult = $this->evaluatePayout($reels, $betAmount);

            if ($payResult['payout'] > 0) {
                $this->creditWinnings($player, $currency, $payResult['payout']);
            }

            $round = CasinoRound::create([
                'casino_table_id' => null,
                'game_type' => 'slots',
                'currency' => $currency,
                'round_number' => $spinCount + 1,
                'rng_seed' => "slots:{$playerId}:{$spinCount}",
                'state_snapshot' => ['reels' => $reels],
                'result_summary' => $payResult,
                'resolved_at' => now(),
            ]);

            CasinoBet::create([
                'casino_round_id' => $round->id,
                'player_id' => $playerId,
                'bet_type' => 'spin',
                'amount' => $betAmount,
                'payout' => $payResult['payout'],
            ]);

            $player->refresh();

            $balanceField = $currency === 'akzar_cash' ? 'akzar_cash' : 'oil_barrels';

            return [
                'reels' => $reels,
                'payout' => $payResult['payout'],
                'net' => $payResult['payout'] - $betAmount,
                'balance' => $currency === 'akzar_cash'
                    ? (float) $player->$balanceField
                    : (int) $player->$balanceField,
                'multiplier' => $payResult['multiplier'],
                'win_line' => $payResult['win_line'],
            ];
        });
    }

    /**
     * @return list<string>
     */
    private function rollReels(int $playerId, int $spinCount): array
    {
        $symbols = (array) $this->config->get('casino.slots.symbols');
        $reelCount = (int) $this->config->get('casino.slots.reel_count', 3);

        $weights = [];
        foreach ($symbols as $key => $cfg) {
            $weights[$key] = (int) ($cfg['weight'] ?? 1);
        }

        $reels = [];
        for ($i = 0; $i < $reelCount; $i++) {
            $reels[] = (string) $this->rng->rollWeighted(
                'casino.slots.reel',
                "{$playerId}:{$spinCount}:{$i}",
                $weights,
            );
        }

        return $reels;
    }

    /**
     * @param  list<string>  $reels
     * @return array{payout: float, multiplier: float, win_line: string|null}
     */
    private function evaluatePayout(array $reels, float $betAmount): array
    {
        $payTable = (array) $this->config->get('casino.slots.pay_table');

        $barSymbols = ['bar', 'double_bar', 'triple_bar'];

        foreach ($payTable as $entry) {
            [$symbol, $count, $multiplier] = $entry;

            if ($symbol === 'any_bar' && $count === 3) {
                $allBars = true;
                foreach ($reels as $reel) {
                    if (! in_array($reel, $barSymbols, true)) {
                        $allBars = false;
                        break;
                    }
                }
                $notAllSame = count(array_unique($reels)) > 1;
                if ($allBars && $notAllSame) {
                    return [
                        'payout' => round($betAmount * $multiplier, 2),
                        'multiplier' => (float) $multiplier,
                        'win_line' => 'any_bar_3',
                    ];
                }

                continue;
            }

            if ($count === 2) {
                $matched = 0;
                foreach ($reels as $reel) {
                    if ($reel === $symbol) {
                        $matched++;
                    }
                }
                // Exact match of 2 — a 3-of-a-kind must be handled by the
                // 3-of-a-kind entry (which should be ordered before this
                // rule in the pay table anyway). Using === $count prevents
                // the 2-of-a-kind rule from silently capturing a triple.
                if ($matched === $count) {
                    return [
                        'payout' => round($betAmount * $multiplier, 2),
                        'multiplier' => (float) $multiplier,
                        'win_line' => "{$symbol}_{$count}",
                    ];
                }

                continue;
            }

            if ($count === 3 && count($reels) >= 3) {
                if ($reels[0] === $symbol && $reels[1] === $symbol && $reels[2] === $symbol) {
                    return [
                        'payout' => round($betAmount * $multiplier, 2),
                        'multiplier' => (float) $multiplier,
                        'win_line' => "{$symbol}_{$count}",
                    ];
                }
            }
        }

        return [
            'payout' => 0.0,
            'multiplier' => 0.0,
            'win_line' => null,
        ];
    }

    private function validateBet(string $currency, float $betAmount): void
    {
        if ($currency === 'akzar_cash') {
            $min = (float) $this->config->get('casino.slots.min_bet_cash');
            $max = (float) $this->config->get('casino.slots.max_bet_cash');
        } else {
            $min = (float) $this->config->get('casino.slots.min_bet_barrels');
            $max = (float) $this->config->get('casino.slots.max_bet_barrels');
        }

        if ($betAmount < $min || $betAmount > $max) {
            throw CasinoException::invalidBetAmount($betAmount, $min, $max);
        }
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

    private function playerSpinCount(int $playerId): int
    {
        // Per-spin counter used as a deterministic event key component.
        // Cached in Redis to avoid a whereHas N+1 on every spin; falls
        // back to a DB count if the cache is cold.
        $cacheKey = "casino.slots.spin_count:{$playerId}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            Cache::increment($cacheKey);

            return (int) $cached;
        }

        $count = (int) CasinoBet::query()
            ->where('player_id', $playerId)
            ->whereHas('round', fn ($q) => $q->where('game_type', 'slots'))
            ->count();

        Cache::put($cacheKey, $count + 1, now()->addDays(30));

        return $count;
    }

    private function enforceSpinInterval(int $playerId): void
    {
        $minSeconds = (int) $this->config->get('casino.slots.min_interval_seconds', 1);
        if ($minSeconds <= 0) {
            return;
        }

        $lockKey = "casino.slots.last_spin:{$playerId}";
        $lastSpin = Cache::get($lockKey);
        $now = microtime(true);

        if ($lastSpin !== null && ($now - (float) $lastSpin) < $minSeconds) {
            throw CasinoException::invalidAction("Please wait {$minSeconds}s between spins");
        }

        Cache::put($lockKey, $now, now()->addMinutes(5));
    }
}
