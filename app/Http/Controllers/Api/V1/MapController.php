<?php

namespace App\Http\Controllers\Api\V1;

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
use App\Http\Resources\MapStateResource;
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

        return new MapStateResource($this->mapState->build($player->fresh()));
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
