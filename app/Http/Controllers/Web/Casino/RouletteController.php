<?php

namespace App\Http\Controllers\Web\Casino;

use App\Domain\Casino\CasinoService;
use App\Domain\Casino\CasinoTableManager;
use App\Domain\Casino\RouletteService;
use App\Domain\Exceptions\CasinoException;
use App\Domain\Player\MapStateBuilder;
use App\Domain\World\WorldService;
use App\Events\Casino\BetPlaced;
use App\Events\Casino\BettingWindowOpened;
use App\Http\Controllers\Controller;
use App\Http\Requests\Casino\RouletteBetRequest;
use App\Models\CasinoTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RouletteController extends Controller
{
    public function __construct(
        private readonly RouletteService $roulette,
        private readonly CasinoService $casinoService,
        private readonly CasinoTableManager $tableManager,
        private readonly MapStateBuilder $mapState,
        private readonly WorldService $world,
    ) {}

    public function show(Request $request, int $tableId): Response
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        $tableState = $this->roulette->tableState($tableId, $player->id);

        return Inertia::render('Casino/Roulette', [
            'state' => $this->mapState->build($player),
            'casino_session' => $this->sessionPayload($player->id),
            'table' => $tableState,
        ]);
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        $this->tableManager->ensureRouletteTablesExist();

        $tables = CasinoTable::query()
            ->where('game_type', 'roulette')
            ->whereIn('status', ['waiting', 'active'])
            ->get(['id', 'currency', 'label', 'min_bet', 'max_bet', 'status', 'round_number'])
            ->map(fn (CasinoTable $t) => [
                'id' => $t->id,
                'currency' => $t->currency,
                'label' => $t->label,
                'min_bet' => (float) $t->min_bet,
                'max_bet' => (float) $t->max_bet,
                'status' => $t->status,
            ])
            ->all();

        return Inertia::render('Casino/RouletteIndex', [
            'state' => $this->mapState->build($player),
            'casino_session' => $this->sessionPayload($player->id),
            'tables' => $tables,
        ]);
    }

    public function placeBet(RouletteBetRequest $request, int $tableId): RedirectResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $result = $this->roulette->placeBet(
                $player->id,
                $tableId,
                $request->validated('bet_type'),
                $request->validated('numbers'),
                (float) $request->validated('amount'),
            );

            BetPlaced::dispatch(
                $tableId,
                $user->name,
                $request->validated('bet_type'),
                (float) $request->validated('amount'),
            );

            $table = CasinoTable::find($tableId);
            if ($table && $table->round_expires_at) {
                BettingWindowOpened::dispatch(
                    $tableId,
                    $table->round_number,
                    $table->round_expires_at->toIso8601String(),
                );
            }
        } catch (CasinoException $e) {
            return redirect()->route('casino.roulette.show', $tableId)
                ->withErrors(['roulette' => $e->getMessage()]);
        }

        return redirect()->route('casino.roulette.show', $tableId)
            ->with('roulette_bet', $result);
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
