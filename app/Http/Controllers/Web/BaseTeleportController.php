<?php

namespace App\Http\Controllers\Web;

use App\Domain\Exceptions\CannotBaseTeleportException;
use App\Domain\Exceptions\CannotPurchaseException;
use App\Domain\Exceptions\CannotSpyException;
use App\Domain\Exceptions\InsufficientMovesException;
use App\Domain\Teleport\BaseTeleportService;
use App\Http\Controllers\Controller;
use App\Models\Player;
use App\Models\SpyAttempt;
use Carbon\CarbonInterface;
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
 * typed exceptions to flash errors. The game logic lives in the domain
 * service so the Api\V1 controller can share it unchanged.
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

        return Redirect::back()->with('base_teleport_result', "Homing Flare fired — you're back at your base ({$destination->x}, {$destination->y}).");
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

        return Redirect::back()->with('base_teleport_result', "Foundation Charge detonated — your base is now at ({$destination->x}, {$destination->y}).");
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
        } catch (CannotBaseTeleportException|InsufficientMovesException|CannotSpyException $e) {
            return Redirect::back()->withErrors(['base_teleport' => $e->getMessage()]);
        }

        return Redirect::back()->with('base_teleport_result', "Abduction Anchor fired — {$result['target_username']}'s base is now at ({$result['new_base']->x}, {$result['new_base']->y}).");
    }

    /**
     * Target picker feed for the Abduction Anchor toolbox modal.
     *
     * Returns every rival player the caller has a successful spy on
     * within the configured freshness window, plus a `reason` string
     * for any target that would fail the service-layer guards. The
     * UI shows ineligible rows greyed out with the reason so the
     * player understands why a visible target is unclickable.
     */
    public function abductionTargets(Request $request): JsonResponse
    {
        $player = $request->user()->player;
        if ($player === null) {
            return response()->json(['targets' => []]);
        }

        $targets = $this->collectEligibleTargets($player);

        return response()->json(['targets' => $targets]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function collectEligibleTargets(Player $player): array
    {
        $freshnessHours = (int) config('game.teleport_items.abduction_anchor.spy_freshness_hours');

        $rows = SpyAttempt::query()
            ->where('spy_player_id', $player->id)
            ->where('success', true)
            ->where('created_at', '>=', now()->subHours($freshnessHours))
            ->orderByDesc('created_at')
            ->get(['target_player_id', 'created_at']);

        // Collapse duplicates — the most recent successful spy per
        // target is the one we care about. Distinct-on target_player_id.
        /** @var array<int,CarbonInterface> $latestByTarget */
        $latestByTarget = [];
        foreach ($rows as $row) {
            $id = (int) $row->target_player_id;
            if (! isset($latestByTarget[$id])) {
                $latestByTarget[$id] = $row->created_at;
            }
        }

        if ($latestByTarget === []) {
            return [];
        }

        $targetModels = Player::query()
            ->whereIn('id', array_keys($latestByTarget))
            ->with(['user:id,name', 'baseTile:id,x,y'])
            ->get();

        $results = [];
        foreach ($targetModels as $target) {
            $reason = $this->ineligibleReason($player, $target);

            $results[] = [
                'id' => (int) $target->id,
                'username' => (string) ($target->user?->name ?? 'Unknown'),
                'base_x' => (int) ($target->baseTile?->x ?? 0),
                'base_y' => (int) ($target->baseTile?->y ?? 0),
                'spied_at' => $latestByTarget[(int) $target->id]->toIso8601String(),
                'eligible' => $reason === null,
                'reason' => $reason,
            ];
        }

        // Sort: eligible first, then most recently spied.
        usort($results, function (array $a, array $b) {
            if ($a['eligible'] !== $b['eligible']) {
                return $a['eligible'] ? -1 : 1;
            }

            return strcmp((string) $b['spied_at'], (string) $a['spied_at']);
        });

        return $results;
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
