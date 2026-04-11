<?php

namespace App\Domain\Player;

use App\Models\Tile;

/**
 * Thin façade that delegates every travel request to
 * TransportMovementService. The multi-tile service handles walking
 * (spaces=1, fuel=0) identically to a one-tile move, so one call-site
 * covers every transport mode.
 *
 * Kept as a separate class because the domain and the existing tests
 * reference TravelService::travel(); removing it would mean touching
 * every caller.
 */
class TravelService
{
    public function __construct(
        private readonly TransportMovementService $transportMovement,
    ) {}

    public function travel(int $playerId, string $direction): Tile
    {
        return $this->transportMovement->travel($playerId, $direction);
    }
}
