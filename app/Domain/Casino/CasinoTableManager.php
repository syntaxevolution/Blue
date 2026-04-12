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

    public function ensureAllTablesExist(): void
    {
        if ((bool) $this->config->get('casino.roulette.enabled')) {
            $this->ensureRouletteTablesExist();
        }

        if ((bool) $this->config->get('casino.blackjack.enabled')) {
            $this->ensureBlackjackTablesExist();
        }
    }
}
