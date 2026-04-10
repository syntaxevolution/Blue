<?php

namespace App\Http\Controllers\Web;

use App\Domain\World\WorldService;
use App\Http\Controllers\Controller;
use App\Models\Tile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    ) {}

    public function show(Request $request): Response
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        $ownsAtlas = DB::table('player_items')
            ->where('player_id', $player->id)
            ->where('item_key', 'explorers_atlas')
            ->exists();

        if (! $ownsAtlas) {
            return Inertia::render('Game/Atlas', [
                'owns_atlas' => false,
                'tiles' => [],
                'current_tile_id' => $player->current_tile_id,
                'base_tile_id' => $player->base_tile_id,
                'bounds' => null,
            ]);
        }

        // Pull every tile this player has discovered, joined with the
        // tile row so we get coordinates + type + subtype in one trip.
        $tiles = DB::table('tile_discoveries')
            ->where('tile_discoveries.player_id', $player->id)
            ->join('tiles', 'tiles.id', '=', 'tile_discoveries.tile_id')
            ->orderBy('tiles.y')
            ->orderBy('tiles.x')
            ->get([
                'tiles.id',
                'tiles.x',
                'tiles.y',
                'tiles.type',
                'tiles.subtype',
                'tile_discoveries.discovered_at',
            ]);

        $bounds = null;
        if ($tiles->isNotEmpty()) {
            $bounds = [
                'min_x' => (int) $tiles->min('x'),
                'max_x' => (int) $tiles->max('x'),
                'min_y' => (int) $tiles->min('y'),
                'max_y' => (int) $tiles->max('y'),
            ];
        }

        return Inertia::render('Game/Atlas', [
            'owns_atlas' => true,
            'tiles' => $tiles->map(fn ($t) => [
                'id' => (int) $t->id,
                'x' => (int) $t->x,
                'y' => (int) $t->y,
                'type' => (string) $t->type,
                'subtype' => $t->subtype,
                'discovered_at' => $t->discovered_at,
            ])->all(),
            'current_tile_id' => $player->current_tile_id,
            'base_tile_id' => $player->base_tile_id,
            'bounds' => $bounds,
        ]);
    }
}
