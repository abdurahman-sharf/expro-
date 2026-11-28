<?php

namespace App\Libraries\Accounting\Engine;

/**
 * One line of a posting rule: the side it is posted to, where its account comes from, and the
 * expression of its amount. A negative amount puts the line on the other side and an amount of
 * nothing leaves it out. A line that repeats over a list of the document is posted once for each
 * entry of the list, with the figures and fields of the entry.
 */
final readonly class RuleLine
{
    /**
     * @param array{first: list<array<string, string>>, else?: string} $account
     * @param array{field: string}|null                                $costCenter
     */
    public function __construct(
        public int $lineNo,
        public string $side,
        public array $account,
        public string $amount,
        public ?string $repeatOver = null,
        public ?string $foreignAmount = null,
        public ?array $costCenter = null,
        public ?string $matrixRef = null,
        public bool $autoTax = false,
    ) {
    }
}
