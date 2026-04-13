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
     *   tiles: list<array{id:int,x:int,y:int,type:string,subtype:string|null,discovered_at:mixed,owner_username:string|null}>,
     *   bounds: array{min_x:int,max_x:int,min_y:int,max_y:int}|null
     * }
     */
    public function buildPayload(Player $player): array
    {
        // Left-join players→users on base_tile_id so we can expose the
        // owner_username for every base the viewer has discovered.
        // Non-base tiles get null here, which is fine — the frontend
        // only reads owner_username when type === 'base'. Matters for
        // the atlas hover panel (so a player hovering any enemy base
        // in fog sees the same name they'd see if they actually
        // walked onto it in Map.vue) and for debugging who owns what
        // in screenshots.
        $tiles = DB::table('tile_discoveries')
            ->where('tile_discoveries.player_id', $player->id)
            ->join('tiles', 'tiles.id', '=', 'tile_discoveries.tile_id')
            ->leftJoin('players', 'players.base_tile_id', '=', 'tiles.id')
            ->leftJoin('users', 'users.id', '=', 'players.user_id')
            ->orderBy('tiles.y')
            ->orderBy('tiles.x')
            ->get([
                'tiles.id',
                'tiles.x',
                'tiles.y',
                'tiles.type',
                'tiles.subtype',
                'tile_discoveries.discovered_at',
                'users.name as owner_username',
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
                'owner_username' => $t->type === 'base' && $t->owner_username !== null
                    ? (string) $t->owner_username
                    : null,
            ])->all(),
            'bounds' => $bounds,
        ];
    }
}
