<?php

namespace Tests\Support;

use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\PostingService;

/**
 * What a test of the financial reports needs to put journals in the books by branch and by day. A
 * class that uses it also uses AccountingFixtureTrait.
 */
trait ReportFixtureTrait
{
    private int $reportSourceId = 500000;

    /**
     * Posts a balanced journal of the lines given as [account id, debit, credit].
     *
     * @param list<array{int, string, string}> $lines
     */
    protected function postOn(string $date, int $branchId, array $lines, string $description = 'Report test'): int
    {
        $draftLines = array_map(
            static fn (array $line): JournalLineDraft => new JournalLineDraft($line[0], debit: $line[1], credit: $line[2]),
            $lines,
        );

        return (new PostingService())->post(new JournalDraft(
            date: $date,
            lines: $draftLines,
            sourceType: 'sale',
            sourceId: ++$this->reportSourceId,
            description: $description,
            currencyCode: 'SAR',
            branchId: $branchId,
        ))->journalId;
    }

    /**
     * A sale of the amount on the day, in cash: the cash account against the sales.
     */
    protected function cashSale(string $date, int $branchId, string $amount): int
    {
        return $this->postOn($date, $branchId, [[$this->accountId('cash'), $amount, '0'], [$this->accountId('sales_revenue'), '0', $amount]]);
    }

    protected function reverseOn(int $journalId, string $date): int
    {
        return (new JournalService())->reverseAutomatic($journalId, $date, 'Report test reversal', null);
    }
}
