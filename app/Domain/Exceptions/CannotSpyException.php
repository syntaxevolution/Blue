<?php

namespace App\Domain\Exceptions;

use RuntimeException;

class CannotSpyException extends RuntimeException
{
    public static function notOnABase(string $tileType): self
    {
        return new self("Cannot spy: current tile is '{$tileType}', not a player base");
    }

    public static function ownBase(): self
    {
        return new self('Cannot spy on your own base');
    }

    public static function targetImmune(): self
    {
        return new self('Target is under new-player immunity and cannot be spied on');
    }

    public static function targetNotFound(): self
    {
        return new self('No player owns this base');
    }
}
