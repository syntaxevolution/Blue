<?php

namespace App\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when a game action is attempted but the player does not have
 * enough moves in the bank to pay the configured move cost.
 *
 * Controllers map this to HTTP 422 (unprocessable entity).
 */
class InsufficientMovesException extends RuntimeException
{
    public static function forAction(string $action, int $has, int $needs): self
    {
        return new self("Not enough moves for {$action}: have {$has}, need {$needs}");
    }
}
