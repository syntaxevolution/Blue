<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Exceptions\CannotBaseTeleportException;
use App\Domain\Exceptions\CannotPurchaseException;
use App\Domain\Exceptions\InsufficientMovesException;
use App\Domain\Teleport\BaseTeleportService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REST (Sanctum) mirror of Web\BaseTeleportController. Thin — all
 * game logic and eligibility-query logic lives in
 * BaseTeleportService. Mobile clients get the same contract: POST
 * the action, receive 200 on success with the relocated-coordinate
 * payload, or 422 with a human-readable message on any guard
 * rejection.
 */
class BaseTeleportController extends Controller
{
    public function __construct(
        private readonly BaseTeleportService $service,
    ) {}

    public function homingFlare(Request $request): JsonResponse
    {
        $player = $request->user()->player;
        if ($player === null) {
            return response()->json(['message' => 'No player found — enter the map first.'], 422);
        }

        try {
            $destination = $this->service->teleportSelfToBase($player->id);
        } catch (CannotBaseTeleportException|InsufficientMovesException|CannotPurchaseException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'x' => $destination->x,
            'y' => $destination->y,
            'tile_id' => $destination->id,
        ]);
    }

    public function foundationCharge(Request $request): JsonResponse
    {
        $player = $request->user()->player;
        if ($player === null) {
            return response()->json(['message' => 'No player found — enter the map first.'], 422);
        }

        try {
            $destination = $this->service->moveOwnBase($player->id);
        } catch (CannotBaseTeleportException|InsufficientMovesException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'x' => $destination->x,
            'y' => $destination->y,
            'tile_id' => $destination->id,
        ]);
    }

    public function abductionAnchor(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'target_player_id' => ['required', 'integer'],
        ]);

        $player = $request->user()->player;
        if ($player === null) {
            return response()->json(['message' => 'No player found — enter the map first.'], 422);
        }

        try {
            $result = $this->service->moveEnemyBase($player->id, (int) $validated['target_player_id']);
        } catch (CannotBaseTeleportException|InsufficientMovesException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'x' => $result['new_base']->x,
            'y' => $result['new_base']->y,
            'tile_id' => $result['new_base']->id,
            'target_username' => $result['target_username'],
        ]);
    }

    public function abductionTargets(Request $request): JsonResponse
    {
        $player = $request->user()->player;
        if ($player === null) {
            return response()->json(['targets' => []]);
        }

        return response()->json(['targets' => $this->service->listAbductionTargets($player->id)]);
    }
}
