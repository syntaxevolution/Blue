<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Economy\TeleportService;
use App\Domain\Exceptions\CannotPurchaseException;
use App\Domain\Exceptions\CannotTravelException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeleportController extends Controller
{
    public function __construct(
        private readonly TeleportService $teleportService,
    ) {}

    public function tileExists(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'x' => ['required', 'integer'],
            'y' => ['required', 'integer'],
        ]);

        return response()->json([
            'exists' => $this->teleportService->tileExists((int) $validated['x'], (int) $validated['y']),
        ]);
    }

    public function teleport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'x' => ['required', 'integer'],
            'y' => ['required', 'integer'],
        ]);

        $player = $request->user()->player;

        try {
            $destination = $this->teleportService->teleport(
                $player->id,
                (int) $validated['x'],
                (int) $validated['y'],
            );
        } catch (CannotTravelException|CannotPurchaseException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'x' => $destination->x,
            'y' => $destination->y,
            'tile_id' => $destination->id,
        ]);
    }
}
