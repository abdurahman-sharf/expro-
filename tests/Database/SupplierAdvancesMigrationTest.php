<?php

namespace Tests\Database;

use App\Database\Migrations\SupplierAdvances;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

class SupplierAdvancesMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;

    private const PERMISSION = 'acc_payables_manage';

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
        $this->accountingDb()->table('supplier_advances')->truncate();
        $this->stopBuying();

        (new SupplierAdvances())->up();

        parent::tearDown();
    }

    private function hasTable(): bool
    {
        return $this->accountingDb()->query(
            'SELECT COUNT(*) AS found FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->accountingDb()->prefixTable('supplier_advances')],
        )->getRow()->found > 0;
    }

    private function advance(string $amount = '10.00', string $kind = 'payment', string $status = 'completed'): void
    {
        $this->accountingDb()->table('supplier_advances')->insert(['company_id' => 1, 'branch_id' => 1, 'supplier_id' => $this->supplierId, 'kind' => $kind, 'amount' => $amount, 'advance_date' => '2026-10-25', 'status' => $status]);
    }

    public function testUp_CreatesTheTableAndRegistersThePermissionUnderTheModuleOfTheSuppliers(): void
    {
        $this->assertTrue($this->hasTable());

        $permission = $this->accountingDb()->table('permissions')->where('permission_id', self::PERMISSION)->get()->getRow();

        $this->assertNotNull($permission);
        $this->assertSame('accounting_payables', $permission->module_id);
    }

    public function testUp_GrantsTheNewPermissionToNoOne(): void
    {
        $this->assertSame(0, $this->accountingDb()->table('grants')->where('permission_id', self::PERMISSION)->countAllResults());
    }

    public function testUp_AcceptsAPaymentAndAnOpeningBalanceAndNothingElse(): void
    {
        $this->advance('10.00', 'payment');
        $this->advance('20.00', 'opening');

        $this->assertSame(2, $this->countRows('supplier_advances'));

        foreach ([['10.00', 'loan', 'completed'], ['10.00', 'payment', 'paid']] as [$amount, $kind, $status]) {
            try {
                $this->advance($amount, $kind, $status);
                $this->fail('A row with kind ' . $kind . ' and status ' . $status . ' was accepted.');
            } catch (DatabaseException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testUp_RefusesAnAmountOfNothingAndANegativeOne(): void
    {
        foreach (['0.00', '-5.00'] as $amount) {
            try {
                $this->advance($amount);
                $this->fail('The amount ' . $amount . ' was accepted.');
            } catch (DatabaseException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, $this->countRows('supplier_advances'));
    }

    public function testUp_RefusesToDeleteASupplierThatHasAnAdvance(): void
    {
        $this->advance();

        $this->expectException(DatabaseException::class);

        $this->accountingDb()->table('suppliers')->where('person_id', $this->supplierId)->delete();
    }

    public function testUp_IsSafeToRunTwice(): void
    {
        $permissions = $this->countRows('permissions');

        $this->advance();

        (new SupplierAdvances())->up();

        $this->assertSame($permissions, $this->countRows('permissions'));
        $this->assertSame(1, $this->countRows('supplier_advances'));
    }

    public function testDown_TakesTheTableThePermissionAndItsGrantsAway(): void
    {
        $employee  = $this->employeeWithGrants([self::PERMISSION]);
        $migration = new SupplierAdvances();

        try {
            $migration->down();

            $this->assertFalse($this->hasTable());
            $this->assertSame(0, $this->accountingDb()->table('permissions')->where('permission_id', self::PERMISSION)->countAllResults());
            $this->assertSame(0, $this->accountingDb()->table('grants')->where(['person_id' => $employee, 'permission_id' => self::PERMISSION])->countAllResults());
        } finally {
            $migration->up();
        }

        $this->assertTrue($this->hasTable());
    }
}
