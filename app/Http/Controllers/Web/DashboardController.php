<?php

namespace App\Http\Controllers\Web;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Leaderboard\LeaderboardService;
use App\Domain\Player\MoveRegenService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders the dashboard with configuration-sourced stat boxes so
 * immunity hours, daily regen, starting cash, and bank cap reflect
 * live config (tunable via the admin panel without a deploy). Also
 * surfaces the three cached top-5 leaderboards from LeaderboardService
 * so players land on something dynamic, not a static stat panel.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly GameConfigResolver $config,
        private readonly MoveRegenService $moveRegen,
        private readonly LeaderboardService $leaderboards,
    ) {}

    public function show(Request $request): Response
    {
        $dailyRegen = (int) $this->config->get('moves.daily_regen');

        // If the current user has a player row, show their personal bank
        // cap (base + Iron Lungs bonuses). Anonymous / pre-spawn users
        // see the base cap so the dashboard still renders without a
        // player record.
        $player = $request->user()?->player;
        $bankCap = $player
            ? $this->moveRegen->bankCapFor($player)
            : $this->moveRegen->bankCap();

        return Inertia::render('Dashboard', [
            'startingCash' => number_format((float) $this->config->get('new_player.starting_cash'), 2),
            'dailyRegen' => $dailyRegen,
            'bankCap' => $bankCap,
            'immunityHours' => (int) $this->config->get('new_player.immunity_hours'),
            'leaderboards' => $this->leaderboards->boards($player),
            // Passed separately so the Vue side can highlight the
            // viewer's own row across any of the three boards.
            'currentPlayerId' => $player?->id,
        ]);
    }
}
