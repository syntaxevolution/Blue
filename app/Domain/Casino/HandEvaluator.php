<?php

namespace App\Domain\Casino;

/**
 * Pure poker hand evaluator. No dependencies.
 *
 * Evaluates the best 5-card hand from 5-7 cards and returns an integer
 * ranking where higher = better. Two hands can be compared directly
 * via their rank integers.
 *
 * Rank encoding (32-bit integer):
 *   bits 24-27: hand category (0=high card .. 9=royal flush)
 *   bits 20-23: primary kicker (e.g. pair rank)
 *   bits 16-19: secondary kicker
 *   bits 12-15: third kicker
 *   bits 8-11:  fourth kicker
 *   bits 4-7:   fifth kicker
 */
class HandEvaluator
{
    public const HIGH_CARD       = 0;
    public const ONE_PAIR        = 1;
    public const TWO_PAIR        = 2;
    public const THREE_OF_A_KIND = 3;
    public const STRAIGHT        = 4;
    public const FLUSH           = 5;
    public const FULL_HOUSE      = 6;
    public const FOUR_OF_A_KIND  = 7;
    public const STRAIGHT_FLUSH  = 8;
    public const ROYAL_FLUSH     = 9;

    public const HAND_NAMES = [
        0 => 'High Card',
        1 => 'One Pair',
        2 => 'Two Pair',
        3 => 'Three of a Kind',
        4 => 'Straight',
        5 => 'Flush',
        6 => 'Full House',
        7 => 'Four of a Kind',
        8 => 'Straight Flush',
        9 => 'Royal Flush',
    ];

    /**
     * Evaluate the best 5-card hand from the given cards.
     *
     * @param  list<int>  $cards  5-7 card integers (0-51)
     * @return array{rank: int, category: int, category_name: string, best_five: list<int>}
     */
    public function evaluate(array $cards): array
    {
        $combos = $this->combinations($cards, 5);
        $bestRank = -1;
        $bestFive = [];
        $bestCategory = 0;

        foreach ($combos as $five) {
            [$rank, $category] = $this->rankFive($five);
            if ($rank > $bestRank) {
                $bestRank = $rank;
                $bestFive = $five;
                $bestCategory = $category;
            }
        }

        return [
            'rank' => $bestRank,
            'category' => $bestCategory,
            'category_name' => self::HAND_NAMES[$bestCategory] ?? 'Unknown',
            'best_five' => $bestFive,
        ];
    }

    /**
     * Compare two evaluated hands. Returns -1, 0, or 1.
     */
    public function compare(array $a, array $b): int
    {
        return $a['rank'] <=> $b['rank'];
    }

    /**
     * @param  list<int>  $five  Exactly 5 cards
     * @return array{0: int, 1: int}  [encoded_rank, category]
     */
    private function rankFive(array $five): array
    {
        $ranks = array_map(fn (int $c) => $c % 13, $five);
        $suits = array_map(fn (int $c) => intdiv($c, 13), $five);

        sort($ranks);

        $isFlush = count(array_unique($suits)) === 1;
        $isStraight = $this->isStraight($ranks);
        $straightHigh = $isStraight ? $this->straightHigh($ranks) : 0;

        $counts = array_count_values($ranks);
        arsort($counts);

        if ($isFlush && $isStraight) {
            if ($straightHigh === 12) {
                return [$this->encode(self::ROYAL_FLUSH, [$straightHigh]), self::ROYAL_FLUSH];
            }

            return [$this->encode(self::STRAIGHT_FLUSH, [$straightHigh]), self::STRAIGHT_FLUSH];
        }

        $groups = array_values($counts);
        $groupRanks = array_keys($counts);

        if ($groups[0] === 4) {
            $quad = $groupRanks[0];
            $kicker = $groupRanks[1];

            return [$this->encode(self::FOUR_OF_A_KIND, [$quad, $kicker]), self::FOUR_OF_A_KIND];
        }

        if ($groups[0] === 3 && ($groups[1] ?? 0) === 2) {
            return [$this->encode(self::FULL_HOUSE, [$groupRanks[0], $groupRanks[1]]), self::FULL_HOUSE];
        }

        if ($isFlush) {
            $sorted = $ranks;
            rsort($sorted);

            return [$this->encode(self::FLUSH, $sorted), self::FLUSH];
        }

        if ($isStraight) {
            return [$this->encode(self::STRAIGHT, [$straightHigh]), self::STRAIGHT];
        }

        if ($groups[0] === 3) {
            $trip = $groupRanks[0];
            $kickers = array_values(array_filter($ranks, fn ($r) => $r !== $trip));
            rsort($kickers);

            return [$this->encode(self::THREE_OF_A_KIND, [$trip, ...$kickers]), self::THREE_OF_A_KIND];
        }

        if ($groups[0] === 2 && ($groups[1] ?? 0) === 2) {
            $pairs = [$groupRanks[0], $groupRanks[1]];
            rsort($pairs);
            $kicker = $groupRanks[2] ?? 0;

            return [$this->encode(self::TWO_PAIR, [$pairs[0], $pairs[1], $kicker]), self::TWO_PAIR];
        }

        if ($groups[0] === 2) {
            $pair = $groupRanks[0];
            $kickers = array_values(array_filter($ranks, fn ($r) => $r !== $pair));
            rsort($kickers);

            return [$this->encode(self::ONE_PAIR, [$pair, ...$kickers]), self::ONE_PAIR];
        }

        $sorted = $ranks;
        rsort($sorted);

        return [$this->encode(self::HIGH_CARD, $sorted), self::HIGH_CARD];
    }

    private function isStraight(array $sortedRanks): bool
    {
        $unique = array_unique($sortedRanks);
        if (count($unique) !== 5) {
            return false;
        }

        $vals = array_values($unique);
        sort($vals);

        if ($vals[4] - $vals[0] === 4) {
            return true;
        }

        // Ace-low straight: A-2-3-4-5 (ranks: 0,1,2,3,12)
        if ($vals === [0, 1, 2, 3, 12]) {
            return true;
        }

        return false;
    }

    private function straightHigh(array $sortedRanks): int
    {
        $vals = array_unique($sortedRanks);
        sort($vals);

        // Ace-low: high card is 3 (rank index for 5)
        if ($vals === [0, 1, 2, 3, 12]) {
            return 3;
        }

        return max($vals);
    }

    private function encode(int $category, array $kickers): int
    {
        $rank = $category << 20;
        foreach (array_slice($kickers, 0, 5) as $i => $k) {
            $rank |= ($k & 0xF) << (16 - $i * 4);
        }

        return $rank;
    }

    /**
     * Generate all C(n,k) combinations.
     *
     * @param  list<int>  $items
     * @return list<list<int>>
     */
    private function combinations(array $items, int $k): array
    {
        $n = count($items);
        if ($k > $n) {
            return [];
        }
        if ($k === $n) {
            return [$items];
        }

        $result = [];
        $this->combinationsHelper($items, $k, 0, [], $result);

        return $result;
    }

    private function combinationsHelper(array $items, int $k, int $start, array $current, array &$result): void
    {
        if (count($current) === $k) {
            $result[] = $current;

            return;
        }

        $remaining = $k - count($current);
        for ($i = $start; $i <= count($items) - $remaining; $i++) {
            $current[] = $items[$i];
            $this->combinationsHelper($items, $k, $i + 1, $current, $result);
            array_pop($current);
        }
    }
}
