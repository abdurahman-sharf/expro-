<?php

namespace Tests\Libraries\Portal;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Portal\PortalSettings;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\PortalFixtureTrait;

/**
 * @internal
 */
final class PortalSettingsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use PortalFixtureTrait;

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
        $this->cleanPortal();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function refusalOf(callable $action): ?AccountingError
    {
        try {
            $action();
        } catch (AccountingException $failure) {
            return $failure->error;
        }

        return null;
    }

    public function testIsEnabled_IsFalseUntilSomeoneSwitchesThePortalOn(): void
    {
        $this->accountingDb()->table('app_config')->where('key', PortalSettings::KEY)->delete();

        $this->assertFalse((new PortalSettings())->isEnabled());

        $this->setConfigValue(PortalSettings::KEY, '0');
        $this->assertFalse((new PortalSettings())->isEnabled());

        $this->setConfigValue(PortalSettings::KEY, 'yes');
        $this->assertFalse((new PortalSettings())->isEnabled());

        $this->setConfigValue(PortalSettings::KEY, '1');
        $this->assertTrue((new PortalSettings())->isEnabled());
    }

    public function testSetEnabled_SwitchesThePortalOnAndOffAndWritesAnAuditEntryOfEach(): void
    {
        $manager = $this->portalManager();
        $setting = new PortalSettings();

        $setting->setEnabled(true, $manager);
        $this->assertTrue($setting->isEnabled());

        $setting->setEnabled(false, $manager);
        $this->assertFalse($setting->isEnabled());

        $audit = $this->portalAudit('portal_setting_changed');
        $this->assertCount(2, $audit);
        $this->assertSame([true, false], array_map(static fn (array $row): bool => json_decode($row['details'], true)['enabled'], $audit));
        $this->assertSame((string) $manager, (string) $audit[0]['employee_id']);
    }

    public function testSetEnabled_WritesNothingWhenTheSwitchIsAlreadyAsAsked(): void
    {
        (new PortalSettings())->setEnabled(false, $this->portalManager());

        $this->assertCount(0, $this->portalAudit('portal_setting_changed'));
    }

    public function testSetEnabled_RequiresThePermissionToManageThePortal(): void
    {
        $clerk = $this->employeeWithGrants(['customers', 'config']);

        $this->assertSame(AccountingError::PermissionDenied, $this->refusalOf(static fn () => (new PortalSettings())->setEnabled(true, $clerk)));
        $this->assertFalse((new PortalSettings())->isEnabled());
    }

    public function testSetEnabled_RefusesTheSystemAsTheAuthor(): void
    {
        $this->assertSame(AccountingError::PermissionDenied, $this->refusalOf(static fn () => (new PortalSettings())->setEnabled(true, null)));
        $this->assertFalse((new PortalSettings())->isEnabled());
    }
}
