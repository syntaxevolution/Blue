<?php

namespace App\Http\Controllers\Web\Casino;

use App\Domain\Casino\BlackjackService;
use App\Domain\Casino\CasinoService;
use App\Domain\Casino\CasinoTableManager;
use App\Domain\Exceptions\CasinoException;
use App\Domain\Player\MapStateBuilder;
use App\Domain\World\WorldService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Casino\BlackjackActionRequest;
use App\Models\CasinoTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BlackjackController extends Controller
{
    public function __construct(
        private readonly BlackjackService $blackjack,
        private readonly CasinoService $casinoService,
        private readonly CasinoTableManager $tableManager,
        private readonly MapStateBuilder $mapState,
        private readonly WorldService $world,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        $this->tableManager->ensureBlackjackTablesExist();

        $tables = CasinoTable::query()
            ->where('game_type', 'blackjack')
            ->whereIn('status', ['waiting', 'active'])
            ->get(['id', 'currency', 'label', 'min_bet', 'max_bet', 'seats', 'status'])
            ->map(fn (CasinoTable $t) => [
                'id' => $t->id,
                'currency' => $t->currency,
                'label' => $t->label,
                'min_bet' => (float) $t->min_bet,
                'max_bet' => (float) $t->max_bet,
                'seats' => $t->seats,
                'status' => $t->status,
                'players' => $t->activePlayers()->count(),
            ])
            ->all();

        return Inertia::render('Casino/BlackjackIndex', [
            'state' => $this->mapState->build($player),
            'tables' => $tables,
        ]);
    }

    public function show(Request $request, int $tableId): Response
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        return Inertia::render('Casino/Blackjack', [
            'state' => $this->mapState->build($player),
            'table' => $this->blackjack->tableState($tableId, $player->id),
        ]);
    }

    public function join(Request $request, int $tableId): RedirectResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $this->blackjack->joinTable($player->id, $tableId);
        } catch (CasinoException $e) {
            return redirect()->route('casino.blackjack.show', $tableId)->withErrors(['blackjack' => $e->getMessage()]);
        }

        return redirect()->route('casino.blackjack.show', $tableId);
    }

    public function bet(Request $request, int $tableId): RedirectResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        $request->validate(['amount' => ['required', 'numeric', 'min:0.01']]);

        try {
            $result = $this->blackjack->placeBet($player->id, $tableId, (float) $request->input('amount'));
        } catch (CasinoException $e) {
            return redirect()->route('casino.blackjack.show', $tableId)->withErrors(['blackjack' => $e->getMessage()]);
        }

        return redirect()->route('casino.blackjack.show', $tableId)->with('blackjack_result', $result);
    }

    public function action(BlackjackActionRequest $request, int $tableId): RedirectResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $result = $this->blackjack->playerAction($player->id, $tableId, $request->validated('action'));
        } catch (CasinoException $e) {
            return redirect()->route('casino.blackjack.show', $tableId)->withErrors(['blackjack' => $e->getMessage()]);
        }

        return redirect()->route('casino.blackjack.show', $tableId)->with('blackjack_result', $result);
    }

    public function leave(Request $request, int $tableId): RedirectResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        $this->blackjack->leaveTable($player->id, $tableId);

        return redirect()->route('casino.show');
    }
}
