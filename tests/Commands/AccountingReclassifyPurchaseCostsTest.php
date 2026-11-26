<?php

namespace Tests\Commands;

use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\PurchaseCostReclass;
use App\Libraries\Accounting\ReceivingPostingBuilder;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

class AccountingReclassifyPurchaseCostsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;
    use AccountingFixtureTrait;
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

        $this->startBuying();
    }

    protected function tearDown(): void
    {
        $this->stopBuying();

        parent::tearDown();
    }

    private function purchaseOnTheCostOfSales(string $amount): void
    {
        (new PostingService())->post(new JournalDraft(
            date: $this->databaseToday(),
            lines: [new JournalLineDraft($this->accountId('cogs'), debit: $amount), new JournalLineDraft($this->supplierAccount(), credit: $amount)],
            sourceType: ReceivingPostingBuilder::SOURCE_PURCHASE,
            sourceId: $this->purchaseOf('10.00'),
            revision: 2,
            employeeId: 1,
            branchId: 1,
        ));
    }

    private function runReclass(string $arguments = ''): string
    {
        $this->resetStreamFilterBuffer();
        command('accounting:reclassify_purchase_costs ' . $arguments);

        return $this->getStreamFilterBuffer();
    }

    private function reclassJournals(): int
    {
        return $this->accountingDb()->table('accounting_journals')->where('source_type', PurchaseCostReclass::SOURCE)->countAllResults();
    }

    public function testRun_ListsWhatIsPendingAndWritesNothingWithoutTheOption(): void
    {
        $this->purchaseOnTheCostOfSales('80.00');

        $output = $this->runReclass();

        $this->assertStringContainsString('Branch 1: 80.0000 still to move.', $output);
        $this->assertStringContainsString('Nothing was written.', $output);
        $this->assertSame(0, $this->reclassJournals());
    }

    public function testRun_PostsTheJournalOnlyWhenAskedTo(): void
    {
        $this->purchaseOnTheCostOfSales('80.00');

        $this->assertStringContainsString('Branch 1: journal ', $this->runReclass('--post'));
        $this->assertSame(1, $this->reclassJournals());
        $this->assertStringContainsString('Nothing is pending in the fiscal year open today.', $this->runReclass('--post'));
        $this->assertSame(1, $this->reclassJournals());
    }

    public function testRun_AcceptsBooksWhosePurchasesNeverTouchedTheCostOfSales(): void
    {
        $this->purchaseOf('500.00');

        $this->assertStringContainsString('No purchase journal put a line on the cost of sales.', $this->runReclass('--post'));
    }
}
