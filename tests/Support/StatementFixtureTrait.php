<?php

namespace Tests\Support;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Accounting\TreasuryTransfers;
use App\Libraries\Accounting\YearEndEntries;

/**
 * The books a test of the income statement and the balance sheet reads: the two scenarios whose
 * figures were observed in the reference system, a year closed by journal, and a business of
 * documents in two branches. A class that uses it also uses AccountingFixtureTrait,
 * ItemFixtureTrait, ReportFixtureTrait, SaleAccountingFixtureTrait and
 * PurchaseAccountingFixtureTrait.
 */
trait StatementFixtureTrait
{
    protected int $second = 0;

    private int $yearEndSourceId = 700000;

    /**
     * Posts the journal that closes a year: its source is the one the statements leave out of the
     * income statement.
     *
     * @param list<array{int, string, string}> $lines
     */
    protected function postYearEnd(string $date, int $branchId, array $lines): int
    {
        return (new PostingService())->post(new JournalDraft(
            date: $date,
            lines: array_map(static fn (array $line): JournalLineDraft => new JournalLineDraft($line[0], debit: $line[1], credit: $line[2]), $lines),
            sourceType: YearEndEntries::SOURCE_TYPE,
            sourceId: ++$this->yearEndSourceId,
            description: 'Closing of the year',
            currencyCode: 'SAR',
            branchId: $branchId,
        ))->journalId;
    }

    /**
     * Sales of 2,210 and returns of 220 (net sales 1,990), a cost of sales of 5,280 and rent of
     * 3,000 (expenses 8,280): a net income of minus 6,290.
     */
    protected function abScenario(int $branchId = 1): void
    {
        $cash = $this->accountId('cash');

        $this->postOn('2026-10-05', $branchId, [[$cash, '2210.00', '0'], [$this->accountId('sales_revenue'), '0', '2210.00']]);
        $this->postOn('2026-10-06', $branchId, [[$this->accountId('sales_returns'), '220.00', '0'], [$cash, '0', '220.00']]);
        $this->postOn('2026-10-07', $branchId, [[$this->accountId('cogs'), '5280.00', '0'], [$this->accountId('purchases'), '0', '5280.00']]);
        $this->postOn('2026-10-08', $branchId, [[$this->accountIdByCode('545'), '3000.00', '0'], [$cash, '0', '3000.00']]);
    }

    /**
     * The books of the reference system on the day they were read: sales 9,222.01 and returns
     * 1,110, cost of sales 8,188.47, other expenses 200 and rent 3,000 (revenue 8,112.01,
     * expenses 11,388.47, net income minus 3,276.46), against assets 96,991.84, liabilities 268.30
     * and capital 100,000. The capital and the sales are in the first branch, the rest in the
     * second, and every journal balances within its branch.
     */
    protected function observedBooks(int $first, int $second): void
    {
        $cash      = $this->accountId('cash');
        $inventory = $this->accountId('purchases');

        $this->postOn('2026-10-01', $first, [[$cash, '80000.00', '0'], [$inventory, '20000.00', '0'], [$this->accountIdByCode('31'), '0', '100000.00']]);
        $this->postOn('2026-10-05', $first, [[$cash, '9222.01', '0'], [$this->accountId('sales_revenue'), '0', '9222.01']]);
        $this->postOn('2026-10-06', $first, [[$this->accountId('sales_returns'), '1110.00', '0'], [$cash, '0', '1110.00']]);
        $this->postOn('2026-10-07', $second, [[$this->accountId('cogs'), '8188.47', '0'], [$inventory, '0', '8188.47']]);
        $this->postOn('2026-10-08', $second, [[$this->accountId('expenses_default'), '200.00', '0'], [$this->accountIdByCode('545'), '3000.00', '0'], [$cash, '0', '3200.00']]);
        $this->postOn('2026-10-09', $second, [[$inventory, '305.50', '0'], [$this->accountId('other_payables'), '0', '305.50']]);
        $this->postOn('2026-10-10', $second, [[$this->accountId('tax_input'), '37.20', '0'], [$cash, '0', '37.20']]);
    }

    /**
     * Starting to sell installs the chart again, which leaves the default branch only, so the
     * second branch is made after it.
     */
    protected function startBooks(): void
    {
        $this->startBuying();

        $this->second = $this->createBranch('T2', 'Second branch');
    }

    protected function putInTreasury(int $treasuryId, string $amount): void
    {
        (new PostingService())->post(new JournalDraft(
            date: '2026-10-20',
            lines: [new JournalLineDraft((int) (new TreasuryService())->accountOf($treasuryId), debit: $amount), new JournalLineDraft($this->accountId('opening_balance'), credit: $amount)],
            sourceType: 'opening',
            sourceId: random_int(1000, 9999),
            currencyCode: 'SAR',
        ));
    }

    /**
     * Sales and a credit note in two branches, purchases and a debit note, stock bought and moved
     * from the first branch to the second, a transfer of money between the treasuries of the two,
     * and a sale that was cancelled.
     *
     * @return array{treasury: int, depot: int, item: int, other: int, supplier: int}
     */
    protected function mixedBooks(): array
    {
        $this->startBooks();

        $customer = $this->anotherCustomer();
        $supplier = $this->anotherSupplier();
        $depot    = $this->locationOfBranch($this->second, 'Second depot');
        $item     = $this->item('0.00');

        $this->workInEveryBranch();
        $manager = $this->employeeWithGrants([AccountingGuard::TREASURIES_MANAGE]);
        $main    = (new TreasuryService())->ensureMain();
        $there   = (new TreasuryService())->create(['name' => 'Second branch drawer', 'kind' => 'cash', 'branch_id' => $this->second], $manager);

        $this->putInTreasury($main, '500.00');
        (new TreasuryTransfers())->transfer($main, $there, '150.00', '2026-10-21', '', $manager);

        $this->workInBranches(1);
        $invoice = $this->invoiceOf('100.00', $this->customerId);
        $this->invoiceOf('40.00', $customer, [$this->payment(lang('Sales.cash'), '15.00')]);
        $this->creditNoteOf($invoice, '10.00', $this->customerId);

        $purchase = $this->purchaseOf('300.00');
        $this->debitNoteOf($purchase, '60.00');
        $this->payPurchase($purchase, '100.00');
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '10', '50')], $supplier));

        $this->workInEveryBranch();
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '4', '0', '0', '0', PERCENT, $depot), $this->purchaseLine($item, 1, '-4', '0')], null, null));

        $this->workInBranches($this->second);
        $this->invoiceOf('70.00', $this->customerId, [$this->payment(lang('Sales.cash'), '20.00')]);
        $cancelled = $this->save([$this->line($this->item('0', false), 0, '1', '55.00')], [], [$this->payment(lang('Sales.cash'), '55.00')], SALE_TYPE_POS, $customer);

        $this->assertTrue($this->sales->delete($cancelled, false, true, $this->employeeId));

        return ['treasury' => $there, 'depot' => $depot, 'item' => $item, 'other' => $customer, 'supplier' => $supplier];
    }
}
