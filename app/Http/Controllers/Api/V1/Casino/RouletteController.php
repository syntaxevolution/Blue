<?php

namespace App\Http\Controllers\Api\V1\Casino;

use App\Domain\Casino\CasinoTableManager;
use App\Domain\Casino\RouletteService;
use App\Domain\Exceptions\CasinoException;
use App\Domain\World\WorldService;
use App\Events\Casino\BetPlaced;
use App\Events\Casino\BettingWindowOpened;
use App\Http\Controllers\Controller;
use App\Http\Requests\Casino\RouletteBetRequest;
use App\Models\CasinoTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RouletteController extends Controller
{
    public function __construct(
        private readonly RouletteService $roulette,
        private readonly CasinoTableManager $tableManager,
        private readonly WorldService $world,
    ) {}

    public function tables(Request $request): JsonResponse
    {
        $this->tableManager->ensureRouletteTablesExist();

        $tables = CasinoTable::query()
            ->where('game_type', 'roulette')
            ->whereIn('status', ['waiting', 'active'])
            ->get(['id', 'currency', 'label', 'min_bet', 'max_bet', 'status', 'round_number']);

        return response()->json(['data' => $tables]);
    }

    public function show(Request $request, int $tableId): JsonResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        return response()->json([
            'data' => $this->roulette->tableState($tableId, $player->id),
        ]);
    }

    public function placeBet(RouletteBetRequest $request, int $tableId): JsonResponse
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
            return response()->json(['errors' => ['roulette' => $e->getMessage()]], 422);
        }

        return response()->json(['data' => $result]);
    }
}
