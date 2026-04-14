<?php

namespace App\Http\Controllers\Web;

use App\Domain\Exceptions\CannotOpenLootCrateException;
use App\Domain\Loot\LootCrateService;
use App\Domain\World\WorldService;
use App\Http\Controllers\Controller;
use App\Http\Requests\DeployLootCrateRequest;
use App\Models\TileLootCrate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Inertia controller for loot crate actions. Stays thin — every
 * operation delegates to LootCrateService. Mirrors the existing
 * MapController pattern of flash-with-redirect so the frontend can
 * render result messages and trigger the crate-result modal after
 * a successful open.
 *
 * Routes:
 *   POST /map/loot-crates/{crate}/open    → open the crate
 *   POST /map/loot-crates/{crate}/decline → leave the crate for next visitor
 *   POST /map/loot-crates/deploy          → deploy sabotage crate on current tile
 */
class LootCrateController extends Controller
{
    public function __construct(
        private readonly WorldService $world,
        private readonly LootCrateService $lootCrates,
    ) {}

    public function open(Request $request, TileLootCrate $crate): RedirectResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $outcome = $this->lootCrates->open($player->id, (int) $crate->id);
        } catch (CannotOpenLootCrateException $e) {
            return redirect()->route('map.show')->withErrors(['loot_crate' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return redirect()->route('map.show')->withErrors([
                'loot_crate' => 'Open failed: '.$e->getMessage(),
            ]);
        }

        // Flash the result into a dedicated key so Map.vue can render
        // the outcome modal without colliding with other flash
        // messages. The same key is re-used by the auto-pop modal —
        // the frontend switches between the "you found a crate"
        // prompt and the "here's what was inside" reveal based on
        // whether loot_result is set.
        return redirect()->route('map.show')->with('loot_result', $outcome);
    }

    public function decline(Request $request, TileLootCrate $crate): RedirectResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $this->lootCrates->decline($player->id, (int) $crate->id);
        } catch (CannotOpenLootCrateException $e) {
            return redirect()->route('map.show')->withErrors(['loot_crate' => $e->getMessage()]);
        }

        return redirect()->route('map.show');
    }

    public function deploy(DeployLootCrateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $result = $this->lootCrates->place(
                $player->id,
                (string) $request->validated('item_key'),
            );
        } catch (CannotOpenLootCrateException $e) {
            return redirect()->route('map.show')->withErrors(['loot_deploy' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return redirect()->route('map.show')->withErrors([
                'loot_deploy' => 'Deploy failed: '.$e->getMessage(),
            ]);
        }

        return redirect()->route('map.show')->with('loot_deploy_result', [
            'crate_id' => (int) $result['crate']->id,
            'remaining_quantity' => (int) $result['remaining_quantity'],
            'item_key' => (string) $result['crate']->device_key,
        ]);
    }
}
