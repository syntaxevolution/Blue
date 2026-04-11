<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Economy\TransportService;
use App\Domain\Exceptions\CannotTravelException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransportController extends Controller
{
    public function __construct(
        private readonly TransportService $transport,
    ) {}

    public function switch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'transport' => ['required', 'string'],
        ]);

        $player = $request->user()->player;

        try {
            $this->transport->switchTo($player, $validated['transport']);
        } catch (CannotTravelException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'active_transport' => $validated['transport'],
        ]);
    }
}
