<?php

namespace App\Domain\Player;

use App\Models\Player;
use Illuminate\Support\Facades\DB;

/**
 * Assembles the Explorer's Atlas payload — every tile the player has
 * discovered, joined with its coordinates/type/subtype.
 *
 * Extracted out of Web\AtlasController and Api\V1\AtlasController so
 * neither controller reaches into the query builder directly.
 */
class AtlasService
{
    public function ownsAtlas(Player $player): bool
    {
        return DB::table('player_items')
            ->where('player_id', $player->id)
            ->where('item_key', 'explorers_atlas')
            ->where('status', 'active')
            ->where('quantity', '>', 0)
            ->exists();
    }

    /**
     * @return array{
     *   tiles: list<array{id:int,x:int,y:int,type:string,subtype:string|null,discovered_at:mixed}>,
     *   bounds: array{min_x:int,max_x:int,min_y:int,max_y:int}|null
     * }
     */
    public function buildPayload(Player $player): array
    {
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

        return [
            'tiles' => $tiles->map(fn ($t) => [
                'id' => (int) $t->id,
                'x' => (int) $t->x,
                'y' => (int) $t->y,
                'type' => (string) $t->type,
                'subtype' => $t->subtype,
                'discovered_at' => $t->discovered_at,
            ])->all(),
            'bounds' => $bounds,
        ];
    }
}
