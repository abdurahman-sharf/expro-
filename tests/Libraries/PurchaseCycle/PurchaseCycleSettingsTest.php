<?php

namespace Tests\Libraries\PurchaseCycle;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\PurchaseCycle\PurchaseCycleSettings;
use App\Libraries\PurchaseCycle\PurchaseDocumentKind;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;

class PurchaseCycleSettingsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private const KEYS = ['enable_purchase_cycle', 'purchase_order_allow_excess', 'enable_purchase_request_manual_status', 'enable_quotation_request_manual_status', 'enable_purchase_quotation_manual_status', 'enable_purchase_order_manual_status'];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetSettings();
    }

    protected function tearDown(): void
    {
        $this->resetSettings();
        $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'purchase_cycle_settings')->delete();

        parent::tearDown();
    }

    private function resetSettings(): void
    {
        $this->accountingDb()->table('app_config')->whereIn('key', self::KEYS)->delete();
    }

    private function settings(): PurchaseCycleSettings
    {
        return new PurchaseCycleSettings($this->accountingDb());
    }

    public function testIsEnabled_IsOffUntilSomebodyTurnsTheCycleOnAndSoAreTheOtherFiveSwitches(): void
    {
        $settings = $this->settings();

        $this->assertFalse($settings->isEnabled());
        $this->assertFalse($settings->allowsExcess());

        foreach (PurchaseDocumentKind::cases() as $kind) {
            $this->assertFalse($settings->allowsManualStatus($kind), $kind->value);
        }
    }

    public function testUpdate_StoresEachSwitchUnderItsOwnKeyAndSaysSoInTheAuditLogWithBeforeAndAfter(): void
    {
        $employee = $this->employeeWithGrants(['config']);

        $this->settings()->update(['enabled' => true, 'allow_excess' => true, 'manual' => ['purchase_order' => true]], $employee);

        $settings = $this->settings();

        $this->assertTrue($settings->isEnabled());
        $this->assertTrue($settings->allowsExcess());
        $this->assertTrue($settings->allowsManualStatus(PurchaseDocumentKind::Order));
        $this->assertFalse($settings->allowsManualStatus(PurchaseDocumentKind::Request));

        foreach (['enable_purchase_cycle', 'purchase_order_allow_excess', 'enable_purchase_order_manual_status'] as $key) {
            $this->assertSame('1', $this->accountingDb()->table('app_config')->where('key', $key)->get()->getRow()->value, $key);
        }

        $entry   = $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'purchase_cycle_settings')->get()->getRow();
        $details = json_decode((string) $entry->details, true);

        $this->assertSame('settings_updated', $entry->action);
        $this->assertSame($employee, (int) $entry->employee_id);
        $this->assertFalse($details['before']['enabled']);
        $this->assertTrue($details['after']['enabled']);
        $this->assertTrue($details['after']['manual']['purchase_order']);
        $this->assertFalse($details['before']['manual']['purchase_order']);
    }

    public function testUpdate_ChangesOnlyTheSwitchesGivenAndKeepsTheRest(): void
    {
        $employee = $this->employeeWithGrants(['config']);

        $this->settings()->update(['enabled' => true, 'manual' => ['purchase_request' => true, 'quotation_request' => true]], $employee);
        $this->settings()->update(['manual' => ['purchase_request' => false]], $employee);

        $settings = $this->settings();

        $this->assertTrue($settings->isEnabled());
        $this->assertSame(
            [false, true, false, false],
            [$settings->allowsManualStatus(PurchaseDocumentKind::Request), $settings->allowsManualStatus(PurchaseDocumentKind::QuotationRequest), $settings->allowsManualStatus(PurchaseDocumentKind::SupplierQuotation), $settings->allowsManualStatus(PurchaseDocumentKind::Order)],
            'only the switch that was given changes and the switches of the other families stay as they were',
        );
    }

    public function testUpdate_WritesNothingWhenNothingChanges(): void
    {
        $employee = $this->employeeWithGrants(['config']);

        $this->settings()->update(['enabled' => true], $employee);
        $this->settings()->update(['enabled' => true, 'allow_excess' => false], $employee);

        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'purchase_cycle_settings')->countAllResults());
    }

    public function testUpdate_RequiresTheConfigPermissionAndChangesNothingWithout(): void
    {
        $outsider = $this->employeeWithGrants(['purchase_orders', 'po_add', 'po_edit']);

        try {
            $this->settings()->update(['enabled' => true], $outsider);
            $this->fail('A person without the config permission may not change the settings.');
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::PermissionDenied, $refusal->error);
        }

        $this->assertFalse($this->settings()->isEnabled());
        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'purchase_cycle_settings')->countAllResults());
    }

    public function testCurrent_ListsEverySwitchByTheValueOfItsKind(): void
    {
        $current = $this->settings()->current();

        $this->assertSame(['enabled', 'allow_excess', 'manual'], array_keys($current));
        $this->assertSame(['purchase_request', 'quotation_request', 'supplier_quotation', 'purchase_order'], array_keys($current['manual']));
    }
}
