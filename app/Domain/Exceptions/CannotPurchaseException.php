<?php

namespace App\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when a shop purchase cannot proceed for reasons other than
 * move cost: wrong tile type, item doesn't exist, item belongs to a
 * different post type, insufficient currency, stat at hard cap, etc.
 *
 * Controllers map this to HTTP 422.
 */
class CannotPurchaseException extends RuntimeException
{
    public static function notOnAPost(string $tileType): self
    {
        return new self("Cannot purchase: current tile is '{$tileType}', not a post");
    }

    public static function unknownItem(string $itemKey): self
    {
        return new self("Cannot purchase: item '{$itemKey}' does not exist");
    }

    public static function wrongPostType(string $itemKey, string $postType, string $itemPostType): self
    {
        return new self("Cannot purchase '{$itemKey}' at a '{$postType}' post — it is sold at '{$itemPostType}' posts");
    }

    public static function insufficientBarrels(int $has, int $needs): self
    {
        return new self("Not enough barrels: have {$has}, need {$needs}");
    }

    public static function insufficientCash(float $has, float $needs): self
    {
        return new self('Not enough cash: have A'.number_format($has, 2).', need A'.number_format($needs, 2));
    }

    public static function insufficientIntel(int $has, int $needs): self
    {
        return new self("Not enough intel: have {$has}, need {$needs}");
    }

    public static function atHardCap(string $stat, int $cap): self
    {
        $label = match ($stat) {
            'strength' => 'Strength',
            'fortification' => 'Fortification',
            'stealth' => 'Stealth',
            'security' => 'Security',
            default => ucfirst($stat),
        };

        return new self("Your {$label} is already at the hard cap of {$cap}. No further upgrades possible.");
    }

    public static function alreadyHaveBetterDrill(int $currentTier, int $offeredTier): self
    {
        return new self("You already own a tier {$currentTier} drill. A tier {$offeredTier} rig would be a downgrade — best tech only, no stacking.");
    }

    public static function alreadyOwned(string $itemKey): self
    {
        return new self("You already own '{$itemKey}' — this item can only be purchased once.");
    }
}
