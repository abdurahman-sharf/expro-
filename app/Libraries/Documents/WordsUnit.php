<?php

namespace App\Libraries\Documents;

/**
 * How one unit of money, the main one or the minor one, is named when an amount is spelled.
 */
final readonly class WordsUnit
{
    /**
     * @param array{0: string, 1: string, 2: string, 3: string} $arabic  The singular (after a number that ends in a round number or in one or two), the dual, the plural (after three to ten) and the singular that follows eleven to ninety-nine.
     * @param array{0: string, 1: string}                       $english The singular and the plural.
     */
    public function __construct(
        public bool $feminine,
        public array $arabic,
        public array $english,
    ) {
    }
}
