<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API shape for WorldService::getWorldInfo().
 *
 * Wraps the service-level array in the standard /api/v1 envelope
 * (data / meta / errors is handled by Laravel's default wrap).
 */
class WorldInfoResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string,mixed> $info */
        $info = $this->resource;

        return [
            'initial_radius' => $info['initial_radius'],
            'density' => $info['density'],
            'growth' => $info['growth'],
            'abandonment' => $info['abandonment'],
        ];
    }
}
