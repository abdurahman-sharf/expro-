<?php

namespace Tests\Database;

use App\Database\Migrations\InvoiceDatePermissions;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;

class InvoiceDatePermissionsMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;

    private const PERMISSIONS = ['sales_invoice_date' => 'sales', 'receivings_invoice_date' => 'receivings'];

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

        $migration = new InvoiceDatePermissions();
        $migration->down();
        $migration->up();
    }

    private function grantsOf(string $permissionId): int
    {
        return $this->accountingDb()->table('grants')->where('permission_id', $permissionId)->countAllResults();
    }

    public function testUp_RegistersEachPermissionUnderTheModuleOfTheDocument(): void
    {
        foreach (self::PERMISSIONS as $permission => $module) {
            $row = $this->accountingDb()->table('permissions')->where('permission_id', $permission)->get()->getRow();

            $this->assertNotNull($row, $permission);
            $this->assertSame($module, $row->module_id, $permission);
        }
    }

    public function testUp_GrantsNothingToAnyone(): void
    {
        $migration = new InvoiceDatePermissions();
        $holder    = $this->employeeWithGrants(['sales_invoice_date', 'receivings_invoice_date', 'sales', 'receivings']);

        try {
            $migration->down();

            foreach (array_keys(self::PERMISSIONS) as $permission) {
                $this->assertSame(0, $this->grantsOf($permission), $permission);
            }

            $migration->up();

            foreach (array_keys(self::PERMISSIONS) as $permission) {
                $this->assertSame(0, $this->grantsOf($permission), $permission);
            }

            $this->assertSame(1, $this->accountingDb()->table('grants')->where('person_id', $holder)->where('permission_id', 'sales')->countAllResults());
        } finally {
            $migration->up();
        }
    }

    public function testUp_IsSafeToRunTwice(): void
    {
        $before = $this->countRows('permissions');

        (new InvoiceDatePermissions())->up();

        $this->assertSame($before, $this->countRows('permissions'));

        foreach (array_keys(self::PERMISSIONS) as $permission) {
            $this->assertSame(1, $this->accountingDb()->table('permissions')->where('permission_id', $permission)->countAllResults(), $permission);
        }
    }

    public function testDown_RemovesThePermissionsAndTheirGrantsAndUpBringsThemBack(): void
    {
        $holder    = $this->employeeWithGrants(['sales_invoice_date', 'receivings_invoice_date', 'sales']);
        $migration = new InvoiceDatePermissions();

        try {
            $migration->down();

            foreach (array_keys(self::PERMISSIONS) as $permission) {
                $this->assertSame(0, $this->accountingDb()->table('permissions')->where('permission_id', $permission)->countAllResults(), $permission);
                $this->assertSame(0, $this->grantsOf($permission), $permission);
            }

            $this->assertSame(1, $this->accountingDb()->table('grants')->where('person_id', $holder)->where('permission_id', 'sales')->countAllResults());
        } finally {
            $migration->up();
        }

        foreach (array_keys(self::PERMISSIONS) as $permission) {
            $this->assertSame(1, $this->accountingDb()->table('permissions')->where('permission_id', $permission)->countAllResults(), $permission);
        }
    }
}
