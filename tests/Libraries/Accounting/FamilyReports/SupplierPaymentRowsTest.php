<?php

namespace Tests\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\FamilyReports\SupplierPaymentRows;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\SupplierAdvances;
use Tests\Support\FamilyReportTestCase;

/**
 * Every payment to a supplier that stands in the books, and the sources of the journals that move the
 * treasuries and banks for them.
 *
 * @internal
 */
final class SupplierPaymentRowsTest extends FamilyReportTestCase
{
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

    public function testCashSources_AddsTheJournalOfTheTreasuryOfAnotherBranchToTheSourcesOfThePayments(): void
    {
        $sources = SupplierPaymentRows::cashSources();

        foreach (SupplierPaymentRows::SOURCES as $source) {
            $this->assertContains($source, $sources);
        }

        $this->assertContains('supplier_payment_branch', $sources);
        $this->assertNotContains('sale_payment_branch', $sources);
    }
}
