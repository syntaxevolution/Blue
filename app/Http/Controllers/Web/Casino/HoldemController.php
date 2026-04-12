<?php

namespace App\Http\Controllers\Web\Casino;

use App\Domain\Casino\CasinoService;
use App\Domain\Casino\CasinoTableManager;
use App\Domain\Casino\HoldemService;
use App\Domain\Exceptions\CasinoException;
use App\Domain\Player\MapStateBuilder;
use App\Domain\World\WorldService;
use App\Events\Casino\HoldemCommunityCards;
use App\Events\Casino\HoldemHoleCards;
use App\Events\Casino\HoldemPlayerAction;
use App\Events\Casino\HoldemShowdown;
use App\Events\Casino\PlayerJoinedTable;
use App\Events\Casino\PlayerLeftTable;
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

        $this->tableManager->ensureHoldemTablesExist();

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

    public function show(Request $request, int $tableId)
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        if (! $this->casinoService->hasActiveSession($player->id)) {
            return redirect()->route('casino.show');
        }

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
            $result = $this->holdem->joinTable($player->id, $tableId, (float) $request->input('buy_in'));
            PlayerJoinedTable::dispatch($tableId, $user->name, (int) $result['seat']);

            // If the table now has enough players and no hand is in
            // progress, start one.
            $this->maybeStartHand($tableId);
        } catch (CasinoException $e) {
            return redirect()->route('casino.holdem.show', $tableId)->withErrors(['holdem' => $e->getMessage()]);
        }

        return redirect()->route('casino.holdem.show', $tableId);
    }

    public function action(HoldemActionRequest $request, int $tableId): RedirectResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);
        $actionName = $request->validated('action');
        $amount = (float) ($request->validated('amount') ?? 0);

        try {
            // Capture seat before state mutates.
            $preState = $this->holdem->tableState($tableId, $player->id);
            $seatBefore = $preState['my_seat'];

            $result = $this->holdem->playerAction($player->id, $tableId, $actionName, $amount);

            // Broadcast the player action.
            $table = CasinoTable::find($tableId);
            $potTotal = (float) ($table?->state_json['pot'] ?? 0);
            HoldemPlayerAction::dispatch(
                $tableId,
                (int) ($seatBefore ?? -1),
                $actionName,
                $amount,
                $potTotal,
            );

            // If street advanced, broadcast community cards.
            $postState = $this->holdem->tableState($tableId, $player->id);
            if ($postState['phase'] !== ($preState['phase'] ?? null)
                && in_array($postState['phase'], ['flop', 'turn', 'river'], true)
                && count($postState['community'] ?? []) > count($preState['community'] ?? [])
            ) {
                HoldemCommunityCards::dispatch($tableId, $postState['phase'], $postState['community']);
            }

            // If the round resolved (showdown), broadcast result.
            if (($result['action'] ?? null) === 'showdown') {
                HoldemShowdown::dispatch(
                    $tableId,
                    (array) ($result['results'] ?? []),
                    (array) ($result['community'] ?? []),
                );
            }
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
            PlayerLeftTable::dispatch($tableId, $user->name);
        } catch (CasinoException $e) {
            return redirect()->route('casino.holdem.show', $tableId)->withErrors(['holdem' => $e->getMessage()]);
        }

        return redirect()->route('casino.show');
    }

    /**
     * If the table has enough players and no active hand, start one and
     * broadcast hole cards to each player via their private channel.
     */
    private function maybeStartHand(int $tableId): void
    {
        $table = CasinoTable::find($tableId);
        if ($table === null) {
            return;
        }

        $state = $table->state_json ?? ['phase' => 'waiting'];
        if ($state['phase'] !== 'waiting') {
            return;
        }

        try {
            $this->holdem->startHand($tableId);
        } catch (CasinoException $e) {
            return;
        }

        // Broadcast hole cards to each seated player.
        $table->refresh();
        $state = $table->state_json;
        foreach (($state['players'] ?? []) as $p) {
            $player = \App\Models\Player::with('user:id')->find($p['player_id']);
            if ($player === null) {
                continue;
            }

            HoldemHoleCards::dispatch(
                (int) $player->user_id,
                $tableId,
                \App\Domain\Casino\CardDeck::toDisplayArray($p['hole_cards']),
            );
        }
    }
}
