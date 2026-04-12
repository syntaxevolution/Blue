<?php

namespace App\Domain\Exceptions;

use RuntimeException;

class CasinoException extends RuntimeException
{
    public static function notOnCasinoTile(string $tileType): self
    {
        return new self("Cannot enter casino: current tile is '{$tileType}', not a casino");
    }

    public static function casinoDisabled(): self
    {
        return new self('The casino is currently closed');
    }

    public static function noActiveSession(): self
    {
        return new self('You must enter the casino before playing — pay the entry fee first');
    }

    public static function sessionExpired(): self
    {
        return new self('Your casino session has expired — pay the entry fee to re-enter');
    }

    public static function insufficientBarrels(int $has, int $needs): self
    {
        return new self("Not enough barrels: have {$has}, need {$needs}");
    }

    public static function insufficientCash(float $has, float $needs): self
    {
        return new self('Not enough cash: have A'.number_format($has, 2).', need A'.number_format($needs, 2));
    }

    public static function insufficientStack(float $has, float $needs): self
    {
        return new self('Not enough in your stack: have '.number_format($has, 2).', need '.number_format($needs, 2));
    }

    public static function invalidBetAmount(float $amount, float $min, float $max): self
    {
        return new self("Bet of {$amount} is outside the allowed range [{$min}, {$max}]");
    }

    public static function invalidBetType(string $betType): self
    {
        return new self("Invalid bet type: '{$betType}'");
    }

    public static function tableIsFull(int $maxSeats): self
    {
        return new self("This table is full ({$maxSeats} seats)");
    }

    public static function notYourTurn(): self
    {
        return new self('It is not your turn to act');
    }

    public static function bettingWindowClosed(): self
    {
        return new self('The betting window has closed — wait for the next round');
    }

    public static function invalidAction(string $action): self
    {
        return new self("Invalid action: '{$action}' is not allowed right now");
    }

    public static function minimumPlayersRequired(int $min, int $current): self
    {
        return new self("Need at least {$min} players to start, currently {$current}");
    }

    public static function alreadySeated(): self
    {
        return new self('You are already seated at this table');
    }

    public static function notSeated(): self
    {
        return new self('You are not seated at this table');
    }

    public static function gameNotEnabled(string $game): self
    {
        return new self("The {$game} game is currently disabled");
    }

    public static function invalidBuyIn(float $amount, float $min, float $max): self
    {
        return new self("Buy-in of {$amount} is outside the allowed range [{$min}, {$max}]");
    }
}
