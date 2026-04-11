<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Player\AtlasService;
use App\Domain\World\WorldService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REST mirror of Web\AtlasController.
 *
 * GET /api/v1/atlas — returns the discovered-tile grid. Gated behind
 * the explorers_atlas item purchased at the General Store; unowned
 * players get a 402 Payment Required with an owns_atlas=false payload.
 */
class AtlasController extends Controller
{
    public function __construct(
        private readonly WorldService $world,
        private readonly AtlasService $atlas,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        if (! $this->atlas->ownsAtlas($player)) {
            return response()->json([
                'data' => [
                    'owns_atlas' => false,
                    'tiles' => [],
                    'current_tile_id' => $player->current_tile_id,
                    'base_tile_id' => $player->base_tile_id,
                    'bounds' => null,
                ],
            ], 402);
        }

        $payload = $this->atlas->buildPayload($player);

        return response()->json([
            'data' => [
                'owns_atlas' => true,
                'tiles' => $payload['tiles'],
                'current_tile_id' => $player->current_tile_id,
                'base_tile_id' => $player->base_tile_id,
                'bounds' => $payload['bounds'],
            ],
        ]);
    }
}
