<?php

namespace App\Http\Controllers\Api\V1\Casino;

use App\Domain\Casino\CasinoService;
use App\Domain\Exceptions\CasinoException;
use App\Domain\World\WorldService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CasinoController extends Controller
{
    public function __construct(
        private readonly CasinoService $casinoService,
        private readonly WorldService $world,
    ) {}

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);
        $session = $this->casinoService->activeSession($player->id);

        return response()->json([
            'data' => [
                'has_session' => $session !== null,
                'session' => $session ? [
                    'id' => $session->id,
                    'expires_at' => $session->expires_at->toIso8601String(),
                ] : null,
            ],
        ]);
    }

    public function enter(Request $request): JsonResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $result = $this->casinoService->enterCasino($player->id);
        } catch (CasinoException $e) {
            return response()->json(['errors' => ['casino' => $e->getMessage()]], 422);
        }

        return response()->json(['data' => $result]);
    }
}
