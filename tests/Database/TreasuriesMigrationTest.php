<?php

namespace Tests\Database;

use App\Database\Migrations\BankAccounts;
use App\Database\Migrations\CreditFlows;
use App\Database\Migrations\ChequesAndRevenues;
use App\Database\Migrations\ExpensesAccounting;
use App\Database\Migrations\InterBranchSettlements;
use App\Database\Migrations\InterBranchTransfers;
use App\Database\Migrations\SupplierAdvances;
use App\Database\Migrations\Treasuries;
use App\Database\Migrations\TreasuryScopes as TreasuryScopesMigration;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class TreasuriesMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    private const MODULE_ID  = 'accounting_treasuries';
    private const PERMISSION = 'acc_treasuries_manage';

    private const STAMPED = ['sales_payments', 'receivings_payments', 'receivings'];

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        $db = $this->accountingDb();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->table('treasury_transfers')->truncate();
        $db->table('treasuries')->truncate();
        $db->query('SET FOREIGN_KEY_CHECKS = 1');

        (new Treasuries())->up();

        parent::tearDown();
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->accountingDb()->query(
            'SELECT COUNT(*) AS found FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->accountingDb()->prefixTable($table), $column],
        )->getRow()->found > 0;
    }

    private function isNullable(string $table, string $column): bool
    {
        return $this->accountingDb()->query(
            'SELECT IS_NULLABLE AS nullable FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->accountingDb()->prefixTable($table), $column],
        )->getRow()->nullable === 'YES';
    }

    private function hasTable(string $table): bool
    {
        return $this->accountingDb()->query(
            'SELECT COUNT(*) AS found FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->accountingDb()->prefixTable($table)],
        )->getRow()->found > 0;
    }

    /**
     * @return list<string> The tables a foreign key of the table points at.
     */
    private function referencedBy(string $table): array
    {
        $rows = $this->accountingDb()->query(
            'SELECT DISTINCT REFERENCED_TABLE_NAME AS referenced FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY REFERENCED_TABLE_NAME',
            [$this->accountingDb()->prefixTable($table)],
        )->getResultArray();

        return array_column($rows, 'referenced');
    }

    private function treasury(string $name = 'Drawer', string $kind = 'cash'): int
    {
        $this->accountingDb()->table('treasuries')->insert(['company_id' => 1, 'branch_id' => 1, 'name' => $name, 'kind' => $kind]);

        return (int) $this->accountingDb()->insertID();
    }

    public function testUp_CreatesTheTablesOfTreasuriesAndOfTheirTransfers(): void
    {
        $this->assertTrue($this->hasTable('treasuries'));
        $this->assertTrue($this->hasTable('treasury_transfers'));
    }

    public function testUp_LetsEveryDocumentAndEmployeeThatExistedKeepNoTreasury(): void
    {
        foreach (self::STAMPED as $table) {
            $this->assertTrue($this->hasColumn($table, 'treasury_id'), $table);
            $this->assertTrue($this->isNullable($table, 'treasury_id'), $table);
            $this->assertContains($this->accountingDb()->prefixTable('treasuries'), $this->referencedBy($table), $table);
        }

        $this->assertTrue($this->hasColumn('employees', 'default_treasury_id'));
        $this->assertTrue($this->isNullable('employees', 'default_treasury_id'));
        $this->assertContains($this->accountingDb()->prefixTable('treasuries'), $this->referencedBy('employees'));
    }

    public function testUp_RegistersTheScreenAndItsPermissionAfterTheOtherAccountingScreens(): void
    {
        $module = $this->accountingDb()->table('modules')->where('module_id', self::MODULE_ID)->get()->getRow();

        $this->assertNotNull($module);
        $this->assertSame(127, (int) $module->sort);
        $this->assertSame('module_accounting_treasuries', $module->name_lang_key);
        $this->assertSame('module_accounting_treasuries_desc', $module->desc_lang_key);

        foreach ([self::MODULE_ID, self::PERMISSION] as $permissionId) {
            $permission = $this->accountingDb()->table('permissions')->where('permission_id', $permissionId)->get()->getRow();

            $this->assertNotNull($permission, $permissionId);
            $this->assertSame(self::MODULE_ID, $permission->module_id);
        }

        $clashes = $this->accountingDb()->table('modules')->where('sort', 127)->where('module_id !=', self::MODULE_ID)->countAllResults();

        $this->assertSame(0, $clashes);
    }

    public function testUp_GrantsNothingToAnyone(): void
    {
        $granted = $this->accountingDb()->table('grants')->whereIn('permission_id', [self::MODULE_ID, self::PERMISSION])->countAllResults();

        $this->assertSame(0, $granted);
    }

    public function testUp_AcceptsACashDrawerAndABankAndNothingElse(): void
    {
        $this->assertGreaterThan(0, $this->treasury('Cash drawer', 'cash'));
        $this->assertGreaterThan(0, $this->treasury('Bank', 'bank'));

        $this->expectException(DatabaseException::class);

        $this->treasury('Wallet', 'wallet');
    }

    public function testUp_RefusesTwoTreasuriesWithTheSameNameInACompany(): void
    {
        $this->treasury('Drawer');

        $this->expectException(DatabaseException::class);

        $this->treasury('Drawer');
    }

    public function testUp_RefusesATransferOfNothingAndToTheSameTreasury(): void
    {
        $from = $this->treasury('From');
        $to   = $this->treasury('To');

        $transfer = static fn (int $source, int $target, string $amount): array => [
            'company_id' => 1, 'branch_id' => 1, 'from_treasury_id' => $source, 'to_treasury_id' => $target, 'amount' => $amount, 'transfer_date' => '2026-10-25',
        ];

        $this->assertTrue($this->accountingDb()->table('treasury_transfers')->insert($transfer($from, $to, '10.0000')));

        foreach ([$transfer($from, $to, '0.0000'), $transfer($from, $to, '-5.0000'), $transfer($from, $from, '10.0000')] as $row) {
            try {
                $this->accountingDb()->table('treasury_transfers')->insert($row);
                $this->fail('A transfer that moves nothing or stays in one treasury was accepted.');
            } catch (DatabaseException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(1, $this->accountingDb()->table('treasury_transfers')->countAllResults());
    }

    public function testUp_RefusesToDeleteATreasuryThatAPaymentOrATransferPointsAt(): void
    {
        $drawer = $this->treasury('Used drawer');
        $other  = $this->treasury('Other drawer');

        $this->accountingDb()->table('treasury_transfers')->insert([
            'company_id' => 1, 'branch_id' => 1, 'from_treasury_id' => $drawer, 'to_treasury_id' => $other, 'amount' => '5.0000', 'transfer_date' => '2026-10-25',
        ]);

        $this->expectException(DatabaseException::class);

        $this->accountingDb()->table('treasuries')->where('treasury_id', $drawer)->delete();
    }

    public function testUp_ForgetsTheDefaultOfAnEmployeeWhoseTreasuryIsDeleted(): void
    {
        $drawer   = $this->treasury('Default drawer');
        $employee = $this->employeeWithGrants([]);

        $this->accountingDb()->table('employees')->where('person_id', $employee)->update(['default_treasury_id' => $drawer]);
        $this->accountingDb()->table('treasuries')->where('treasury_id', $drawer)->delete();

        $this->assertNull($this->accountingDb()->table('employees')->where('person_id', $employee)->get()->getRow()->default_treasury_id);
    }

    public function testUp_IsSafeToRunTwice(): void
    {
        $permissions = $this->countRows('permissions');
        $modules     = $this->countRows('modules');
        $drawer      = $this->treasury('Kept drawer');

        (new Treasuries())->up();

        $this->assertSame($permissions, $this->countRows('permissions'));
        $this->assertSame($modules, $this->countRows('modules'));
        $this->assertSame(1, $this->accountingDb()->table('treasuries')->where('treasury_id', $drawer)->countAllResults());
    }

    public function testDown_TakesTheTablesTheColumnsAndTheScreenAway(): void
    {
        $employee  = $this->employeeWithGrants([self::MODULE_ID, self::PERMISSION]);
        $migration = new Treasuries();
        $later     = new SupplierAdvances();
        $scopes    = new TreasuryScopesMigration();
        $expenses  = new ExpensesAccounting();
        $between   = new InterBranchSettlements();
        $credit    = new CreditFlows();
        $cheques   = new ChequesAndRevenues();

        try {
            $cheques->down();
            $credit->down();
            $between->down();
            $expenses->down();
            $scopes->down();
            $later->down();
            $migration->down();

            $this->assertFalse($this->hasTable('treasuries'));
            $this->assertFalse($this->hasTable('treasury_transfers'));
            $this->assertFalse($this->hasColumn('employees', 'default_treasury_id'));

            foreach (self::STAMPED as $table) {
                $this->assertFalse($this->hasColumn($table, 'treasury_id'), $table);
            }

            $this->assertSame(0, $this->accountingDb()->table('modules')->where('module_id', self::MODULE_ID)->countAllResults());
            $this->assertSame(0, $this->accountingDb()->table('permissions')->where('module_id', self::MODULE_ID)->countAllResults());
            $this->assertSame(0, $this->accountingDb()->table('grants')->where('person_id', $employee)->whereIn('permission_id', [self::MODULE_ID, self::PERMISSION])->countAllResults());
        } finally {
            $migration->up();
            $later->up();
            (new BankAccounts())->up();
            $scopes->up();
            (new InterBranchTransfers())->up();
            $expenses->up();
            $between->up();
            $credit->up();
            $cheques->up();
        }

        $this->assertTrue($this->hasTable('treasuries'));
        $this->assertTrue($this->hasColumn('treasury_transfers', 'to_branch_id'), 'The migrations that came after it are run again');
        $this->assertTrue($this->hasColumn('sales_payments', 'treasury_id'));
        $this->assertSame(1, $this->accountingDb()->table('modules')->where('module_id', self::MODULE_ID)->countAllResults());
    }
}
