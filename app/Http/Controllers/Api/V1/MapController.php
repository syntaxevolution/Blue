<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Combat\AttackService;
use App\Domain\Combat\SpyService;
use App\Domain\Combat\TileCombatService;
use App\Domain\Drilling\DrillService;
use App\Domain\Economy\ShopService;
use App\Domain\Exceptions\CannotAttackException;
use App\Domain\Exceptions\CannotDrillException;
use App\Domain\Exceptions\CannotPurchaseException;
use App\Domain\Exceptions\CannotSabotageException;
use App\Domain\Exceptions\CannotSpyException;
use App\Domain\Exceptions\CannotTravelException;
use App\Domain\Exceptions\InsufficientMovesException;
use App\Domain\Loot\LootCrateService;
use App\Domain\Player\MapStateBuilder;
use App\Domain\Player\TravelService;
use App\Domain\Sabotage\SabotageService;
use App\Domain\World\WorldService;
use App\Http\Controllers\Controller;
use App\Http\Requests\DrillRequest;
use App\Http\Requests\PlaceDeviceRequest;
use App\Http\Requests\PurchaseRequest;
use App\Http\Requests\TravelRequest;
use App\Http\Resources\MapStateResource;
use App\Models\Tile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REST mirror of Web\MapController for the /api/v1/map/* surface.
 *
 * GET  /api/v1/map          — current tile + player state + edge hints
 * POST /api/v1/map/move     — travel N/S/E/W (1 tile walking or N via transport)
 * POST /api/v1/map/drill    — drill one cell of the 5×5 sub-grid
 * POST /api/v1/map/purchase — buy an item at the current post
 * POST /api/v1/map/spy      — recon an enemy base
 * POST /api/v1/map/attack   — raid an enemy base
 *
 * Both endpoints require auth:sanctum (see routes/api.php).
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
        private readonly TileCombatService $tileCombatSvc,
        private readonly LootCrateService $lootCrates,
    ) {}

    public function show(Request $request): MapStateResource
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        return new MapStateResource($this->mapState->build($player));
    }

    public function move(TravelRequest $request): MapStateResource|JsonResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $this->travel->travel($player->id, $request->validated('direction'));
        } catch (InsufficientMovesException $e) {
            return response()->json(['errors' => ['travel' => $e->getMessage()]], 422);
        } catch (CannotTravelException $e) {
            return response()->json(['errors' => ['travel' => $e->getMessage()]], 422);
        }

        $player = $player->fresh();

        // Loot crate spawn/fetch hook — see Web\MapController::move for
        // the full rationale. onArrival is a no-op on non-wasteland
        // tiles so it's safe to call unconditionally. The returned
        // crate (if any) is merged into the MapStateResource payload
        // under `loot_event`, which the mobile client reads to pop a
        // one-shot modal.
        $lootEvent = null;
        /** @var Tile|null $destination */
        $destination = Tile::query()->find($player->current_tile_id);
        if ($destination !== null) {
            $crate = $this->lootCrates->onArrival($player, $destination);
            if ($crate !== null) {
                $lootEvent = [
                    'crate_id' => (int) $crate->id,
                    'placed_by_me' => (int) ($crate->placed_by_player_id ?? 0) === (int) $player->id,
                ];
            }
        }

        $state = $this->mapState->build($player);
        if ($lootEvent !== null) {
            $state['loot_event'] = $lootEvent;
        }

        return new MapStateResource($state);
    }

    public function drill(DrillRequest $request): MapStateResource|JsonResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $this->drillSvc->drill(
                $player->id,
                (int) $request->validated('grid_x'),
                (int) $request->validated('grid_y'),
            );
        } catch (InsufficientMovesException $e) {
            return response()->json(['errors' => ['drill' => $e->getMessage()]], 422);
        } catch (CannotDrillException $e) {
            return response()->json(['errors' => ['drill' => $e->getMessage()]], 422);
        }

        return new MapStateResource($this->mapState->build($player->fresh()));
    }

    public function purchase(PurchaseRequest $request): MapStateResource|JsonResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $this->shop->purchase(
                $player->id,
                (string) $request->validated('item_key'),
            );
        } catch (CannotPurchaseException $e) {
            return response()->json(['errors' => ['purchase' => $e->getMessage()]], 422);
        }

        return new MapStateResource($this->mapState->build($player->fresh()));
    }

    public function spy(Request $request): MapStateResource|JsonResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $this->spySvc->spy($player->id);
        } catch (InsufficientMovesException $e) {
            return response()->json(['errors' => ['spy' => $e->getMessage()]], 422);
        } catch (CannotSpyException $e) {
            return response()->json(['errors' => ['spy' => $e->getMessage()]], 422);
        }

        return new MapStateResource($this->mapState->build($player->fresh()));
    }

    public function attack(Request $request): MapStateResource|JsonResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $this->attackSvc->attack($player->id);
        } catch (InsufficientMovesException $e) {
            return response()->json(['errors' => ['attack' => $e->getMessage()]], 422);
        } catch (CannotAttackException $e) {
            return response()->json(['errors' => ['attack' => $e->getMessage()]], 422);
        }

        return new MapStateResource($this->mapState->build($player->fresh()));
    }

    public function tileCombat(Request $request): MapStateResource|JsonResponse
    {
        $data = $request->validate([
            'defender_player_id' => ['required', 'integer'],
        ]);

        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $this->tileCombatSvc->engage($player->id, (int) $data['defender_player_id']);
        } catch (InsufficientMovesException $e) {
            return response()->json(['errors' => ['tile_combat' => $e->getMessage()]], 422);
        } catch (CannotAttackException $e) {
            return response()->json(['errors' => ['tile_combat' => $e->getMessage()]], 422);
        } catch (\Throwable $e) {
            // Catch-all mirrors the web controller so a DB race or
            // any other unexpected failure lands as a 422 JSON error
            // instead of a raw 500, keeping mobile clients on a
            // predictable error shape.
            return response()->json(['errors' => ['tile_combat' => 'Fight failed: '.$e->getMessage()]], 422);
        }

        return new MapStateResource($this->mapState->build($player->fresh()));
    }

    public function placeDevice(PlaceDeviceRequest $request): MapStateResource|JsonResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $this->sabotage->place(
                $player->id,
                (int) $request->validated('grid_x'),
                (int) $request->validated('grid_y'),
                (string) $request->validated('item_key'),
            );
        } catch (InsufficientMovesException $e) {
            return response()->json(['errors' => ['place_device' => $e->getMessage()]], 422);
        } catch (CannotSabotageException $e) {
            return response()->json(['errors' => ['place_device' => $e->getMessage()]], 422);
        }

        return new MapStateResource($this->mapState->build($player->fresh()));
    }
}
