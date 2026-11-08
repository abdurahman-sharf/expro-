<?php

namespace Tests\Database;

use App\Database\Migrations\CustomerMasterData;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;
use Tests\Support\AccountingFixtureTrait;
use Throwable;

/**
 * @internal
 */
final class CustomerMasterDataMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    private const COLUMNS = ['classification_id', 'manual_status', 'assigned_employee_id', 'area', 'country_code', 'customer_type', 'is_government_entity', 'billing_method'];

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        (new CustomerMasterData())->up();

        $db = $this->accountingDb();
        $db->table('customers')->update(['classification_id' => null, 'manual_status' => null, 'assigned_employee_id' => null, 'area' => null, 'country_code' => null, 'customer_type' => null, 'is_government_entity' => 0, 'billing_method' => 'print']);
        $db->table('suppliers')->update(['country_code' => null]);
        $db->query('DELETE FROM ' . $db->prefixTable('customer_classifications'));
        $this->removeInsertedEntities();

        parent::tearDown();
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

        return ['type' => (string) preg_replace('/^((?:small|medium|big|tiny)?int)\(\d+\)/', '$1', strtolower((string) $row->type)), 'nullable' => (string) $row->nullable, 'default' => in_array($row->dflt, [null, 'NULL'], true) ? null : trim((string) $row->dflt, "'")];
    }

    private function hasNoneOfIt(): bool
    {
        foreach (self::COLUMNS as $column) {
            if ($this->column('customers', $column) !== null) {
                return false;
            }
        }

        return $this->column('suppliers', 'country_code') === null && $this->accountingDb()->query('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$this->accountingDb()->prefixTable('customer_classifications')])->getRow() === null;
    }

    public function testUp_AddsColumnsThatChangeNothingForACustomerAndASupplierThatExist(): void
    {
        $this->assertSame(['type' => 'int unsigned', 'nullable' => 'YES', 'default' => null], $this->column('customers', 'classification_id'));
        $this->assertSame(['type' => 'varchar(20)', 'nullable' => 'YES', 'default' => null], $this->column('customers', 'manual_status'));
        $this->assertSame(['type' => 'int', 'nullable' => 'YES', 'default' => null], $this->column('customers', 'assigned_employee_id'));
        $this->assertSame(['type' => 'varchar(100)', 'nullable' => 'YES', 'default' => null], $this->column('customers', 'area'));
        $this->assertSame(['type' => 'char(2)', 'nullable' => 'YES', 'default' => null], $this->column('customers', 'country_code'));
        $this->assertSame(['type' => 'varchar(10)', 'nullable' => 'YES', 'default' => null], $this->column('customers', 'customer_type'));
        $this->assertSame(['type' => 'tinyint', 'nullable' => 'NO', 'default' => '0'], $this->column('customers', 'is_government_entity'));
        $this->assertSame(['type' => 'varchar(5)', 'nullable' => 'NO', 'default' => 'print'], $this->column('customers', 'billing_method'));
        $this->assertSame(['type' => 'char(2)', 'nullable' => 'YES', 'default' => null], $this->column('suppliers', 'country_code'));
    }

    public function testUp_LeavesACustomerThatExistsWithNeutralValuesAndItsOtherColumnsAsTheyWere(): void
    {
        $migration = new CustomerMasterData();
        $migration->down();

        $customerId = $this->insertCustomer('Omar', 'Khaled', 'Khaled Trading');
        $before     = $this->accountingDb()->table('customers')->where('person_id', $customerId)->get()->getRowArray();

        $migration->up();

        $after = $this->accountingDb()->table('customers')->where('person_id', $customerId)->get()->getRowArray();

        $this->assertSame([null, null, null, null, null, null, '0', 'print'], [$after['classification_id'], $after['manual_status'], $after['assigned_employee_id'], $after['area'], $after['country_code'], $after['customer_type'], (string) $after['is_government_entity'], $after['billing_method']]);
        $this->assertSame($before, array_diff_key($after, array_flip(self::COLUMNS)));
    }

    public function testUp_IsSafeToRunAgain(): void
    {
        (new CustomerMasterData())->up();
        (new CustomerMasterData())->up();

        $this->assertNotNull($this->column('customers', 'area'));
    }

    public function testUp_RefusesAStatusOutsideTheFiveAndATypeOutsideTheTwoAtTheDatabase(): void
    {
        $customerId = $this->insertCustomer();

        foreach ([['manual_status', 'vip'], ['customer_type', 'both'], ['billing_method', 'fax'], ['is_government_entity', 2]] as [$column, $value]) {
            $accepted = false;

            try {
                $accepted = $this->accountingDb()->table('customers')->where('person_id', $customerId)->update([$column => $value]) !== false;
            } catch (Throwable) {
            }

            $this->assertFalse($accepted, "The database accepted {$value} for {$column}");
        }
    }

    public function testUp_RefusesAClassificationOrAnEmployeeThatDoesNotExistAtTheDatabase(): void
    {
        $customerId = $this->insertCustomer();

        $this->expectException(Throwable::class);

        $this->accountingDb()->table('customers')->where('person_id', $customerId)->update(['classification_id' => 987654]);
    }

    public function testUp_KeepsTheNameOfAClassificationUnique(): void
    {
        $db = $this->accountingDb();
        $db->table('customer_classifications')->insert(['name' => 'Gold']);

        $this->expectException(Throwable::class);

        $db->table('customer_classifications')->insert(['name' => 'Gold']);
    }

    public function testDown_RemovesEverythingWhenNothingWasEntered(): void
    {
        (new CustomerMasterData())->down();

        $this->assertTrue($this->hasNoneOfIt());
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: mixed}>
     */
    public static function enteredProvider(): array
    {
        return [
            'a manual status'    => ['customers', 'manual_status', 'served'],
            'an area'            => ['customers', 'area', 'Olaya'],
            'a country code'     => ['customers', 'country_code', 'SA'],
            'a customer type'    => ['customers', 'customer_type', 'individual'],
            'a government flag'  => ['customers', 'is_government_entity', 1],
            'a billing method'   => ['customers', 'billing_method', 'email'],
            'a supplier country' => ['suppliers', 'country_code', 'EG'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('enteredProvider')]
    public function testDown_RefusesOnceAValueIsStored(string $table, string $column, mixed $value): void
    {
        $db = $this->accountingDb();

        if ($table === 'customers') {
            $db->table('customers')->where('person_id', $this->insertCustomer())->update([$column => $value]);
        } else {
            $db->table('suppliers')->where('person_id', $this->insertSupplier())->update([$column => $value]);
        }

        try {
            (new CustomerMasterData())->down();
            $this->fail('down() dropped a value that was stored.');
        } catch (RuntimeException $refusal) {
            $this->assertNotNull($this->column('customers', 'area'));
        }
    }

    public function testDown_RefusesWhileAClassificationExists(): void
    {
        $this->accountingDb()->table('customer_classifications')->insert(['name' => 'Gold']);

        $this->expectException(RuntimeException::class);

        (new CustomerMasterData())->down();
    }
}
