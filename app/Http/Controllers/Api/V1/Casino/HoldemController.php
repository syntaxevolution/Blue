<?php

namespace App\Http\Controllers\Api\V1\Casino;

use App\Domain\Casino\HoldemService;
use App\Domain\Exceptions\CasinoException;
use App\Domain\World\WorldService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Casino\HoldemActionRequest;
use App\Models\CasinoTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HoldemController extends Controller
{
    public function __construct(
        private readonly HoldemService $holdem,
        private readonly WorldService $world,
    ) {}

    public function tables(Request $request): JsonResponse
    {
        $tables = CasinoTable::query()
            ->where('game_type', 'holdem')
            ->whereIn('status', ['waiting', 'active'])
            ->get();

        return response()->json(['data' => $tables]);
    }

    public function show(Request $request, int $tableId): JsonResponse
    {
        $player = $request->user()->player ?? $this->world->spawnPlayer($request->user()->id);

        return response()->json(['data' => $this->holdem->tableState($tableId, $player->id)]);
    }

    public function join(Request $request, int $tableId): JsonResponse
    {
        $request->validate(['buy_in' => ['required', 'numeric', 'min:0.01']]);
        $player = $request->user()->player ?? $this->world->spawnPlayer($request->user()->id);

        try {
            $result = $this->holdem->joinTable($player->id, $tableId, (float) $request->input('buy_in'));
        } catch (CasinoException $e) {
            return response()->json(['errors' => ['holdem' => $e->getMessage()]], 422);
        }

        return response()->json(['data' => $result]);
    }

    public function action(HoldemActionRequest $request, int $tableId): JsonResponse
    {
        $player = $request->user()->player ?? $this->world->spawnPlayer($request->user()->id);

        try {
            $result = $this->holdem->playerAction(
                $player->id, $tableId,
                $request->validated('action'),
                (float) ($request->validated('amount') ?? 0),
            );
        } catch (CasinoException $e) {
            return response()->json(['errors' => ['holdem' => $e->getMessage()]], 422);
        }

        return response()->json(['data' => $result]);
    }

    public function leave(Request $request, int $tableId): JsonResponse
    {
        $player = $request->user()->player ?? $this->world->spawnPlayer($request->user()->id);

        try {
            $result = $this->holdem->leaveTable($player->id, $tableId);
        } catch (CasinoException $e) {
            return response()->json(['errors' => ['holdem' => $e->getMessage()]], 422);
        }

        return response()->json(['data' => $result]);
    }
}
