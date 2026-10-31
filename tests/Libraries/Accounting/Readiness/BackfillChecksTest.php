<?php

namespace Tests\Libraries\Accounting\Readiness;

use App\Libraries\Accounting\Readiness\BackfillChecks;
use App\Libraries\Accounting\Readiness\CheckResult;
use App\Libraries\Accounting\Readiness\CheckStatus;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\BackfillFixtureTrait;
use Tests\Support\DatabaseFingerprintTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PreviewJournalFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * @internal
 */
final class BackfillChecksTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use BackfillFixtureTrait;
    use DatabaseFingerprintTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use PreviewJournalFixtureTrait;
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

        $this->startBackfill(true, false);
    }

    protected function tearDown(): void
    {
        $this->stopBackfill();

        parent::tearDown();
    }

    private function outcome(): CheckResult
    {
        return (new BackfillChecks($this->accountingDb()))->run()[0];
    }

    public function testRun_GivesOneCheckWithItsReference(): void
    {
        $result = $this->outcome();

        $this->assertSame('backfill', $result->id);
        $this->assertSame('A6-BACKFILL', $result->ref);
    }

    public function testRun_PassesWhenThePostingIsOffNoBatchIsOpenAndEveryDocumentHasItsJournal(): void
    {
        $result = $this->outcome();

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertFalse($result->data['enabled']);
        $this->assertSame([], $result->data['waiting']);
    }

    public function testRun_StatesWhatApprovingNeeds(): void
    {
        $result = $this->outcome();

        $this->assertStringContainsString('accounting_backfill_enabled', $result->findings[0]);
        $this->assertStringContainsString('opening balances', $result->findings[0]);
        $this->assertStringContainsString('start date', $result->findings[0]);
    }

    public function testRun_ReviewsDocumentsWithNoJournalAndCountsThemByFamily(): void
    {
        $this->legacyInvoice('2026-03-10');
        $this->legacyExpense();
        $this->legacyExpense('50', '0', '2026-03-13');

        $result = $this->outcome();

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertSame(['sales' => 1, 'expenses' => 2], $result->data['waiting']);
    }

    public function testRun_ListsWhatStandsInTheWayOfApproving(): void
    {
        $this->accountingDb()->table('accounting_journals')->where('source_type', 'opening')->update(['source_type' => 'manual']);
        $this->setConfigValue('accounting_backfill_enabled', '1');

        $result = $this->outcome();

        $this->assertSame(['opening_missing:HQ'], $result->data['blockers']);
        $this->assertSame(CheckStatus::Review, $result->status);
    }

    public function testRun_ListsTheOpenBatches(): void
    {
        $this->legacyExpense();

        $batch  = $this->previewed();
        $result = $this->outcome();

        $this->assertSame([['batch_id' => $batch->id, 'name' => $batch->name, 'status' => 'preview', 'ready' => 1, 'refused' => 0]], $result->data['open_batches']);
        $this->assertSame(CheckStatus::Review, $result->status);
    }

    public function testRun_FailsWhenAPreviewBelongsToNoOpenBatch(): void
    {
        $this->legacyExpense();

        $batch = $this->previewed();

        $this->backfill->cancel($batch->id, $this->backfillManager);
        $this->accountingDb()->table('accounting_journals')->insert(['company_id' => 1, 'branch_id' => 1, 'series' => 'auto', 'journal_date' => '2026-03-10', 'status' => 'preview', 'source_type' => 'sale', 'source_id' => 5, 'currency_code' => 'SAR', 'backfill_batch_id' => $batch->id]);

        $result = $this->outcome();

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame(1, $result->data['orphan_previews']);
    }

    public function testRun_ChangesNoRow(): void
    {
        $this->legacyInvoice('2026-03-10');
        $this->previewed();

        $before = $this->databaseFingerprint();

        $this->outcome();

        $this->assertDatabaseUnchanged($before);
    }
}
