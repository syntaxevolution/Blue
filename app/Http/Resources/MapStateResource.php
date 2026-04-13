<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shape for the map view: current player state + current tile + the
 * four cardinal-adjacent "edge hint" tiles (type only, no detail),
 * plus the tile_detail sub-payload for whatever kind the player is
 * standing on (oil field, post, wasteland occupants, etc).
 *
 * Consumed by the REST API controller. The Inertia web controller
 * bypasses the resource and passes the full builder array straight
 * to Vue via Inertia::render, so both layers read from the same
 * MapStateBuilder source of truth.
 */
class MapStateResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string,mixed> $state */
        $state = $this->resource;

        return [
            'player' => $state['player'],
            'current_tile' => $state['current_tile'],
            // tile_detail carries the per-tile-type sub-payload —
            // oil_field grid, post shop inventory, wasteland occupants
            // list, enemy-base recon status, own-base vault, casino
            // entry info. Null for tile types without a detail view
            // (landmark, ruin). Needed by mobile clients to render
            // the current tile's interaction panel.
            'tile_detail' => $state['tile_detail'] ?? null,
            'neighbors' => $state['neighbors'],
            'discovered_count' => $state['discovered_count'],
            'bank_cap' => $state['bank_cap'],
        ];
    }
}
