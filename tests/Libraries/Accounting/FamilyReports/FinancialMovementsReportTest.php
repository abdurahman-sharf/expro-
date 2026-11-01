<?php

namespace Tests\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\FamilyReports\FinancialMovementsReport;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
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
        $this->assertAmount('180.00', $table->ties[1]->book);
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
}
