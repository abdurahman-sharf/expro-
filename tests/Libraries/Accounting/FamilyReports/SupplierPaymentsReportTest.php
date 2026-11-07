<?php

namespace Tests\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\FamilyReports\SupplierPaymentRows;
use App\Libraries\Accounting\FamilyReports\SupplierPaymentsReport;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\SupplierAdvances;
use App\Libraries\Roles\RoleService;
use Tests\Support\FamilyReportTestCase;

/**
 * The money paid to suppliers: when a purchase is saved, against it later and to no purchase, by
 * supplier, way of payment, employee and period, and what the journals of those payments took out of the
 * treasury and bank accounts.
 *
 * @internal
 */
final class SupplierPaymentsReportTest extends FamilyReportTestCase
{
    private SupplierPaymentsReport $report;

    protected function setUp(): void
    {
        parent::setUp();

        $this->report = new SupplierPaymentsReport();
    }

    /**
     * 100 paid when the purchase was saved, 80 of a purchase of 200 paid after, and 50 paid to no purchase.
     */
    private function payments(): void
    {
        $item = $this->item('0.00');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '1', '100.00')], -1, lang('Sales.cash')));
        $this->payPurchase($this->purchaseOf('200.00'), '80.00', lang('Sales.cash'));
        (new SupplierAdvances())->pay($this->supplierId, '50.00', $this->today(), lang('Sales.cash'), null, 'ADV-1', '', $this->employeeWithGrants([AccountingGuard::PAYABLES_MANAGE]));
    }

    public function testBuild_ListsEachPaymentWithItsKindAndTotalsAllOfThem(): void
    {
        $this->payments();

        $table = $this->built($this->report, [...$this->thisDay(), 'view' => 'detail']);
        $rows  = $this->rowsOf($table);

        $this->assertCount(3, $rows);
        $this->assertAmount('230.00', $this->grandOf($table, 'amount'));
        $this->assertSame(['50.0000', '80.0000', '100.0000'], $this->columnOf($table, 'amount'));
    }

    public function testBuild_GroupsBySupplierMethodEmployeeAndPeriodWithTheSameTotal(): void
    {
        $this->payments();

        foreach (SupplierPaymentsReport::GROUPS as $group) {
            $this->assertAmount('230.00', $this->grandOf($this->built($this->report, [...$this->thisDay(), 'group' => $group]), 'amount'), "by {$group}");
        }

        $byMethod = $this->built($this->report, [...$this->thisDay(), 'group' => 'method']);

        $this->assertCount(1, $this->rowsOf($byMethod));
        $this->assertSame('3', $this->rowsOf($byMethod)[0]->cell('count'));
    }

    public function testBuild_TiesTheTotalToWhatTheJournalsTookOutOfTheTreasuryAndBankAccounts(): void
    {
        $this->payments();

        $table = $this->built($this->report, $this->thisDay());

        $this->assertReportTies($table);
        $this->assertAmount('230.00', $table->ties[0]->book);
    }

    public function testBuild_DoesNotListACancelledPaymentNorTheCreditAppliedFromTheBalance(): void
    {
        $this->payments();

        $id = $this->purchaseOf('60.00');
        $paid = $this->payPurchase($id, '60.00', lang('Sales.cash'));
        $this->assertTrue($this->changePayment($id, $paid, ['payment_amount' => '0']));

        $table = $this->built($this->report, $this->thisDay());

        $this->assertAmount('230.00', $this->grandOf($table, 'amount'));
    }

    public function testBuild_ShowsWhatASupplierGaveBackAsNegative(): void
    {
        $item = $this->item('0.00');
        $id   = $this->buy([$this->purchaseLine($item, 0, '1', '100.00')], -1, lang('Sales.cash'));

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '-0.4', '100.00')], -1, lang('Sales.cash'), $id));

        $table = $this->built($this->report, [...$this->thisDay(), 'view' => 'detail']);

        $this->assertAmount('60.00', $this->grandOf($table, 'amount'));
        $this->assertContains('-40.0000', $this->columnOf($table, 'amount'));
        $this->assertReportTies($table);
    }

    public function testBuild_ReadsOnlyTheBranchesOfTheScopeAndComparesOnlyTheWholeCompany(): void
    {
        $this->payments();

        $branch = $this->createBranch('PY2', 'Payment branch');
        $item   = $this->item('0.00');
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '1', '45.00', '0', '0', PERCENT, $this->locationOfBranch($branch))], -1, lang('Sales.cash')));

        $all     = $this->built($this->report, $this->thisDay());
        $limited = $this->built($this->report, $this->thisDay(), $this->limitedTo($branch));
        $first   = $this->built($this->report, $this->thisDay(), $this->limitedTo(1));

        $this->assertAmount('275.00', $this->grandOf($all, 'amount'));
        $this->assertAmount('45.00', $this->grandOf($limited, 'amount'));
        $this->assertAmount('230.00', $this->grandOf($first, 'amount'));
        $this->assertSame([], $limited->ties);
        $this->assertReportTies($all);
    }

    public function testRead_CutsDownToASupplierAndAWayOfPayment(): void
    {
        $this->payments();

        $rows = new SupplierPaymentRows();
        $day  = ReportPeriod::of($this->today(), $this->today());

        $this->assertCount(3, $rows->read($this->everyBranch(), $day));
        $this->assertCount(0, $rows->read($this->everyBranch(), $day, null, ['supplier_id' => $this->anotherSupplier()]));
        $this->assertCount(0, $rows->read($this->everyBranch(), $day, null, ['payment_type' => 'No such way']));
        $this->assertCount(3, $rows->read($this->everyBranch(), $day, null, ['payment_type' => lang('Sales.cash')]));
    }

    public function testBuild_ReadsOnlyThePaymentsOfAViewerWhoSeesTheirOwnRecords(): void
    {
        $author = $this->employeeId;

        $this->payments();
        $this->workInEveryBranch();

        $other = $this->employeeId;

        $this->payPurchase($this->purchaseOf('300.00'), '70.00', lang('Sales.cash'));

        (new RoleService())->setRecordScope($author, 'own', null);

        $own      = $this->built($this->report, $this->thisDay(), null, $author);
        $everyone = $this->built($this->report, $this->thisDay(), null, $other);

        $this->assertAmount('180.00', $this->grandOf($own, 'amount'));
        $this->assertAmount('300.00', $this->grandOf($everyone, 'amount'));
    }
}
