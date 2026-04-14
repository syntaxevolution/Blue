<?php

namespace App\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown by LootCrateService when a crate operation cannot proceed:
 * crate already opened, player not on the crate's tile, placer trying
 * to open their own sabotage crate, deployment cap reached, etc.
 *
 * Controllers map this to HTTP 422.
 */
class CannotOpenLootCrateException extends RuntimeException
{
    public static function alreadyOpened(int $crateId): self
    {
        return new self("Loot crate #{$crateId} has already been opened.");
    }

    public static function notFound(int $crateId): self
    {
        return new self("Loot crate #{$crateId} does not exist.");
    }

    public static function notOnTile(): self
    {
        return new self("You must be standing on the crate's tile to open it.");
    }

    public static function ownSabotage(): self
    {
        return new self('You can see your own sabotage crate, but you cannot open it.');
    }

    public static function notOnWasteland(string $tileType): self
    {
        return new self("Loot crates can only be deployed on wasteland tiles (current tile is '{$tileType}').");
    }

    public static function tileAlreadyHasCrate(int $tileX, int $tileY): self
    {
        return new self("Tile ({$tileX}, {$tileY}) already has a loot crate deployed on it.");
    }

    public static function notOwned(string $itemKey): self
    {
        return new self("You don't own a '{$itemKey}' to deploy.");
    }

    public static function unknownDevice(string $itemKey): self
    {
        return new self("'{$itemKey}' is not a deployable loot crate.");
    }

    public static function deploymentCapReached(int $currentCount, int $cap): self
    {
        return new self("You already have {$currentCount} sabotage crates deployed (cap: {$cap}). Wait for one to be opened before deploying another.");
    }
}
