<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\JournalSettings;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

/**
 * The settings of what a journal line may carry: all off until someone turns them on, changed only by
 * whoever manages the accounting settings across the whole company, each change in the audit log.
 *
 * @internal
 */
final class JournalSettingsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private JournalSettings $settings;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
        $this->resetJournalSettings();
        $this->settings = new JournalSettings();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->resetJournalSettings();

        parent::tearDown();
    }

    private function manager(?array $branchIds = null): int
    {
        return $this->employeeWithGrants([AccountingGuard::SETTINGS_MANAGE], $branchIds);
    }

    /**
     * @return list<object>
     */
    private function audit(): array
    {
        return $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'settings')->where('action', 'settings_updated')->orderBy('audit_id')->get()->getResult();
    }

    public function testAll_IsOffUntilSomeoneTurnsASettingOn(): void
    {
        $this->assertSame(['allow_over_100' => false, 'cost_center_required' => false, 'automatic_default' => false, 'line_tags' => false, 'line_tax' => false], $this->settings->all());
        $this->assertFalse($this->settings->allowOver100());
        $this->assertFalse($this->settings->costCenterRequired());
        $this->assertFalse($this->settings->defaultsOnAutomaticJournals());
        $this->assertFalse($this->settings->lineTags());
        $this->assertFalse($this->settings->lineTax());
    }

    public function testUpdate_TurnsOnEachSettingAndWritesWhatItWasToTheAuditLog(): void
    {
        $manager = $this->manager();

        $this->settings->update(['allow_over_100' => true, 'line_tags' => true], $manager);

        $this->assertTrue($this->settings->allowOver100());
        $this->assertTrue($this->settings->lineTags());
        $this->assertFalse($this->settings->costCenterRequired());
        $this->assertSame('1', $this->settingValue(JournalSettings::OVER_100));

        $entries = $this->audit();

        $this->assertCount(1, $entries);
        $this->assertSame($manager, (int) $entries[0]->employee_id);
        $this->assertSame(['before' => ['allow_over_100' => false, 'line_tags' => false], 'after' => ['allow_over_100' => true, 'line_tags' => true]], json_decode($entries[0]->details, true));

        $this->settings->update(['allow_over_100' => false, 'cost_center_required' => true, 'automatic_default' => true, 'line_tax' => true], $manager);

        $this->assertSame(['allow_over_100' => false, 'cost_center_required' => true, 'automatic_default' => true, 'line_tags' => true, 'line_tax' => true], $this->settings->all());
        $this->assertCount(2, $this->audit());
    }

    public function testUpdate_LeavesWhatIsNotNamedAndWritesNothingWhenNothingChanged(): void
    {
        $manager = $this->manager();

        $this->settings->update(['line_tax' => true], $manager);
        $this->settings->update([], $manager);
        $this->settings->update(['line_tax' => true], $manager);

        $this->assertTrue($this->settings->lineTax());
        $this->assertFalse($this->settings->lineTags());
        $this->assertCount(1, $this->audit());
    }

    public function testUpdate_RequiresThePermissionToManageTheAccountingSettings(): void
    {
        try {
            $this->settings->update(['allow_over_100' => true], $this->employeeWithGrants(['accounting_settings']));
            $this->fail('The settings were changed without the permission.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::PermissionDenied, $exception->error);
        }

        $this->assertFalse($this->settings->allowOver100());
        $this->assertSame([], $this->audit());
    }

    public function testUpdate_RejectsAnEmployeeLimitedToBranches(): void
    {
        $this->expectException(BranchException::class);

        try {
            $this->settings->update(['allow_over_100' => true], $this->manager([1]));
        } finally {
            $this->assertFalse($this->settings->allowOver100());
        }
    }

    public function testUpdate_AcceptsTheSystemItself(): void
    {
        $this->settings->update(['line_tags' => true]);

        $this->assertTrue($this->settings->lineTags());
    }

    private function settingValue(string $key): string
    {
        return (string) $this->accountingDb()->table('app_config')->where('key', $key)->get()->getRow()->value;
    }
}
