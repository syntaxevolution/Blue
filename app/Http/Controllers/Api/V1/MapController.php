<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Drilling\DrillService;
use App\Domain\Exceptions\CannotDrillException;
use App\Domain\Exceptions\CannotTravelException;
use App\Domain\Exceptions\InsufficientMovesException;
use App\Domain\Player\MapStateBuilder;
use App\Domain\Player\TravelService;
use App\Domain\World\WorldService;
use App\Http\Controllers\Controller;
use App\Http\Requests\DrillRequest;
use App\Http\Requests\TravelRequest;
use App\Http\Resources\MapStateResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REST mirror of Web\MapController for the /api/v1/map/* surface.
 *
 * GET  /api/v1/map       — current tile + player state + edge hints
 * POST /api/v1/map/move  — travel N/S/E/W one tile
 *
 * Both endpoints require auth:sanctum (see routes/api.php).
 */
class MapController extends Controller
{
    public function __construct(
        private readonly WorldService $world,
        private readonly TravelService $travel,
        private readonly DrillService $drillSvc,
        private readonly MapStateBuilder $mapState,
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
}
