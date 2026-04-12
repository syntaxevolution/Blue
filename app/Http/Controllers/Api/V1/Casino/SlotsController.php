<?php

namespace App\Http\Controllers\Api\V1\Casino;

use App\Domain\Casino\SlotMachineService;
use App\Domain\Exceptions\CasinoException;
use App\Domain\World\WorldService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Casino\SpinRequest;
use Illuminate\Http\JsonResponse;

class SlotsController extends Controller
{
    public function __construct(
        private readonly SlotMachineService $slots,
        private readonly WorldService $world,
    ) {}

    public function spin(SpinRequest $request): JsonResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $result = $this->slots->spin(
                $player->id,
                $request->validated('currency'),
                (float) $request->validated('bet'),
            );
        } catch (CasinoException $e) {
            return response()->json(['errors' => ['slots' => $e->getMessage()]], 422);
        }

        return response()->json(['data' => $result]);
    }
}
