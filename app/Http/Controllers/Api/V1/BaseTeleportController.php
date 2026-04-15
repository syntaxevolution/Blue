<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Exceptions\CannotBaseTeleportException;
use App\Domain\Exceptions\CannotPurchaseException;
use App\Domain\Exceptions\CannotSpyException;
use App\Domain\Exceptions\InsufficientMovesException;
use App\Domain\Teleport\BaseTeleportService;
use App\Http\Controllers\Controller;
use App\Models\Player;
use App\Models\SpyAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REST (Sanctum) mirror of Web\BaseTeleportController. Thin — all
 * game logic lives in BaseTeleportService. Mobile clients get the
 * same contract: POST the action, receive 200 on success with the
 * relocated-coordinate payload, or 422 with a human-readable message
 * on any guard rejection.
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
        } catch (CannotBaseTeleportException|InsufficientMovesException|CannotSpyException $e) {
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

        $freshnessHours = (int) config('game.teleport_items.abduction_anchor.spy_freshness_hours');

        $rows = SpyAttempt::query()
            ->where('spy_player_id', $player->id)
            ->where('success', true)
            ->where('created_at', '>=', now()->subHours($freshnessHours))
            ->orderByDesc('created_at')
            ->get(['target_player_id', 'created_at']);

        $latestByTarget = [];
        foreach ($rows as $row) {
            $id = (int) $row->target_player_id;
            if (! isset($latestByTarget[$id])) {
                $latestByTarget[$id] = $row->created_at;
            }
        }

        if ($latestByTarget === []) {
            return response()->json(['targets' => []]);
        }

        $targets = Player::query()
            ->whereIn('id', array_keys($latestByTarget))
            ->with(['user:id,name', 'baseTile:id,x,y'])
            ->get()
            ->map(fn (Player $target) => [
                'id' => (int) $target->id,
                'username' => (string) ($target->user?->name ?? 'Unknown'),
                'base_x' => (int) ($target->baseTile?->x ?? 0),
                'base_y' => (int) ($target->baseTile?->y ?? 0),
                'spied_at' => $latestByTarget[(int) $target->id]->toIso8601String(),
                'eligible' => $this->isEligible($player, $target),
                'reason' => $this->ineligibleReason($player, $target),
            ])
            ->sortBy([
                ['eligible', 'desc'],
                ['spied_at', 'desc'],
            ])
            ->values();

        return response()->json(['targets' => $targets]);
    }

    private function isEligible(Player $player, Player $target): bool
    {
        return $this->ineligibleReason($player, $target) === null;
    }

    private function ineligibleReason(Player $player, Player $target): ?string
    {
        if ((int) $target->id === (int) $player->id) {
            return 'Cannot target your own base.';
        }
        if ($target->immunity_expires_at !== null && $target->immunity_expires_at->isFuture()) {
            return 'Target is under new-player immunity.';
        }
        if ((bool) $target->base_move_protected) {
            return 'Target has a Deadbolt Plinth installed.';
        }
        if ($player->mdn_id !== null
            && $target->mdn_id !== null
            && (int) $player->mdn_id === (int) $target->mdn_id) {
            return 'Same MDN — attacks forbidden by charter.';
        }

        return null;
    }
}
