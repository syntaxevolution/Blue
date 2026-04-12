<?php

namespace App\Http\Controllers\Web\Casino;

use App\Domain\Casino\CasinoService;
use App\Domain\Casino\HoldemService;
use App\Domain\Exceptions\CasinoException;
use App\Domain\Player\MapStateBuilder;
use App\Domain\World\WorldService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Casino\HoldemActionRequest;
use App\Models\CasinoTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HoldemController extends Controller
{
    public function __construct(
        private readonly HoldemService $holdem,
        private readonly CasinoService $casinoService,
        private readonly MapStateBuilder $mapState,
        private readonly WorldService $world,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        $tables = CasinoTable::query()
            ->where('game_type', 'holdem')
            ->whereIn('status', ['waiting', 'active'])
            ->get()
            ->map(fn (CasinoTable $t) => [
                'id' => $t->id,
                'currency' => $t->currency,
                'label' => $t->label,
                'status' => $t->status,
                'players' => $t->activePlayers()->count(),
                'seats' => $t->seats,
                'blind_level' => ($t->state_json ?? [])['blind_level'] ?? null,
            ])
            ->all();

        return Inertia::render('Casino/HoldemIndex', [
            'state' => $this->mapState->build($player),
            'tables' => $tables,
        ]);
    }

    public function show(Request $request, int $tableId): Response
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        return Inertia::render('Casino/Holdem', [
            'state' => $this->mapState->build($player),
            'table' => $this->holdem->tableState($tableId, $player->id),
        ]);
    }

    public function join(Request $request, int $tableId): RedirectResponse
    {
        $request->validate(['buy_in' => ['required', 'numeric', 'min:0.01']]);
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $this->holdem->joinTable($player->id, $tableId, (float) $request->input('buy_in'));
        } catch (CasinoException $e) {
            return redirect()->route('casino.holdem.show', $tableId)->withErrors(['holdem' => $e->getMessage()]);
        }

        return redirect()->route('casino.holdem.show', $tableId);
    }

    public function action(HoldemActionRequest $request, int $tableId): RedirectResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $result = $this->holdem->playerAction(
                $player->id,
                $tableId,
                $request->validated('action'),
                (float) ($request->validated('amount') ?? 0),
            );
        } catch (CasinoException $e) {
            return redirect()->route('casino.holdem.show', $tableId)->withErrors(['holdem' => $e->getMessage()]);
        }

        return redirect()->route('casino.holdem.show', $tableId)->with('holdem_result', $result);
    }

    public function leave(Request $request, int $tableId): RedirectResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $this->holdem->leaveTable($player->id, $tableId);
        } catch (CasinoException $e) {
            return redirect()->route('casino.holdem.show', $tableId)->withErrors(['holdem' => $e->getMessage()]);
        }

        return redirect()->route('casino.show');
    }
}
