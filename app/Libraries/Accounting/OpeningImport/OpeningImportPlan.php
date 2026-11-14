<?php

namespace App\Libraries\Accounting\OpeningImport;

/**
 * What posting a file of opening balances would do, row by row, and the fingerprint that names it. The
 * fingerprint covers what each row says and whether it is in order, and not whether it is posted yet, so
 * that it stays the same while the file is being posted a step at a time.
 */
final readonly class OpeningImportPlan
{
    /**
     * @param list<OpeningImportRow> $rows
     */
    public function __construct(
        public OpeningImportKind $kind,
        public array $rows,
        public string $fileHash,
        public string $fingerprint,
        public string $defaultDate,
        public int $branchId,
    ) {
    }

    /**
     * @return list<OpeningImportRow>
     */
    public function pending(): array
    {
        return array_values(array_filter($this->rows, static fn (OpeningImportRow $row): bool => $row->isPending()));
    }

    /**
     * @return list<OpeningImportRow>
     */
    public function failing(): array
    {
        return array_values(array_filter($this->rows, static fn (OpeningImportRow $row): bool => ! $row->isValid()));
    }

    public function postedCount(): int
    {
        return count(array_filter($this->rows, static fn (OpeningImportRow $row): bool => $row->isValid() && $row->posted));
    }

    /**
     * What the rows that are in order come to by side, as the journals they make would hold: the debit and credit
     * of the account of the parties and of the account of opening balances.
     *
     * @return array{positive: numeric-string, negative: numeric-string, net: numeric-string}
     */
    public function totals(): array
    {
        $positive = '0.0000';
        $negative = '0.0000';

        foreach ($this->rows as $row) {
            if (! $row->isValid() || $row->amount === null) {
                continue;
            }

            if ($row->side === $this->kind->sideOf(true)) {
                $positive = bcadd($positive, $row->amount, 4);
            } else {
                $negative = bcadd($negative, $row->amount, 4);
            }
        }

        return ['positive' => $positive, 'negative' => $negative, 'net' => bcsub($positive, $negative, 4)];
    }
}
