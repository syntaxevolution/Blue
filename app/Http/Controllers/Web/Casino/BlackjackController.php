<?php

namespace App\Http\Controllers\Web\Casino;

use App\Domain\Casino\BlackjackService;
use App\Domain\Casino\CasinoService;
use App\Domain\Casino\CasinoTableManager;
use App\Domain\Exceptions\CasinoException;
use App\Domain\Player\MapStateBuilder;
use App\Domain\World\WorldService;
use App\Events\Casino\BlackjackDealerTurn;
use App\Events\Casino\BlackjackHandDealt;
use App\Events\Casino\BlackjackPayout;
use App\Events\Casino\BlackjackPlayerAction;
use App\Events\Casino\PlayerJoinedTable;
use App\Events\Casino\PlayerLeftTable;
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

    public function index(Request $request)
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        if (! $this->casinoService->hasActiveSession($player->id)) {
            return redirect()->route('casino.show');
        }

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

    public function show(Request $request, int $tableId)
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        if (! $this->casinoService->hasActiveSession($player->id)) {
            return redirect()->route('casino.show');
        }

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
            $result = $this->blackjack->joinTable($player->id, $tableId);
            if (! ($result['already_seated'] ?? false)) {
                PlayerJoinedTable::dispatch($tableId, $user->name, (int) $result['seat']);
            }
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
            $this->dispatchBlackjackEvents($tableId, $result);
        } catch (CasinoException $e) {
            return redirect()->route('casino.blackjack.show', $tableId)->withErrors(['blackjack' => $e->getMessage()]);
        }

        return redirect()->route('casino.blackjack.show', $tableId)->with('blackjack_result', $result);
    }

    public function action(BlackjackActionRequest $request, int $tableId): RedirectResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);
        $actionName = $request->validated('action');

        try {
            $state = $this->blackjack->tableState($tableId, $player->id);
            $seatBefore = $state['current_seat'];
            $handBefore = $seatBefore !== null ? ($state['hands'][$seatBefore] ?? null) : null;

            $result = $this->blackjack->playerAction($player->id, $tableId, $actionName);

            if ($handBefore !== null) {
                BlackjackPlayerAction::dispatch(
                    $tableId,
                    (int) $seatBefore,
                    $actionName,
                    (int) ($handBefore['total'] ?? 0),
                );
            }

            $this->dispatchBlackjackEvents($tableId, $result);
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
        PlayerLeftTable::dispatch($tableId, $user->name);

        return redirect()->route('casino.show');
    }

    private function dispatchBlackjackEvents(int $tableId, array $result): void
    {
        $action = $result['action'] ?? null;

        if ($action === 'dealt') {
            $state = CasinoTable::find($tableId)?->state_json ?? [];
            $dealerCards = $state['dealer']['cards'] ?? [];
            $upCard = $dealerCards[0] ?? null;

            BlackjackHandDealt::dispatch(
                $tableId,
                (int) ($state['round_number'] ?? 0),
                array_map(fn ($h) => count($h['cards'] ?? []), $state['hands'] ?? []),
                $upCard !== null ? [
                    'rank' => \App\Domain\Casino\CardDeck::rankName($upCard),
                    'suit' => \App\Domain\Casino\CardDeck::suitName($upCard),
                ] : ['rank' => '?', 'suit' => '?'],
            );
        }

        if ($action === 'round_resolved') {
            BlackjackDealerTurn::dispatch(
                $tableId,
                (array) ($result['dealer_cards'] ?? []),
                (int) ($result['dealer_total'] ?? 0),
                (bool) ($result['dealer_bust'] ?? false),
            );

            BlackjackPayout::dispatch(
                $tableId,
                (int) ($result['dealer_total'] ?? 0),
                (bool) ($result['dealer_bust'] ?? false),
                (array) ($result['results'] ?? []),
            );
        }
    }
}
