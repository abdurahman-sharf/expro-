<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingNotReadyException;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\ReadinessCode;
use App\Libraries\Accounting\ReadinessIssue;
use App\Libraries\Accounting\RoutingKey;
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

        $this->settings->enable(7);

        $this->assertTrue($this->settings->isEnabled());

        $audit = $this->accountingDb()->table('accounting_audit_log')->get()->getRow();
        $this->assertSame('accounting_enabled', $audit->action);
        $this->assertSame('7', (string) $audit->employee_id);
    }

    public function testDisable_TurnsTheSwitchOffAndRecordsIt(): void
    {
        $this->completeTheSettings();
        $this->settings->enable(7);

        $this->settings->disable(7);

        $this->assertFalse($this->settings->isEnabled());
        $this->assertSame(2, $this->countRows('accounting_audit_log'));
    }
}
