<?php

namespace App\Http\Controllers\Web;

use App\Domain\Combat\AttackService;
use App\Domain\Combat\SpyService;
use App\Domain\Drilling\DrillService;
use App\Domain\Economy\ShopService;
use App\Domain\Exceptions\CannotAttackException;
use App\Domain\Exceptions\CannotDrillException;
use App\Domain\Exceptions\CannotPurchaseException;
use App\Domain\Exceptions\CannotSabotageException;
use App\Domain\Exceptions\CannotSpyException;
use App\Domain\Exceptions\CannotTravelException;
use App\Domain\Exceptions\InsufficientMovesException;
use App\Domain\Player\MapStateBuilder;
use App\Domain\Player\TravelService;
use App\Domain\Sabotage\SabotageService;
use App\Domain\World\WorldService;
use App\Http\Controllers\Controller;
use App\Http\Requests\DrillRequest;
use App\Http\Requests\PlaceDeviceRequest;
use App\Http\Requests\PurchaseRequest;
use App\Http\Requests\TravelRequest;
use App\Models\Item;
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
        private readonly SpyService $spySvc,
        private readonly AttackService $attackSvc,
        private readonly MapStateBuilder $mapState,
        private readonly SabotageService $sabotage,
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

        return redirect()->route('map.show')->with('drill_result', [
            'barrels' => (int) $result['barrels'],
            'quality' => (string) $result['quality'],
            'grid_x' => (int) $request->validated('grid_x'),
            'grid_y' => (int) $request->validated('grid_y'),
            'drill_broke' => (bool) ($result['drill_broke'] ?? false),
            'broken_item_key' => $result['broken_item_key'] ?? null,
            'sabotage_outcome' => $result['sabotage_outcome'] ?? null,
            'sabotage_device_key' => $result['sabotage_device_key'] ?? null,
            'siphoned_barrels' => (int) ($result['siphoned_barrels'] ?? 0),
        ]);
    }

    public function placeDevice(PlaceDeviceRequest $request): RedirectResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $result = $this->sabotage->place(
                $player->id,
                (int) $request->validated('grid_x'),
                (int) $request->validated('grid_y'),
                (string) $request->validated('item_key'),
            );
        } catch (InsufficientMovesException | CannotSabotageException $e) {
            return redirect()->route('map.show')->withErrors(['place_device' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return redirect()->route('map.show')->withErrors([
                'place_device' => 'Place failed: '.$e->getMessage(),
            ]);
        }

        // Resolve a display name so the flash toast says "Gremlin Coil"
        // not "gremlin_coil" — every other shop flash follows this
        // pattern (see purchase() above which echoes $result['item']->name).
        $displayName = Item::query()
            ->where('key', $result['device_key'])
            ->value('name') ?? (string) $result['device_key'];

        return redirect()->route('map.show')->with('place_result', [
            'device_key' => (string) $result['device_key'],
            'device_name' => (string) $displayName,
            'grid_x' => (int) $request->validated('grid_x'),
            'grid_y' => (int) $request->validated('grid_y'),
            'remaining_quantity' => (int) $result['remaining_quantity'],
        ]);
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

    public function spy(Request $request): RedirectResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $result = $this->spySvc->spy($player->id);
        } catch (InsufficientMovesException | CannotSpyException $e) {
            return redirect()->route('map.show')->withErrors(['spy' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return redirect()->route('map.show')->withErrors([
                'spy' => 'Spy failed: '.$e->getMessage(),
            ]);
        }

        $message = $result['outcome'] === 'success'
            ? "Reconnaissance successful. +{$result['intel_gained']} intel. You may now attack this base for the next 24h."
            : 'Reconnaissance failed. You gained nothing and the target may be alerted.';

        return redirect()->route('map.show')->with('spy_result', $message);
    }

    public function attack(Request $request): RedirectResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $result = $this->attackSvc->attack($player->id);
        } catch (InsufficientMovesException | CannotAttackException $e) {
            return redirect()->route('map.show')->withErrors(['attack' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return redirect()->route('map.show')->withErrors([
                'attack' => 'Attack failed: '.$e->getMessage(),
            ]);
        }

        $message = $result['outcome'] === 'success'
            ? 'Raid successful! You took A'.number_format($result['cash_stolen'], 2).' from the defender.'
            : 'Raid failed. The defenders held you off.';

        return redirect()->route('map.show')->with('attack_result', $message);
    }
}
