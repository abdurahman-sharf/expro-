<?php

namespace Tests\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\Currencies;
use App\Libraries\Accounting\FamilyReports\PurchasePaymentsReport;
use App\Libraries\Roles\RoleService;
use Tests\Support\FamilyReportTestCase;

/**
 * For each purchase and return what it comes to, what was paid and what is left to pay, with the credit
 * a supplier holds shown apart, and what is left less that credit set beside the accounts of the
 * suppliers.
 *
 * @internal
 */
final class PurchasePaymentsReportTest extends FamilyReportTestCase
{
    private PurchasePaymentsReport $report;

    protected function setUp(): void
    {
        parent::setUp();

        $this->report = new PurchasePaymentsReport();
    }

    public function testBuild_ShowsTheNetThePaidAndTheRemainingOfEachDocument(): void
    {
        $ids = $this->market();

        $this->payPurchase($ids['big'], '30.00');

        $table = $this->built($this->report, $this->thisDay());
        $big   = $this->rowWhere($table, 'number', 'RECV ' . $ids['big']);

        $this->assertNotNull($big);
        $this->assertAmount('230.00', $big->cell('net'));
        $this->assertAmount('30.00', $big->cell('paid'));
        $this->assertAmount('200.00', $big->cell('remaining'));

        $note = $this->rowWhere($table, 'number', 'RECV ' . $ids['note']);

        $this->assertNotNull($note);
        $this->assertAmount('-50.00', $note->cell('net'));
        $this->assertAmount('0.00', $note->cell('remaining'));
    }

    public function testBuild_DoesNotTakeTheCreditOfASupplierOffWhatIsLeftAndShowsItApart(): void
    {
        $ids = $this->market();

        $this->payPurchase($ids['other'], '130.00');

        $table = $this->built($this->report, ['to' => $this->today()]);
        $other = $this->rowWhere($table, 'number', 'RECV ' . $ids['other']);

        $this->assertNotNull($other);
        $this->assertAmount('0.00', $other->cell('remaining'));
        $this->assertAmount('130.00', $other->cell('paid'));
        $this->assertStringContainsString(to_currency_no_money('30'), implode(' ', $table->notes));
    }

    public function testBuild_RejectsTheCheckAgainstTheAccountsByNameWhenAPurchaseIsInAForeignCurrency(): void
    {
        $ids = $this->market();

        (new Currencies())->setActive('USD', true);
        $this->accountingDb()->table('receivings')->where('receiving_id', $ids['other'])->update(['currency_code' => 'USD', 'currency_rate' => '3.75000000']);

        $table = $this->built($this->report, ['to' => $this->today()]);

        $this->assertCount(1, $table->ties);
        $this->assertTrue($table->ties[0]->isRefused());
        $this->assertSame(lang('Accounting_family_reports.tie_foreign_currency', ['RECV ' . $ids['other'] . ' (USD)']), $table->ties[0]->refusal);
    }

    public function testBuild_TiesWhatIsLeftLessTheCreditToTheAccountsOfTheSuppliers(): void
    {
        $ids = $this->market();

        $this->payPurchase($ids['big'], '230.00');
        $this->payPurchase($ids['other'], '130.00');

        $table = $this->built($this->report, ['to' => $this->today()]);

        $this->assertReportTies($table);
        $this->assertAmount('35.00', $table->ties[0]->book);
    }

    public function testBuild_ComparesWithTheAccountsOnlyOverAllDatesAndForTheWholeCompany(): void
    {
        $this->market();
        $this->createBranch('PO3', 'Third branch');

        $this->assertNotSame([], $this->built($this->report, ['to' => $this->today()])->ties);
        $this->assertSame([], $this->built($this->report, $this->thisDay())->ties);
        $this->assertSame([], $this->built($this->report, ['to' => $this->today()], $this->limitedTo(1))->ties);
    }

    public function testBuild_ComparesWithTheAccountsForAReaderOfEveryonesRecordsAndNotForOneOfTheirOwn(): void
    {
        $author = $this->employeeId;

        $this->market();
        $this->workInEveryBranch();

        $other = $this->employeeId;

        (new RoleService())->setRecordScope($author, 'own', null);

        $own      = $this->built($this->report, ['to' => $this->today()], null, $author);
        $everyone = $this->built($this->report, ['to' => $this->today()], null, $other);

        $this->assertSame([], $own->ties);
        $this->assertStringContainsString(lang('Accounting_family_reports.note_remaining_not_compared'), implode(' ', $own->notes));
        $this->assertReportTies($everyone);
    }

    public function testBuild_ReadsOnlyTheBranchesOfTheScope(): void
    {
        $this->market();

        $branch = $this->createBranch('PO2', 'Open branch');
        $item   = $this->item('0.00');
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '1', '90.00', '0', '0', PERCENT, $this->locationOfBranch($branch))]));

        $limited = $this->built($this->report, $this->thisDay(), $this->limitedTo($branch));

        $this->assertAmount('90.00', $this->grandOf($limited, 'net'));
        $this->assertAmount('90.00', $this->grandOf($limited, 'remaining'));
        $this->assertCount(1, $this->rowsOf($limited));
    }
}
