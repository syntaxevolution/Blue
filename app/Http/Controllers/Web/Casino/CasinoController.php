<?php

namespace App\Http\Controllers\Web\Casino;

use App\Domain\Casino\CasinoService;
use App\Domain\Exceptions\CasinoException;
use App\Domain\Player\MapStateBuilder;
use App\Domain\World\WorldService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CasinoController extends Controller
{
    public function __construct(
        private readonly CasinoService $casinoService,
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
        return redirect()->route('map.show');
    }
}
