<?php

namespace App\Domain\Economy;

use App\Domain\Config\GameConfigResolver;
use App\Models\Player;

/**
 * Grants extra moves purchased from the general store's extra_moves_pack.
 *
 * Per spec: unlimited purchases per day, each grants a configurable flat
 * amount (default 10), and may push the player's moves_current above the
 * normal bank cap without raising the cap itself. The purchase price is
 * enforced by ShopService before this is invoked.
 */
class ExtraMovesService
{
    public function __construct(
        private readonly GameConfigResolver $config,
    ) {}

    public function grant(Player $player): int
    {
        $amount = (int) $this->config->get('general_store.extra_moves.amount');
        $player->moves_current = (int) $player->moves_current + $amount;

        return $amount;
    }
}
