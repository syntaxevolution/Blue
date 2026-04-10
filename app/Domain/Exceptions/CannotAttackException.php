<?php

namespace App\Domain\Exceptions;

use RuntimeException;

class CannotAttackException extends RuntimeException
{
    public static function notOnABase(string $tileType): self
    {
        return new self("Cannot attack: current tile is '{$tileType}', not a player base");
    }

    public static function ownBase(): self
    {
        return new self('Cannot attack your own base');
    }

    public static function targetImmune(): self
    {
        return new self('Target is under new-player immunity and cannot be attacked');
    }

    public static function targetNotFound(): self
    {
        return new self('No player owns this base');
    }

    public static function noSpy(int $decayHours): self
    {
        return new self("You need a successful spy within the last {$decayHours}h before you can attack this base");
    }

    public static function inCooldown(int $cooldownHours): self
    {
        return new self("You attacked this base recently. Wait {$cooldownHours}h between raids on the same target");
    }
}
