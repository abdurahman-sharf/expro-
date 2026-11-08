<?php

namespace Tests\Libraries\Stock;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Stock\NegativeStockOption;
use App\Libraries\Stock\StockOptions;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\StockUnitsFixtureTrait;

/**
 * @internal
 */
final class StockOptionsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use StockUnitsFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startStockUnits();
    }

    protected function tearDown(): void
    {
        $this->stopStockUnits();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    public function testAllStartsWithEverythingOffAndNegativeStockAllowed(): void
    {
        $this->assertSame(['units' => false, 'bundles' => false, 'tracking' => false, 'expired_refused' => true, 'negative_stock' => 'allow'], (new StockOptions())->all());
    }

    public function testUpdateRequiresTheAccountingSettingsPermission(): void
    {
        $stranger = $this->employeeWithGrants([]);

        try {
            (new StockOptions())->update(['units' => true], $stranger);
            $this->fail('Whoever may not change the settings must be refused.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::PermissionDenied, $exception->error);
        }

        $this->assertFalse((new StockOptions())->unitsEnabled());
    }

    public function testUpdateSwitchesEachOptionAndWritesWhatItWasToTheAuditLog(): void
    {
        $options = new StockOptions();

        $options->update(['units' => true, 'bundles' => true, 'tracking' => true, 'expired_refused' => false, 'negative_stock' => 'refuse_below_available'], $this->unitManagerId);

        $this->assertSame(['units' => true, 'bundles' => true, 'tracking' => true, 'expired_refused' => false, 'negative_stock' => 'refuse_below_available'], $options->all());
        $this->assertSame(NegativeStockOption::RefuseAvailable, $options->negativeStock());

        $audit = $this->accountingDb()->table('accounting_audit_log')->where('action', 'stock_options_updated')->get()->getRow();

        $this->assertNotNull($audit);
        $this->assertStringContainsString('"before"', (string) $audit->details);
    }

    public function testUpdateWritesNothingWhenNothingChanges(): void
    {
        (new StockOptions())->update(['units' => false, 'negative_stock' => 'allow'], $this->unitManagerId);

        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where('action', 'stock_options_updated')->countAllResults());
    }

    public function testUpdateRejectsAnOptionOfNegativeStockThatIsNotOneOfTheThree(): void
    {
        try {
            (new StockOptions())->update(['negative_stock' => 'sometimes'], $this->unitManagerId);
            $this->fail('The option must be refused.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::InvalidName, $exception->error);
        }

        $this->assertSame(NegativeStockOption::Allow, (new StockOptions())->negativeStock());
    }

    public function testNegativeStockFallsBackToAllowWhenTheStoredValueIsUnknown(): void
    {
        $this->setConfigValue(StockOptions::NEGATIVE_STOCK, 'garbage');

        $this->assertSame(NegativeStockOption::Allow, (new StockOptions())->negativeStock());
    }
}
