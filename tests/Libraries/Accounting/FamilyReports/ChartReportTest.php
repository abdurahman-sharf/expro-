<?php

namespace Tests\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\FamilyReports\ChartReport;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use Tests\Support\FamilyReportTestCase;

/**
 * The chart of accounts as a list, cut down by level, nature and the exclusion of the accounts of
 * customers and suppliers, in the order of the codes, the same for every branch, and with balances the
 * accounts adding up to nothing.
 *
 * @internal
 */
final class ChartReportTest extends FamilyReportTestCase
{
    private ChartReport $report;

    protected function setUp(): void
    {
        parent::setUp();

        $this->report = new ChartReport();
    }

    public function testBuild_ListsTheCodeTheNameTheNatureAndTheLevelOfEachAccount(): void
    {
        $table = $this->built($this->report);

        $this->assertGreaterThan(60, $table->rowCount());
        $this->assertSame(['code', 'name', 'nature', 'level', 'state'], array_map(static fn ($column): string => $column->key, $table->columns));
        $this->assertSame([], $table->ties);

        $codes = $this->columnOf($table, 'code');
        $sorted = $codes;
        sort($sorted, SORT_STRING);

        $this->assertSame($sorted, $codes);
    }

    public function testBuild_FiltersByLevelNatureAndTheExclusionOfCustomersAndSuppliers(): void
    {
        $this->supplier('Excluded supplier');

        $all   = $this->built($this->report)->rowCount();
        $main  = $this->built($this->report, ['level' => 'main'])->rowCount();
        $sub   = $this->built($this->report, ['level' => 'sub'])->rowCount();
        $debit = $this->built($this->report, ['nature' => 'debit'])->rowCount();
        $credit = $this->built($this->report, ['nature' => 'credit'])->rowCount();

        $this->assertSame($all, $main + $sub);
        $this->assertSame($all, $debit + $credit);

        $names = $this->columnOf($this->built($this->report, ['exclude' => 'both']), 'name');

        $this->assertNotContains('Excluded supplier', $names);
        $this->assertContains('Excluded supplier', $this->columnOf($this->built($this->report), 'name'));
        $this->assertContains('Excluded supplier', $this->columnOf($this->built($this->report, ['exclude' => 'customers']), 'name'));
        $this->assertNotContains('Excluded supplier', $this->columnOf($this->built($this->report, ['exclude' => 'suppliers']), 'name'));
        $this->assertSame(array_reverse($this->columnOf($this->built($this->report), 'code')), $this->columnOf($this->built($this->report, ['order' => 'desc']), 'code'));
    }

    public function testBuild_IsTheSameForEveryBranch(): void
    {
        $this->createBranch('CH2', 'Chart branch');

        $this->assertSame($this->built($this->report)->rowCount(), $this->built($this->report, [], $this->limitedTo(1))->rowCount());
    }

    public function testBuild_WithBalancesShowsWhatTheJournalsOfTheBranchesPutOnEachAccountAndTheyAddUpToNothing(): void
    {
        $branch = $this->createBranch('CH3', 'Balance chart branch');

        $this->purchaseOf('230.00');
        (new PostingService())->post(new JournalDraft(
            date: $this->today(),
            lines: [new JournalLineDraft($this->accountId('cash'), debit: '40.00'), new JournalLineDraft($this->accountId('opening_balance'), credit: '40.00')],
            sourceType: JournalDraft::SOURCE_MANUAL,
            description: 'Cash',
            currencyCode: 'SAR',
            branchId: $branch,
        ));

        (new PostingService())->post(new JournalDraft(
            date: $this->today(),
            lines: [new JournalLineDraft($this->accountId('cash'), debit: '25.00'), new JournalLineDraft($this->accountId('opening_balance'), credit: '25.00')],
            sourceType: JournalDraft::SOURCE_MANUAL,
            description: 'First branch cash',
            currencyCode: 'SAR',
            branchId: 1,
        ));

        $all     = $this->built($this->report, ['balances' => '1']);
        $limited = $this->built($this->report, ['balances' => '1'], $this->limitedTo($branch));
        $cash    = (string) $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->accountId('cash'))->get()->getRow()->code;

        $this->assertReportTies($all);
        $this->assertReportTies($limited);
        $this->assertAmount('40.00', $this->rowWhere($limited, 'code', $cash)->cell('balance'));
        $this->assertAmount('65.00', $this->rowWhere($all, 'code', $cash)->cell('balance'));
        $this->assertContains('balance', array_map(static fn ($column): string => $column->key, $all->columns));
    }
}
