<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Exceptions\CannotPurchaseException;
use App\Domain\Items\ItemBreakService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItemBreakController extends Controller
{
    public function __construct(
        private readonly ItemBreakService $itemBreak,
    ) {}

    public function repair(Request $request): JsonResponse
    {
        $player = $request->user()->player;
        if ($player === null) {
            return response()->json(['message' => 'No player found — enter the map first.'], 422);
        }

        try {
            $this->itemBreak->repair($player);
        } catch (CannotPurchaseException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => 'repaired']);
    }

    public function abandon(Request $request): JsonResponse
    {
        $player = $request->user()->player;
        if ($player === null) {
            return response()->json(['message' => 'No player found — enter the map first.'], 422);
        }

        $this->itemBreak->abandon($player);

        return response()->json(['status' => 'abandoned']);
    }
}
