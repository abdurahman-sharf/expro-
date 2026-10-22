<?php

namespace Tests\Database;

use App\Database\Migrations\AccountingRouting;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\PaymentType;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\SaleFixtureTrait;

class AccountingRoutingMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use SaleFixtureTrait;

    private const MODULE     = 'accounting_routing';
    private const PERMISSION = 'acc_routing_manage';

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    /** @var list<int> */
    private array $createdSales = [];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        $db = $this->accountingDb();

        foreach ($this->createdSales as $saleId) {
            $db->table('sales_items')->where('sale_id', $saleId)->delete();
            $db->table('sales')->where('sale_id', $saleId)->delete();
        }

        $this->createdSales = [];
        $this->resetBranches();

        parent::tearDown();
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->accountingDb()->query(
            'SELECT COUNT(*) AS found FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->accountingDb()->prefixTable($table), $column],
        )->getRow()->found > 0;
    }

    public function testUp_GivesEverySaleABranchWithTheDefaultBranchForExistingOnes(): void
    {
        $this->assertTrue($this->hasColumn('sales', 'branch_id'));

        $saleId = $this->createSale($this->employeeWithGrants(['sales']));
        $this->createdSales[] = $saleId;

        $this->assertSame(1, (int) $this->accountingDb()->table('sales')->where('sale_id', $saleId)->get()->getRow()->branch_id);
    }

    public function testUp_RefusesASaleOfABranchThatDoesNotExist(): void
    {
        $saleId = $this->createSale($this->employeeWithGrants(['sales']));
        $this->createdSales[] = $saleId;

        $this->expectException(DatabaseException::class);

        $this->accountingDb()->table('sales')->where('sale_id', $saleId)->update(['branch_id' => 999999]);
    }

    public function testUp_MovesExistingSalesToTheBranchOfTheLocationTheySoldFrom(): void
    {
        $db       = $this->accountingDb();
        $branchId = $this->createBranch('MOV', 'Moved branch');
        $saleId   = $this->createSale($this->employeeWithGrants(['sales']));
        $this->createdSales[] = $saleId;
        $db->table('stock_locations')->where('location_id', 1)->update(['branch_id' => $branchId]);

        $migration = new AccountingRouting();
        $migration->down();
        $migration->up();

        $this->assertSame($branchId, (int) $db->table('sales')->where('sale_id', $saleId)->get()->getRow()->branch_id);
    }

    public function testUp_RegistersTheModuleAndItsSensitivePermission(): void
    {
        $db = $this->accountingDb();

        $this->assertSame(1, $db->table('modules')->where('module_id', self::MODULE)->countAllResults());

        foreach ([self::MODULE, self::PERMISSION] as $permissionId) {
            $this->assertSame(1, $db->table('permissions')->where('permission_id', $permissionId)->countAllResults(), $permissionId);
        }

        $this->assertContains(self::PERMISSION, AccountingGuard::SENSITIVE);
    }

    public function testUp_GivesHoldersOfTheConfigModuleTheScreenButNotTheSensitivePermission(): void
    {
        $db     = $this->accountingDb();
        $holder = $this->employeeWithGrants(['config']);
        $nobody = $this->employeeWithGrants(['customers']);

        $migration = new AccountingRouting();
        $migration->down();
        $migration->up();

        $this->assertSame(1, $db->table('grants')->where('person_id', $holder)->where('permission_id', self::MODULE)->countAllResults());
        $this->assertSame(0, $db->table('grants')->where('permission_id', self::PERMISSION)->countAllResults());
        $this->assertSame(0, $db->table('grants')->where('person_id', $nobody)->whereIn('permission_id', [self::MODULE, self::PERMISSION])->countAllResults());
    }

    public function testUp_KeepsThePermissionAnAdministratorGrantedBeforeItRunsAgain(): void
    {
        $manager = $this->employeeWithGrants(['config', self::PERMISSION]);

        (new AccountingRouting())->up();

        $this->assertSame(1, $this->accountingDb()->table('grants')->where('person_id', $manager)->where('permission_id', self::PERMISSION)->countAllResults());
    }

    public function testUp_StartsEachPaymentTypeOnItsDefaultAccount(): void
    {
        $this->reinstallChart();
        $db = $this->accountingDb();
        $db->table('accounting_routing')->like('routing_key', 'payment.', 'after')->delete();

        (new AccountingRouting())->up();

        foreach (PaymentType::cases() as $type) {
            $row = $db->table('accounting_routing')->where('routing_key', $type->routingKey())->get()->getRow();

            if ($type->defaultSystemKey() === null) {
                $this->assertNull($row, $type->value);

                continue;
            }

            $this->assertSame($this->accountId($type->defaultSystemKey()), (int) $row->account_id, $type->value);
        }
    }

    public function testUp_DoesNotChangeARoutingAnAdministratorAlreadyChose(): void
    {
        $this->reinstallChart();
        $other = $this->customAccount('11', '1110', 'Petty cash');
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'payment.cash')->update(['account_id' => $other]);

        (new AccountingRouting())->up();

        $this->assertSame($other, (int) $this->accountingDb()->table('accounting_routing')->where('routing_key', 'payment.cash')->get()->getRow()->account_id);
    }

    public function testUp_IsSafeToRunTwice(): void
    {
        $counts = [
            'accounting_routing' => $this->countRows('accounting_routing'),
            'grants'             => $this->countRows('grants'),
            'permissions'        => $this->countRows('permissions'),
            'modules'            => $this->countRows('modules'),
        ];

        (new AccountingRouting())->up();

        foreach ($counts as $table => $count) {
            $this->assertSame($count, $this->countRows($table), $table);
        }
    }

    public function testDown_ThenUpRemovesAndRestoresWhatTheMigrationAdded(): void
    {
        $db        = $this->accountingDb();
        $migration = new AccountingRouting();

        try {
            $migration->down();

            $this->assertFalse($this->hasColumn('sales', 'branch_id'));
            $this->assertSame(0, $db->table('modules')->where('module_id', self::MODULE)->countAllResults());
            $this->assertSame(0, $db->table('accounting_routing')->like('routing_key', 'payment.', 'after')->countAllResults());
        } finally {
            $migration->up();
        }

        $this->assertTrue($this->hasColumn('sales', 'branch_id'));
        $this->assertSame(1, $db->table('modules')->where('module_id', self::MODULE)->countAllResults());
    }
}
