<?php

namespace App\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when a drill action cannot proceed for reasons other than
 * move cost: wrong tile type, depleted point, out-of-range coords.
 *
 * Controllers map this to HTTP 422.
 */
class CannotDrillException extends RuntimeException
{
    public static function notAnOilField(string $tileType): self
    {
        return new self("Cannot drill: current tile is '{$tileType}', not an oil field");
    }

    public static function pointDepleted(int $gridX, int $gridY): self
    {
        return new self("Drill point ({$gridX}, {$gridY}) is depleted — wait for regen");
    }

    public static function outOfRange(int $gridX, int $gridY): self
    {
        return new self("Drill point ({$gridX}, {$gridY}) is outside the 5×5 grid");
    }

    public static function dailyLimitReached(int $limit): self
    {
        return new self("Daily drill limit reached for this oil field ({$limit}/day). Find another field, or come back after midnight.");
    }
}
