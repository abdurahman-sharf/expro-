<?php

namespace Tests\Libraries\Accounting\Backfill;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\Backfill\BackfillStatus;
use App\Libraries\Accounting\StockLedger;
use Closure;
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
final class BackfillServiceTest extends CIUnitTestCase
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

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBackfill();
    }

    protected function tearDown(): void
    {
        $this->stopBackfill();

        parent::tearDown();
    }

    public function testApprove_PostsTheJournalsThePreviewShowed(): void
    {
        $saleId = $this->legacyInvoice('2026-03-10', [$this->payment(lang('Sales.cash'), '816.50')]);
        $batch  = $this->previewed();

        $this->assertSame(BackfillStatus::Preview, $batch->status);
        $this->assertSame(1, $batch->readyCount);
        $this->assertSame(3, $batch->journalCount);

        $shown = array_map(static fn (array $journal): array => [$journal['key'], $journal['lines'], $journal['date']], $this->batchJournals($batch->id));
        $done  = $this->approved($batch);
        $after = array_map(static fn (array $journal): array => [$journal['key'], $journal['lines'], $journal['date']], $this->batchJournals($batch->id));

        $this->assertSame(BackfillStatus::Approved, $done->status);
        $this->assertSame($shown, $after);
        $this->assertSame(['posted', 'posted', 'posted'], array_column($this->batchJournals($batch->id), 'status'));
        $this->assertSame('2026-03-10', $after[0][2]);
        $this->assertGreaterThan(0, $saleId);
    }

    private function assertRefusedWith(AccountingError $error, Closure $action): AccountingException
    {
        try {
            $action();
        } catch (AccountingException $exception) {
            $this->assertSame($error, $exception->error, $exception->getMessage());

            return $exception;
        }

        $this->fail('Expected ' . $error->value);
    }

    public function testPreview_LeavesEveryBalanceAndTheStockLedgerUnchanged(): void
    {
        $this->legacyInvoice('2026-03-10', [$this->payment(lang('Sales.cash'), '816.50')]);
        $this->legacyPurchase('500');
        $this->legacyExpense();
        $this->legacyCashup();

        $before = $this->booksSnapshot();
        $batch  = $this->previewed();

        $this->assertSame(4, $batch->readyCount);
        $this->assertGreaterThan(0, $this->countRows('accounting_journals') - $before['journals']);
        $this->assertSame($before, $this->booksSnapshot());
        $this->assertSame(0, $this->accountingDb()->table('accounting_stock_entries')->countAllResults());
    }

    public function testApprove_NumbersTheJournalsFromTheirOwnIdAndPreviewsHaveNoNumber(): void
    {
        $this->legacyInvoice();
        $batch = $this->previewed();

        $this->assertSame([null, null], array_column($this->batchJournals($batch->id), 'number'));

        $this->approved($batch);

        foreach ($this->batchJournals($batch->id) as $journal) {
            $this->assertSame(sprintf('%06d', $journal['id']), $journal['number']);
        }
    }

    public function testApprove_WritesTheStockMovementsAndKeepsEveryTieAndTheShelvesAlone(): void
    {
        $saleId   = $this->legacyInvoice('2026-03-10', [$this->payment(lang('Sales.cash'), '816.50')]);
        $purchase = $this->legacyPurchase('500', '2026-03-11', lang('Sales.cash'));
        $items    = $this->accountingDb()->table('sales_items')->select('item_id')->where('sale_id', $saleId)->get()->getResultArray();
        $shelves  = array_map(fn (array $row): float => $this->getItemQuantity((int) $row['item_id'], self::LOCATION_ID), $items);

        $this->approved($this->previewed());

        $this->assertGreaterThan(0, $this->accountingDb()->table('accounting_stock_entries')->countAllResults());
        $this->assertSame([], (new StockLedger())->mismatchedJournals());
        $this->assertSame($shelves, array_map(fn (array $row): float => $this->getItemQuantity((int) $row['item_id'], self::LOCATION_ID), $items));
        $this->assertTies();
        $this->assertSupplierTies();
        $this->assertStockTies();
        $this->assertGreaterThan(0, $purchase);
    }

    public function testApprove_RefusesAFingerprintThatIsNotTheBatchs(): void
    {
        $this->legacyInvoice();
        $batch = $this->previewed();

        $this->assertRefusedWith(AccountingError::BackfillPreviewChanged, fn () => $this->backfill->approve($batch->id, str_repeat('a', 64), $this->backfillManager));
        $this->assertSame(BackfillStatus::Preview, $this->statusOf($batch->id));
        $this->assertSame(['preview'], array_values(array_unique(array_column($this->batchJournals($batch->id), 'status'))));
    }

    public function testApprove_RefusesWhenADocumentChangedSincethePreview(): void
    {
        $expenseId = $this->legacyExpense();
        $batch     = $this->previewed();

        $this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->update(['amount' => '999']);

        $before = $this->booksSnapshot();

        $this->assertRefusedWith(AccountingError::BackfillPreviewChanged, fn () => $this->approved($batch));
        $this->assertSame($before, $this->booksSnapshot());
        $this->assertSame(BackfillStatus::Preview, $this->statusOf($batch->id));
    }

    public function testApprove_RefusesWhenANewDocumentQualifiesSinceThePreview(): void
    {
        $this->legacyExpense();
        $batch = $this->previewed();

        $this->legacyExpense('50', '0', '2026-03-13');

        $before = $this->booksSnapshot();

        $this->assertRefusedWith(AccountingError::BackfillPreviewChanged, fn () => $this->approved($batch));
        $this->assertSame($before, $this->booksSnapshot());
    }

    public function testApprove_RefusesWhenTheBooksClosedTheDaySincePreview(): void
    {
        $this->legacyExpense('100', '0', '2026-03-12');
        $batch = $this->previewed();

        $this->closeThrough('2026-03-31');

        $before = $this->booksSnapshot();

        $this->assertRefusedWith(AccountingError::BackfillPreviewChanged, fn () => $this->approved($batch));
        $this->assertSame($before, $this->booksSnapshot());
    }

    public function testApprove_RefusesWhileTheSettingIsOff(): void
    {
        $this->legacyExpense();
        $batch = $this->previewed();

        $this->setConfigValue('accounting_backfill_enabled', '0');

        $this->assertRefusedWith(AccountingError::BackfillDisabled, fn () => $this->approved($batch));
        $this->assertSame(['preview'], array_values(array_unique(array_column($this->batchJournals($batch->id), 'status'))));
    }

    public function testApprove_RefusesWithoutThePostedOpeningBalancesOfTheBranch(): void
    {
        $this->stopBackfill();
        $this->startBackfill(false);

        $this->legacyExpense();
        $batch = $this->previewed();

        $exception = $this->assertRefusedWith(AccountingError::BackfillOpeningMissing, fn () => $this->approved($batch));

        $this->assertSame('HQ', $exception->context['name']);
        $this->assertSame(BackfillStatus::Preview, $this->statusOf($batch->id));
    }

    public function testApprove_RefusesWithoutAStartDate(): void
    {
        $this->legacyExpense();
        $batch = $this->previewed();

        $this->setConfigValue('accounting_start_date', '');

        $this->assertRefusedWith(AccountingError::BackfillStartDateMissing, fn () => $this->approved($batch));
    }

    public function testApprove_RefusesWhileAccountingIsOff(): void
    {
        $this->legacyExpense();
        $batch = $this->previewed();

        $this->setConfigValue('accounting_enabled', '0');

        $this->assertRefusedWith(AccountingError::BackfillAccountingOff, fn () => $this->approved($batch));
    }

    public function testApprove_RefusesABatchWhosePreviewIsNotFinished(): void
    {
        $this->legacyExpense();
        $this->legacyExpense('60', '0', '2026-03-13');

        $batch = $this->previewed(['chunk_size' => 1], false);
        $batch = $this->backfill->preview($batch->id, $this->backfillManager, 1);

        $this->assertSame(BackfillStatus::Collecting, $batch->status);
        $this->assertRefusedWith(AccountingError::BackfillNotReady, fn () => $this->backfill->approve($batch->id, str_repeat('b', 64), $this->backfillManager));
    }

    public function testApprove_RefusesABatchThatWasApprovedAlready(): void
    {
        $this->legacyExpense();
        $batch = $this->previewed();

        $this->approved($batch);

        $before = $this->booksSnapshot();

        $this->assertRefusedWith(AccountingError::BackfillDecided, fn () => $this->approved($batch));
        $this->assertSame($before, $this->booksSnapshot());
    }

    public function testApprove_RefusesABatchWithNothingToPost(): void
    {
        $batch = $this->previewed(['from' => '2019-01-01', 'to' => '2019-01-02']);

        $this->assertSame(0, $batch->documentCount);
        $this->assertRefusedWith(AccountingError::BackfillNothingToApprove, fn () => $this->approved($batch));
    }

    public function testCancel_RemovesThePreviewsAndTheRecordOfTheDocumentsAndLeavesTheBooksAsTheyWere(): void
    {
        $this->legacyInvoice('2026-03-10', [$this->payment(lang('Sales.cash'), '816.50')]);
        $this->legacyExpense();

        $before = $this->booksSnapshot();
        $rows   = $this->countRows('accounting_journals');
        $batch  = $this->previewed();

        $this->assertGreaterThan($rows, $this->countRows('accounting_journals'));

        $cancelled = $this->backfill->cancel($batch->id, $this->backfillManager);

        $this->assertSame(BackfillStatus::Cancelled, $cancelled->status);
        $this->assertSame($rows, $this->countRows('accounting_journals'));
        $this->assertSame(0, $this->countRows('accounting_backfill_items'));
        $this->assertSame($before, $this->booksSnapshot());
        $this->assertSame([], $this->batchJournals($batch->id));
    }

    public function testCancel_RefusesABatchThatWasApprovedOrCancelled(): void
    {
        $this->legacyExpense();

        $approved = $this->approved($this->previewed());

        $this->assertRefusedWith(AccountingError::BackfillDecided, fn () => $this->backfill->cancel($approved->id, $this->backfillManager));
        $this->assertSame('posted', $this->batchJournals($approved->id)[0]['status']);

        $other = $this->previewed(['from' => '2026-04-01', 'to' => '2026-04-30']);

        $this->backfill->cancel($other->id, $this->backfillManager);
        $this->assertRefusedWith(AccountingError::BackfillDecided, fn () => $this->backfill->cancel($other->id, $this->backfillManager));
    }

    public function testCancel_RefusesABatchWhoseApprovalHasBegun(): void
    {
        $this->legacyExpense();
        $this->legacyExpense('60', '0', '2026-03-13');

        $batch = $this->previewed(['chunk_size' => 1]);

        $this->backfill->approve($batch->id, (string) $batch->fingerprint, $this->backfillManager, 1);

        $this->assertSame(BackfillStatus::Approving, $this->statusOf($batch->id));
        $this->assertRefusedWith(AccountingError::BackfillApproving, fn () => $this->backfill->cancel($batch->id, $this->backfillManager));
    }

    public function testPreview_ResumesFromTheCursorWithoutWritingAnythingTwice(): void
    {
        $first  = $this->legacyExpense('100', '0', '2026-03-12');
        $second = $this->legacyExpense('200', '0', '2026-03-13');
        $third  = $this->legacyExpense('300', '0', '2026-03-14');

        $batch = $this->previewed(['chunk_size' => 1], false);
        $batch = $this->backfill->preview($batch->id, $this->backfillManager, 2);

        $this->assertSame(BackfillStatus::Collecting, $batch->status);
        $this->assertSame(2, $this->countRows('accounting_backfill_items'));
        $this->assertSame($second, $batch->cursorId);

        $batch = $this->backfill->preview($batch->id, $this->backfillManager);

        $this->assertSame(BackfillStatus::Preview, $batch->status);
        $this->assertSame(3, $this->countRows('accounting_backfill_items'));
        $this->assertSame(3, $this->countRows('accounting_journals') - 1);
        $this->assertSame([$first, $second, $third], array_map('intval', array_column($this->accountingDb()->table('accounting_backfill_items')->orderBy('item_id')->get()->getResultArray(), 'document_id')));
    }

    public function testPreview_GivesTheSameFingerprintWhetherOrNotItWasInterrupted(): void
    {
        $this->legacyInvoice('2026-03-10', [$this->payment(lang('Sales.cash'), '816.50')]);
        $this->legacyExpense('100', '0', '2026-03-12');
        $this->legacyExpense('200', '0', '2026-03-13');

        $whole = $this->previewed(['chunk_size' => 100]);

        $this->backfill->cancel($whole->id, $this->backfillManager);

        $batch = $this->previewed(['chunk_size' => 1], false);

        do {
            $batch = $this->backfill->preview($batch->id, $this->backfillManager, 1);
        } while ($batch->status === BackfillStatus::Collecting);

        $this->assertSame($whole->fingerprint, $batch->fingerprint);
        $this->assertSame($whole->journalCount, $batch->journalCount);
    }

    public function testApprove_ResumesFromTheCursorAndPostsEveryJournalOnce(): void
    {
        $this->legacyExpense('100', '0', '2026-03-12');
        $this->legacyExpense('200', '0', '2026-03-13');
        $this->legacyExpense('300', '0', '2026-03-14');

        $batch = $this->previewed(['chunk_size' => 1]);
        $batch = $this->backfill->approve($batch->id, (string) $batch->fingerprint, $this->backfillManager, 2);

        $this->assertSame(BackfillStatus::Approving, $batch->status);
        $this->assertSame(2, $batch->approvedCount);
        $this->assertSame(['posted', 'posted', 'preview'], array_column($this->batchJournals($batch->id), 'status'));

        $done = $this->backfill->approve($batch->id, (string) $batch->fingerprint, $this->backfillManager);

        $this->assertSame(BackfillStatus::Approved, $done->status);
        $this->assertSame(3, $done->approvedCount);
        $this->assertSame(['posted', 'posted', 'posted'], array_column($this->batchJournals($batch->id), 'status'));
        $this->assertSame(3, $this->accountingDb()->table('accounting_journals')->where('source_type', 'expense')->countAllResults());
    }
}
