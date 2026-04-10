<?php

namespace App\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when a travel action cannot proceed for a non-move-cost reason:
 * edge of world, blocked tile, invalid direction, etc.
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
}
