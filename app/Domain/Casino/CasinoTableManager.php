<?php

namespace App\Domain\Casino;

use App\Domain\Config\GameConfigResolver;
use App\Models\CasinoTable;

class CasinoTableManager
{
    public function __construct(
        private readonly GameConfigResolver $config,
    ) {}

    public function ensureRouletteTablesExist(): void
    {
        $perCurrency = (int) $this->config->get('casino.roulette.tables_per_currency', 1);

        foreach (['akzar_cash', 'oil_barrels'] as $currency) {
            $existing = CasinoTable::query()
                ->where('game_type', 'roulette')
                ->where('currency', $currency)
                ->whereIn('status', ['waiting', 'active'])
                ->count();

            for ($i = $existing; $i < $perCurrency; $i++) {
                $label = $currency === 'akzar_cash' ? 'Cash Roulette' : 'Oil Roulette';
                if ($perCurrency > 1) {
                    $label .= ' #'.($i + 1);
                }

                CasinoTable::create([
                    'game_type' => 'roulette',
                    'currency' => $currency,
                    'label' => $label,
                    'min_bet' => $currency === 'akzar_cash'
                        ? (float) $this->config->get('casino.roulette.min_bet_cash')
                        : (float) $this->config->get('casino.roulette.min_bet_barrels'),
                    'max_bet' => $currency === 'akzar_cash'
                        ? (float) $this->config->get('casino.roulette.max_bet_cash')
                        : (float) $this->config->get('casino.roulette.max_bet_barrels'),
                    'seats' => 0,
                    'status' => 'waiting',
                    'state_json' => ['bets' => [], 'phase' => 'idle'],
                ]);
            }
        }
    }

    public function ensureBlackjackTablesExist(): void
    {
        $perCurrency = (int) $this->config->get('casino.blackjack.tables_per_currency', 1);

        foreach (['akzar_cash', 'oil_barrels'] as $currency) {
            $existing = CasinoTable::query()
                ->where('game_type', 'blackjack')
                ->where('currency', $currency)
                ->whereIn('status', ['waiting', 'active'])
                ->count();

            for ($i = $existing; $i < $perCurrency; $i++) {
                $label = $currency === 'akzar_cash' ? 'Cash Blackjack' : 'Oil Blackjack';
                if ($perCurrency > 1) {
                    $label .= ' #'.($i + 1);
                }

                CasinoTable::create([
                    'game_type' => 'blackjack',
                    'currency' => $currency,
                    'label' => $label,
                    'min_bet' => $currency === 'akzar_cash'
                        ? (float) $this->config->get('casino.blackjack.min_bet_cash')
                        : (float) $this->config->get('casino.blackjack.min_bet_barrels'),
                    'max_bet' => $currency === 'akzar_cash'
                        ? (float) $this->config->get('casino.blackjack.max_bet_cash')
                        : (float) $this->config->get('casino.blackjack.max_bet_barrels'),
                    'seats' => (int) $this->config->get('casino.blackjack.max_seats', 5),
                    'status' => 'waiting',
                    'state_json' => ['phase' => 'waiting', 'deck' => [], 'hands' => []],
                ]);
            }
        }
    }

    public function ensureHoldemTablesExist(): void
    {
        $blindLevels = (array) $this->config->get('casino.holdem.default_blinds', []);

        foreach (['akzar_cash', 'oil_barrels'] as $currency) {
            $existing = CasinoTable::query()
                ->where('game_type', 'holdem')
                ->where('currency', $currency)
                ->whereIn('status', ['waiting', 'active'])
                ->count();

            if ($existing >= 1) {
                continue;
            }

            $blind = $blindLevels[$currency] ?? ($currency === 'akzar_cash'
                ? ['small' => 0.05, 'big' => 0.10]
                : ['small' => 5, 'big' => 10]);

            $label = $currency === 'akzar_cash'
                ? "Cash Hold'em {$blind['small']}/{$blind['big']}"
                : "Oil Hold'em {$blind['small']}/{$blind['big']} bbl";

            $maxSeats = (int) $this->config->get('casino.holdem.max_seats', 6);
            $maxBuyMult = (int) $this->config->get('casino.holdem.max_buy_in_multiplier', 100);

            CasinoTable::create([
                'game_type' => 'holdem',
                'currency' => $currency,
                'label' => $label,
                'min_bet' => (float) $blind['big'],
                'max_bet' => (float) ($blind['big'] * $maxBuyMult),
                'seats' => $maxSeats,
                'status' => 'waiting',
                'state_json' => [
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
                    'blind_level' => ['small' => (float) $blind['small'], 'big' => (float) $blind['big']],
                    'actions_this_round' => 0,
                ],
            ]);
        }
    }

    /**
     * Mark stale, empty tables as closed. Run from the scheduler.
     */
    public function cleanupEmptyTables(int $idleMinutes = 60): int
    {
        $threshold = now()->subMinutes($idleMinutes);

        $staleTableIds = CasinoTable::query()
            ->whereIn('status', ['waiting'])
            ->where('updated_at', '<', $threshold)
            ->whereDoesntHave('activePlayers')
            ->pluck('id')
            ->all();

        if (empty($staleTableIds)) {
            return 0;
        }

        // Don't close the single "always on" table per currency for
        // roulette/blackjack. Keep one waiting table for each game+currency.
        $keepIds = [];
        foreach (['roulette', 'blackjack', 'holdem'] as $gameType) {
            foreach (['akzar_cash', 'oil_barrels'] as $currency) {
                $keeperId = CasinoTable::query()
                    ->where('game_type', $gameType)
                    ->where('currency', $currency)
                    ->where('status', 'waiting')
                    ->orderBy('id')
                    ->value('id');
                if ($keeperId !== null) {
                    $keepIds[] = $keeperId;
                }
            }
        }

        $toClose = array_diff($staleTableIds, $keepIds);
        if (empty($toClose)) {
            return 0;
        }

        CasinoTable::query()->whereIn('id', $toClose)->update(['status' => 'closed']);

        return count($toClose);
    }

    public function ensureAllTablesExist(): void
    {
        if ((bool) $this->config->get('casino.roulette.enabled')) {
            $this->ensureRouletteTablesExist();
        }

        if ((bool) $this->config->get('casino.blackjack.enabled')) {
            $this->ensureBlackjackTablesExist();
        }

        if ((bool) $this->config->get('casino.holdem.enabled')) {
            $this->ensureHoldemTablesExist();
        }
    }
}
