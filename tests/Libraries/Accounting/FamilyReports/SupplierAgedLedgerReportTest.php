<?php

namespace Tests\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\FamilyReports\SupplierAgedLedgerReport;
use Tests\Support\FamilyReportTestCase;

/**
 * What is owed to each supplier on a day in six buckets, from the aging of the payables screen, with a
 * length of bucket that can change, and the buckets less the credit set beside the accounts of the
 * suppliers in the branches read.
 *
 * @internal
 */
final class SupplierAgedLedgerReportTest extends FamilyReportTestCase
{
    private SupplierAgedLedgerReport $report;

    protected function setUp(): void
    {
        parent::setUp();

        $this->report = new SupplierAgedLedgerReport();
    }

    private function ago(int $days): string
    {
        return date('Y-m-d', strtotime("-{$days} days"));
    }

    public function testBuild_PutsWhatIsOwedInTheBucketOfTheAgeOfEachPurchase(): void
    {
        $this->purchaseDated('100.00', $this->ago(40));
        $this->purchaseDated('70.00', $this->ago(0));

        $table = $this->built($this->report, ['to' => $this->today()]);
        $row   = $this->rowsOf($table)[0];

        $this->assertAmount('70.00', $row->cell('b0'));
        $this->assertAmount('100.00', $row->cell('b2'));
        $this->assertAmount('170.00', $row->cell('total'));
        $this->assertAmount('170.00', $row->cell('balance'));
        $this->assertReportTies($table);
    }

    public function testBuild_ChangesTheLengthOfTheBucketsAndTheDayOfTheReport(): void
    {
        $this->purchaseDated('100.00', $this->ago(7));

        $thirty = $this->built($this->report, ['to' => $this->today()]);
        $two    = $this->built($this->report, ['to' => $this->today(), 'period' => '2']);
        $later  = $this->built($this->report, ['to' => date('Y-m-d', strtotime('+60 days'))]);

        $this->assertAmount('100.00', $this->rowsOf($thirty)[0]->cell('b1'));
        $this->assertAmount('100.00', $this->rowsOf($two)[0]->cell('b4'));
        $this->assertAmount('100.00', $this->rowsOf($later)[0]->cell('b3'));
        $this->assertStringContainsString('1 - 2', $two->columns[4]->label);
    }

    public function testBuild_AppliesADebitNoteToTheOldestPurchaseFirstAndKeepsTheCreditApart(): void
    {
        $old   = $this->purchaseDated('100.00', $this->ago(40));
        $this->purchaseDated('50.00', $this->ago(0));
        $this->debitNoteOf($old, '30.00');

        $table = $this->built($this->report, ['to' => $this->today()]);
        $row   = $this->rowsOf($table)[0];

        $this->assertAmount('70.00', $row->cell('b2'));
        $this->assertAmount('50.00', $row->cell('b0'));
        $this->assertAmount('120.00', $row->cell('balance'));
        $this->assertReportTies($table);
    }

    public function testBuild_TiesToTheAccountOfTheSuppliersOfTheBranchesRead(): void
    {
        $item   = $this->item('0.00');
        $branch = $this->createBranch('AG2', 'Aging branch');

        $this->purchaseDated('100.00', $this->ago(10));
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '1', '40.00', '0', '0', PERCENT, $this->locationOfBranch($branch))]));

        $all     = $this->built($this->report, ['to' => $this->today()]);
        $limited = $this->built($this->report, ['to' => $this->today()], $this->limitedTo($branch));

        $this->assertAmount('140.00', $this->grandOf($all, 'balance'));
        $this->assertAmount('40.00', $this->grandOf($limited, 'balance'));
        $this->assertReportTies($all);
        $this->assertReportTies($limited);
    }

    public function testBuild_CutsDownToOneSupplierAndDoesNotCompareIt(): void
    {
        $other = $this->anotherSupplier();

        $this->purchaseOf('100.00');
        $this->purchaseOf('25.00', $other);

        $table = $this->built($this->report, ['to' => $this->today(), 'supplier_id' => (string) $other]);

        $this->assertCount(1, $this->rowsOf($table));
        $this->assertAmount('25.00', $this->grandOf($table, 'total'));
        $this->assertSame([], $table->ties);
    }

    public function testBuild_RefusesADayThatIsNotOne(): void
    {
        $this->expectException(AccountingException::class);

        $this->built($this->report, ['to' => '2026-02-31']);
    }
}
