<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\RoutingKey;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\AccountingControllerFixtureTrait;

class AccountingSetupControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingControllerFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareAccounting(false);
        $this->setConfigValue('accounting_start_date', '2026-01-01');
        $this->setConfigValue('accounting_auto_fiscal_year', '1');

        foreach (RoutingKey::cases() as $key) {
            $this->accountingDb()->table('accounting_routing')->where('routing_key', $key->value)->update(['account_id' => $this->accountId($key->defaultSystemKey())]);
        }
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();

        parent::tearDown();
    }

    /**
     * @param list<int>|null $branchIds
     */
    private function manager(?array $branchIds = null): int
    {
        $personId = $this->employeeWithGrants(['accounting_settings', AccountingGuard::SETTINGS_MANAGE, AccountingGuard::PERIODS_MANAGE], $branchIds);
        $this->actAs($personId);

        return $personId;
    }

    public function testGetIndex_ShowsTheScreenWhileAccountingIsOff(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_settings']));

        $response = $this->get('/accounting_settings');

        $response->assertStatus(200);
        $response->assertSee('settings_form');
        $response->assertSee('text-bg-secondary');
    }

    public function testGetIndex_RedirectsAUserWithoutTheModuleGrant(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_chart']));

        $this->get('/accounting_settings')->assertRedirect();
    }

    public function testGetIndex_OffersTheButtonsOnlyToSomeoneWhoManagesSettings(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_settings']));

        $viewer = $this->get('/accounting_settings');

        $viewer->assertDontSee('data-settings-action="enable"');
        $viewer->assertDontSee('btn-primary btn-sm">' . lang('Common.submit'));

        $this->manager();

        $this->get('/accounting_settings')->assertSee('data-settings-action="enable"');
    }

    public function testGetIndex_HidesTheButtonsFromSomeoneLimitedToBranches(): void
    {
        $this->manager([1]);

        $this->get('/accounting_settings')->assertDontSee('data-settings-action="enable"');
    }

    public function testGetIndex_ListsWhatIsMissingBeforeAccountingCanBeTurnedOn(): void
    {
        $this->setConfigValue('accounting_base_currency', '');
        $this->setConfigValue('accounting_start_date', '');
        $this->accountingDb()->table('accounting_fiscal_years')->where('company_id', 1)->delete();
        $this->manager();

        $response = $this->get('/accounting_settings');

        $response->assertSee('acc-readiness');
        $response->assertSee(lang('Accounting_settings.readiness_base_currency_missing'));
        $response->assertSee(lang('Accounting_settings.readiness_no_fiscal_year'));
        $response->assertSee('id="first_year_form"');
        $response->assertSee('disabled>' . lang('Accounting_settings.enable'));
    }

    public function testGetIndex_ShowsReadyWhenEverythingIsSet(): void
    {
        $this->manager();

        $response = $this->get('/accounting_settings');

        $response->assertSee(lang('Accounting_settings.ready'));
        $response->assertDontSee('id="first_year_form"');
    }

    public function testPostSave_SavesTheSettings(): void
    {
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_settings/save', ['base_currency' => 'usd', 'start_date' => '2026-02-01', 'auto_fiscal_year' => '0']));

        $this->assertTrue($result['success']);

        $settings = new AccountingSettings();

        $this->assertSame('USD', $settings->baseCurrency());
        $this->assertSame('2026-02-01', $settings->startDate());
        $this->assertFalse($settings->autoFiscalYear());
    }

    public function testPostSave_ReportsAnInvalidCurrency(): void
    {
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_settings/save', ['base_currency' => 'SAUDI', 'start_date' => '2026-01-01', 'auto_fiscal_year' => '1']));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_invalid_currency'), $result['message']);
    }

    public function testPostSave_ReportsTheLockOnceAJournalIsPosted(): void
    {
        $this->setConfigValue('accounting_base_currency', 'SAR');
        (new JournalService())->postNew($this->journalInput());
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_settings/save', ['base_currency' => 'USD', 'start_date' => '2026-01-01', 'auto_fiscal_year' => '1']));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_settings_locked'), $result['message']);
    }

    public function testPostSave_RefusesSomeoneWithoutThePermission(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_settings']));

        $result = $this->jsonOf($this->post('/accounting_settings/save', ['base_currency' => 'USD', 'start_date' => '2026-01-01']));

        $this->assertFalse($result['success']);
        $this->assertSame('SAR', (new AccountingSettings())->baseCurrency());
    }

    public function testPostEnable_TurnsAccountingOnWhenEverythingIsSet(): void
    {
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_settings/enable'));

        $this->assertTrue($result['success']);
        $this->assertTrue((new AccountingSettings())->isEnabled());
    }

    public function testPostEnable_NamesWhatIsMissing(): void
    {
        $this->setConfigValue('accounting_base_currency', '');
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_settings/enable'));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString(lang('Accounting_settings.readiness_base_currency_missing'), $result['message']);
        $this->assertFalse((new AccountingSettings())->isEnabled());
    }

    public function testPostEnable_RefusesSomeoneLimitedToBranches(): void
    {
        $this->manager([1]);

        $result = $this->jsonOf($this->post('/accounting_settings/enable'));

        $this->assertFalse($result['success']);
        $this->assertFalse((new AccountingSettings())->isEnabled());
    }

    public function testPostDisable_TurnsAccountingOff(): void
    {
        $this->setConfigValue('accounting_enabled', '1');
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_settings/disable'));

        $this->assertTrue($result['success']);
        $this->assertFalse((new AccountingSettings())->isEnabled());
    }

    public function testPostFirstYear_CreatesTheYearThatCoversTheStartDate(): void
    {
        $this->accountingDb()->table('accounting_fiscal_years')->where('company_id', 1)->delete();
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_settings/first_year', ['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']));

        $this->assertTrue($result['success']);
        $this->assertSame(1, $this->accountingDb()->table('accounting_fiscal_years')->where('company_id', 1)->countAllResults());
        $this->assertSame([], (new AccountingSettings())->readiness());
    }

    public function testPostFirstYear_ReportsAnInvalidRange(): void
    {
        $this->accountingDb()->table('accounting_fiscal_years')->where('company_id', 1)->delete();
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_settings/first_year', ['name' => '2026', 'start_date' => '2026-12-31', 'end_date' => '2026-01-01']));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_fiscal_year_range'), $result['message']);
    }
}
