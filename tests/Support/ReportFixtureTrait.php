<?php

namespace Tests\Support;

use App\Libraries\Accounting\Currencies;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\Money;
use App\Libraries\Accounting\PostingService;

/**
 * What a test of the financial reports needs to put journals in the books by branch and by day. A
 * class that uses it also uses AccountingFixtureTrait.
 */
trait ReportFixtureTrait
{
    private int $reportSourceId = 500000;

    /**
     * Posts a balanced journal of the lines given as [account id, debit, credit], added by the
     * employee when one is given.
     *
     * @param list<array{int, string, string}> $lines
     */
    protected function postOn(string $date, int $branchId, array $lines, string $description = 'Report test', ?int $employeeId = null): int
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
            employeeId: $employeeId,
        ))->journalId;
    }

    /**
     * A sale of the amount on the day, in cash: the cash account against the sales.
     */
    protected function cashSale(string $date, int $branchId, string $amount, ?int $employeeId = null): int
    {
        return $this->postOn($date, $branchId, [[$this->accountId('cash'), $amount, '0'], [$this->accountId('sales_revenue'), '0', $amount]], employeeId: $employeeId);
    }

    protected function reverseOn(int $journalId, string $date): int
    {
        return (new JournalService())->reverseAutomatic($journalId, $date, 'Report test reversal', null);
    }

    /**
     * Posts a sale in dollars paid in cash: the journal is in the base at the rate and each line carries the
     * dollars it is worth.
     *
     * @param numeric-string $dollars
     * @param numeric-string $rate
     */
    protected function foreignCashSale(string $date, int $branchId, string $dollars, string $rate = '3.75'): int
    {
        $currencies = new Currencies();
        $currencies->setActive('USD', true);

        if ($currencies->rates()->rateOn('USD', $date) === null) {
            $currencies->rates()->add(['currency_code' => 'USD', 'rate' => $rate, 'effective_date' => '2020-01-01']);
        }

        $base = Money::toBase($dollars, $rate, 2);

        return (new PostingService())->post(new JournalDraft(
            date: $date,
            lines: [
                new JournalLineDraft($this->accountId('cash'), debit: $base, foreignAmount: $dollars, currencyCode: 'USD'),
                new JournalLineDraft($this->accountId('sales_revenue'), credit: $base, foreignAmount: $dollars, currencyCode: 'USD'),
            ],
            sourceType: 'sale',
            sourceId: ++$this->reportSourceId,
            description: 'Report test in dollars',
            currencyCode: 'USD',
            exchangeRate: $rate,
            branchId: $branchId,
        ))->journalId;
    }
}
