<?php

namespace Tests\Commands;

use App\Libraries\Accounting\PostingService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

class AccountingBooksSnapshotTest extends CIUnitTestCase
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
    private string $file;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBuying();
        $this->file = WRITEPATH . 'books-snapshot-' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }

        $this->stopBuying();

        parent::tearDown();
    }

    private function runSnapshot(string $arguments): string
    {
        $this->resetStreamFilterBuffer();
        command('accounting:books_snapshot ' . $arguments);

        return $this->getStreamFilterBuffer();
    }

    public function testRun_WritesTheBalancesOfTheAccountsAndTheStockToTheFile(): void
    {
        (new PostingService())->post($this->balancedDraft('sale', 9201, 1, '100.00'));

        $output   = $this->runSnapshot($this->file);
        $snapshot = json_decode((string) file_get_contents($this->file), true);

        $this->assertStringContainsString('Company 1: 2 account(s) with movements, debit 100.0000, credit 100.0000', $output);
        $this->assertSame([1], array_keys($snapshot['companies']));
        $this->assertSame('100.0000', $snapshot['companies'][1]['accounts'][$this->accountId('cash')]['branches'][1]['debit']);
        $this->assertSame(['debit' => '100.0000', 'credit' => '100.0000'], $snapshot['companies'][1]['totals']);
    }

    public function testRun_WritesTheBooksOfEveryCompanyWhenAskedForAll(): void
    {
        $this->accountingDb()->table('companies')->insert(['company_id' => 2, 'name' => 'Second company']);

        try {
            $output   = $this->runSnapshot($this->file . ' --all-companies');
            $snapshot = json_decode((string) file_get_contents($this->file), true);
        } finally {
            $this->accountingDb()->table('companies')->where('company_id', 2)->delete();
        }

        $this->assertSame([1, 2], array_keys($snapshot['companies']));
        $this->assertStringContainsString('Company 2: 0 account(s) with movements', $output);
    }

    public function testRun_RequiresAFileToWriteTo(): void
    {
        $this->assertStringContainsString('Name the file to write the snapshot to.', $this->runSnapshot(''));
        $this->assertFileDoesNotExist($this->file);
    }

    public function testRun_RejectsAFileItCannotWrite(): void
    {
        $this->assertStringContainsString('could not be written', $this->runSnapshot(WRITEPATH . 'no-such-directory/snapshot.json'));
    }
}
