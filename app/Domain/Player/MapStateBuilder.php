<?php

namespace App\Domain\Player;

use App\Domain\World\FogOfWarService;
use App\Models\Player;
use App\Models\Tile;

/**
 * Assembles the map-view payload (player state + current tile + edge
 * hint neighbors + discovered count) from a Player. Used by both the
 * Web and Api/V1 MapControllers so the two layers return the same
 * shape without duplicating the query logic.
 *
 * Lives in Domain because it's pure data-shaping from the DB; the
 * result is rendered by the HTTP layer (Inertia props or a JSON
 * resource), not by this builder.
 */
class MapStateBuilder
{
    public function __construct(
        private readonly MoveRegenService $moveRegen,
        private readonly FogOfWarService $fogOfWar,
    ) {}

    /**
     * @return array{
     *     player: array<string,mixed>,
     *     current_tile: array<string,mixed>,
     *     neighbors: list<array<string,mixed>>,
     *     discovered_count: int,
     *     bank_cap: int,
     * }
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
            ],
            'current_tile' => [
                'id' => $current->id,
                'x' => $current->x,
                'y' => $current->y,
                'type' => $current->type,
                'subtype' => $current->subtype,
                'flavor_text' => $current->flavor_text,
            ],
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
}
