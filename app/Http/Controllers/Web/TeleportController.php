<?php

namespace App\Http\Controllers\Web;

use App\Domain\Economy\TeleportService;
use App\Domain\Exceptions\CannotPurchaseException;
use App\Domain\Exceptions\CannotTravelException;
use App\Domain\Exceptions\InsufficientMovesException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;

class TeleportController extends Controller
{
    public function __construct(
        private readonly TeleportService $teleportService,
    ) {}

    /**
     * Pre-check endpoint the UI calls before charging the user.
     */
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

    public function teleport(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'x' => ['required', 'integer'],
            'y' => ['required', 'integer'],
        ]);

        $player = $request->user()->player;
        if ($player === null) {
            return Redirect::back()->withErrors(['teleport' => 'No player found — enter the map first.']);
        }

        try {
            $destination = $this->teleportService->teleport(
                $player->id,
                (int) $validated['x'],
                (int) $validated['y'],
            );
        } catch (InsufficientMovesException|CannotTravelException|CannotPurchaseException $e) {
            return Redirect::back()->withErrors(['teleport' => $e->getMessage()]);
        }

        return Redirect::back()->with('flash', [
            'teleport_result' => "Teleported to ({$destination->x}, {$destination->y}).",
        ]);
    }
}
