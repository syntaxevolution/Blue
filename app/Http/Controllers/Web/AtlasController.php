<?php

namespace App\Http\Controllers\Web;

use App\Domain\Player\AtlasService;
use App\Domain\World\WorldService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inertia controller for the Explorer's Atlas view — a grid of
 * previously-discovered tiles. Gated behind the explorers_atlas item
 * purchased at the General Store; an unowned visit renders a locked
 * state pointing players to where they can buy it.
 */
class AtlasController extends Controller
{
    public function __construct(
        private readonly WorldService $world,
        private readonly AtlasService $atlas,
    ) {}

    public function show(Request $request): Response
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        if (! $this->atlas->ownsAtlas($player)) {
            return Inertia::render('Game/Atlas', [
                'owns_atlas' => false,
                'tiles' => [],
                'current_tile_id' => $player->current_tile_id,
                'base_tile_id' => $player->base_tile_id,
                'bounds' => null,
            ]);
        }

        $payload = $this->atlas->buildPayload($player);

        return Inertia::render('Game/Atlas', [
            'owns_atlas' => true,
            'tiles' => $payload['tiles'],
            'current_tile_id' => $player->current_tile_id,
            'base_tile_id' => $player->base_tile_id,
            'bounds' => $payload['bounds'],
        ]);
    }
}
