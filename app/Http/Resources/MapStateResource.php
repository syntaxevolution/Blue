<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shape for the map view: current player state + current tile + the
 * four cardinal-adjacent "edge hint" tiles (type only, no detail).
 *
 * Consumed by both the Inertia web controller and the REST API
 * controller so the two layers return the same schema.
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
            'neighbors' => $state['neighbors'],
            'discovered_count' => $state['discovered_count'],
            'bank_cap' => $state['bank_cap'],
        ];
    }
}
