<?php

namespace Tests\Libraries\SalesOrders;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\SalesOrders\SalesOrderSettings;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;

class SalesOrderSettingsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private SalesOrderSettings $settings;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->accountingDb()->table('app_config')->whereIn('key', ['enable_sales_order', 'sales_order_allow_excess'])->delete();
        $this->settings = new SalesOrderSettings($this->accountingDb());
    }

    protected function tearDown(): void
    {
        $this->accountingDb()->table('app_config')->whereIn('key', ['enable_sales_order', 'sales_order_allow_excess'])->delete();
        $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'sales_order_settings')->delete();

        parent::tearDown();
    }

    public function testIsEnabled_IsOffUntilItIsTurnedOn(): void
    {
        $this->assertFalse($this->settings->isEnabled());
        $this->assertFalse($this->settings->allowsExcess());
    }

    public function testUpdate_TurnsTheModuleAndTheExcessOnAndOffAndWritesTheAuditLog(): void
    {
        $this->settings->update(['enabled' => true], null);

        $this->assertTrue($this->settings->isEnabled());
        $this->assertFalse($this->settings->allowsExcess());

        $this->settings->update(['allow_excess' => true], null);

        $this->assertTrue($this->settings->isEnabled(), 'a setting that is not given stays as it is');
        $this->assertTrue($this->settings->allowsExcess());

        $this->settings->update(['enabled' => false, 'allow_excess' => false], null);

        $this->assertFalse($this->settings->isEnabled());
        $this->assertFalse($this->settings->allowsExcess());

        $entries = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'sales_order_settings', 'action' => 'settings_changed'])->orderBy('audit_id')->get()->getResult();

        $this->assertCount(3, $entries);
        $this->assertSame(['before' => ['enabled' => false, 'allow_excess' => false], 'after' => ['enabled' => true, 'allow_excess' => false]], json_decode($entries[0]->details, true));
    }

    public function testUpdate_WritesNothingWhenNothingChanges(): void
    {
        $this->settings->update(['enabled' => false, 'allow_excess' => false], null);

        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'sales_order_settings')->countAllResults());
    }

    public function testUpdate_RequiresThePermissionOfTheConfiguration(): void
    {
        $clerk = $this->employeeWithGrants(['customer_orders']);
        $admin = $this->employeeWithGrants(['config']);

        try {
            $this->settings->update(['enabled' => true], $clerk);
            $this->fail('An employee without the permission of the configuration may not turn the module on.');
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::PermissionDenied, $refusal->error);
        }

        $this->assertFalse($this->settings->isEnabled());

        $this->settings->update(['enabled' => true], $admin);

        $this->assertTrue($this->settings->isEnabled());
    }
}
