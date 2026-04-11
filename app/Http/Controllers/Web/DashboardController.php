<?php

namespace App\Http\Controllers\Web;

use App\Domain\Config\GameConfigResolver;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders the dashboard with configuration-sourced stat boxes so
 * immunity hours, daily regen, starting cash, and bank cap reflect
 * live config (tunable via the admin panel without a deploy).
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly GameConfigResolver $config,
    ) {}

    public function show(): Response
    {
        $dailyRegen = (int) $this->config->get('moves.daily_regen');
        $bankCap = (int) floor($dailyRegen * (float) $this->config->get('moves.bank_cap_multiplier'));

        return Inertia::render('Dashboard', [
            'startingCash' => number_format((float) $this->config->get('new_player.starting_cash'), 2),
            'dailyRegen' => $dailyRegen,
            'bankCap' => $bankCap,
            'immunityHours' => (int) $this->config->get('new_player.immunity_hours'),
        ]);
    }
}
