<?php

namespace App\Http\Controllers\Web\Casino;

use App\Domain\Casino\CasinoService;
use App\Domain\Casino\SlotMachineService;
use App\Domain\Exceptions\CasinoException;
use App\Domain\Player\MapStateBuilder;
use App\Domain\World\WorldService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Casino\SpinRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SlotsController extends Controller
{
    public function __construct(
        private readonly SlotMachineService $slots,
        private readonly CasinoService $casinoService,
        private readonly MapStateBuilder $mapState,
        private readonly WorldService $world,
    ) {}

    public function show(Request $request)
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        if (! $this->casinoService->hasActiveSession($player->id)) {
            return redirect()->route('casino.show');
        }

        return Inertia::render('Casino/Slots', [
            'state' => $this->mapState->build($player),
            'casino_session' => $this->sessionPayload($player->id),
        ]);
    }

    public function spin(SpinRequest $request): RedirectResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $result = $this->slots->spin(
                $player->id,
                $request->validated('currency'),
                (float) $request->validated('bet'),
            );
        } catch (CasinoException $e) {
            return redirect()->route('casino.slots.show')->withErrors(['slots' => $e->getMessage()]);
        }

        return redirect()->route('casino.slots.show')->with('spin_result', $result);
    }

    private function sessionPayload(int $playerId): ?array
    {
        $session = $this->casinoService->activeSession($playerId);

        return $session ? [
            'id' => $session->id,
            'expires_at' => $session->expires_at->toIso8601String(),
        ] : null;
    }
}
