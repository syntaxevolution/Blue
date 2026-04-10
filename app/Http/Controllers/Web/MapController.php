<?php

namespace App\Http\Controllers\Web;

use App\Domain\Drilling\DrillService;
use App\Domain\Economy\ShopService;
use App\Domain\Exceptions\CannotDrillException;
use App\Domain\Exceptions\CannotPurchaseException;
use App\Domain\Exceptions\CannotTravelException;
use App\Domain\Exceptions\InsufficientMovesException;
use App\Domain\Player\MapStateBuilder;
use App\Domain\Player\TravelService;
use App\Domain\World\WorldService;
use App\Http\Controllers\Controller;
use App\Http\Requests\DrillRequest;
use App\Http\Requests\PurchaseRequest;
use App\Http\Requests\TravelRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inertia controller for the main gameplay map view.
 *
 * GET  /map       — render current tile + player state + edge hints
 * POST /map/move  — travel N/S/E/W one tile, then redirect back to /map
 *
 * If the authenticated user has never spawned, visiting /map auto-spawns
 * them. Phase L+ will move this into an explicit onboarding flow.
 */
class MapController extends Controller
{
    public function __construct(
        private readonly WorldService $world,
        private readonly TravelService $travel,
        private readonly DrillService $drillSvc,
        private readonly ShopService $shop,
        private readonly MapStateBuilder $mapState,
    ) {}

    public function show(Request $request): Response
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        return Inertia::render('Game/Map', [
            'state' => $this->mapState->build($player),
        ]);
    }

    public function move(TravelRequest $request): RedirectResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        $beforeTileId = $player->current_tile_id;

        try {
            $this->travel->travel($player->id, $request->validated('direction'));
        } catch (InsufficientMovesException | CannotTravelException $e) {
            return redirect()->route('map.show')->withErrors(['travel' => $e->getMessage()]);
        } catch (\Throwable $e) {
            // Catch-all so DB errors (e.g. an un-deployed FogOfWar fix) show
            // up as a visible flash message instead of a silent 500 or an
            // Inertia error modal the user might miss.
            return redirect()->route('map.show')->withErrors([
                'travel' => 'Travel failed: '.$e->getMessage(),
            ]);
        }

        // Safety net: verify the update actually persisted. If not, something
        // is swallowing the write (e.g. rolled-back transaction from a bug
        // we haven't caught yet).
        $player->refresh();
        if ((int) $player->current_tile_id === (int) $beforeTileId) {
            return redirect()->route('map.show')->withErrors([
                'travel' => 'Travel did not persist — player is still on tile #'.$beforeTileId.'. Check server logs.',
            ]);
        }

        return redirect()->route('map.show');
    }

    public function drill(DrillRequest $request): RedirectResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $result = $this->drillSvc->drill(
                $player->id,
                (int) $request->validated('grid_x'),
                (int) $request->validated('grid_y'),
            );
        } catch (InsufficientMovesException | CannotDrillException $e) {
            return redirect()->route('map.show')->withErrors(['drill' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return redirect()->route('map.show')->withErrors([
                'drill' => 'Drill failed: '.$e->getMessage(),
            ]);
        }

        return redirect()->route('map.show')->with(
            'drill_result',
            "Drilled a {$result['quality']} point: +{$result['barrels']} barrels.",
        );
    }

    public function purchase(PurchaseRequest $request): RedirectResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $result = $this->shop->purchase(
                $player->id,
                (string) $request->validated('item_key'),
            );
        } catch (CannotPurchaseException $e) {
            return redirect()->route('map.show')->withErrors(['purchase' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return redirect()->route('map.show')->withErrors([
                'purchase' => 'Purchase failed: '.$e->getMessage(),
            ]);
        }

        return redirect()->route('map.show')->with(
            'purchase_result',
            "Purchased {$result['item']->name}. You now own {$result['quantity']}.",
        );
    }
}
