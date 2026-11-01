<?php

namespace Tests\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\FamilyReports\SupplierBalancesReport;
use Tests\Support\FamilyReportTestCase;

/**
 * The balance of each supplier over a period and what moved it, with the returns and debit notes as one
 * column off the purchases, the balances beside the account of the suppliers on the last day, and the
 * balances before beside the account the day before the first.
 *
 * @internal
 */
final class SupplierBalancesReportTest extends FamilyReportTestCase
{
    private SupplierBalancesReport $report;

    protected function setUp(): void
    {
        parent::setUp();

        $this->report = new SupplierBalancesReport();
    }

    public function testBuild_SplitsTheBalanceIntoBeforePurchasesReturnsPaymentsAndTheBalance(): void
    {
        $ids = $this->market();

        $this->payPurchase($ids['big'], '100.00');

        $table = $this->built($this->report, $this->thisDay());
        $first = $this->rowWhere($table, 'name', 'Gulf Supplies');

        $this->assertNotNull($first);
        $this->assertAmount('345.00', $first->cell('purchases'));
        $this->assertAmount('50.00', $first->cell('returns'));
        $this->assertAmount('295.00', $first->cell('net'));
        $this->assertAmount('100.00', $first->cell('payments'));
        $this->assertAmount('195.00', $first->cell('balance'));
        $this->assertAmount('295.00', $this->grandOf($table, 'balance'));
    }

    public function testBuild_GivesTheSameNetPurchasesAsThePurchasesReport(): void
    {
        $this->market();

        $this->assertAmount('395.00', $this->grandOf($this->built($this->report, $this->thisDay()), 'net'));
    }

    public function testBuild_TiesTheBalancesAndTheBalancesBeforeToTheAccountOfTheSuppliers(): void
    {
        $this->market();

        $table = $this->built($this->report, ['from' => date('Y-m-d', strtotime('+1 day')), 'to' => date('Y-m-d', strtotime('+2 days'))]);

        $this->assertCount(2, $table->ties);
        $this->assertReportTies($table);
        $this->assertAmount('395.00', $table->ties[1]->book);
    }

    public function testBuild_ReadsOnlyTheBranchesOfTheScopeAndTiesThem(): void
    {
        $item   = $this->item('0.00');
        $branch = $this->createBranch('SB2', 'Balance branch');

        $this->purchaseOf('100.00');
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '1', '35.00', '0', '0', PERCENT, $this->locationOfBranch($branch))]));

        $limited = $this->built($this->report, $this->thisDay(), $this->limitedTo($branch));

        $this->assertAmount('35.00', $this->grandOf($limited, 'balance'));
        $this->assertAmount('135.00', $this->grandOf($this->built($this->report, $this->thisDay()), 'balance'));
        $this->assertReportTies($limited);
    }

    public function testBuild_HidesSuppliersWithNothingUnlessAskedAndRefusesABadPeriod(): void
    {
        $id = $this->purchaseOf('10.00');
        $this->debitNoteOf($id, '10.00');

        $later = ['from' => date('Y-m-d', strtotime('+1 day')), 'to' => date('Y-m-d', strtotime('+2 days'))];

        $this->assertCount(0, $this->rowsOf($this->built($this->report, $later)));
        $this->assertCount(1, $this->rowsOf($this->built($this->report, [...$later, 'empty' => '1'])));

        $this->expectException(AccountingException::class);

        $this->built($this->report, ['from' => '2026-05-02', 'to' => '2026-05-01']);
    }
}
