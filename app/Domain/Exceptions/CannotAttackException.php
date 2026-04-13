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

    public static function sameMdn(): self
    {
        return new self('Cannot attack a fellow MDN member');
    }

    public static function mdnHopCooldown(int $hoursRemaining): self
    {
        return new self("Recent MDN change — raids unlocked in {$hoursRemaining}h");
    }

    // ----- Tile combat (wasteland duel) factories --------------------

    public static function tileCombatRequiresWasteland(string $actualType): self
    {
        return new self("Tile combat is only allowed on wasteland tiles (this tile is '{$actualType}')");
    }

    public static function notOnSameTile(): self
    {
        return new self('You and your target must be standing on the same tile');
    }

    public static function cannotFightSelf(): self
    {
        return new self("You can't fight yourself");
    }

    public static function tileCombatSelfCooldown(int $hours): self
    {
        return new self("You've already fought on this tile in the last {$hours}h — walk it off somewhere else");
    }

    public static function tileCombatTargetCooldown(int $hours): self
    {
        return new self('Your target is catching their breath on this tile — try again later or pick someone else');
    }

    public static function targetNotOnTile(): self
    {
        return new self('No such target standing on this tile');
    }
}
