<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Exceptions\MdnException;
use App\Domain\Mdn\MdnAllianceService;
use App\Domain\World\WorldService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MdnAllianceController extends Controller
{
    public function __construct(
        private readonly WorldService $world,
        private readonly MdnAllianceService $alliances,
    ) {}

    public function store(Request $request, int $mdn): JsonResponse
    {
        $data = $request->validate(['other_mdn_id' => ['required', 'integer']]);

        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $alliance = $this->alliances->declare($player->id, (int) $data['other_mdn_id']);
        } catch (MdnException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $alliance], 201);
    }

    public function destroy(Request $request, int $mdn, int $alliance): JsonResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $this->alliances->revoke($player->id, $alliance);
        } catch (MdnException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }
}
