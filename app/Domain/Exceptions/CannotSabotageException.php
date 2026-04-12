<?php

namespace App\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when a sabotage device cannot be placed: wrong tile, bad cell,
 * cell already has an armed trap, player doesn't own that device, etc.
 *
 * Also used by DrillService when a scanner-owning player attempts to
 * drill a cell that's already been flagged as rigged.
 *
 * Controllers map this to HTTP 422.
 */
class CannotSabotageException extends RuntimeException
{
    public static function notAnOilField(string $tileType): self
    {
        return new self("Cannot plant device: current tile is '{$tileType}', not an oil field");
    }

    public static function outOfRange(int $gridX, int $gridY): self
    {
        return new self("Drill point ({$gridX}, {$gridY}) is outside the 5×5 grid");
    }

    public static function pointAlreadyRigged(int $gridX, int $gridY): self
    {
        return new self("Drill point ({$gridX}, {$gridY}) already has an active device planted on it");
    }

    public static function unknownDevice(string $itemKey): self
    {
        return new self("'{$itemKey}' is not a deployable sabotage device");
    }

    public static function notOwned(string $itemKey): self
    {
        return new self("You don't own a '{$itemKey}' to plant");
    }

    public static function cellIsRigged(int $gridX, int $gridY): self
    {
        return new self("Drill point ({$gridX}, {$gridY}) is rigged — your scanner is blocking the drill attempt");
    }
}
