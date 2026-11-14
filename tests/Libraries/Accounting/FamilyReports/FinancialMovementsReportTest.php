<?php

namespace Tests\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\FamilyReports\FinancialMovementsReport;
use App\Libraries\Accounting\FamilyReports\SupplierPaymentRows;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Roles\RoleService;
use Tests\Support\FamilyReportTestCase;

/**
 * What came into the treasuries and the banks and what went out of them by the documents of trade,
 * with the balance after each movement, the manual journals left out, the opening balance, the closing
 * balance and the payments to suppliers set beside the report of the payments to suppliers.
 *
 * @internal
 */
final class FinancialMovementsReportTest extends FamilyReportTestCase
{
    private FinancialMovementsReport $report;

    protected function setUp(): void
    {
        parent::setUp();

        $this->report = new FinancialMovementsReport();
    }

    private function movements(): void
    {
        $item = $this->item('0.00');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '1', '100.00')], -1, lang('Sales.cash')));
        $this->payPurchase($this->purchaseOf('200.00'), '80.00', lang('Sales.cash'));
        $this->assertGreaterThan(0, $this->save([$this->line($this->item('0', false), 0, '1', '60.00')], [], [$this->payment(lang('Sales.cash'), '60')]));
    }

    public function testBuild_ListsThePaymentsAndReceiptsOfTheTreasuriesWithTheirRunningBalance(): void
    {
        $this->movements();

        $table = $this->built($this->report, $this->thisDay());
        $rows  = $this->rowsOf($table);

        $moves = array_values(array_filter($rows, static fn ($row): bool => $row->cell('date') !== ''));

        $this->assertCount(3, $moves);
        $this->assertAmount('60.00', $this->rowWhere($table, 'description', lang('Accounting_family_reports.total_income'))->cell('amount'));
        $this->assertAmount('-180.00', $this->rowWhere($table, 'description', lang('Accounting_family_reports.total_expenses'))->cell('amount'));
        $this->assertAmount('-120.00', $this->rowWhere($table, 'description', lang('Accounting_family_reports.closing_balance'))->cell('balance'));
        $this->assertAmount('0.00', $this->rowWhere($table, 'description', lang('Accounting_family_reports.opening_balance'))->cell('balance'));
        $this->assertAmount('-120.00', $moves[2]->cell('balance'));
    }

    public function testBuild_LeavesOutManualJournalsAndTransfersBetweenTreasuries(): void
    {
        $this->movements();

        (new PostingService())->post(new JournalDraft(
            date: $this->today(),
            lines: [new JournalLineDraft($this->accountId('cash'), debit: '500.00'), new JournalLineDraft($this->accountId('opening_balance'), credit: '500.00')],
            sourceType: JournalDraft::SOURCE_MANUAL,
            description: 'Capital',
            currencyCode: 'SAR',
            branchId: 1,
        ));

        $table = $this->built($this->report, $this->thisDay());

        $this->assertAmount('-120.00', $this->rowWhere($table, 'description', lang('Accounting_family_reports.closing_balance'))->cell('balance'));
    }

    public function testBuild_CarriesWhatTheSameDocumentsMovedBeforeTheFirstDayAsTheOpeningBalance(): void
    {
        $this->movements();

        $table = $this->built($this->report, ['from' => date('Y-m-d', strtotime('+1 day')), 'to' => date('Y-m-d', strtotime('+2 days'))]);

        $this->assertAmount('-120.00', $this->rowWhere($table, 'description', lang('Accounting_family_reports.opening_balance'))->cell('balance'));
        $this->assertAmount('-120.00', $this->rowWhere($table, 'description', lang('Accounting_family_reports.closing_balance'))->cell('balance'));
    }

    public function testBuild_TiesTheClosingBalanceAndTheSupplierPaymentsToTheReportOfThem(): void
    {
        $this->movements();

        $table = $this->built($this->report, $this->thisDay());

        $this->assertCount(2, $table->ties);
        $this->assertReportTies($table);
        $this->assertAmount('-120.00', $table->ties[0]->book);
        $this->assertAmount('180.00', $table->ties[1]->book);
    }

    public function testBuild_ComparesTheSupplierPaymentsForAReaderOfEveryonesRecordsAndNotForOneOfTheirOwn(): void
    {
        $author = $this->employeeId;

        $this->movements();
        $this->workInEveryBranch();

        (new RoleService())->setRecordScope($author, 'own', null);

        $own      = $this->built($this->report, $this->thisDay(), null, $author);
        $everyone = $this->built($this->report, $this->thisDay(), null, $this->employeeId);

        $this->assertCount(1, $own->ties);
        $this->assertCount(2, $everyone->ties);
        $this->assertReportTies($everyone);
    }

    public function testBuild_CountsAPaymentOutOfATreasuryOfAnotherBranchOnceThroughTheJournalOfThatBranch(): void
    {
        $branch   = $this->createBranch('FM3', 'Far treasury branch');
        $treasury = (new TreasuryService())->create(['name' => 'Far drawer', 'kind' => 'cash', 'branch_id' => $branch]);

        $this->workInBranches(1, $branch);

        $id = $this->purchaseOf('300.00');

        $this->assertTrue($this->purchases->update($id, ['comment' => 'paid far', 'payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '100.00', 'employee_id' => $this->employeeId, 'payment_status' => 'completed', 'reference_code' => '', 'treasury_id' => $treasury],
        ]]));

        $table = $this->built($this->report, $this->thisDay());

        $this->assertCount(2, $table->ties);
        $this->assertReportTies($table);
        $this->assertAmount('-100.00', $table->ties[0]->book);
        $this->assertAmount('100.00', $table->ties[1]->book);
    }

    public function testBuild_ReadsOnlyTheBranchesOfTheScope(): void
    {
        $this->movements();

        $branch = $this->createBranch('FM2', 'Movement branch');
        $item   = $this->item('0.00');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '1', '35.00', '0', '0', PERCENT, $this->locationOfBranch($branch))], -1, lang('Sales.cash')));

        $limited = $this->built($this->report, $this->thisDay(), $this->limitedTo($branch));

        $this->assertAmount('-35.00', $this->rowWhere($limited, 'description', lang('Accounting_family_reports.closing_balance'))->cell('balance'));
        $this->assertCount(1, $limited->ties);
    }

    public function testBuild_NamesThePaymentInDollarsInTheCheckOfThePaymentsAndStillTiesTheClosingBalance(): void
    {
        $this->enableDollars();
        $purchase = $this->foreignPurchase();
        $payment  = $this->payPurchase($purchase, '100.00');
        $range    = ['from' => $this->ago(30), 'to' => date('Y-m-d', strtotime('+1 day'))];

        $table = $this->built($this->report, $range);
        $named = array_values(array_filter((new SupplierPaymentRows())->read($this->everyBranch(), $this->input($range)->period()), static fn (object $row): bool => $row->currency_code === 'USD'));

        $this->assertCount(2, $table->ties);
        $this->assertFalse($table->ties[0]->isRefused());
        $this->assertTrue($table->ties[0]->ties(), 'the closing balance is the treasuries in the base, whatever the currency of the payment');
        $this->assertTrue($table->ties[1]->isRefused());
        $this->assertSame(lang('Accounting_family_reports.tie_foreign_currency', [$named[0]->number . ' (USD)']), $table->ties[1]->refusal);
        $this->assertSame((string) $payment, (string) $named[0]->source_id);
    }

    public function testBuild_ComparesThePaymentsWhenNoneIsInAForeignCurrency(): void
    {
        $this->enableDollars();
        $this->assertGreaterThan(0, $this->foreignPurchase());
        $this->movements();

        $table = $this->built($this->report, ['from' => $this->ago(1), 'to' => date('Y-m-d', strtotime('+1 day'))]);

        $this->assertFalse($table->ties[1]->isRefused());
    }
}
