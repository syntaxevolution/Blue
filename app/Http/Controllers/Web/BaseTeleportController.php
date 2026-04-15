<?php

namespace App\Http\Controllers\Web;

use App\Domain\Exceptions\CannotBaseTeleportException;
use App\Domain\Exceptions\CannotPurchaseException;
use App\Domain\Exceptions\InsufficientMovesException;
use App\Domain\Teleport\BaseTeleportService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;

/**
 * Web (Inertia) controller for the three base-teleport toolbox items.
 *
 * Actions:
 *   POST /toolbox/homing-flare      — teleport self to own base
 *   POST /toolbox/foundation-charge — relocate own base to current tile
 *   POST /toolbox/abduction-anchor  — relocate rival base to current tile
 *   GET  /toolbox/abduction-targets — list of rival players eligible
 *                                      for an Abduction Anchor strike
 *                                      (used by the target-picker modal)
 *
 * All mutators are thin: validate, call BaseTeleportService, translate
 * typed exceptions to flash errors. Eligibility query logic lives in
 * BaseTeleportService::listAbductionTargets so web and API stay in
 * sync per CLAUDE.md.
 */
class BaseTeleportController extends Controller
{
    public function __construct(
        private readonly BaseTeleportService $service,
    ) {}

    public function homingFlare(Request $request): RedirectResponse
    {
        $player = $request->user()->player;
        if ($player === null) {
            return Redirect::back()->withErrors(['base_teleport' => 'No player found — enter the map first.']);
        }

        try {
            $destination = $this->service->teleportSelfToBase($player->id);
        } catch (CannotBaseTeleportException|InsufficientMovesException|CannotPurchaseException $e) {
            return Redirect::back()->withErrors(['base_teleport' => $e->getMessage()]);
        }

        return Redirect::back()->with(
            'base_teleport_result',
            "Homing Flare fired — you're back at your base ({$destination->x}, {$destination->y}).",
        );
    }

    public function foundationCharge(Request $request): RedirectResponse
    {
        $player = $request->user()->player;
        if ($player === null) {
            return Redirect::back()->withErrors(['base_teleport' => 'No player found — enter the map first.']);
        }

        try {
            $destination = $this->service->moveOwnBase($player->id);
        } catch (CannotBaseTeleportException|InsufficientMovesException $e) {
            return Redirect::back()->withErrors(['base_teleport' => $e->getMessage()]);
        }

        return Redirect::back()->with(
            'base_teleport_result',
            "Foundation Charge detonated — your base is now at ({$destination->x}, {$destination->y}).",
        );
    }

    public function abductionAnchor(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'target_player_id' => ['required', 'integer'],
        ]);

        $player = $request->user()->player;
        if ($player === null) {
            return Redirect::back()->withErrors(['base_teleport' => 'No player found — enter the map first.']);
        }

        try {
            $result = $this->service->moveEnemyBase($player->id, (int) $validated['target_player_id']);
        } catch (CannotBaseTeleportException|InsufficientMovesException $e) {
            return Redirect::back()->withErrors(['base_teleport' => $e->getMessage()]);
        }

        return Redirect::back()->with(
            'base_teleport_result',
            "Abduction Anchor fired — {$result['target_username']}'s base is now at ({$result['new_base']->x}, {$result['new_base']->y}).",
        );
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
