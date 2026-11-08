<?php

namespace Tests\Commands;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\DatabaseFingerprintTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The benchmark command refuses to run unless it is told the name of the database, generates its own
 * documents, prints a table of timings and query counts, and leaves no row behind. What it measures is
 * recorded and never compared with anything here.
 */
class AccountingBenchmarkTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use DatabaseFingerprintTrait;

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

        $this->startSelling();
    }

    protected function tearDown(): void
    {
        $this->stopSelling();
        $this->clearJournals();

        parent::tearDown();
    }

    /**
     * @param array<string, string|null> $params
     *
     * @return array{int, string}
     */
    private function benchmark(array $params): array
    {
        $this->resetStreamFilterBuffer();
        $exit = service('commands')->run('accounting:benchmark', $params);

        return [$exit, $this->getStreamFilterBuffer()];
    }

    private function database(): string
    {
        return Database::connect()->getDatabase();
    }

    public function testRun_RefusesWithoutTheNameOfTheDatabaseAndWritesNothing(): void
    {
        $before = $this->databaseFingerprint();

        [$exit, $output] = $this->benchmark(['documents' => '3']);

        $this->assertSame(EXIT_USER_INPUT, $exit);
        $this->assertStringContainsString('--confirm ' . $this->database(), $output);
        $this->assertDatabaseUnchanged($before);
    }

    public function testRun_RefusesAnotherNameThanTheDatabaseItIsConnectedTo(): void
    {
        $before = $this->databaseFingerprint();

        [$exit] = $this->benchmark(['confirm' => 'ospos_production', 'documents' => '3']);

        $this->assertSame(EXIT_USER_INPUT, $exit);
        $this->assertDatabaseUnchanged($before);
    }

    public function testRun_RefusesANumberOfDocumentsThatIsNotAWholeNumberInRange(): void
    {
        foreach (['0', '-4', 'ten', '2.5', '200001', ''] as $documents) {
            [$exit, $output] = $this->benchmark(['confirm' => $this->database(), 'documents' => $documents]);

            $this->assertSame(EXIT_USER_INPUT, $exit, $documents);
            $this->assertStringContainsString('whole number', $output, $documents);
        }
    }

    public function testRun_RefusesWhileTheBooksAreOffBecauseItSavesSalesWithTheirPosting(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        [$exit, $output] = $this->benchmark(['confirm' => $this->database(), 'documents' => '3']);

        $this->assertSame(EXIT_ERROR, $exit);
        $this->assertStringContainsString('accounting', $output);
    }

    public function testRun_TimesTheSaleSaveTheChecksAndTheReportsAndLeavesTheDatabaseUnchanged(): void
    {
        $before = $this->databaseFingerprint();

        [$exit, $output] = $this->benchmark(['confirm' => $this->database(), 'documents' => '6', 'machine' => '4 cores, MariaDB local']);

        $this->assertSame(EXIT_SUCCESS, $exit, $output);

        $start = strpos($output, '{');

        $this->assertNotFalse($start);

        $table = json_decode(substr($output, (int) $start), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(6, $table['documents']);
        $this->assertTrue($table['rolled_back']);
        $this->assertSame([], $table['rows_left']);
        $this->assertSame('4 cores, MariaDB local', $table['environment']['machine']);

        $names = array_column($table['results'], 'name');

        $this->assertSame('sale_save_with_posting', $names[0]);

        foreach (['readiness', 'trial_balance', 'aged_ledger', 'sales_revenue', 'net_position', 'family_purchases', 'family_journals', 'family_stock_operations'] as $expected) {
            $this->assertContains($expected, $names, $expected);
        }

        $sales = $table['results'][0];

        $this->assertSame(0, $sales['refused'], 'every generated sale was saved');
        $this->assertGreaterThan(0, $sales['queries_per_document']);

        foreach ($table['results'] as $result) {
            $this->assertArrayHasKey('ms', $result, $result['name']);
            $this->assertArrayHasKey('queries', $result, $result['name']);
            $this->assertArrayNotHasKey('failed', $result, $result['name'] . ' failed');
        }

        $this->assertDatabaseUnchanged($before, 'The benchmark left rows behind.');
    }

    public function testRun_SavesNoSaleOfItsOwnInTheTablesOfTheSales(): void
    {
        $sales = $this->countSales();

        [$exit] = $this->benchmark(['confirm' => $this->database(), 'documents' => '4']);

        $this->assertSame(EXIT_SUCCESS, $exit);
        $this->assertSame($sales, $this->countSales());
        $this->assertSame(0, $this->accountingDb()->table('people')->like('first_name', 'Benchmark', 'after')->countAllResults());
    }
}
