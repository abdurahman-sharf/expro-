<?php

namespace Tests\Libraries\Accounting\Backfill;

use ReflectionMethod;
use App\Models\Expense;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\TaxReport;
use App\Libraries\Accounting\Backfill\BackfillBatch;
use App\Libraries\Accounting\Backfill\BackfillService;
use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CashupPosting;
use App\Libraries\Accounting\Backfill\BackfillStatus;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\StockLedger;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use Closure;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\BackfillFixtureTrait;
use Tests\Support\ConcurrentDbRaceTrait;
use Tests\Support\DatabaseFingerprintTrait;
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
    use ConcurrentDbRaceTrait;
    use DatabaseFingerprintTrait;
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

        $this->assertNull($batch->decidedBy);
        $this->assertSame(BackfillStatus::Approved, $done->status);
        $this->assertSame($this->backfillManager, $done->decidedBy);
        $this->assertNotNull($done->decidedAt);
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

    public function testCancel_EndsAnApprovalThatCannotGoOnAndKeepsWhatWasPosted(): void
    {
        $this->legacyExpense('0.01', '0', '2019-03-14');

        $first  = $this->legacyExpense('100', '0', '2026-03-12');
        $second = $this->legacyExpense('200', '0', '2026-03-13');
        $third  = $this->legacyExpense('300', '0', '2026-03-14');
        $batch  = $this->previewed(['chunk_size' => 1, 'from' => '2019-01-01', 'to' => '2026-12-31']);

        $this->assertSame([3, 1], [$batch->readyCount, $batch->refusedCount]);

        $this->backfill->approve($batch->id, (string) $batch->fingerprint, $this->backfillManager, 1);
        $this->accountingDb()->table('expenses')->where('expense_id', $second)->update(['amount' => '999']);

        $this->assertRefusedWith(AccountingError::BackfillPreviewChanged, fn () => $this->backfill->approve($batch->id, (string) $batch->fingerprint, $this->backfillManager));
        $this->assertSame(BackfillStatus::Approving, $this->statusOf($batch->id));
        $this->assertRefusedWith(AccountingError::BackfillOverlaps, fn () => $this->backfill->create(['name' => 'Again', 'from' => '2026-01-01', 'to' => '2026-12-31'], $this->backfillManager));

        $cancelled = $this->backfill->cancel($batch->id, $this->backfillManager);

        $this->assertSame(BackfillStatus::Cancelled, $cancelled->status);
        $this->assertSame($this->backfillManager, $cancelled->decidedBy);
        $this->assertSame(['posted'], array_column($this->batchJournals($batch->id), 'status'));
        $this->assertSame(0, $this->accountingDb()->table('accounting_journals')->where('status', 'preview')->countAllResults());
        $this->assertSame([$first], array_map('intval', array_column($this->accountingDb()->table('accounting_backfill_items')->where('batch_id', $batch->id)->get()->getResultArray(), 'document_id')));

        $audit = $this->accountingDb()->table('accounting_audit_log')->where('action', 'backfill_cancelled')->get()->getRow();

        $this->assertSame(1, (int) json_decode((string) $audit->details, true)['approved']);

        $other = $this->previewed(['name' => 'The rest']);

        $this->assertSame(2, $other->readyCount);
        $this->assertSame(BackfillStatus::Approved, $this->approved($other)->status);
        $this->assertSame(3, $this->accountingDb()->table('accounting_journals')->where('source_type', 'expense')->where('status', 'posted')->countAllResults());
        $this->assertGreaterThan(0, $third);
    }

    public function testCancel_EndsAnApprovalThatFailedBeforeItPostedAnything(): void
    {
        $this->legacyExpense('100', '0', '2026-03-12');
        $second = $this->legacyExpense('200', '0', '2026-03-13');

        $batch   = $this->previewed(['chunk_size' => 2]);
        $db      = $this->accountingDb();
        $blocker = $db->prefixTable('wp12_backfill_blocker');

        $db->query("DROP TRIGGER IF EXISTS {$blocker}");
        $db->query("CREATE TRIGGER {$blocker} BEFORE UPDATE ON " . $db->prefixTable('accounting_journals') . " FOR EACH ROW BEGIN IF NEW.status = 'posted' AND NEW.source_id = {$second} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'blocked'; END IF; END");

        try {
            $this->backfill->approve($batch->id, (string) $batch->fingerprint, $this->backfillManager);
            $this->fail('A chunk that could not be written was reported as written.');
        } catch (PostingException|AccountingException) {
            $this->releaseOpenTransactions();
        } finally {
            $db->query("DROP TRIGGER IF EXISTS {$blocker}");
        }

        $stuck = $this->backfill->batch($batch->id, $this->backfillManager);

        $this->assertSame(BackfillStatus::Approving, $stuck->status);
        $this->assertSame(0, $stuck->approvedCount);
        $this->assertSame(['preview', 'preview'], array_column($this->batchJournals($batch->id), 'status'));

        $this->assertSame(BackfillStatus::Cancelled, $this->backfill->cancel($batch->id, $this->backfillManager)->status);
        $this->assertSame([], $this->batchJournals($batch->id));
        $this->assertSame(0, $this->countRows('accounting_backfill_items'));
        $this->assertSame(0, $this->accountingDb()->table('accounting_journals')->where('source_type', 'expense')->countAllResults());
    }

    public function testCancel_RefusesABatchWhileAnotherRunApprovesIt(): void
    {
        $this->legacyExpense();
        $this->legacyExpense('60', '0', '2026-03-13');

        $batch = $this->previewed(['chunk_size' => 1]);

        $this->backfill->approve($batch->id, (string) $batch->fingerprint, $this->backfillManager, 1);

        $other = $this->openSecondConnection();
        $name  = 'ospos_backfill_' . substr(sha1($this->accountingDb()->getDatabase()), 0, 10) . '_batch_' . $batch->id;

        $this->assertSame(1, (int) $other->query('SELECT GET_LOCK(?, 0) AS got', [$name])->getRow()->got);

        try {
            $this->assertRefusedWith(AccountingError::BackfillBusy, fn () => $this->backfill->cancel($batch->id, $this->backfillManager));
        } finally {
            $other->query('SELECT RELEASE_LOCK(?)', [$name]);
            $other->close();
        }

        $this->assertSame(BackfillStatus::Approving, $this->statusOf($batch->id));
        $this->assertSame(['posted', 'preview'], array_column($this->batchJournals($batch->id), 'status'));
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

    public function testPreview_ShowsWhatItHoldsSoFarWhileTheBatchIsStillBeingPreviewed(): void
    {
        $this->legacyExpense('100', '0', '2026-03-12');
        $this->legacyExpense('200', '0', '2026-03-13');
        $this->legacyExpense('0.01', '0', '2019-03-14');

        $batch = $this->previewed(['chunk_size' => 1, 'from' => '2019-01-01'], false);
        $batch = $this->backfill->preview($batch->id, $this->backfillManager, 2);

        $this->assertSame(BackfillStatus::Collecting, $batch->status);
        $this->assertSame([2, 2, 0, 2], [$batch->documentCount, $batch->readyCount, $batch->refusedCount, $batch->journalCount]);
        $this->assertSame('300.0000', bcadd($batch->totalDebit, '0', 4));

        $done = $this->backfill->preview($batch->id, $this->backfillManager);

        $this->assertSame([3, 2, 1, 2], [$done->documentCount, $done->readyCount, $done->refusedCount, $done->journalCount]);
    }

    public function testPreview_KeepsNothingOfAChunkThatFailsHalfWay(): void
    {
        $this->legacyExpense('100', '0', '2026-03-12');
        $this->legacyExpense('200', '0', '2026-03-13');
        $third = $this->legacyExpense('300', '0', '2026-03-14');

        $batch    = $this->previewed(['chunk_size' => 3], false);
        $journals = $this->countRows('accounting_journals');
        $db       = $this->accountingDb();
        $blocker  = $db->prefixTable('wp12_backfill_blocker');

        $db->query("DROP TRIGGER IF EXISTS {$blocker}");
        $db->query("CREATE TRIGGER {$blocker} BEFORE INSERT ON " . $db->prefixTable('accounting_journals') . " FOR EACH ROW BEGIN IF NEW.status = 'preview' AND NEW.source_id = {$third} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'blocked'; END IF; END");

        try {
            $this->backfill->preview($batch->id, $this->backfillManager);
            $this->fail('A chunk that could not be written was reported as written.');
        } catch (PostingException $exception) {
            $this->assertSame(PostingError::WriteFailed, $exception->error);
            $this->releaseOpenTransactions();
        } finally {
            $db->query("DROP TRIGGER IF EXISTS {$blocker}");
        }

        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertSame(0, $this->countRows('accounting_backfill_items'));
        $this->assertSame(0, $this->backfill->batch($batch->id, $this->backfillManager)->cursorId);

        $batch = $this->backfill->preview($batch->id, $this->backfillManager);

        $this->assertSame(BackfillStatus::Preview, $batch->status);
        $this->assertSame(3, $this->countRows('accounting_backfill_items'));
        $this->assertSame($journals + 3, $this->countRows('accounting_journals'));
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

    private function assertBranchRefusedWith(BranchError $error, Closure $action): void
    {
        try {
            $action();
        } catch (BranchException $exception) {
            $this->assertSame($error, $exception->error, $exception->getMessage());

            return;
        }

        $this->fail('Expected ' . $error->value);
    }

    private function nobody(?array $branchIds = null): int
    {
        return $this->employeeWithGrants([], $branchIds);
    }

    /**
     * @return array<string, list<Closure>>
     */
    private function everyCallOfTheService(): array
    {
        return [
            'create'  => [fn (int $who) => $this->backfill->create(['name' => 'X', 'from' => '2026-01-01', 'to' => '2026-12-31'], $who)],
            'preview' => [fn (int $who) => $this->backfill->preview($this->previewed(['from' => '2025-01-01', 'to' => '2025-01-31'], false)->id, $who)],
            'approve' => [fn (int $who) => $this->backfill->approve($this->previewed(['from' => '2025-02-01', 'to' => '2025-02-28'])->id, str_repeat('a', 64), $who)],
            'cancel'  => [fn (int $who) => $this->backfill->cancel($this->previewed(['from' => '2025-03-01', 'to' => '2025-03-31'], false)->id, $who)],
            'dryRun'  => [fn (int $who) => $this->backfill->dryRun(['from' => '2026-01-01', 'to' => '2026-12-31'], $who)],
            'batches' => [fn (int $who) => $this->backfill->batches($who)],
            'batch'   => [fn (int $who) => $this->backfill->batch($this->previewed(['from' => '2025-04-01', 'to' => '2025-04-30'], false)->id, $who)],
        ];
    }

    public function testEveryCall_RequiresThePermissionToManageBatches(): void
    {
        $this->legacyExpense();

        $nobody = $this->nobody();

        foreach ($this->everyCallOfTheService() as $name => [$call]) {
            try {
                $call($nobody);
                $this->fail("{$name} ran without the permission.");
            } catch (AccountingException $exception) {
                $this->assertSame(AccountingError::PermissionDenied, $exception->error, $name);
            }
        }
    }

    public function testCreate_WritesNothingForSomeoneWithoutThePermission(): void
    {
        $nobody = $this->nobody();
        $before = $this->databaseFingerprint();

        $this->assertRefusedWith(AccountingError::PermissionDenied, fn () => $this->backfill->create(['name' => 'X', 'from' => '2026-01-01', 'to' => '2026-12-31'], $nobody));
        $this->assertDatabaseUnchanged($before);
    }

    public function testApprove_ChangesNothingForSomeoneWithoutThePermissionEvenWithTheRightFingerprint(): void
    {
        $this->legacyExpense();

        $batch  = $this->previewed();
        $nobody = $this->nobody();
        $before = $this->databaseFingerprint();

        $this->assertRefusedWith(AccountingError::PermissionDenied, fn () => $this->backfill->approve($batch->id, (string) $batch->fingerprint, $nobody));
        $this->assertDatabaseUnchanged($before);
    }

    public function testCreate_RefusesABranchOutsideTheScopeOfTheEmployeeAndTheWholeCompanyToWhoWorksInOne(): void
    {
        $other = $this->createBranch('B2', 'Second branch');
        $local = $this->employeeWithGrants([AccountingGuard::BACKFILL_MANAGE], [1]);

        $this->assertBranchRefusedWith(BranchError::NotAllowed, fn () => $this->backfill->create(['name' => 'X', 'from' => '2026-01-01', 'to' => '2026-12-31', 'branch_id' => $other], $local));
        $this->assertBranchRefusedWith(BranchError::CompanyScopeNeeded, fn () => $this->backfill->create(['name' => 'X', 'from' => '2026-01-01', 'to' => '2026-12-31'], $local));
        $this->assertBranchRefusedWith(BranchError::NotFound, fn () => $this->backfill->create(['name' => 'X', 'from' => '2026-01-01', 'to' => '2026-12-31', 'branch_id' => 99999], $this->backfillManager));
        $this->assertSame(0, $this->countRows('accounting_backfill_batches'));

        $this->assertGreaterThan(0, $this->backfill->create(['name' => 'Mine', 'from' => '2026-01-01', 'to' => '2026-12-31', 'branch_id' => 1], $local));
    }

    public function testBatches_ListsNothingForAnEmployeeWhoWorksInNoBranch(): void
    {
        $this->legacyExpense();
        $this->previewed();

        $this->assertSame([], $this->backfill->batches($this->employeeWithGrants([AccountingGuard::BACKFILL_MANAGE], [])));
        $this->assertCount(1, $this->backfill->batches($this->backfillManager));
    }

    public function testBatch_IsHiddenFromAnEmployeeWhoDoesNotWorkInItsBranch(): void
    {
        $other = $this->createBranch('B2', 'Second branch');
        $local = $this->employeeWithGrants([AccountingGuard::BACKFILL_MANAGE], [1]);

        $this->legacyExpense('100', '0', '2026-03-12', $other);

        $branchTwo = $this->previewed(['branch_id' => $other, 'name' => 'Two']);
        $company   = $this->previewed(['from' => '2025-01-01', 'to' => '2025-12-31', 'name' => 'Company']);

        foreach ([$branchTwo->id, $company->id] as $batchId) {
            $this->assertRefusedWith(AccountingError::BackfillNotFound, fn () => $this->backfill->batch($batchId, $local));
            $this->assertRefusedWith(AccountingError::BackfillNotFound, fn () => $this->backfill->preview($batchId, $local));
            $this->assertRefusedWith(AccountingError::BackfillNotFound, fn () => $this->backfill->approve($batchId, str_repeat('a', 64), $local));
            $this->assertRefusedWith(AccountingError::BackfillNotFound, fn () => $this->backfill->cancel($batchId, $local));
        }

        $this->assertSame([], $this->backfill->batches($local));
        $this->assertCount(2, $this->backfill->batches($this->backfillManager));
    }

    public function testPreview_LooksOnlyAtTheDocumentsOfTheBranchOfTheBatchAndApprovalWritesInThatBranch(): void
    {
        $other = $this->createBranch('B2', 'Second branch');

        $this->postOpening($other);

        $one = $this->legacyExpense('100', '0', '2026-03-12', 1);
        $two = $this->legacyExpense('200', '0', '2026-03-12', $other);

        $batch = $this->previewed(['branch_id' => $other]);

        $this->assertSame(1, $batch->documentCount);
        $this->assertSame([$two], array_map('intval', array_column($this->accountingDb()->table('accounting_backfill_items')->where('batch_id', $batch->id)->get()->getResultArray(), 'document_id')));

        $this->approved($batch);

        $this->assertSame([(string) $other], array_values(array_unique(array_column($this->accountingDb()->table('accounting_journals')->select('branch_id')->where('backfill_batch_id', $batch->id)->get()->getResultArray(), 'branch_id'))));
        $this->assertSame(0, $this->accountingDb()->table('accounting_journals')->where('source_type', 'expense')->where('source_id', $one)->countAllResults());
    }

    public function testPreview_ListsADocumentOfAClosedPeriodAsRefusedAndApprovalNeverPostsIt(): void
    {
        $closed = $this->legacyExpense('100', '0', '2026-03-12');
        $open   = $this->legacyExpense('200', '0', '2026-05-12');

        $this->closeThrough('2026-03-31');

        $batch = $this->previewed();

        $this->assertSame(1, $batch->readyCount);
        $this->assertSame(1, $batch->refusedCount);
        $this->assertSame(['period_closed' => 1], $this->backfill->repository()->reasons($batch->id));

        $this->approved($batch);

        $this->assertSame(0, $this->accountingDb()->table('accounting_journals')->where('source_type', 'expense')->where('source_id', $closed)->countAllResults());
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('source_type', 'expense')->where('source_id', $open)->where('status', 'posted')->countAllResults());
    }

    public function testPreview_HonoursTheClosedPeriodOfABranchForItsOwnDocumentsOnly(): void
    {
        $other = $this->createBranch('B2', 'Second branch');

        $this->postOpening($other);
        $this->legacyExpense('100', '0', '2026-03-12', 1);
        $this->legacyExpense('200', '0', '2026-03-12', $other);
        $this->closeThrough('2026-03-31', $other);

        $batch   = $this->previewed();
        $refused = $this->backfill->repository()->items($batch->id, 'refused', 10, 0)['rows'];

        $this->assertSame(1, $batch->readyCount);
        $this->assertCount(1, $refused);
        $this->assertSame((int) $other, (int) $refused[0]->branch_id);
        $this->assertSame('period_closed', $refused[0]->reason);
    }

    public function testPreview_NamesTheReasonOfEveryDocumentItRefusesInsteadOfThrowing(): void
    {
        $this->legacyInvoice('2026-03-10');
        $this->legacyExpense('0.01', '0', '2025-03-12');

        $zero = $this->whileOff(fn (): int => $this->dateSale($this->save([$this->line($this->item('0', false), 0, '1', '0')]), '2026-03-10'));
        $gone = $this->legacyInvoice('2026-03-11', [], $this->anotherCustomer());

        $this->accountingDb()->table('customers')->where('person_id', $this->accountingDb()->table('sales')->where('sale_id', $gone)->get()->getRow()->customer_id)->update(['deleted' => 1]);
        $this->setConfigValue('accounting_start_date', '2026-01-01');

        $batch = $this->previewed(['from' => '2025-01-01']);

        $reasons = $this->backfill->repository()->reasons($batch->id);

        ksort($reasons);

        $this->assertSame(['before_start_date' => 1, 'party_missing' => 1, 'zero_total' => 1], $reasons);
        $this->assertSame(1, $batch->readyCount);
        $this->assertGreaterThan(0, $zero);
    }

    public function testPreview_NeverMakesACandidateOfADocumentThatHasAPostedJournal(): void
    {
        $cycle = $this->referenceInvoice();
        $live  = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '816.50')]);
        $expense = $this->legacyExpense();

        $this->accountingDb()->table('expenses')->where('expense_id', $expense)->update(['branch_id' => 1]);
        $this->approved($this->previewed(['families' => ['expenses']]));

        $second = $this->previewed(['from' => '2026-01-01', 'to' => '2026-12-31', 'name' => 'Again']);

        $this->assertSame(0, $second->documentCount);
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('source_type', 'sale')->where('source_id', $live)->where('entry_type', 'revenue')->countAllResults());
    }

    public function testPreview_WritesEachSourceKeyOnceWhateverHowOftenItRuns(): void
    {
        $this->legacyInvoice('2026-03-10', [$this->payment(lang('Sales.cash'), '816.50')]);
        $this->legacyPurchase('500', '2026-03-11', lang('Sales.cash'));
        $this->legacyExpense();

        $batch = $this->previewed();

        $this->backfill->preview($batch->id, $this->backfillManager);
        $this->backfill->preview($batch->id, $this->backfillManager);

        $keys = array_column($this->accountingDb()->query('SELECT CONCAT(source_type, source_id, entry_type, revision) AS k FROM ' . $this->accountingDb()->prefixTable('accounting_journals') . ' WHERE backfill_batch_id = ?', [$batch->id])->getResultArray(), 'k');

        $this->assertSame($batch->journalCount, count($keys));
        $this->assertSame($keys, array_values(array_unique($keys)));
    }

    public function testCreate_RefusesABatchThatOverlapsAnOpenOneAndAllowsItOnceThatOneIsDecided(): void
    {
        $first = $this->backfill->create(['name' => 'First', 'from' => '2026-03-01', 'to' => '2026-03-31'], $this->backfillManager);

        $exception = $this->assertRefusedWith(AccountingError::BackfillOverlaps, fn () => $this->backfill->create(['name' => 'Second', 'from' => '2026-03-31', 'to' => '2026-04-30'], $this->backfillManager));

        $this->assertSame('First', $exception->context['name']);
        $this->assertGreaterThan(0, $this->backfill->create(['name' => 'Later', 'from' => '2026-04-01', 'to' => '2026-04-30'], $this->backfillManager));

        $this->backfill->cancel($first, $this->backfillManager);

        $this->assertGreaterThan(0, $this->backfill->create(['name' => 'Second', 'from' => '2026-03-31', 'to' => '2026-03-31'], $this->backfillManager));
    }

    public function testCreate_AllowsTheSameRangeForAnotherBranchOrAnotherFamily(): void
    {
        $other = $this->createBranch('B2', 'Second branch');

        $this->backfill->create(['name' => 'One', 'from' => '2026-03-01', 'to' => '2026-03-31', 'branch_id' => 1, 'families' => ['expenses']], $this->backfillManager);

        $this->assertGreaterThan(0, $this->backfill->create(['name' => 'Branch two', 'from' => '2026-03-01', 'to' => '2026-03-31', 'branch_id' => $other, 'families' => ['expenses']], $this->backfillManager));
        $this->assertGreaterThan(0, $this->backfill->create(['name' => 'Other family', 'from' => '2026-03-01', 'to' => '2026-03-31', 'branch_id' => 1, 'families' => ['cashups']], $this->backfillManager));
        $this->assertRefusedWith(AccountingError::BackfillOverlaps, fn () => $this->backfill->create(['name' => 'Whole company', 'from' => '2026-03-15', 'to' => '2026-03-15', 'families' => ['expenses', 'sales']], $this->backfillManager));
    }

    public function testCreate_RejectsABadNameRangeFamilyOrChunk(): void
    {
        $good = ['name' => 'X', 'from' => '2026-01-01', 'to' => '2026-12-31'];

        foreach ([
            [AccountingError::BackfillNameInvalid, ['name' => '   ']],
            [AccountingError::BackfillNameInvalid, ['name' => str_repeat('n', 121)]],
            [AccountingError::BackfillRangeInvalid, ['from' => '2026-02-30']],
            [AccountingError::BackfillRangeInvalid, ['to' => 'tomorrow']],
            [AccountingError::BackfillRangeInvalid, ['from' => '2026-12-31', 'to' => '2026-01-01']],
            [AccountingError::BackfillFamiliesInvalid, ['families' => ['sales', 'nonsense']]],
            [AccountingError::BackfillChunkInvalid, ['chunk_size' => 0]],
            [AccountingError::BackfillChunkInvalid, ['chunk_size' => 1001]],
        ] as [$error, $change]) {
            $this->assertRefusedWith($error, fn () => $this->backfill->create($change + $good, $this->backfillManager));
        }

        $this->assertSame(0, $this->countRows('accounting_backfill_batches'));
    }

    public function testCreate_AcceptsANameOfTheMostCharactersAndAChunkOfTheMostDocuments(): void
    {
        $batchId = $this->backfill->create(['name' => str_repeat('n', 120), 'from' => '2026-01-01', 'to' => '2026-12-31', 'chunk_size' => 1000], $this->backfillManager);
        $batch   = $this->backfill->batch($batchId, $this->backfillManager);

        $this->assertSame(120, mb_strlen($batch->name));
        $this->assertSame(1000, $batch->chunkSize);

        $this->backfill->cancel($batchId, $this->backfillManager);

        $this->assertSame(1, $this->backfill->batch($this->backfill->create(['name' => 'X', 'from' => '2026-01-01', 'to' => '2026-12-31', 'chunk_size' => 1], $this->backfillManager), $this->backfillManager)->chunkSize);
    }

    public function testCreate_RejectsABranchThatIsNotTheNumberOfOneInsteadOfTakingTheWholeCompany(): void
    {
        $good = ['name' => 'X', 'from' => '2026-01-01', 'to' => '2026-12-31'];

        foreach (['2abc', '-1', '0', 'abc', '1.5', ' 1', 0, -3, 1.0, true, [1]] as $branch) {
            $this->assertBranchRefusedWith(BranchError::NotFound, fn () => $this->backfill->create(['branch_id' => $branch] + $good, $this->backfillManager));
            $this->assertBranchRefusedWith(BranchError::NotFound, fn () => $this->backfill->dryRun(['branch_id' => $branch] + $good, $this->backfillManager));
        }

        $this->assertSame(0, $this->countRows('accounting_backfill_batches'));

        foreach ([null, '', '1', 1] as $branch) {
            $batchId = $this->backfill->create(['branch_id' => $branch] + $good, $this->backfillManager);

            $this->assertSame($branch === null || $branch === '' ? null : 1, $this->backfill->batch($batchId, $this->backfillManager)->branchId);
            $this->backfill->cancel($batchId, $this->backfillManager);
        }
    }

    public function testCreate_RefusesWhileAccountingIsOffOrTheStartDateIsMissing(): void
    {
        $this->setConfigValue('accounting_start_date', '');
        $this->assertRefusedWith(AccountingError::BackfillStartDateMissing, fn () => $this->backfill->create(['name' => 'X', 'from' => '2026-01-01', 'to' => '2026-12-31'], $this->backfillManager));

        $this->setConfigValue('accounting_start_date', '2020-01-01');
        $this->setConfigValue('accounting_enabled', '0');
        $this->assertRefusedWith(AccountingError::BackfillAccountingOff, fn () => $this->backfill->create(['name' => 'X', 'from' => '2026-01-01', 'to' => '2026-12-31'], $this->backfillManager));
    }

    public function testCreate_TwoRealConnectionsMakeOneBatchOfTheSameRange(): void
    {
        [$first, $second] = $this->raceTwoProcesses('backfillCreate', ['2026-03-01', '2026-03-31', (string) $this->backfillManager], ['2026-03-15', '2026-04-15', (string) $this->backfillManager]);

        $this->assertSame(1, (int) $first + (int) $second);
        $this->assertSame(1, $this->countRows('accounting_backfill_batches'));
    }

    public function testApprove_TwoRealConnectionsProduceOneResult(): void
    {
        $this->legacyInvoice('2026-03-10', [$this->payment(lang('Sales.cash'), '816.50')]);
        $this->legacyPurchase('500', '2026-03-11');
        $this->legacyExpense();

        $batch = $this->previewed();
        $args  = [(string) $batch->id, (string) $batch->fingerprint, (string) $this->backfillManager];

        [$first, $second] = $this->raceTwoProcesses('backfillApprove', $args, $args);

        $this->assertSame(1, (int) $first + (int) $second);
        $this->assertSame(BackfillStatus::Approved, $this->statusOf($batch->id));
        $this->assertSame($batch->journalCount, $this->accountingDb()->table('accounting_journals')->where('backfill_batch_id', $batch->id)->where('status', 'posted')->countAllResults());
        $this->assertSame(0, $this->accountingDb()->table('accounting_journals')->where('status', 'preview')->countAllResults());
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where('action', 'backfill_approved')->countAllResults());
        $this->assertTies();
    }

    public function testApprove_RefusesWhileAnotherRunHoldsTheBatch(): void
    {
        $this->legacyExpense();

        $batch = $this->previewed();
        $other = $this->openSecondConnection();
        $name  = 'ospos_backfill_' . substr(sha1($this->accountingDb()->getDatabase()), 0, 10) . '_batch_' . $batch->id;

        $this->assertSame(1, (int) $other->query('SELECT GET_LOCK(?, 0) AS got', [$name])->getRow()->got);

        try {
            $this->assertRefusedWith(AccountingError::BackfillBusy, fn () => $this->approved($batch));
            $this->assertRefusedWith(AccountingError::BackfillBusy, fn () => $this->backfill->cancel($batch->id, $this->backfillManager));
        } finally {
            $other->query('SELECT RELEASE_LOCK(?)', [$name]);
            $other->close();
        }

        $this->assertSame(BackfillStatus::Preview, $this->statusOf($batch->id));
    }

    public function testApprove_StampsTheBranchOfAnExpenseAndACashupThatWereNeverStampedAndAuditsIt(): void
    {
        $expense = $this->legacyExpense();
        $cashup  = $this->legacyCashup('900', '2026-03-12');
        $kept    = $this->legacyExpense('80', '0', '2026-03-13', 1);

        $this->approved($this->previewed());

        $db = $this->accountingDb();

        $this->assertSame(1, (int) $db->table('expenses')->where('expense_id', $expense)->get()->getRow()->branch_id);

        $row = $db->table('cash_up')->where('cashup_id', $cashup)->get()->getRow();

        $this->assertSame(1, (int) $row->branch_id);
        $this->assertSame('770.0000', bcadd((string) $row->expected_amount_cash, '0', 4), 'The cash the drawer was opened with, less the cash of the expense of the day');
        $this->assertSame('130.0000', bcadd((string) $row->cash_difference, '0', 4));
        $this->assertSame(2, $db->table('accounting_audit_log')->where('action', 'backfill_stamped')->countAllResults());
        $this->assertSame(1, (int) $db->table('expenses')->where('expense_id', $kept)->get()->getRow()->branch_id);
    }

    public function testApprove_StampsACashupThatCountedExactlyWhatWasExpectedSoThatItIsNoLongerLeftOutOfTheBooks(): void
    {
        $exact = $this->legacyCashup('1000', '2026-03-12');
        $short = $this->legacyCashup('900', '2026-03-13');
        $posts = new CashupPosting();

        $this->assertSame(2, $posts->unpostedCount());

        $batch = $this->previewed();

        $this->assertSame([2, 1], [$batch->readyCount, $batch->journalCount]);

        $this->approved($batch);

        $db = $this->accountingDb();

        $this->assertSame(0, $posts->unpostedCount());
        $this->assertSame(1, (int) $db->table('cash_up')->where('cashup_id', $exact)->get()->getRow()->branch_id);
        $this->assertSame(0, $db->table('accounting_journals')->where('source_type', 'cashup')->where('source_id', $exact)->countAllResults());
        $this->assertSame(1, $db->table('accounting_journals')->where('source_type', 'cashup')->where('source_id', $short)->where('status', 'posted')->countAllResults());
        $this->assertSame(2, $db->table('accounting_audit_log')->where('action', 'backfill_stamped')->countAllResults());
    }

    public function testApprove_RefusesAnExpenseThatSomeoneStampedAfterThePreview(): void
    {
        $expense = $this->legacyExpense();
        $batch   = $this->previewed();

        $this->accountingDb()->table('expenses')->where('expense_id', $expense)->update(['branch_id' => 1]);

        $this->assertRefusedWith(AccountingError::BackfillPreviewChanged, fn () => $this->approved($batch));
    }

    public function testApprove_PostsTheSameJournalsTheLivePostingMadeOfTheSameDocuments(): void
    {
        $payments = [$this->payment(lang('Sales.cash'), '800'), $this->payment(lang('Sales.due'), '16.50')];
        $legacy   = $this->legacyInvoice('2026-03-10', $payments);
        $cycle    = $this->referenceInvoice();
        $live     = $this->save($cycle['items'], $cycle['taxes'], $payments);

        $this->approved($this->previewed(['families' => ['sales']]));

        $form = static fn (array $journals): array => array_map(static fn (array $journal): string => $journal['source'] . ':' . $journal['entry'] . ':' . $journal['revision'] . '|' . implode(',', $journal['lines']), $journals);

        $this->assertSame($form($this->standing($live)), $form($this->standing($legacy)));
        $this->assertSame('2026-03-10', $this->standing($legacy)[0]['date']);
        $this->assertSame(date('Y-m-d'), $this->standing($live)[0]['date']);
    }

    public function testApprove_SettlesTheCreditOfACustomerAndKeepsTheLedgerAndTheTreasuryTied(): void
    {
        [$invoice, $cycle] = $this->whileOff(function (): array {
            $cycle = $this->referenceInvoice();

            return [$this->dateSale($this->save($cycle['items'], $cycle['taxes']), '2026-03-10'), $cycle];
        });

        $this->whileOff(fn (): int => $this->dateSale($this->save([$this->line($cycle['cream'], 0, '-1', '75', '40', '10')], [], [], SALE_TYPE_RETURN, -1, true, $invoice), '2026-03-11'));
        $this->legacyExpense('230', '30', '2026-03-12');

        $this->approved($this->previewed());

        $this->assertTies();
        $this->assertNotSame([], $this->applications());

        $treasuries = new TreasuryService();
        $main       = $treasuries->ensureMain();

        $this->assertSame($this->balance($this->accountId('cash')), bcadd($treasuries->position($main)->balance, '0', 2));
    }

    public function testApprove_KeepsTheValuedStockOfAPurchaseAndASaleOfTheSameItemTied(): void
    {
        $this->legacyPurchase('500', '2026-03-10');
        $this->legacyInvoice('2026-03-11');

        $this->approved($this->previewed());

        $this->assertStockTies();
        $this->assertSame([], (new StockLedger())->mismatchedJournals());

        foreach ((new StockLedger())->reconcile() as $row) {
            $this->assertSame(0, bccomp($row['unattributed'], '0', 4), 'The account and the movements of location ' . $row['location_id'] . ' say the same');
        }
    }

    public function testApprove_ResumingRefusesADocumentThatChangedWhileTheRunWasStoppedAndKeepsWhatWasPosted(): void
    {
        $first  = $this->legacyExpense('100', '0', '2026-03-12');
        $second = $this->legacyExpense('200', '0', '2026-03-13');
        $batch  = $this->previewed(['chunk_size' => 1]);

        $partial = $this->backfill->approve($batch->id, (string) $batch->fingerprint, $this->backfillManager, 1);

        $this->accountingDb()->table('expenses')->where('expense_id', $second)->update(['amount' => '999']);

        $this->assertRefusedWith(AccountingError::BackfillPreviewChanged, fn () => $this->backfill->approve($partial->id, (string) $batch->fingerprint, $this->backfillManager));
        $this->assertSame(['posted', 'preview'], array_column($this->batchJournals($batch->id), 'status'));
        $this->assertSame(BackfillStatus::Approving, $this->statusOf($batch->id));

        $this->accountingDb()->table('expenses')->where('expense_id', $second)->update(['amount' => '200']);

        $this->assertSame(BackfillStatus::Approved, $this->backfill->approve($partial->id, (string) $batch->fingerprint, $this->backfillManager)->status);
        $this->assertGreaterThan(0, $first);
    }

    public function testApprove_RunsAChunkInATransactionOfItsOwnSoAFailureLeavesTheChunksBeforeIt(): void
    {
        for ($day = 10; $day <= 14; $day++) {
            $this->legacyExpense((string) ($day * 10), '0', '2026-03-' . $day);
        }

        $batch = $this->previewed(['chunk_size' => 2]);

        $this->backfill->approve($batch->id, (string) $batch->fingerprint, $this->backfillManager, 1);

        $this->assertSame(['posted', 'posted', 'preview', 'preview', 'preview'], array_column($this->batchJournals($batch->id), 'status'));
        $this->assertSame(2, $this->backfill->batch($batch->id, $this->backfillManager)->approvedCount);
    }

    public function testBatch_RecordsEachStepInTheAuditLogWithTheNumbersOfTheRun(): void
    {
        $this->legacyInvoice('2026-03-10');
        $this->legacyExpense();

        $batch = $this->previewed();

        $this->approved($batch);

        $audit = $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'backfill_batch')->where('entity_id', $batch->id)->orderBy('audit_id')->get()->getResult();

        $this->assertSame(['backfill_created', 'backfill_previewed', 'backfill_approved'], array_column($audit, 'action'));
        $this->assertSame([$this->backfillManager, $this->backfillManager, $this->backfillManager], array_map(static fn (object $row): int => (int) $row->employee_id, $audit));
        $this->assertSame($batch->fingerprint, json_decode($audit[1]->details, true)['fingerprint']);
        $this->assertSame($batch->journalCount, json_decode($audit[2]->details, true)['journals']);

        $other = $this->previewed(['from' => '2025-01-01', 'to' => '2025-12-31', 'name' => 'To cancel']);

        $this->backfill->cancel($other->id, $this->backfillManager);

        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where('action', 'backfill_cancelled')->where('entity_id', $other->id)->countAllResults());
    }

    public function testBatch_AuditsAnApprovedJournalAsPostedWithItsBatch(): void
    {
        $this->legacyExpense();

        $batch   = $this->approved($this->previewed());
        $journal = $this->batchJournals($batch->id)[0];
        $audit   = $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'journal')->where('entity_id', $journal['id'])->where('action', 'posted')->get()->getRow();

        $this->assertSame($batch->id, json_decode($audit->details, true)['batch_id']);
    }

    public function testDryRun_ChangesNoRowAndGivesTheFingerprintOfThePreview(): void
    {
        $this->legacyInvoice('2026-03-10', [$this->payment(lang('Sales.cash'), '816.50')]);
        $this->legacyPurchase('500', '2026-03-11');
        $this->legacyExpense();
        $this->legacyCashup();

        $before = $this->databaseFingerprint();
        $report = $this->backfill->dryRun(['from' => '2026-01-01', 'to' => '2026-12-31'], $this->backfillManager);

        $this->assertDatabaseUnchanged($before);
        $this->assertSame(4, $report['summary']['documents']);
        $this->assertSame($this->previewed()->fingerprint, $report['fingerprint']);
    }

    public function testPreview_GivesTheSameFingerprintToTwoBatchesOfTheSameDocuments(): void
    {
        $this->legacyInvoice('2026-03-10');
        $this->legacyExpense();

        $first = $this->previewed();

        $this->backfill->cancel($first->id, $this->backfillManager);

        $this->assertSame($first->fingerprint, $this->previewed()->fingerprint);
    }

    public function testApprovalBlockers_NameWhatStandsInTheWayOfApproving(): void
    {
        $this->assertSame([], $this->backfill->approvalBlockers());

        $this->setConfigValue('accounting_backfill_enabled', '0');
        $this->setConfigValue('accounting_start_date', '');
        $this->setConfigValue('accounting_enabled', '0');

        $this->assertSame(['accounting_off', 'start_date_missing', 'backfill_off'], $this->backfill->approvalBlockers());

        $this->setConfigValue('accounting_enabled', '1');
        $this->setConfigValue('accounting_start_date', '2020-01-01');
        $this->setConfigValue('accounting_backfill_enabled', '1');

        $other = $this->createBranch('B2', 'Second branch');

        $this->assertSame(['opening_missing:B2'], $this->backfill->approvalBlockers());
        $this->assertGreaterThan(0, $other);
    }

    public function testApprovalBlockers_AskForTheBranchesTheBatchWritesInAndTheEmployeeWorksIn(): void
    {
        $other = $this->createBranch('B2', 'Second branch');
        $local = $this->employeeWithGrants([AccountingGuard::BACKFILL_MANAGE], [1]);

        $this->legacyExpense('100', '0', '2026-03-12', 1);

        $inHeadquarters = $this->previewed(['name' => 'HQ']);

        $this->assertSame(['opening_missing:B2'], $this->backfill->approvalBlockers($this->backfillManager));
        $this->assertSame([], $this->backfill->approvalBlockers($local));
        $this->assertSame([], $this->backfill->approvalBlockers($this->employeeWithGrants([AccountingGuard::BACKFILL_MANAGE], [])));
        $this->assertSame([], $this->backfill->approvalBlockers($this->backfillManager, $inHeadquarters->id));

        $this->backfill->cancel($inHeadquarters->id, $this->backfillManager);
        $this->legacyExpense('100', '0', '2026-03-12', $other);

        $inSecond = $this->previewed(['name' => 'B2', 'branch_id' => $other]);

        $this->assertSame(['opening_missing:B2'], $this->backfill->approvalBlockers($this->backfillManager, $inSecond->id));
        $this->assertSame([], $this->backfill->approvalBlockers($this->backfillManager, $this->emptyBatch()));
    }

    public function testApprove_RequiresTheOpeningBalancesOnlyOfTheBranchesTheBatchWritesIn(): void
    {
        $this->createBranch('B2', 'Second branch');
        $this->legacyExpense('100', '0', '2026-03-12', 1);

        $this->assertSame(BackfillStatus::Approved, $this->approved($this->previewed())->status);
    }

    public function testApprove_RefusesABatchWhoseJournalsWereTamperedWith(): void
    {
        $this->legacyExpense();

        $batch = $this->previewed();

        $this->accountingDb()->table('accounting_journals')->where('backfill_batch_id', $batch->id)->update(['status' => 'draft']);

        $this->assertRefusedWith(AccountingError::BackfillPreviewChanged, fn () => $this->approved($batch));
    }

    public function testApprove_RefusesABatchThatHoldsAPreviewTheFingerprintDoesNotCover(): void
    {
        $this->legacyExpense();

        $batch = $this->previewed();

        $this->insertPreview($batch->id, [new JournalLineDraft($this->accountId('cash'), '10.00'), new JournalLineDraft($this->accountId('retained_earnings'), '0', '10.00')]);

        $this->assertRefusedWith(AccountingError::BackfillPreviewChanged, fn () => $this->approved($batch));
        $this->assertSame(BackfillStatus::Preview, $this->statusOf($batch->id));
    }

    public function testApprove_WaitsForAChangeToTheDocumentThatIsRunningAndGoesOnWhenItIsDone(): void
    {
        $expense = $this->legacyExpense();
        $batch   = $this->previewed();
        $other   = $this->openSecondConnection();

        $other->transStart();
        $other->query('SELECT expense_id FROM ' . $other->prefixTable('expenses') . ' WHERE expense_id = ? FOR UPDATE', [$expense]);
        $this->accountingDb()->query('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $this->assertWaitsForLock(fn () => $this->approved($batch));
        } finally {
            $other->transRollback();
            $other->close();
            $this->releaseOpenTransactions();
            $this->accountingDb()->query('SET SESSION innodb_lock_wait_timeout = 50');
        }

        $this->assertSame(BackfillStatus::Approved, $this->approved($this->backfill->batch($batch->id, $this->backfillManager))->status);
    }

    public function testApprove_ReadsEveryDocumentOfAChunkOnlyAfterHoldingTheLocksOfThemAll(): void
    {
        $this->legacyExpense('100', '0', '2026-03-12');

        $second = $this->legacyExpense('200', '0', '2026-03-13');
        $batch  = $this->previewed(['chunk_size' => 2]);

        $this->assertSame('0', $this->approveInAnotherProcessWhileSomeoneEdits($batch, $second));
        $this->assertSame(0, $this->accountingDb()->table('accounting_journals')->where('source_type', 'expense')->where('status', 'posted')->countAllResults());
        $this->assertSame(BackfillStatus::Approving, $this->statusOf($batch->id));
        $this->assertSame(0, $this->backfill->batch($batch->id, $this->backfillManager)->approvedCount);
    }

    public function testApprove_ResumedByAnotherProcessReadsEveryDocumentOfAChunkOnlyAfterHoldingTheLocksOfThemAll(): void
    {
        $this->legacyExpense('100', '0', '2026-03-12');
        $this->legacyExpense('150', '0', '2026-03-13');
        $this->legacyExpense('200', '0', '2026-03-14');

        $last  = $this->legacyExpense('250', '0', '2026-03-15');
        $batch = $this->previewed(['chunk_size' => 2]);

        $this->backfill->approve($batch->id, (string) $batch->fingerprint, $this->backfillManager, 1);

        $this->assertSame('0', $this->approveInAnotherProcessWhileSomeoneEdits($batch, $last));
        $this->assertSame(2, $this->accountingDb()->table('accounting_journals')->where('source_type', 'expense')->where('status', 'posted')->countAllResults());
        $this->assertSame(2, $this->backfill->batch($batch->id, $this->backfillManager)->approvedCount);
    }

    /**
     * Holds the row of an expense, changed and not saved yet, while another process approves the batch, lets that
     * process wait for it, saves the change and returns what the process says: 1 when the batch was approved.
     */
    private function approveInAnotherProcessWhileSomeoneEdits(BackfillBatch $batch, int $expenseId): string
    {
        $other = $this->openSecondConnection();

        $other->transStart();
        $other->table('expenses')->where('expense_id', $expenseId)->update(['amount' => '999']);

        $worker = $this->startWorker('backfillApprove', [(string) $batch->id, (string) $batch->fingerprint, (string) $this->backfillManager]);

        try {
            $this->waitForALockWait();
        } finally {
            $other->transComplete();
            $other->close();
        }

        return $this->finishWorker($worker);
    }

    public function testApprove_WaitsForTheChangeOfACustomerThatIsRunning(): void
    {
        $this->legacyInvoice('2026-03-10');

        $batch   = $this->previewed();
        $service = new BackfillService(null, null, null, 1);
        $other   = $this->openSecondConnection();
        $name    = 'ospos_customer_' . substr(sha1($this->accountingDb()->getDatabase()), 0, 10) . '_' . $this->customerId;

        $this->assertSame(1, (int) $other->query('SELECT GET_LOCK(?, 0) AS got', [$name])->getRow()->got);

        try {
            $this->assertRefusedWith(AccountingError::CustomerBusy, fn () => $service->approve($batch->id, (string) $batch->fingerprint, $this->backfillManager));
        } finally {
            $other->query('SELECT RELEASE_LOCK(?)', [$name]);
            $other->close();
        }

        $this->assertSame(['preview'], array_values(array_unique(array_column($this->batchJournals($batch->id), 'status'))));
        $this->assertSame(BackfillStatus::Approved, $service->approve($batch->id, (string) $batch->fingerprint, $this->backfillManager)->status);
    }

    public function testStamp_RefusesADocumentThatHasABranchAlready(): void
    {
        $expense = $this->legacyExpense('100', '0', '2026-03-12', 1);
        $batch   = $this->previewed();
        $method  = new ReflectionMethod($this->backfill, 'stamp');
        $stamp   = ['table' => 'expenses', 'key' => 'expense_id', 'id' => $expense, 'null' => 'branch_id', 'columns' => ['branch_id' => 1]];

        try {
            $method->invoke($this->backfill, $stamp, $batch, $this->backfillManager);
            $this->fail('A document with a branch was stamped.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::BackfillPreviewChanged, $exception->error);
        }

        $method->invoke($this->backfill, [], $batch, $this->backfillManager);
        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where('action', 'backfill_stamped')->countAllResults());
    }

    public function testPost_RefusesARunThatReadsABatchAnotherRunHasMovedOn(): void
    {
        $this->legacyExpense('100', '0', '2026-03-12');
        $this->legacyExpense('200', '0', '2026-03-13');

        $batch   = $this->previewed(['chunk_size' => 1]);
        $stale   = $this->backfill->approve($batch->id, (string) $batch->fingerprint, $this->backfillManager, 1);
        $current = $this->backfill->approve($batch->id, (string) $batch->fingerprint, $this->backfillManager, 1);
        $method  = new ReflectionMethod($this->backfill, 'post');

        $this->assertSame(BackfillStatus::Approving, $current->status);
        $this->assertSame(2, $current->approvedCount);
        $this->assertRefusedWith(AccountingError::BackfillBusy, fn () => $method->invoke($this->backfill, $stale, $this->backfillManager, null));
    }

    public function testApprove_PutsThePostedTaxOfThePastDocumentsInTheTaxReportThatCountedNothingBefore(): void
    {
        $this->legacyInvoice('2026-03-10');

        $report = static fn (): string => (new TaxReport())->build(ReportScope::resolve(null, null), ReportPeriod::of('2026-03-01', '2026-03-31'))->net();
        $before = $report();

        $this->approved($this->previewed());

        $this->assertSame('0.0000', bcadd($before, '0', 4));
        $this->assertSame('106.5000', bcadd($report(), '0', 4));
    }

    public function testApprove_LeavesThePastSaleToTheLivePostingForWhatHappensToItNext(): void
    {
        $saleId = $this->legacyInvoice('2026-03-10', [$this->payment(lang('Sales.cash'), '300')]);

        $this->approved($this->previewed());

        $paymentId = $this->addPayment($saleId, '100');

        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('source_type', 'sale_payment')->where('source_id', $paymentId)->where('status', 'posted')->countAllResults());
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('source_type', 'sale')->where('source_id', $saleId)->where('entry_type', 'revenue')->countAllResults());
        $this->assertTies();
    }

    public function testApprove_LeavesAPastSaleThatIsCancelledAfterwardsToBeReversedWithItsStockAndKeepsTheNumbers(): void
    {
        [$saleId, $cycle] = $this->whileOff(function (): array {
            $cycle = $this->referenceInvoice();

            return [$this->dateSale($this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '816.50')], SALE_TYPE_POS), '2026-03-10'), $cycle];
        });

        $creams = $this->stock($cycle['cream']);
        $batch  = $this->previewed();

        $this->approved($batch);

        $posted = $this->batchJournals($batch->id);

        $this->assertSame(['posted', 'posted', 'posted'], array_column($posted, 'status'));
        $this->assertSame(2, $this->stockEntries($saleId, 'posting'));
        $this->assertSame(0, $this->stockEntries($saleId, 'reversal'));

        $this->assertTrue($this->sales->delete($saleId, false, true, $this->employeeId));

        $reversed = $this->batchJournals($batch->id);

        $this->assertSame([], $this->standing($saleId));
        $this->assertSame(['reversed', 'reversed', 'reversed'], array_column($reversed, 'status'));
        $this->assertSame(array_column($posted, 'number'), array_column($reversed, 'number'));
        $this->assertSame(array_column($posted, 'lines'), array_column($reversed, 'lines'));
        $this->assertSame($creams + 4.0, $this->stock($cycle['cream']));
        $this->assertSame('0.00', $this->balance($this->customerAccount()));
        $this->assertSame(2, $this->stockEntries($saleId, 'reversal'));
        $this->assertStockTies();
        $this->assertTies();
    }

    private function stockEntries(int $saleId, string $kind): int
    {
        return $this->accountingDb()->table('accounting_stock_entries')->where('source_type', 'sale')->where('source_id', $saleId)->where('kind', $kind)->countAllResults();
    }

    public function testApprove_LeavesAPastInvoiceThatIsReturnedAfterwardsToTheLivePostingAndKeepsEveryTie(): void
    {
        [$invoice, $cycle] = $this->whileOff(function (): array {
            $cycle = $this->referenceInvoice();

            return [$this->dateSale($this->save($cycle['items'], $cycle['taxes']), '2026-03-10'), $cycle];
        });

        $this->approved($this->previewed());

        $return = $this->save([$this->line($cycle['cream'], 0, '-1', '75', '40', '10')], [], [], SALE_TYPE_RETURN, -1, true, $invoice);

        $this->assertGreaterThan(0, $return);
        $this->assertNotSame([], $this->standing($return));
        $this->assertCount(1, $this->standing($invoice, 'revenue'));
        $this->assertStockTies();
        $this->assertTies();
    }

    public function testApprove_LeavesAPaymentOfAPastInvoiceThatIsRemovedAfterwardsToBeReversedByTheLivePosting(): void
    {
        $saleId = $this->legacyInvoice('2026-03-10', [$this->payment(lang('Sales.cash'), '300')]);
        $batch  = $this->previewed();

        $this->approved($batch);

        $payment = (int) $this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->get()->getRow()->payment_id;
        $before  = $this->balance($this->accountId('cash'));

        $this->removePayment($saleId, $payment);

        $paymentJournal = array_values(array_filter($this->batchJournals($batch->id), static fn (array $journal): bool => str_starts_with($journal['key'], 'sale_payment:')))[0];

        $this->assertSame('reversed', $paymentJournal['status']);
        $this->assertNotNull($paymentJournal['number']);
        $this->assertSame(bcsub($before, '300.00', 2), $this->balance($this->accountId('cash')));
        $this->assertTies();
    }

    public function testApprove_LeavesAnExpenseThatIsEditedAndThenDeletedAfterwardsToBeReversedAndPostedAgainByTheLivePosting(): void
    {
        $expenseId = $this->legacyExpense('230', '30', '2026-03-12');
        $batch     = $this->previewed();

        $this->approved($batch);

        $model = model(Expense::class);
        $row   = $this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow();
        $data  = [
            'date'                => $row->date,
            'supplier_id'         => null,
            'supplier_tax_code'   => '',
            'amount'              => '115.00',
            'tax_amount'          => '15.00',
            'payment_type'        => $row->payment_type,
            'expense_category_id' => $row->expense_category_id,
            'description'         => $row->description,
            'employee_id'         => $this->backfillManager,
            'deleted'             => 0,
        ];

        $this->assertTrue($model->save_value($data, $expenseId, $this->backfillManager), (string) $model->accountingFailure()?->getMessage());

        $journals = $this->accountingDb()->table('accounting_journals')->where('source_type', 'expense')->where('source_id', $expenseId)->whereIn('status', ['posted', 'reversed'])->orderBy('journal_id', 'ASC')->get()->getResult();

        $this->assertSame([['reversed', '1'], ['posted', '2']], array_map(static fn (object $journal): array => [$journal->status, (string) $journal->revision], array_values(array_filter($journals, static fn (object $journal): bool => $journal->reverses_journal_id === null))));
        $this->assertSame((string) $this->batchJournals($batch->id)[0]['number'], (string) $journals[0]->number);
        $this->assertSame('885.00', $this->balance($this->accountId('cash')));

        $this->assertTrue($model->delete_list([$expenseId], $this->backfillManager), (string) $model->accountingFailure()?->getMessage());
        $this->assertSame(0, $this->accountingDb()->table('accounting_journals')->where('source_type', 'expense')->where('source_id', $expenseId)->where('status', 'posted')->where('reverses_journal_id IS NULL', null, false)->countAllResults());
        $this->assertSame('1000.00', $this->balance($this->accountId('cash')));
    }

    public function testPreview_ReadsNoFiscalYearIntoBeingForADocumentOutsideEveryYear(): void
    {
        $this->legacyExpense('100', '0', '2037-06-10');

        $years  = $this->countRows('accounting_fiscal_years');
        $batch  = $this->previewed(['from' => '2037-01-01', 'to' => '2037-12-31']);

        $this->assertSame(['outside_fiscal_year' => 1], $this->backfill->repository()->reasons($batch->id));
        $this->assertSame($years, $this->countRows('accounting_fiscal_years'));
    }

    public function testApprove_RefusesWhenAnAccountOfAPreviewWasDeactivatedSinceAndPostsNothing(): void
    {
        $this->legacyExpense('230', '30', '2026-03-12');

        $batch = $this->previewed();

        $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->accountId('tax_input'))->update(['active' => 0]);

        $before = $this->booksSnapshot();

        try {
            $this->assertRefusedWith(AccountingError::BackfillPreviewChanged, fn () => $this->approved($batch));
            $this->assertSame($before, $this->booksSnapshot());
        } finally {
            $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->accountId('tax_input'))->update(['active' => 1]);
        }
    }

    public function testPreview_MarksTheDocumentsThatWillBeStampedOnTheirItem(): void
    {
        $this->legacyExpense();
        $this->legacyExpense('80', '0', '2026-03-13', 1);

        $batch = $this->previewed();
        $rows  = $this->accountingDb()->table('accounting_backfill_items')->where('batch_id', $batch->id)->orderBy('item_id')->get()->getResult();

        $this->assertSame('stamp:branch_id', $rows[0]->detail);
        $this->assertNull($rows[1]->detail);
    }

    public function testDryRun_RefusesABranchOutsideTheScopeOfTheEmployee(): void
    {
        $other = $this->createBranch('B2', 'Second branch');
        $local = $this->employeeWithGrants([AccountingGuard::BACKFILL_MANAGE], [1]);

        $this->assertBranchRefusedWith(BranchError::NotAllowed, fn () => $this->backfill->dryRun(['from' => '2026-01-01', 'to' => '2026-12-31', 'branch_id' => $other], $local));
        $this->assertBranchRefusedWith(BranchError::CompanyScopeNeeded, fn () => $this->backfill->dryRun(['from' => '2026-01-01', 'to' => '2026-12-31'], $local));
        $this->assertSame(0, $this->backfill->dryRun(['from' => '2026-01-01', 'to' => '2026-12-31', 'branch_id' => 1], $local)['summary']['documents']);
    }

    public function testPreview_RefusesWhileAccountingIsOff(): void
    {
        $batch = $this->previewed([], false);

        $this->setConfigValue('accounting_enabled', '0');

        $this->assertRefusedWith(AccountingError::BackfillAccountingOff, fn () => $this->backfill->preview($batch->id, $this->backfillManager));
        $this->assertSame(BackfillStatus::Collecting, $this->statusOf($batch->id));
    }

    public function testApprove_WaitsForTheChangeOfASupplierThatIsRunning(): void
    {
        $this->legacyPurchase('500', '2026-03-10');

        $batch   = $this->previewed();
        $service = new BackfillService(null, null, null, 1);
        $other   = $this->openSecondConnection();
        $name    = 'ospos_supplier_' . substr(sha1($this->accountingDb()->getDatabase()), 0, 10) . '_' . $this->supplierId;

        $this->assertSame(1, (int) $other->query('SELECT GET_LOCK(?, 0) AS got', [$name])->getRow()->got);

        try {
            $this->assertRefusedWith(AccountingError::SupplierBusy, fn () => $service->approve($batch->id, (string) $batch->fingerprint, $this->backfillManager));
        } finally {
            $other->query('SELECT RELEASE_LOCK(?)', [$name]);
            $other->close();
        }

        $this->assertSame(BackfillStatus::Approved, $service->approve($batch->id, (string) $batch->fingerprint, $this->backfillManager)->status);
    }

    public function testApprove_SettlesThePaymentOfASupplierBeyondAPurchaseOnTheOldestOpenOneAndKeepsTheLedgerTied(): void
    {
        $oldest = $this->legacyPurchase('100', '2026-03-10');
        $small  = $this->legacyPurchase('50', '2026-03-12');

        $this->whileOff(fn (): int => $this->payPurchase($small, '120'));

        $batch = $this->previewed();

        $this->assertSame([], $this->supplierApplications());

        $this->approved($batch);

        $this->assertSame(['invoice_excess:' . $small . '->' . $oldest . ':70.00'], $this->supplierApplications());
        $this->assertSupplierTies();
    }

    public function testPreview_RefusesACounterSaleThatWasNotSettledInFullAndSaysWhy(): void
    {
        $saleId = $this->whileOff(function (): int {
            $cycle = $this->referenceInvoice();

            return $this->dateSale($this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '100')], SALE_TYPE_POS, null), '2026-03-10');
        });

        $batch = $this->previewed();
        $row   = $this->accountingDb()->table('accounting_backfill_items')->where('batch_id', $batch->id)->where('document_id', $saleId)->get()->getRow();

        $this->assertSame('posting_refused', $row->reason);
        $this->assertSame('credit_needs_customer', $row->detail);
    }
}
