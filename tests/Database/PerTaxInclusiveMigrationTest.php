<?php

namespace Tests\Database;

use App\Database\Migrations\PerTaxInclusive;
use App\Models\Appconfig;
use App\Models\Sale;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * @internal
 */
final class PerTaxInclusiveMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

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
        (new PerTaxInclusive())->up();

        $this->accountingDb()->table('sales_items_taxes')->update(['liability_account_id' => null]);
        $this->accountingDb()->table('tax_definitions')->truncate();
        $this->stopSelling();

        parent::tearDown();
    }

    private function hasTable(string $table): bool
    {
        return $this->accountingDb()->query('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$this->accountingDb()->prefixTable($table)])->getRow() !== null;
    }

    /**
     * @return array{type: string, nullable: string, default: ?string}|null
     */
    private function column(string $table, string $column): ?array
    {
        $row = $this->accountingDb()->query(
            'SELECT COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS dflt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->accountingDb()->prefixTable($table), $column],
        )->getRow();

        if ($row === null) {
            return null;
        }

        return ['type' => preg_replace('/\b(int|bigint|smallint|mediumint)\(\d+\)/', '$1', strtolower((string) $row->type)), 'nullable' => (string) $row->nullable, 'default' => in_array($row->dflt, [null, 'NULL'], true) ? null : $row->dflt];
    }

    private function hasIndex(string $table, string $index): bool
    {
        return $this->accountingDb()->query(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$this->accountingDb()->prefixTable($table), $this->accountingDb()->prefixTable($index)],
        )->getRow() !== null;
    }

    /**
     * @return list<array<string, mixed>> The tax rows of a sale without the column the migration adds.
     */
    private function taxRowsOf(int $saleId): array
    {
        $rows = $this->accountingDb()->table('sales_items_taxes')->where('sale_id', $saleId)->orderBy('name', 'ASC')->get()->getResultArray();

        return array_map(static fn (array $row): array => array_diff_key($row, ['liability_account_id' => true]), $rows);
    }

    private function amountDueOf(int $saleId): string
    {
        $this->dropReportTables();

        return bcadd((string) model(Sale::class)->get_info($saleId)->getRow()->amount_due, '0', 2);
    }

    private function mixedSale(): int
    {
        $item = $this->item('0', false);

        return $this->save([$this->line($item, 0, '1', '100')], [$this->taxRow($item, 0, 'VAT', '15.0000', 0, '13.0435'), $this->taxRow($item, 0, 'Service', '5.0000', 1, '4.35')], [], SALE_TYPE_POS);
    }

    public function testUp_CreatesTheTaxListAndTheColumnOfTheAccountAWholeTaxRowIsSavedWith(): void
    {
        $this->assertTrue($this->hasTable('tax_definitions'));
        $this->assertSame(['type' => 'tinyint(1)', 'nullable' => 'YES', 'default' => null], $this->column('tax_definitions', 'tax_included'));
        $this->assertSame(['type' => 'int unsigned', 'nullable' => 'YES', 'default' => null], $this->column('tax_definitions', 'liability_account_id'));
        $this->assertSame(['type' => 'int unsigned', 'nullable' => 'YES', 'default' => null], $this->column('sales_items_taxes', 'liability_account_id'));
        $this->assertTrue($this->hasIndex('sales_items_taxes', 'sales_items_taxes_liability'));
    }

    public function testUp_KeepsTheNameOfATaxUniqueWhateverItsCase(): void
    {
        $this->accountingDb()->table('tax_definitions')->insert(['tax_name' => 'VAT']);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/Duplicate entry/');

        $this->accountingDb()->table('tax_definitions')->insert(['tax_name' => 'vat']);
    }

    public function testUp_SetsTheRequirementOfATaxOffAndNeverMovesItBack(): void
    {
        $this->assertSame('0', model(Appconfig::class)->get_value('require_tax_1', 'missing'));

        $this->setConfigValue('require_tax_1', '1');

        (new PerTaxInclusive())->up();

        $this->assertSame('1', model(Appconfig::class)->get_value('require_tax_1'));

        $this->setConfigValue('require_tax_1', '0');
    }

    public function testUp_IsSafeToRunAgain(): void
    {
        (new PerTaxInclusive())->up();
        (new PerTaxInclusive())->up();

        $this->assertTrue($this->hasTable('tax_definitions'));
        $this->assertNotNull($this->column('sales_items_taxes', 'liability_account_id'));
        $this->assertTrue($this->hasIndex('sales_items_taxes', 'sales_items_taxes_liability'));
    }

    public function testUp_FinishesWhatWasStoppedHalfWay(): void
    {
        $taxes = $this->accountingDb()->prefixTable('sales_items_taxes');

        $this->accountingDb()->query("ALTER TABLE `{$taxes}` DROP KEY `{$taxes}_liability`");

        $this->assertFalse($this->hasIndex('sales_items_taxes', 'sales_items_taxes_liability'));

        (new PerTaxInclusive())->up();

        $this->assertTrue($this->hasIndex('sales_items_taxes', 'sales_items_taxes_liability'));
    }

    public function testUp_LeavesEveryTaxRowOfASaleAndWhatTheSaleCameToExactlyAsTheyWere(): void
    {
        $saleId    = $this->mixedSale();
        $rows      = $this->taxRowsOf($saleId);
        $due       = $this->amountDueOf($saleId);
        $migration = new PerTaxInclusive();

        $migration->down();

        $this->assertNull($this->column('sales_items_taxes', 'liability_account_id'), 'the state before the migration');
        $this->assertSame($rows, $this->taxRowsOf($saleId));
        $this->assertSame($due, $this->amountDueOf($saleId));

        $migration->up();

        $this->assertSame($rows, $this->taxRowsOf($saleId));
        $this->assertSame($due, $this->amountDueOf($saleId));
        $this->assertSame('104.35', $due);
        $this->assertSame(0, $this->accountingDb()->table('sales_items_taxes')->where('sale_id', $saleId)->where('liability_account_id IS NOT NULL', null, false)->countAllResults());
    }

    public function testDown_RefusesOnceATaxRowOfASaleHoldsAnAccountAndChangesNothing(): void
    {
        $account = $this->customAccount('215', '215003', 'Tax on services');
        $item    = $this->item('0', false);
        $saleId  = $this->save([$this->line($item, 0, '1', '100')], [$this->taxRow($item, 0, 'VAT', '15.0000', 1, '15.00', $account)], [], SALE_TYPE_POS);

        try {
            (new PerTaxInclusive())->down();
            $this->fail('The migration was taken back with an account in a tax row.');
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString('restore from a backup', $refusal->getMessage());
        }

        $this->assertNotNull($this->column('sales_items_taxes', 'liability_account_id'));
        $this->assertTrue($this->hasTable('tax_definitions'));
        $this->assertSame((string) $account, (string) $this->accountingDb()->table('sales_items_taxes')->where('sale_id', $saleId)->get()->getRow()->liability_account_id);
    }

    public function testDown_RemovesTheTaxListTheColumnAndTheIndexWhenNoRowHoldsAnAccountAndTakingItDownAgainChangesNothing(): void
    {
        $migration = new PerTaxInclusive();

        $migration->down();
        $migration->down();

        $this->assertFalse($this->hasTable('tax_definitions'));
        $this->assertNull($this->column('sales_items_taxes', 'liability_account_id'));
        $this->assertFalse($this->hasIndex('sales_items_taxes', 'sales_items_taxes_liability'));
    }
}
