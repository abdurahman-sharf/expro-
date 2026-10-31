<?php

namespace Tests\Libraries\Accounting\Backfill;

use App\Libraries\Accounting\Backfill\BackfillService;
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
}
