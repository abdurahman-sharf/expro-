<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingNotReadyException;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\ReadinessCode;
use App\Libraries\Accounting\ReadinessIssue;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class AccountingSettingsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;
    private AccountingSettings $settings;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $db = $this->accountingDb();
        $db->table('accounting_fiscal_years')->truncate();
        $db->table('accounting_accounts')->where('active', 0)->update(['active' => 1]);
        $db->table('accounting_audit_log')->truncate();

        foreach (RoutingKey::cases() as $key) {
            $db->table('accounting_routing')
                ->where('routing_key', $key->value)
                ->update(['account_id' => $this->accountId($key->defaultSystemKey())]);
        }

        $this->setConfigValue('accounting_enabled', '0');
        $this->setConfigValue('accounting_base_currency', '');
        $this->setConfigValue('accounting_start_date', '');
        $this->settings = new AccountingSettings();
    }

    private function completeTheSettings(): void
    {
        $this->setConfigValue('accounting_base_currency', 'SAR');
        $this->setConfigValue('accounting_start_date', '2026-01-01');
        $this->accountingDb()->table('accounting_fiscal_years')->insert([
            'name'       => '2026',
            'start_date' => '2026-01-01',
            'end_date'   => '2026-12-31',
        ]);
    }

    /**
     * @param list<ReadinessIssue> $issues
     *
     * @return list<string>
     */
    private function codesOf(array $issues): array
    {
        return array_map(static fn ($issue): string => $issue->code->value, $issues);
    }

    public function testIsEnabled_IsFalseOnAFreshInstall(): void
    {
        $this->assertFalse($this->settings->isEnabled());
    }

    public function testReadiness_ReportsWhatAFreshInstallStillNeeds(): void
    {
        $this->assertSame(
            ['base_currency_missing', 'start_date_missing', 'no_fiscal_year'],
            $this->codesOf($this->settings->readiness()),
        );
    }

    public function testReadiness_IsEmptyWhenEverythingIsConfigured(): void
    {
        $this->completeTheSettings();

        $this->assertSame([], $this->settings->readiness());
    }

    public function testReadiness_RejectsAStartDateOutsideEveryFiscalYear(): void
    {
        $this->completeTheSettings();
        $this->setConfigValue('accounting_start_date', '2025-06-01');

        $issues = $this->settings->readiness();

        $this->assertSame([ReadinessCode::StartDateOutsideFiscalYear], array_column($issues, 'code'));
        $this->assertSame('2025-06-01', $issues[0]->subject);
    }

    public function testReadiness_RejectsAMalformedStartDate(): void
    {
        $this->completeTheSettings();
        $this->setConfigValue('accounting_start_date', '01/01/2026');

        $this->assertSame(['start_date_missing'], $this->codesOf($this->settings->readiness()));
    }

    public function testReadiness_ReportsAnUnmappedRoutingKey(): void
    {
        $this->completeTheSettings();
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.cogs')->update(['account_id' => null]);

        $issues = $this->settings->readiness();

        $this->assertSame([ReadinessCode::RoutingUnmapped], array_column($issues, 'code'));
        $this->assertSame('sales.cogs', $issues[0]->subject);
    }

    public function testReadiness_ReportsARoutingKeyPointingToAGroupAccount(): void
    {
        $this->completeTheSettings();
        $this->accountingDb()->table('accounting_routing')
            ->where('routing_key', 'cash.default')
            ->update(['account_id' => $this->accountIdByCode('1')]);

        $issues = $this->settings->readiness();

        $this->assertSame([ReadinessCode::RoutingAccountInvalid], array_column($issues, 'code'));
        $this->assertSame('cash.default', $issues[0]->subject);
    }

    public function testReadiness_ReportsARoutingKeyPointingToAnInactiveAccount(): void
    {
        $this->completeTheSettings();
        $this->accountingDb()->table('accounting_accounts')->where('system_key', 'cash')->update(['active' => 0]);

        $this->assertSame(['routing_account_invalid'], $this->codesOf($this->settings->readiness()));
    }

    public function testEnable_RefusesWhileTheSettingsAreIncomplete(): void
    {
        try {
            $this->settings->enable(1);
            $this->fail('Enabling should have been refused.');
        } catch (AccountingNotReadyException $exception) {
            $this->assertSame(
                ['base_currency_missing', 'start_date_missing', 'no_fiscal_year'],
                $this->codesOf($exception->issues),
            );
        }

        $this->assertFalse($this->settings->isEnabled());
        $this->assertSame(0, $this->countRows('accounting_audit_log'));
    }

    public function testEnable_TurnsTheSwitchOnAndRecordsIt(): void
    {
        $this->completeTheSettings();
        $employee = $this->employeeWithGrants([AccountingGuard::SETTINGS_MANAGE]);

        $this->settings->enable($employee);

        $this->assertTrue($this->settings->isEnabled());

        $audit = $this->accountingDb()->table('accounting_audit_log')->get()->getRow();
        $this->assertSame('accounting_enabled', $audit->action);
        $this->assertSame((string) $employee, (string) $audit->employee_id);
    }

    public function testDisable_TurnsTheSwitchOffAndRecordsIt(): void
    {
        $this->completeTheSettings();
        $employee = $this->employeeWithGrants([AccountingGuard::SETTINGS_MANAGE]);
        $this->settings->enable($employee);

        $this->settings->disable($employee);

        $this->assertFalse($this->settings->isEnabled());
        $this->assertSame(2, $this->countRows('accounting_audit_log'));
    }

    private function manager(?array $branchIds = null): int
    {
        return $this->employeeWithGrants([AccountingGuard::SETTINGS_MANAGE], $branchIds);
    }

    private function assertUpdateFails(AccountingError $expected, array $input, ?int $employeeId = null): void
    {
        try {
            $this->settings->update($input, $employeeId);
        } catch (AccountingException $exception) {
            $this->assertSame($expected, $exception->error);

            return;
        }

        $this->fail('Expected the failure ' . $expected->value . '.');
    }

    public function testUpdate_SavesTheCurrencyTheStartDateAndTheAutomaticYearSetting(): void
    {
        $this->clearJournals();

        $this->settings->update(['base_currency' => 'usd', 'start_date' => '2026-04-01', 'auto_fiscal_year' => false], $this->manager());

        $this->assertSame('USD', $this->settings->baseCurrency());
        $this->assertSame('2026-04-01', $this->settings->startDate());
        $this->assertFalse($this->settings->autoFiscalYear());

        $this->settings->update(['auto_fiscal_year' => true], $this->manager());

        $this->assertTrue($this->settings->autoFiscalYear());
    }

    public function testUpdate_RejectsAnInvalidCurrencyOrDate(): void
    {
        $this->clearJournals();

        $this->assertUpdateFails(AccountingError::InvalidCurrency, ['base_currency' => 'SAUDI']);
        $this->assertUpdateFails(AccountingError::InvalidCurrency, ['base_currency' => '12$']);
        $this->assertUpdateFails(AccountingError::InvalidDate, ['start_date' => '2026-02-30']);
        $this->assertUpdateFails(AccountingError::InvalidDate, ['start_date' => 'soon']);
    }

    public function testUpdate_FixesTheCurrencyAndTheStartDateOnceAJournalIsPosted(): void
    {
        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');
        $this->setConfigValue('accounting_start_date', '2026-01-01');
        (new JournalService())->postNew($this->journalInput());

        $this->assertTrue($this->settings->isLocked());
        $this->assertUpdateFails(AccountingError::SettingsLocked, ['base_currency' => 'USD']);
        $this->assertUpdateFails(AccountingError::SettingsLocked, ['start_date' => '2026-02-01']);

        $this->settings->update(['base_currency' => 'SAR', 'start_date' => '2026-01-01', 'auto_fiscal_year' => false]);

        $this->assertFalse($this->settings->autoFiscalYear());
    }

    public function testUpdate_RejectsAStartDateOutsideEveryFiscalYear(): void
    {
        $this->clearJournals();
        $this->accountingDb()->table('accounting_fiscal_years')->where('start_date >=', '2027-01-01')->delete();

        $this->assertUpdateFails(AccountingError::StartDateOutsideFiscalYear, ['start_date' => '2040-01-01']);
    }

    public function testUpdate_RequiresThePermissionAndEveryBranch(): void
    {
        $this->clearJournals();

        $this->assertUpdateFails(AccountingError::PermissionDenied, ['base_currency' => 'USD'], $this->employeeWithGrants([]));

        $this->expectException(BranchException::class);

        $this->settings->update(['base_currency' => 'USD'], $this->manager([1]));
    }

    public function testUpdate_RecordsWhatChangedInTheAuditLog(): void
    {
        $this->clearJournals();
        $employee = $this->manager();

        $this->settings->update(['base_currency' => 'EUR'], $employee);

        $audit = $this->accountingDb()->table('accounting_audit_log')->where('action', 'settings_updated')->get()->getRow();

        $this->assertSame((string) $employee, (string) $audit->employee_id);
        $this->assertSame(['before' => ['base_currency' => ''], 'after' => ['base_currency' => 'EUR']], json_decode($audit->details, true));
    }

    public function testUpdate_WritesNothingWhenNothingChanges(): void
    {
        $this->clearJournals();
        $this->setConfigValue('accounting_base_currency', 'SAR');
        $this->setConfigValue('accounting_start_date', '2026-01-01');

        $this->settings->update(['base_currency' => 'SAR', 'start_date' => '2026-01-01'], $this->manager());

        $this->assertSame(0, $this->countRows('accounting_audit_log'));
    }

    public function testLastManualNumber_FollowsTheSequence(): void
    {
        $this->clearJournals();

        $this->assertSame(0, $this->settings->lastManualNumber());

        $this->accountingDb()->table('accounting_sequences')->where('series', 'manual')->update(['last_number' => 41]);

        $this->assertSame(41, $this->settings->lastManualNumber());
    }

    public function testEnable_RequiresThePermissionAndEveryBranchForAnEmployee(): void
    {
        $this->completeTheSettings();

        try {
            $this->settings->enable($this->employeeWithGrants([]));
            $this->fail('Expected the permission to be required.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::PermissionDenied, $exception->error);
        }

        $this->expectException(BranchException::class);

        $this->settings->enable($this->manager([1]));
    }
}
