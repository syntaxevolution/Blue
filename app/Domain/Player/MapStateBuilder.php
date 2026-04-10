<?php

namespace App\Domain\Player;

use App\Domain\World\FogOfWarService;
use App\Models\DrillPoint;
use App\Models\Item;
use App\Models\OilField;
use App\Models\Player;
use App\Models\Post;
use App\Models\Tile;
use Illuminate\Support\Facades\DB;

/**
 * Assembles the map-view payload (player state + current tile + edge
 * hint neighbors + tile-specific sub-payload + owned gear) from a Player.
 * Used by both the Web and Api/V1 MapControllers so the two layers return
 * the same shape without duplicating query logic.
 *
 * Sub-payloads keyed on current tile type:
 *   - oil_field: 5×5 drill grid (quality + drilled status per cell)
 *   - post:      post metadata + list of items for sale at this post
 *   - base:      (own base) player's held cash/oil/intel summary
 *   - landmark / wasteland / ruin / auction: null
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

        // Fetch the 4 cardinal-adjacent tiles. Each neighbor is a separate
        // (x = ? AND y = ?) pair — we use nested closures because
        // orWhere(['x' => ..., 'y' => ...]) with an array silently FLATTENS
        // the pairs into separate OR clauses instead of grouping them,
        // which would return entire rows and columns instead of just the
        // four neighbors. Do NOT change this back to the array shorthand.
        $neighbors = Tile::query()
            ->where(function ($q) use ($current) {
                $q->where(function ($q2) use ($current) {
                    $q2->where('x', $current->x + 1)->where('y', $current->y);
                })->orWhere(function ($q2) use ($current) {
                    $q2->where('x', $current->x - 1)->where('y', $current->y);
                })->orWhere(function ($q2) use ($current) {
                    $q2->where('x', $current->x)->where('y', $current->y + 1);
                })->orWhere(function ($q2) use ($current) {
                    $q2->where('x', $current->x)->where('y', $current->y - 1);
                });
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
            'owned_items' => $this->ownedItems($player),
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
                    $t->x === $current->x + 1 && $t->y === $current->y => 'e',
                    $t->x === $current->x - 1 && $t->y === $current->y => 'w',
                    $t->x === $current->x && $t->y === $current->y + 1 => 'n',
                    $t->x === $current->x && $t->y === $current->y - 1 => 's',
                    default => null,
                },
            ])->values()->all(),
            'discovered_count' => $this->fogOfWar->countDiscovered($player->id),
            'bank_cap' => $this->moveRegen->bankCap(),
        ];
    }

    /**
     * @return list<array{key:string, name:string, description:string|null, post_type:string, quantity:int, effects:array<string,mixed>|null}>
     */
    private function ownedItems(Player $player): array
    {
        return DB::table('player_items')
            ->where('player_items.player_id', $player->id)
            ->join('items_catalog', 'items_catalog.key', '=', 'player_items.item_key')
            ->orderBy('items_catalog.post_type')
            ->orderBy('items_catalog.sort_order')
            ->get([
                'items_catalog.key',
                'items_catalog.name',
                'items_catalog.description',
                'items_catalog.post_type',
                'items_catalog.effects',
                'player_items.quantity',
            ])
            ->map(fn ($row) => [
                'key' => $row->key,
                'name' => $row->name,
                'description' => $row->description,
                'post_type' => $row->post_type,
                'quantity' => (int) $row->quantity,
                'effects' => $row->effects ? json_decode($row->effects, true) : null,
            ])
            ->all();
    }

    private function tileDetail(Tile $tile, Player $player): ?array
    {
        return match ($tile->type) {
            'oil_field' => $this->oilFieldDetail($tile),
            'post' => $this->postDetail($tile, $player),
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
     * @return array<string,mixed>
     */
    private function postDetail(Tile $tile, Player $player): array
    {
        /** @var Post|null $post */
        $post = Post::query()->where('tile_id', $tile->id)->first();

        $items = [];

        if ($post) {
            $items = Item::query()
                ->where('post_type', $post->post_type)
                ->orderBy('sort_order')
                ->get()
                ->map(fn (Item $item) => [
                    'key' => $item->key,
                    'name' => $item->name,
                    'description' => $item->description,
                    'price_barrels' => (int) $item->price_barrels,
                    'price_cash' => (float) $item->price_cash,
                    'price_intel' => (int) $item->price_intel,
                    'effects' => $item->effects,
                    'can_afford' => $this->canAfford($player, $item),
                ])
                ->all();
        }

        return [
            'kind' => 'post',
            'post_type' => $post?->post_type,
            'name' => $post?->name,
            'items' => $items,
        ];
    }

    private function canAfford(Player $player, Item $item): bool
    {
        if ($item->price_barrels > 0 && $player->oil_barrels < $item->price_barrels) {
            return false;
        }
        if ((float) $item->price_cash > 0 && (float) $player->akzar_cash < (float) $item->price_cash) {
            return false;
        }
        if ($item->price_intel > 0 && $player->intel < $item->price_intel) {
            return false;
        }

        return true;
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
