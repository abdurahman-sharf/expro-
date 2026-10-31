<?php

namespace App\Libraries\Accounting\Bank;

final class ReconciliationFigures
{
    /**
     * @param array{count: int, total: string, in: string, out: string} $unmatchedStatement
     * @param array{count: int, total: string, in: string, out: string} $outstandingBook
     * @param array{count: int, total: string, in: string, out: string} $carriedBook
     * @param list<object>                                               $statementLines
     * @param list<object>                                               $bookLines
     * @param list<object>                                               $carriedLines
     */
    public function __construct(
        public readonly object $treasury,
        public readonly int $accountId,
        public readonly string $asOf,
        public readonly ?string $coverageStart,
        public readonly ?string $closedThrough,
        public readonly string $bookBalance,
        public readonly ?string $statementBalance,
        public readonly string $statementLinesTotal,
        public readonly array $unmatchedStatement,
        public readonly array $outstandingBook,
        public readonly array $carriedBook,
        public readonly array $statementLines,
        public readonly array $bookLines,
        public readonly array $carriedLines,
        public readonly string $adjustedBook,
        public readonly ?string $adjustedBank,
        public readonly ?string $difference,
        public readonly int $matchesMade,
    ) {
    }

    public function reconciled(): bool
    {
        return $this->difference !== null && bccomp($this->difference, '0', 4) === 0;
    }
}
