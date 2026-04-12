<?php

namespace App\Http\Controllers\Web\Casino;

use App\Domain\Casino\CasinoService;
use App\Domain\Casino\HoldemService;
use App\Domain\Exceptions\CasinoException;
use App\Domain\Player\MapStateBuilder;
use App\Domain\World\WorldService;
use App\Http\Controllers\Controller;
use App\Models\CasinoTablePlayer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class CasinoController extends Controller
{
    public function __construct(
        private readonly CasinoService $casinoService,
        private readonly HoldemService $holdem,
        private readonly \App\Domain\Casino\BlackjackService $blackjack,
        private readonly MapStateBuilder $mapState,
        private readonly WorldService $world,
    ) {}

    public function show(Request $request): Response
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);
        $session = $this->casinoService->activeSession($player->id);

        return Inertia::render('Casino/Lobby', [
            'state' => $this->mapState->build($player),
            'casino_session' => $session ? [
                'id' => $session->id,
                'expires_at' => $session->expires_at->toIso8601String(),
            ] : null,
        ]);
    }

    public function enter(Request $request): RedirectResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $result = $this->casinoService->enterCasino($player->id);
        } catch (CasinoException $e) {
            return redirect()->route('casino.show')->withErrors(['casino' => $e->getMessage()]);
        }

        return redirect()->route('casino.show')->with('casino_entered', [
            'fee_charged' => $result['fee_charged'],
            'expires_at' => $result['expires_at'],
        ]);
    }

    public function leave(Request $request): RedirectResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        // Auto-leave any tables the player is still seated at. Each
        // leaveTable call will cash out their stack back to their wallet.
        $activeSeats = CasinoTablePlayer::query()
            ->where('player_id', $player->id)
            ->where('status', 'active')
            ->with('table:id,game_type')
            ->get();

        foreach ($activeSeats as $seat) {
            $gameType = $seat->table?->game_type;
            try {
                if ($gameType === 'holdem') {
                    $this->holdem->leaveTable($player->id, $seat->casino_table_id);
                } elseif ($gameType === 'blackjack') {
                    $this->blackjack->leaveTable($player->id, $seat->casino_table_id);
                }
            } catch (CasinoException $e) {
                Log::warning('casino.leave.seat_cleanup_failed', [
                    'player_id' => $player->id,
                    'table_id' => $seat->casino_table_id,
                    'game_type' => $gameType,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return redirect()->route('map.show');
    }
}
