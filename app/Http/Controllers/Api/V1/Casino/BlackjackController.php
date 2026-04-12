<?php

namespace App\Http\Controllers\Api\V1\Casino;

use App\Domain\Casino\BlackjackService;
use App\Domain\Casino\CasinoTableManager;
use App\Domain\Exceptions\CasinoException;
use App\Domain\World\WorldService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Casino\BlackjackActionRequest;
use App\Models\CasinoTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlackjackController extends Controller
{
    public function __construct(
        private readonly BlackjackService $blackjack,
        private readonly CasinoTableManager $tableManager,
        private readonly WorldService $world,
    ) {}

    public function tables(Request $request): JsonResponse
    {
        $this->tableManager->ensureBlackjackTablesExist();

        $tables = CasinoTable::query()
            ->where('game_type', 'blackjack')
            ->whereIn('status', ['waiting', 'active'])
            ->get(['id', 'currency', 'label', 'min_bet', 'max_bet', 'seats', 'status']);

        return response()->json(['data' => $tables]);
    }

    public function show(Request $request, int $tableId): JsonResponse
    {
        $player = $request->user()->player ?? $this->world->spawnPlayer($request->user()->id);

        return response()->json(['data' => $this->blackjack->tableState($tableId, $player->id)]);
    }

    public function join(Request $request, int $tableId): JsonResponse
    {
        $player = $request->user()->player ?? $this->world->spawnPlayer($request->user()->id);

        try {
            $result = $this->blackjack->joinTable($player->id, $tableId);
        } catch (CasinoException $e) {
            return response()->json(['errors' => ['blackjack' => $e->getMessage()]], 422);
        }

        return response()->json(['data' => $result]);
    }

    public function bet(Request $request, int $tableId): JsonResponse
    {
        $request->validate(['amount' => ['required', 'numeric', 'min:0.01']]);
        $player = $request->user()->player ?? $this->world->spawnPlayer($request->user()->id);

        try {
            $result = $this->blackjack->placeBet($player->id, $tableId, (float) $request->input('amount'));
        } catch (CasinoException $e) {
            return response()->json(['errors' => ['blackjack' => $e->getMessage()]], 422);
        }

        return response()->json(['data' => $result]);
    }

    public function action(BlackjackActionRequest $request, int $tableId): JsonResponse
    {
        $player = $request->user()->player ?? $this->world->spawnPlayer($request->user()->id);

        try {
            $result = $this->blackjack->playerAction($player->id, $tableId, $request->validated('action'));
        } catch (CasinoException $e) {
            return response()->json(['errors' => ['blackjack' => $e->getMessage()]], 422);
        }

        return response()->json(['data' => $result]);
    }

    public function leave(Request $request, int $tableId): JsonResponse
    {
        $player = $request->user()->player ?? $this->world->spawnPlayer($request->user()->id);
        $this->blackjack->leaveTable($player->id, $tableId);

        return response()->json(['data' => ['left' => true]]);
    }
}
