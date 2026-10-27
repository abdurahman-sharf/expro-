<?php

namespace Tests\Database;

use App\Database\Migrations\SalesInvoiceDiscountPermission;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;

class SalesInvoiceDiscountPermissionMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;

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
        (new SalesInvoiceDiscountPermission())->up();

        parent::tearDown();
    }

    private function permission(): ?object
    {
        return $this->accountingDb()->table('permissions')->where('permission_id', 'sales_invoice_discount')->get()->getRow();
    }

    public function testUp_RegistersThePermissionUnderTheSalesModuleAndGrantsItToNobody(): void
    {
        $this->assertSame('sales', $this->permission()->module_id);
        $this->assertSame(0, $this->accountingDb()->table('grants')->where('permission_id', 'sales_invoice_discount')->countAllResults());
    }

    public function testUp_IsSafeToRunAgainAndKeepsWhatWasGranted(): void
    {
        $employeeId = $this->createEmployee(grants: [['permission_id' => 'sales_invoice_discount', 'menu_group' => 'home']]);

        (new SalesInvoiceDiscountPermission())->up();
        (new SalesInvoiceDiscountPermission())->up();

        $this->assertSame(1, $this->accountingDb()->table('permissions')->where('permission_id', 'sales_invoice_discount')->countAllResults());
        $this->assertSame(1, $this->accountingDb()->table('grants')->where(['permission_id' => 'sales_invoice_discount', 'person_id' => $employeeId])->countAllResults());
    }

    public function testDown_RemovesThePermissionAndWhatWasGrantedOfIt(): void
    {
        $this->createEmployee(grants: [['permission_id' => 'sales_invoice_discount', 'menu_group' => 'home']]);

        (new SalesInvoiceDiscountPermission())->down();

        $this->assertNull($this->permission());
        $this->assertSame(0, $this->accountingDb()->table('grants')->where('permission_id', 'sales_invoice_discount')->countAllResults());
    }
}
