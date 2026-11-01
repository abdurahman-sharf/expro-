<?php

namespace Tests\Libraries\Stock;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Stock\StockDocumentSettings;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;
use Tests\Support\StockDocumentFixtureTrait;

/**
 * The transfer permit and the stock requests: both off until someone with the settings permission turns
 * them on, and every change in the audit log with what it was before.
 */
class StockDocumentSettingsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use SaleAccountingFixtureTrait;
    use StockDocumentFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private StockDocumentSettings $settings;
    private int $manager;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startStockDocuments();
        $this->settings = new StockDocumentSettings();
        $this->manager  = $this->employeeWithGrants([AccountingGuard::SETTINGS_MANAGE]);
    }

    protected function tearDown(): void
    {
        $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'settings')->delete();
        $this->stopStockDocuments();
        $this->stopBuying();

        parent::tearDown();
    }

    public function testAll_IsOffForBothUntilTheyAreTurnedOn(): void
    {
        $this->assertFalse($this->settings->transferPermitEnabled());
        $this->assertFalse($this->settings->requestsEnabled());
        $this->assertSame(['transfer_permit' => false, 'requests' => false], $this->settings->all());
    }

    public function testUpdate_TurnsEachOnAndOffAndLeavesWhatIsNotNamedAlone(): void
    {
        $this->settings->update(['transfer_permit' => true], $this->manager);

        $this->assertSame(['transfer_permit' => true, 'requests' => false], $this->settings->all());

        $this->settings->update(['requests' => true], $this->manager);

        $this->assertSame(['transfer_permit' => true, 'requests' => true], $this->settings->all());

        $this->settings->update(['transfer_permit' => false], $this->manager);

        $this->assertSame(['transfer_permit' => false, 'requests' => true], $this->settings->all());
    }

    public function testUpdate_RequiresThePermissionToChangeTheSettingsAndChangesNothingWithout(): void
    {
        $clerk = $this->keeper();

        try {
            $this->settings->update(['transfer_permit' => true], $clerk);
            $this->fail('Whoever may not change the settings must be refused.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::PermissionDenied, $exception->error);
        }

        $this->assertFalse($this->settings->transferPermitEnabled());
        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'settings')->countAllResults());
    }

    public function testUpdate_WritesTheAuditLogWithWhatItWasBeforeAndWhatItIsNow(): void
    {
        $this->settings->update(['transfer_permit' => true, 'requests' => false], $this->manager);

        $entry   = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'settings', 'action' => 'settings_updated'])->get()->getRow();
        $details = json_decode((string) $entry->details, true);

        $this->assertSame($this->manager, (int) $entry->employee_id);
        $this->assertSame(['transfer_permit' => false], $details['before']);
        $this->assertSame(['transfer_permit' => true], $details['after']);
    }

    public function testUpdate_WritesNothingWhenNothingChanges(): void
    {
        $this->settings->update(['transfer_permit' => false, 'requests' => false], $this->manager);
        $this->settings->update([], $this->manager);

        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'settings')->countAllResults());
        $this->assertSame(0, $this->accountingDb()->table('app_config')->whereIn('key', [StockDocumentSettings::TRANSFER_PERMIT, StockDocumentSettings::REQUESTS])->countAllResults());
    }
}
