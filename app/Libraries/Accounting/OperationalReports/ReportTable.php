<?php

namespace App\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\ReportScope;

/**
 * What a report of the family hands to the screen and to the file: its columns, the blocks of rows, the
 * row that adds everything up, and the checks that say whether it agrees with the books. Every cell is
 * a plain string, a number with the decimals it is kept at, so the screen and the file format it
 * and never compute.
 */
final readonly class ReportTable
{
    /**
     * @param list<ReportColumn>         $columns
     * @param list<ReportBlock>          $blocks
     * @param array<string, string>      $total
     * @param list<TieCheck>             $ties
     * @param list<string>               $notes            Language keys of what the reader should know about the figures.
     * @param array<string, string>|null $detail           Extra figures shown beside the table, by language key.
     * @param list<string>               $refusalArguments
     */
    public function __construct(
        public ReportScope $scope,
        public ?string $from,
        public string $to,
        public array $columns,
        public array $blocks,
        public array $total,
        public array $ties = [],
        public array $notes = [],
        public int $documents = 0,
        public ?array $detail = null,
        public ?string $refusal = null,
        public array $refusalArguments = [],
    ) {
    }

    /**
     * A report that is not made, for the reason the language key gives, so that no figure of it can be read.
     *
     * @param list<string> $refusalArguments
     */
    public static function refused(ReportScope $scope, ?string $from, string $to, string $refusal, array $refusalArguments = []): self
    {
        return new self($scope, $from, $to, [], [], [], refusal: $refusal, refusalArguments: $refusalArguments);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }

    public function ties(): bool
    {
        foreach ($this->ties as $tie) {
            if (! $tie->ties()) {
                return false;
            }
        }

        return true;
    }

    public function isEmpty(): bool
    {
        return $this->documents === 0;
    }
}
