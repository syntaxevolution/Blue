<?php

namespace App\Domain\Player;

use App\Domain\World\FogOfWarService;
use App\Models\DrillPoint;
use App\Models\OilField;
use App\Models\Player;
use App\Models\Post;
use App\Models\Tile;

/**
 * Assembles the map-view payload (player state + current tile + edge
 * hint neighbors + tile-specific sub-payload) from a Player. Used by
 * both the Web and Api/V1 MapControllers so the two layers return the
 * same shape without duplicating query logic.
 *
 * Sub-payloads, keyed on current tile type:
 *   - oil_field: 5×5 drill grid (quality + drilled status per cell)
 *   - post:      post metadata (type + name) — shop items come in Phase 2
 *   - base:      (own base) player's held cash/fort summary — read-only
 *   - landmark / wasteland / ruin / auction: nothing extra
 */
class MapStateBuilder
{
    public function __construct(
        private readonly MoveRegenService $moveRegen,
        private readonly FogOfWarService $fogOfWar,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function build(Player $player): array
    {
        $this->moveRegen->reconcile($player);
        $player->refresh();

        /** @var Tile $current */
        $current = $player->currentTile;

        $neighbors = Tile::query()
            ->where(function ($q) use ($current) {
                $q->where(['x' => $current->x + 1, 'y' => $current->y])
                    ->orWhere(['x' => $current->x - 1, 'y' => $current->y])
                    ->orWhere(['x' => $current->x, 'y' => $current->y + 1])
                    ->orWhere(['x' => $current->x, 'y' => $current->y - 1]);
            })
            ->get(['id', 'x', 'y', 'type']);

        return [
            'player' => [
                'id' => $player->id,
                'akzar_cash' => (float) $player->akzar_cash,
                'oil_barrels' => $player->oil_barrels,
                'intel' => $player->intel,
                'moves_current' => $player->moves_current,
                'strength' => $player->strength,
                'fortification' => $player->fortification,
                'stealth' => $player->stealth,
                'security' => $player->security,
                'drill_tier' => $player->drill_tier,
                'immunity_expires_at' => $player->immunity_expires_at?->toIso8601String(),
                'base_tile_id' => $player->base_tile_id,
            ],
            'current_tile' => [
                'id' => $current->id,
                'x' => $current->x,
                'y' => $current->y,
                'type' => $current->type,
                'subtype' => $current->subtype,
                'flavor_text' => $current->flavor_text,
                'is_own_base' => $current->id === $player->base_tile_id,
            ],
            'tile_detail' => $this->tileDetail($current, $player),
            'neighbors' => $neighbors->map(fn (Tile $t) => [
                'x' => $t->x,
                'y' => $t->y,
                'type' => $t->type,
                'direction' => match (true) {
                    $t->x === $current->x + 1 => 'e',
                    $t->x === $current->x - 1 => 'w',
                    $t->y === $current->y + 1 => 'n',
                    $t->y === $current->y - 1 => 's',
                    default => null,
                },
            ])->values()->all(),
            'discovered_count' => $this->fogOfWar->countDiscovered($player->id),
            'bank_cap' => $this->moveRegen->bankCap(),
        ];
    }

    /**
     * Tile-specific sub-payload. Shape varies by tile type.
     *
     * @return array<string,mixed>|null
     */
    private function tileDetail(Tile $tile, Player $player): ?array
    {
        return match ($tile->type) {
            'oil_field' => $this->oilFieldDetail($tile),
            'post' => $this->postDetail($tile),
            'base' => $tile->id === $player->base_tile_id
                ? $this->ownBaseDetail($player)
                : ['kind' => 'enemy_base'],
            default => null,
        };
    }

    /**
     * @return array{kind:string, grid: list<array{grid_x:int, grid_y:int, quality:string, drilled:bool}>}
     */
    private function oilFieldDetail(Tile $tile): array
    {
        /** @var OilField|null $field */
        $field = OilField::query()->where('tile_id', $tile->id)->first();

        $grid = [];

        if ($field) {
            $points = DrillPoint::query()
                ->where('oil_field_id', $field->id)
                ->orderBy('grid_y')
                ->orderBy('grid_x')
                ->get(['grid_x', 'grid_y', 'quality', 'drilled_at']);

            foreach ($points as $p) {
                $grid[] = [
                    'grid_x' => (int) $p->grid_x,
                    'grid_y' => (int) $p->grid_y,
                    'quality' => $p->drilled_at === null ? (string) $p->quality : 'depleted',
                    'drilled' => $p->drilled_at !== null,
                ];
            }
        }

        return ['kind' => 'oil_field', 'grid' => $grid];
    }

    /**
     * @return array{kind:string, post_type:string|null, name:string|null}
     */
    private function postDetail(Tile $tile): array
    {
        /** @var Post|null $post */
        $post = Post::query()->where('tile_id', $tile->id)->first();

        return [
            'kind' => 'post',
            'post_type' => $post?->post_type,
            'name' => $post?->name,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function ownBaseDetail(Player $player): array
    {
        return [
            'kind' => 'own_base',
            'stored_cash' => (float) $player->akzar_cash,
            'stored_oil_barrels' => $player->oil_barrels,
            'stored_intel' => $player->intel,
        ];
    }
}
