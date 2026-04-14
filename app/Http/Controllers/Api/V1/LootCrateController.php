<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Exceptions\CannotOpenLootCrateException;
use App\Domain\Loot\LootCrateService;
use App\Domain\Player\MapStateBuilder;
use App\Domain\World\WorldService;
use App\Http\Controllers\Controller;
use App\Http\Requests\DeployLootCrateRequest;
use App\Http\Resources\MapStateResource;
use App\Models\TileLootCrate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * REST mirror of Web\LootCrateController for the /api/v1/* surface.
 *
 * Every endpoint returns a MapStateResource representing the player's
 * post-action state, with the crate outcome payload merged into the
 * `loot_result` field. Mobile clients treat these the same way the
 * Inertia frontend treats session-flashed results.
 */
class LootCrateController extends Controller
{
    public function __construct(
        private readonly WorldService $world,
        private readonly LootCrateService $lootCrates,
        private readonly MapStateBuilder $mapState,
    ) {}

    public function open(Request $request, TileLootCrate $crate): MapStateResource|JsonResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $outcome = $this->lootCrates->open($player->id, (int) $crate->id);
        } catch (CannotOpenLootCrateException $e) {
            return response()->json(['errors' => ['loot_crate' => $e->getMessage()]], 422);
        } catch (\Throwable $e) {
            Log::error('Loot crate open failed', [
                'player_id' => $player->id,
                'crate_id' => (int) $crate->id,
                'exception' => $e,
            ]);

            return response()->json(['errors' => ['loot_crate' => 'Could not open the crate. Please try again.']], 422);
        }

        $state = $this->mapState->build($player->fresh());
        $state['loot_result'] = $outcome;

        return new MapStateResource($state);
    }

    public function decline(Request $request, TileLootCrate $crate): MapStateResource|JsonResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $this->lootCrates->decline($player->id, (int) $crate->id);
        } catch (CannotOpenLootCrateException $e) {
            return response()->json(['errors' => ['loot_crate' => $e->getMessage()]], 422);
        }

        return new MapStateResource($this->mapState->build($player->fresh()));
    }

    public function deploy(DeployLootCrateRequest $request): MapStateResource|JsonResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $result = $this->lootCrates->place(
                $player->id,
                (string) $request->validated('item_key'),
            );
        } catch (CannotOpenLootCrateException $e) {
            return response()->json(['errors' => ['loot_deploy' => $e->getMessage()]], 422);
        } catch (\Throwable $e) {
            Log::error('Loot crate deploy failed', [
                'player_id' => $player->id,
                'item_key' => (string) $request->validated('item_key'),
                'exception' => $e,
            ]);

            return response()->json(['errors' => ['loot_deploy' => 'Could not deploy the crate. Please try again.']], 422);
        }

        $state = $this->mapState->build($player->fresh());
        $state['loot_deploy_result'] = [
            'crate_id' => (int) $result['crate']->id,
            'remaining_quantity' => (int) $result['remaining_quantity'],
            'item_key' => (string) $result['crate']->device_key,
        ];

        return new MapStateResource($state);
    }
}
