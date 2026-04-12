<?php

namespace App\Domain\Casino;

use App\Domain\Config\RngService;

/**
 * Shared card deck utility for Blackjack and Hold'em.
 *
 * Cards are represented as integers 0-51:
 *   suit  = card / 13  (0=clubs, 1=diamonds, 2=hearts, 3=spades)
 *   rank  = card % 13  (0=2, 1=3, ..., 8=10, 9=J, 10=Q, 11=K, 12=A)
 *
 * The deck state is a simple array stored in the game's state_json.
 * Shuffle is deterministic via RngService.
 */
class CardDeck
{
    public const RANKS = ['2','3','4','5','6','7','8','9','10','J','Q','K','A'];
    public const SUITS = ['clubs','diamonds','hearts','spades'];
    public const SUIT_SYMBOLS = ['♣','♦','♥','♠'];

    /**
     * @return list<int>
     */
    public static function fresh(int $deckCount = 1): array
    {
        $cards = [];
        for ($d = 0; $d < $deckCount; $d++) {
            for ($c = 0; $c < 52; $c++) {
                $cards[] = $c;
            }
        }

        return $cards;
    }

    /**
     * @param  list<int>  $cards
     * @return list<int>
     */
    public static function shuffle(array $cards, RngService $rng, string $category, string $eventKey): array
    {
        $n = count($cards);
        for ($i = $n - 1; $i > 0; $i--) {
            $j = $rng->rollInt($category, "{$eventKey}:shuffle:{$i}", 0, $i);
            [$cards[$i], $cards[$j]] = [$cards[$j], $cards[$i]];
        }

        return $cards;
    }

    public static function rank(int $card): int
    {
        return $card % 13;
    }

    public static function suit(int $card): int
    {
        return intdiv($card, 13);
    }

    public static function rankName(int $card): string
    {
        return self::RANKS[self::rank($card)];
    }

    public static function suitName(int $card): string
    {
        return self::SUITS[self::suit($card)];
    }

    public static function display(int $card): string
    {
        return self::rankName($card).self::SUIT_SYMBOLS[self::suit($card)];
    }

    public static function blackjackValue(int $card): int
    {
        $rank = self::rank($card);
        if ($rank >= 9) {
            return 10; // 10, J, Q, K
        }

        return $rank + 2; // 2-9
    }

    public static function isAce(int $card): bool
    {
        return self::rank($card) === 12;
    }

    /**
     * @param  list<int>  $hand
     * @return array{total: int, soft: bool}
     */
    public static function blackjackHandValue(array $hand): array
    {
        $total = 0;
        $aces = 0;

        foreach ($hand as $card) {
            if (self::isAce($card)) {
                $aces++;
                $total += 11;
            } else {
                $total += self::blackjackValue($card);
            }
        }

        while ($total > 21 && $aces > 0) {
            $total -= 10;
            $aces--;
        }

        return ['total' => $total, 'soft' => $aces > 0];
    }

    /**
     * @param  list<int>  $hand
     * @return list<array{rank: string, suit: string, display: string}>
     */
    public static function toDisplayArray(array $hand): array
    {
        return array_map(fn (int $c) => [
            'rank' => self::rankName($c),
            'suit' => self::suitName($c),
            'display' => self::display($c),
        ], $hand);
    }
}
