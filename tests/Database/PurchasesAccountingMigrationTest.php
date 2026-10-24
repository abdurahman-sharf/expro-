<?php

namespace Tests\Database;

use App\Database\Migrations\PurchasesAccounting;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;

class PurchasesAccountingMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    /** @var list<int> */
    private array $createdReceivings = [];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        $db = $this->accountingDb();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        if ($this->hasTable('accounting_stock_entries')) {
            $db->table('accounting_stock_entries')->truncate();
        }

        foreach ($this->createdReceivings as $receivingId) {
            $db->table('receivings_items')->where('receiving_id', $receivingId)->delete();
            $db->table('receivings')->where('receiving_id', $receivingId)->delete();
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->createdReceivings = [];

        if (! $this->hasColumn('receivings', 'receiving_type') || ! $this->hasTable('accounting_stock_entries')) {
            (new PurchasesAccounting())->up();
        }

        parent::tearDown();
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->accountingDb()->query(
            'SELECT COUNT(*) AS found FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->accountingDb()->prefixTable($table), $column],
        )->getRow()->found > 0;
    }

    private function hasTable(string $table): bool
    {
        return $this->accountingDb()->query(
            'SELECT COUNT(*) AS found FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->accountingDb()->prefixTable($table)],
        )->getRow()->found > 0;
    }

    private function hasForeignKey(string $table, string $name): bool
    {
        return $this->accountingDb()->query(
            'SELECT COUNT(*) AS found FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$this->accountingDb()->prefixTable($table), $this->accountingDb()->prefixTable($name), 'FOREIGN KEY'],
        )->getRow()->found > 0;
    }

    /**
     * A purchase as it was saved before the migration, with only the columns it had then.
     *
     * @param list<array{int, string}> $lines Stock location and signed quantity of each line.
     */
    private function legacyPurchase(array $lines): int
    {
        $db         = $this->accountingDb();
        $employeeId = $this->createEmployee();
        $itemId     = $this->createTestItem();

        $db->table('receivings')->insert(['employee_id' => $employeeId, 'comment' => 'legacy']);
        $receivingId             = (int) $db->insertID();
        $this->createdReceivings[] = $receivingId;

        foreach ($lines as $line => [$locationId, $quantity]) {
            $db->table('receivings_items')->insert([
                'receiving_id'       => $receivingId,
                'item_id'            => $itemId,
                'line'               => $line,
                'quantity_purchased' => $quantity,
                'item_cost_price'    => '10.00',
                'item_unit_price'    => '10.00',
                'item_location'      => $locationId,
            ]);
        }

        return $receivingId;
    }

    public function testUp_AddsTheColumnsTheTablesAndTheKeysPostingNeeds(): void
    {
        foreach (['receiving_type', 'return_of_receiving_id', 'branch_id'] as $column) {
            $this->assertTrue($this->hasColumn('receivings', $column), $column);
        }

        $this->assertTrue($this->hasColumn('receivings_items', 'tax_percent'));
        $this->assertTrue($this->hasTable('accounting_stock_entries'));
        $this->assertTrue($this->hasForeignKey('receivings', 'receivings_fk_return_of'));
        $this->assertTrue($this->hasForeignKey('receivings', 'receivings_fk_branch'));
    }

    public function testUp_ClassifiesEveryPurchaseThatExistsByTheSignsOfItsLines(): void
    {
        $migration = new PurchasesAccounting();
        $migration->down();

        $in         = $this->legacyPurchase([[1, '5.000']]);
        $out        = $this->legacyPurchase([[1, '-2.000']]);
        $transfer   = $this->legacyPurchase([[1, '-3.000'], [1, '3.000']]);
        $noLines    = $this->legacyPurchase([]);

        $migration->up();

        $types = $this->accountingDb()->table('receivings')->select('receiving_id, receiving_type')->whereIn('receiving_id', [$in, $out, $transfer, $noLines])->get()->getResult();
        $types = array_column($types, 'receiving_type', 'receiving_id');

        $this->assertSame('receive', $types[$in]);
        $this->assertSame('return', $types[$out]);
        $this->assertSame('requisition', $types[$transfer]);
        $this->assertSame('receive', $types[$noLines]);
    }

    public function testUp_PlacesEveryPurchaseInTheBranchOfItsStockLocation(): void
    {
        $migration = new PurchasesAccounting();
        $migration->down();

        $branch   = $this->createBranch('M4', 'Migration branch');
        $location = $this->insertStockLocation('Migration depot');
        $this->accountingDb()->table('stock_locations')->where('location_id', $location)->update(['branch_id' => $branch]);

        $there = $this->legacyPurchase([[$location, '5.000']]);
        $here  = $this->legacyPurchase([[1, '5.000']]);

        $migration->up();

        $this->assertSame($branch, (int) $this->accountingDb()->table('receivings')->where('receiving_id', $there)->get()->getRow()->branch_id);
        $this->assertSame(1, (int) $this->accountingDb()->table('receivings')->where('receiving_id', $here)->get()->getRow()->branch_id);

        $this->accountingDb()->table('receivings_items')->where('receiving_id', $there)->delete();
        $this->accountingDb()->table('receivings')->where('receiving_id', $there)->delete();
        $this->accountingDb()->table('stock_locations')->where('location_id', $location)->delete();
        $this->createdReceivings = array_values(array_diff($this->createdReceivings, [$there]));
    }

    public function testUp_GivesEveryExistingLineARateOfNoTax(): void
    {
        $migration = new PurchasesAccounting();
        $migration->down();

        $receivingId = $this->legacyPurchase([[1, '5.000']]);

        $migration->up();

        $this->assertSame('0.000', $this->accountingDb()->table('receivings_items')->where('receiving_id', $receivingId)->get()->getRow()->tax_percent);
    }

    public function testUp_IsSafeToRunTwice(): void
    {
        $migration = new PurchasesAccounting();

        $migration->up();
        $migration->up();

        $this->assertTrue($this->hasColumn('receivings', 'receiving_type'));
        $this->assertTrue($this->hasTable('accounting_stock_entries'));
    }

    public function testDown_ThenUpRemovesAndRestoresWhatTheMigrationAdded(): void
    {
        $migration = new PurchasesAccounting();

        $migration->down();

        foreach (['receiving_type', 'return_of_receiving_id', 'branch_id'] as $column) {
            $this->assertFalse($this->hasColumn('receivings', $column), $column);
        }

        $this->assertFalse($this->hasColumn('receivings_items', 'tax_percent'));
        $this->assertFalse($this->hasTable('accounting_stock_entries'));

        $migration->down();
        $migration->up();

        $this->assertTrue($this->hasColumn('receivings', 'return_of_receiving_id'));
        $this->assertTrue($this->hasTable('accounting_stock_entries'));
    }

    public function testUp_RefusesAReturnOfAPurchaseThatDoesNotExist(): void
    {
        $employeeId = $this->createEmployee();

        $this->expectException(DatabaseException::class);

        $this->accountingDb()->table('receivings')->insert(['employee_id' => $employeeId, 'comment' => 'orphan', 'return_of_receiving_id' => 99999999]);
    }

    public function testUp_RefusesToDeleteAPurchaseThatAReturnTakesGoodsBackFrom(): void
    {
        $db         = $this->accountingDb();
        $employeeId = $this->createEmployee();

        $db->table('receivings')->insert(['employee_id' => $employeeId, 'comment' => 'origin']);
        $origin                    = (int) $db->insertID();
        $this->createdReceivings[] = $origin;

        $db->table('receivings')->insert(['employee_id' => $employeeId, 'comment' => 'return', 'receiving_type' => 'return', 'return_of_receiving_id' => $origin]);
        $this->createdReceivings[] = (int) $db->insertID();

        $this->expectException(DatabaseException::class);

        $db->table('receivings')->where('receiving_id', $origin)->delete();
    }

    public function testUp_RefusesAStockEntryOfAKindThatIsNeitherPostingNorReversal(): void
    {
        $itemId = $this->createTestItem();

        $this->expectException(DatabaseException::class);

        $this->accountingDb()->table('accounting_stock_entries')->insert([
            'source_type' => 'purchase', 'source_id' => 1, 'line_no' => 0, 'kind' => 'other', 'entry_date' => '2026-10-24',
            'item_id'     => $itemId, 'location_id' => 1, 'quantity' => '1.000', 'value' => '1.0000',
        ]);
    }

    public function testUp_RefusesTheSameStockEntryTwice(): void
    {
        $itemId = $this->createTestItem();
        $row    = [
            'source_type' => 'purchase', 'source_id' => 1, 'line_no' => 0, 'kind' => 'posting', 'entry_date' => '2026-10-24',
            'item_id'     => $itemId, 'location_id' => 1, 'quantity' => '1.000', 'value' => '1.0000',
        ];

        $this->accountingDb()->table('accounting_stock_entries')->insert($row);

        $this->expectException(DatabaseException::class);

        $this->accountingDb()->table('accounting_stock_entries')->insert($row);
    }
}
