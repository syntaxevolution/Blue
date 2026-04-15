<?php

namespace App\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when a Homing Flare / Foundation Charge / Abduction Anchor
 * cannot fire for a non-move-cost reason: item not owned, wrong tile
 * type, stale spy intel, target protected, etc.
 *
 * Controllers map this to HTTP 422 and surface the message as a flash
 * error so the toolbox button simply flashes red without consuming
 * the stack (BaseTeleportService guards run BEFORE any decrement).
 */
class CannotBaseTeleportException extends RuntimeException
{
    public static function homingFlareNotOwned(): self
    {
        return new self("You don't own a Homing Flare. Pick one up at the General Store.");
    }

    public static function foundationChargeNotOwned(): self
    {
        return new self('You have no Foundation Charges left to fire.');
    }

    public static function abductionAnchorNotOwned(): self
    {
        return new self('You have no Abduction Anchors left to fire.');
    }

    public static function notOnWasteland(string $tileType): self
    {
        return new self("Your base can only be bolted onto honest wasteland — current tile is '{$tileType}'.");
    }

    public static function alreadyAtBase(): self
    {
        return new self('You are already standing at your base. Save the flare.');
    }

    public static function targetNotFound(): self
    {
        return new self('That rival has no base to drag — target not found.');
    }

    public static function targetIsSelf(): self
    {
        return new self('The Abduction Anchor refuses to target your own base.');
    }

    public static function targetImmune(): self
    {
        return new self('That rival is still under new-player immunity.');
    }

    public static function targetProtected(): self
    {
        return new self("Their foundation won't budge — Deadbolt Plinth installed.");
    }

    public static function sameMdn(): self
    {
        return new self('MDN charter forbids dragging a fellow member by the foundation.');
    }

    public static function spyIntelStale(int $hours): self
    {
        return new self("Your intel on that rival is too stale. A successful spy within the last {$hours} hours is required.");
    }
}
