<?php

namespace Tests\Libraries\Accounting\Backfill;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\Backfill\BackfillBatches;
use App\Libraries\Accounting\Backfill\BackfillFamily;
use App\Libraries\Accounting\Backfill\BackfillStatus;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\BackfillFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * @internal
 */
final class BackfillBatchesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use BackfillFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use SaleAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private BackfillBatches $batches;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBackfill();

        $this->batches = new BackfillBatches($this->accountingDb());
    }

    protected function tearDown(): void
    {
        $this->stopBackfill();

        parent::tearDown();
    }

    private function refusedDocuments(): void
    {
        $this->legacyExpense('100', '0', '2019-03-14');
        $this->legacyExpense('50', '0', '2019-04-01');
        $this->legacyExpense('70', '0', '2040-01-05');
        $this->legacyExpense('900', '0', '2037-06-01');
        $this->legacyInvoice('2026-03-10', [$this->payment(lang('Sales.cash'), '816.50')]);
        $this->accountingDb()->table('customers')->where('person_id', $this->customerId)->update(['deleted' => 1]);
    }

    public function testRefusalClasses_GroupsTheRefusedDocumentsByReasonWithTheirCountAndWhatTheyComeTo(): void
    {
        $this->refusedDocuments();

        $classes = [];

        foreach ($this->batches->refusalClasses($this->previewed(['from' => '2019-01-01', 'to' => '2040-12-31'])->id) as $class) {
            $classes[$class['reason']] = [$class['documents'], $class['net'], $class['gross']];
        }

        $this->assertSame([
            'before_start_date'   => [2, '150.0000', '150.0000'],
            'outside_fiscal_year' => [2, '970.0000', '970.0000'],
            'party_missing'       => [1, '816.5000', '816.5000'],
        ], $classes);
    }

    public function testRefusalClasses_TellsTheReasonsOfThePostingApartAndLeavesTheReadyDocumentsOut(): void
    {
        $this->legacyExpense('100', '0', '2026-03-12');

        $batch = $this->previewed();

        $this->assertSame([], $this->batches->refusalClasses($batch->id));

        $this->accountingDb()->table('accounting_backfill_items')->where('batch_id', $batch->id)->update(['outcome' => 'refused', 'reason' => 'posting_refused', 'detail' => 'credit_needs_customer', 'total_debit' => '-30.0000']);

        $this->assertSame([['reason' => 'posting_refused', 'kind' => 'credit_needs_customer', 'documents' => 1, 'net' => '-30.0000', 'gross' => '30.0000']], $this->batches->refusalClasses($batch->id));
    }

    public function testFigures_SumTheDebitOfTheReadyDocumentsAndNotTheWorthOfTheRefusedOnes(): void
    {
        $this->legacyExpense('100', '0', '2026-03-12');
        $this->legacyExpense('50', '0', '2019-04-01');

        $batch   = $this->previewed(['from' => '2019-01-01', 'to' => '2026-12-31']);
        $figures = $this->batches->figures($batch->id);

        $this->assertSame([2, 1, 1, '100.0000'], [$figures['documents'], $figures['ready'], $figures['refused'], $figures['debit']]);
        $this->assertSame('100.0000', $batch->totalDebit);
        $this->assertSame('50.0000', $this->batches->refusedItems($batch->id)[0]->total_debit);
    }

    public function testRefusedItems_ListsOnlyTheRefusedDocumentsInTheOrderTheyWereLookedAt(): void
    {
        $this->refusedDocuments();

        $items = $this->batches->refusedItems($this->previewed(['from' => '2019-01-01', 'to' => '2040-12-31'])->id);

        $this->assertSame(['party_missing', 'before_start_date', 'before_start_date', 'outside_fiscal_year', 'outside_fiscal_year'], array_column($items, 'reason'));
        $this->assertSame(['sales', 'expenses'], array_values(array_unique(array_column(array_slice($items, 0, 2), 'family'))));
        $this->assertSame('HQ', $items[0]->branch_code);
    }

    public function testOutsideFiscalYears_GivesTheCountAndTheFirstAndLastDayOrNothing(): void
    {
        $this->legacyExpense('100', '0', '2026-03-12');

        $clean = $this->previewed();

        $this->assertNull($this->batches->outsideFiscalYears($clean->id));

        $this->backfill->cancel($clean->id, $this->backfillManager);
        $this->refusedDocuments();

        $outside = $this->batches->outsideFiscalYears($this->previewed(['name' => 'Far', 'from' => '2019-01-01', 'to' => '2040-12-31'])->id);

        $this->assertSame(['documents' => 2, 'from' => '2037-06-01', 'to' => '2040-01-05'], $outside);
    }

    public function testVerifiedCount_CountsTheDocumentsOfTheFamiliesBeforeTheCursorAndTheFamilyAtItUpToIt(): void
    {
        $first  = $this->legacyExpense('100', '0', '2026-03-12');
        $second = $this->legacyExpense('200', '0', '2026-03-13');
        $this->legacyInvoice('2026-03-10');

        $batch = $this->previewed(['chunk_size' => 1]);

        $this->assertSame(3, $batch->documentCount);

        $db = $this->accountingDb();

        $db->table('accounting_backfill_batches')->where('batch_id', $batch->id)->update(['status' => 'verifying', 'cursor_family' => 'expenses', 'cursor_id' => $first]);

        $this->assertSame(2, $this->batches->verifiedCount($this->backfill->batch($batch->id, $this->backfillManager)));

        $db->table('accounting_backfill_batches')->where('batch_id', $batch->id)->update(['cursor_id' => $second]);

        $this->assertSame(3, $this->batches->verifiedCount($this->backfill->batch($batch->id, $this->backfillManager)));
    }

    public function testAccountTotals_GivesTheBalanceWithoutTheBatchWhatItAddsAndTheBalanceWithIt(): void
    {
        $this->legacyExpense('230', '30', '2026-03-12');

        $batch = $this->previewed();
        $rows  = [];

        foreach ($this->batches->accountTotals($batch) as $row) {
            $rows[$row->code] = [$row->before_balance, $row->debit, $row->credit, $row->after_balance];
        }

        $this->assertSame(['1000.0000', '0.0000', '230.0000', '770.0000'], $rows['120101']);
        $this->assertSame(['0.0000', '200.0000', '0.0000', '200.0000'], $rows['541']);
        $this->assertCount(3, $rows);
    }

    public function testAccountTotals_GivesTheSameFiguresOnceTheBatchIsApprovedAndLeavesTheOtherBranchesOut(): void
    {
        $other = $this->createBranch('B2', 'Second branch');

        $this->postOpening($other, '500.00');
        $this->legacyExpense('230', '30', '2026-03-12', 1);
        $this->legacyExpense('100', '0', '2026-03-12', $other);

        $batch  = $this->previewed(['branch_id' => 1]);
        $before = $this->batches->accountTotals($batch);

        $this->approved($batch);

        $after = $this->batches->accountTotals($this->backfill->batch($batch->id, $this->backfillManager));

        $this->assertEquals($before, $after);
        $this->assertSame('1000.0000', $before[0]->before_balance, 'The opening balances of the other branch are not in the figures of this one');
    }

    public function testItems_PagesAndFiltersTheDocumentsOfABatchByTheirResult(): void
    {
        $this->legacyExpense('100', '0', '2026-03-12');
        $this->legacyExpense('200', '0', '2026-03-13');
        $this->legacyExpense('300', '0', '2019-03-14');

        $batch = $this->previewed(['from' => '2019-01-01']);

        $this->assertSame(3, $this->batches->items($batch->id, null, 10, 0)['total']);
        $this->assertSame(2, $this->batches->items($batch->id, 'ready', 10, 0)['total']);
        $this->assertSame(1, $this->batches->items($batch->id, 'refused', 10, 0)['total']);
        $this->assertCount(1, $this->batches->items($batch->id, null, 1, 1)['rows']);
        $this->assertSame(['before_start_date' => 1], $this->batches->reasons($batch->id));
    }

    public function testFigures_CountsDocumentsJournalsAndTheDebitTotal(): void
    {
        $this->legacyInvoice('2026-03-10', [$this->payment(lang('Sales.cash'), '816.50')]);
        $this->legacyExpense('230', '30', '2026-03-12');

        $figures = $this->batches->figures($this->previewed()->id);

        $this->assertSame(['documents' => 2, 'ready' => 2, 'refused' => 0, 'journals' => 4], array_diff_key($figures, ['debit' => 1]));
        $this->assertSame('2263.0000', $figures['debit']);
    }

    public function testJournalsAndLines_ListTheJournalsOfABatchWithTheirLinesInOrder(): void
    {
        $this->legacyInvoice('2026-03-10', [$this->payment(lang('Sales.cash'), '816.50')]);

        $batch    = $this->previewed();
        $journals = $this->batches->journals($batch->id, 2, 0);

        $this->assertSame(3, $journals['total']);
        $this->assertCount(2, $journals['rows']);
        $this->assertSame('preview', $journals['rows'][0]->status);

        $lines = $this->batches->lines((int) $journals['rows'][0]->journal_id);

        $this->assertSame([1, 2, 3], array_map(static fn (object $line): int => (int) $line->line_no, $lines));
        $this->assertCount(1, $this->batches->journals($batch->id, 2, 2)['rows']);
    }

    public function testItemsAfter_ReturnsOnlyTheReadyDocumentsPastTheCursorInOrder(): void
    {
        $this->legacyExpense('100', '0', '2026-03-12');
        $this->legacyExpense('200', '0', '2026-03-13');
        $this->legacyExpense('300', '0', '2019-03-14');

        $batch = $this->previewed(['from' => '2019-01-01']);
        $items = $this->batches->itemsAfter($batch->id, 0, 10);

        $this->assertCount(2, $items);
        $this->assertCount(1, $this->batches->itemsAfter($batch->id, (int) $items[0]->item_id, 10));
        $this->assertCount(1, $this->batches->itemsAfter($batch->id, 0, 1));
        $this->assertSame([1], $this->batches->readyBranches($batch->id));
    }

    public function testOverlapping_FindsOnlyAnOpenBatchOfTheSameDaysBranchesAndFamilies(): void
    {
        $other = $this->createBranch('B2', 'Second branch');
        $first = $this->backfill->create(['name' => 'Open', 'from' => '2026-03-01', 'to' => '2026-03-31', 'branch_id' => 1, 'families' => ['expenses']], $this->backfillManager);

        $this->assertSame($first, $this->batches->overlapping('2026-03-31', '2026-04-30', 1, [BackfillFamily::Expenses, BackfillFamily::Sales])?->id);
        $this->assertSame($first, $this->batches->overlapping('2026-03-10', '2026-03-10', null, [BackfillFamily::Expenses])?->id);
        $this->assertNull($this->batches->overlapping('2026-04-01', '2026-04-30', 1, [BackfillFamily::Expenses]));
        $this->assertNull($this->batches->overlapping('2026-02-01', '2026-02-28', 1, [BackfillFamily::Expenses]));
        $this->assertSame($first, $this->batches->overlapping('2026-02-01', '2026-03-01', 1, [BackfillFamily::Expenses])?->id);
        $this->assertNull($this->batches->overlapping('2026-03-10', '2026-03-10', $other, [BackfillFamily::Expenses]));
        $this->assertNull($this->batches->overlapping('2026-03-10', '2026-03-10', 1, [BackfillFamily::Cashups]));

        $this->backfill->cancel($first, $this->backfillManager);

        $this->assertNull($this->batches->overlapping('2026-03-10', '2026-03-10', 1, [BackfillFamily::Expenses]));
    }

    public function testAll_ListsTheBatchesOfTheBranchesGivenAndOfTheCompanyOnlyForEveryBranch(): void
    {
        $other = $this->createBranch('B2', 'Second branch');

        $this->backfill->create(['name' => 'Company', 'from' => '2026-01-01', 'to' => '2026-01-31'], $this->backfillManager);
        $this->backfill->create(['name' => 'One', 'from' => '2026-02-01', 'to' => '2026-02-28', 'branch_id' => 1], $this->backfillManager);
        $this->backfill->create(['name' => 'Two', 'from' => '2026-03-01', 'to' => '2026-03-31', 'branch_id' => $other], $this->backfillManager);

        $this->assertSame(['Two', 'One', 'Company'], array_map(static fn ($batch): string => $batch->name, $this->batches->all(null)));
        $this->assertSame(['One'], array_map(static fn ($batch): string => $batch->name, $this->batches->all([1])));
        $this->assertSame([], $this->batches->all([]));
        $this->assertSame(BackfillStatus::Collecting, $this->batches->all(null)[0]->status);
    }

    public function testFind_ReadsABatchOfThisCompanyAndRequireRefusesAMissingOne(): void
    {
        $id = $this->emptyBatch();

        $this->assertSame($id, $this->batches->find($id)?->id);
        $this->assertNull($this->batches->find($id + 100));

        $this->expectException(AccountingException::class);
        $this->batches->require($id + 100);
    }
}
