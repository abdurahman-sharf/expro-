<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\PurchaseCostReclass;
use App\Libraries\Accounting\ReceivingPostingBuilder;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

class PurchaseCostReclassTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use SaleAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private PurchaseCostReclass $reclass;
    private string $today;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBuying();
        $this->reclass = new PurchaseCostReclass();
        $this->today   = $this->databaseToday();
    }

    protected function tearDown(): void
    {
        $this->stopBuying();

        parent::tearDown();
    }

    /**
     * A purchase whose journal put a line on the cost of sales, as purchases of lines not kept in
     * stock were posted before they went to the account of purchases.
     */
    private function purchaseOnTheCostOfSales(string $amount, ?string $date = null): int
    {
        $receivingId = $this->purchaseOf('10.00');

        return (new PostingService())->post(new JournalDraft(
            date: $date ?? $this->today,
            lines: [new JournalLineDraft($this->accountId('cogs'), debit: $amount), new JournalLineDraft($this->supplierAccount(), credit: $amount)],
            sourceType: ReceivingPostingBuilder::SOURCE_PURCHASE,
            sourceId: $receivingId,
            revision: 2,
            employeeId: 1,
            branchId: 1,
        ))->journalId;
    }

    private function balanceOf(string $systemKey): string
    {
        $db  = $this->accountingDb();
        $row = $db->query(
            'SELECT COALESCE(SUM(l.debit), 0) - COALESCE(SUM(l.credit), 0) AS balance
             FROM ' . $db->prefixTable('accounting_journal_lines') . ' l
             JOIN ' . $db->prefixTable('accounting_journals') . " j ON j.journal_id = l.journal_id
             WHERE j.status IN ('posted', 'reversed') AND l.account_id = ?",
            [$this->accountId($systemKey)],
        )->getRow();

        return bcadd((string) $row->balance, '0', 2);
    }

    public function testAffected_ListsThePurchaseJournalsThatDebitTheCostOfSalesAndNoOther(): void
    {
        $this->purchaseOf('500.00');
        $journalId = $this->purchaseOnTheCostOfSales('80.00');

        $affected = $this->reclass->affected($this->today);

        $this->assertSame([$journalId], array_column($affected, 'journal_id'));
        $this->assertSame(['80.0000', true, 1], [$affected[0]['amount'], $affected[0]['in_open_year'], $affected[0]['branch_id']]);
    }

    public function testAffected_MarksAJournalDatedOutsideTheYearOpenTodayAsNotInIt(): void
    {
        $lastYear = date('Y-m-d', strtotime($this->today . ' -1 year'));
        $this->accountingDb()->table('accounting_fiscal_years')->where('start_date >', $lastYear)->orWhere('end_date <', $lastYear)->countAllResults() > 0
            ? $this->purchaseOnTheCostOfSales('80.00', $lastYear)
            : $this->markTestSkipped('The fixture holds one fiscal year over both days.');

        $this->assertFalse($this->reclass->affected($this->today)[0]['in_open_year']);
        $this->assertSame([], $this->reclass->pending($this->today));
    }

    public function testPending_AddsUpWhatTheJournalsOfTheOpenYearPutOnTheCostOfSalesForEachBranch(): void
    {
        $this->purchaseOnTheCostOfSales('80.00');
        $this->purchaseOnTheCostOfSales('20.50');

        $this->assertSame([1 => '100.5000'], $this->reclass->pending($this->today));
    }

    public function testPost_MovesWhatIsPendingToPurchasesInOneJournalDatedTodayAndLeavesTheOldJournalsAsTheyWere(): void
    {
        $old    = $this->purchaseOnTheCostOfSales('80.00');
        $before = $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $old)->orderBy('line_no')->get()->getResultArray();

        $posted = $this->reclass->post($this->today, 1);

        $journal = $this->accountingDb()->table('accounting_journals')->where('journal_id', $posted[1])->get()->getRow();

        $this->assertSame([PurchaseCostReclass::SOURCE, $this->today, 'posted', '80.0000'], [$journal->source_type, $journal->journal_date, $journal->status, $journal->total_debit]);
        $this->assertSame(['0.00', '80.00'], [$this->balanceOf('cogs'), $this->balanceOf('purchases')]);
        $this->assertSame($before, $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $old)->orderBy('line_no')->get()->getResultArray());
    }

    public function testPost_MovesOnlyWhatWasNotMovedYetWhenRunAgain(): void
    {
        $this->purchaseOnTheCostOfSales('80.00');
        $this->reclass->post($this->today, 1);

        $this->assertSame([], $this->reclass->pending($this->today));
        $this->assertSame([], $this->reclass->post($this->today, 1));

        $this->purchaseOnTheCostOfSales('15.00');

        $this->assertSame([1 => '15.0000'], $this->reclass->pending($this->today));

        $this->reclass->post($this->today, 1);

        $this->assertSame(['0.00', '95.00'], [$this->balanceOf('cogs'), $this->balanceOf('purchases')]);
    }
}
