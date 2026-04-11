<?php

namespace App\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when a travel action cannot proceed for a non-move-cost reason:
 * edge of world, blocked tile, invalid direction, transport ownership, etc.
 *
 * Controllers map this to HTTP 422.
 */
class CannotTravelException extends RuntimeException
{
    public static function edgeOfWorld(int $x, int $y): self
    {
        return new self("Cannot travel to ({$x}, {$y}): edge of the world");
    }

    public static function invalidDirection(string $direction): self
    {
        return new self("Invalid travel direction: '{$direction}' (expected n, s, e, or w)");
    }

    public static function unknownTransport(string $transport): self
    {
        return new self("Unknown transport mode: '{$transport}'");
    }

    public static function transportNotOwned(string $transport): self
    {
        return new self("You don't own the {$transport}. Purchase it from a General Store first.");
    }

    public static function insufficientFuel(int $have, int $need): self
    {
        return new self("Not enough oil barrels for fuel: have {$have}, need {$need}");
    }

    public static function teleporterNotOwned(): self
    {
        return new self("You don't own a Teleporter. Purchase one from a General Store first.");
    }
}
