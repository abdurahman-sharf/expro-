<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\PaymentType;
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
            $systemKey = $key->defaultSystemKey();

            $this->accountingDb()->table('accounting_routing')->where('routing_key', $key->value)->update(['account_id' => $systemKey === null ? null : $this->accountId($systemKey)]);
        }
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->setConfigValue('accounting_line_description_required', '0');

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

    public function testGetIndex_LinksToTheRoutingScreenOnlyForSomeoneWhoCanOpenIt(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_settings']));

        $this->get('/accounting_settings')->assertDontSee(lang('Accounting_settings.readiness_routing_link'));

        $this->actAs($this->employeeWithGrants(['accounting_settings', 'accounting_routing']));

        $this->get('/accounting_settings')->assertSee(lang('Accounting_settings.readiness_routing_link'));
    }

    public function testGetIndex_ListsAnUnroutedPaymentTypeAsWhatStopsTurningAccountingOn(): void
    {
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'payment.cash')->update(['account_id' => null]);
        $this->manager();

        $response = $this->get('/accounting_settings');

        $response->assertSee(lang('Accounting_settings.readiness_payment_type_unrouted', [PaymentType::Cash->value]));
        $response->assertSee('disabled>' . lang('Accounting_settings.enable'));
    }

    public function testGetIndex_ListsNoticesWithoutDisablingTheButton(): void
    {
        $this->accountingDb()->table('sales')->insert(['sale_time' => '2025-06-01 10:00:00', 'employee_id' => $this->employeeWithGrants([]), 'comment' => 'earlier sale']);
        $saleId = (int) $this->accountingDb()->insertID();
        $this->manager();

        try {
            $response = $this->get('/accounting_settings');

            $response->assertSee(lang('Accounting_settings.readiness_notices_title'));
            $response->assertDontSee('disabled>' . lang('Accounting_settings.enable'));
        } finally {
            $this->accountingDb()->table('sales')->where('sale_id', $saleId)->delete();
        }
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

    public function testPostSave_SavesWhetherAnExpenseNeedsACategoryAccount(): void
    {
        $this->manager();

        $form = ['base_currency' => 'usd', 'start_date' => '2026-01-01', 'auto_fiscal_year' => '1'];

        $this->assertTrue($this->jsonOf($this->post('/accounting_settings/save', $form + ['expense_account_required' => '1']))['success']);
        $this->assertTrue((new AccountingSettings())->expenseAccountRequired());

        $this->assertTrue($this->jsonOf($this->post('/accounting_settings/save', $form + ['expense_account_required' => '0']))['success']);
        $this->assertFalse((new AccountingSettings())->expenseAccountRequired());
    }

    public function testGetIndex_ShowsWhetherAnExpenseNeedsACategoryAccount(): void
    {
        $this->manager();

        $this->setConfigValue('accounting_expense_account_required', '1');
        $this->assertStringContainsString('value="1" checked', $this->get('/accounting_settings')->getBody());

        $this->setConfigValue('accounting_expense_account_required', '0');
        $this->assertStringNotContainsString('id="expense_account_required" value="1" checked', $this->get('/accounting_settings')->getBody());
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

    public function testGetIndex_ShowsTheLineDescriptionSwitchOffByDefaultAndOnWhenTheBooksAskForIt(): void
    {
        $this->manager();
        $this->setConfigValue('accounting_line_description_required', '0');

        $off = $this->get('/accounting_settings');

        $off->assertSee('id="line_description_required"');
        $off->assertDontSee('id="line_description_required" value="1" checked');

        $this->setConfigValue('accounting_line_description_required', '1');

        $this->get('/accounting_settings')->assertSee('id="line_description_required" value="1" checked');
    }

    public function testGetIndex_PostsZeroBeforeTheLineDescriptionSwitchSoUntickingItTurnsTheRequirementOff(): void
    {
        $this->manager();

        $this->assertMatchesRegularExpression(
            '/<input type="hidden" name="line_description_required" value="0">\s*<input type="checkbox"[^>]*name="line_description_required"/',
            $this->get('/accounting_settings')->getBody(),
        );
    }

    public function testGetIndex_LocksTheLineDescriptionSwitchForSomeoneWhoCannotManageSettings(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_settings']));

        $this->get('/accounting_settings')->assertSee('id="line_description_required" value="1" disabled');
    }

    public function testPostSave_SavesTheLineDescriptionRequirement(): void
    {
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_settings/save', ['base_currency' => 'SAR', 'start_date' => '2026-01-01', 'auto_fiscal_year' => '1', 'line_description_required' => '1']));

        $this->assertTrue($result['success']);
        $this->assertTrue((new AccountingSettings())->lineDescriptionRequired());

        $result = $this->jsonOf($this->post('/accounting_settings/save', ['base_currency' => 'SAR', 'start_date' => '2026-01-01', 'auto_fiscal_year' => '1', 'line_description_required' => '0']));

        $this->assertTrue($result['success']);
        $this->assertFalse((new AccountingSettings())->lineDescriptionRequired());
    }

    public function testPostSave_LeavesTheLineDescriptionRequirementAloneWhenTheFormDoesNotSendIt(): void
    {
        $this->manager();
        $this->setConfigValue('accounting_line_description_required', '1');

        $result = $this->jsonOf($this->post('/accounting_settings/save', ['base_currency' => 'SAR', 'start_date' => '2026-01-01', 'auto_fiscal_year' => '1']));

        $this->assertTrue($result['success']);
        $this->assertTrue((new AccountingSettings())->lineDescriptionRequired());
    }

    public function testPostSave_RejectsTheLineDescriptionRequirementToSomeoneWithoutThePermission(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_settings']));

        $result = $this->jsonOf($this->post('/accounting_settings/save', ['base_currency' => 'SAR', 'start_date' => '2026-01-01', 'line_description_required' => '1']));

        $this->assertFalse($result['success']);
        $this->assertFalse((new AccountingSettings())->lineDescriptionRequired());
    }

    public function testPostSave_SavesTheLineDescriptionRequirementAfterAJournalIsPosted(): void
    {
        (new JournalService())->postNew($this->journalInput());
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_settings/save', ['base_currency' => 'SAR', 'start_date' => '2026-01-01', 'auto_fiscal_year' => '1', 'line_description_required' => '1']));

        $this->assertTrue($result['success']);
        $this->assertTrue((new AccountingSettings())->lineDescriptionRequired());
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

    /**
     * Runs the action while nobody holds a sensitive permission, then gives the grants back.
     */
    private function whileNobodyHoldsSensitivePermissions(callable $action): void
    {
        $db   = $this->accountingDb();
        $rows = $db->table('grants')->whereIn('permission_id', AccountingGuard::SENSITIVE)->get()->getResultArray();

        $db->table('grants')->whereIn('permission_id', AccountingGuard::SENSITIVE)->delete();

        try {
            $action();
        } finally {
            if ($rows !== []) {
                $db->table('grants')->insertBatch($rows);
            }
        }
    }

    public function testGetIndex_ListsWhoHoldsTheSensitivePermissions(): void
    {
        $second = $this->createBranch('B2');
        $this->actAs($this->employeeWithGrants(['accounting_settings']));

        $this->whileNobodyHoldsSensitivePermissions(function () use ($second): void {
            $everyone = $this->employeeWithGrants([AccountingGuard::PERIODS_CLOSE, AccountingGuard::PERIODS_REOPEN]);
            $limited  = $this->employeeWithGrants([AccountingGuard::OPENING_MANAGE], [$second]);
            $ordinary = $this->employeeWithGrants([AccountingGuard::JOURNALS_POST]);

            $response = $this->get('/accounting_settings');

            $response->assertSee('sensitive_holders');
            $response->assertSee(lang('Accounting_periods.periods_close'));
            $response->assertSee(lang('Accounting_periods.periods_reopen'));
            $response->assertSee(lang('Accounting_periods.opening_manage'));
            $response->assertSee(lang('Accounting_settings.holders_all_branches'));
            $response->assertSee('B2');
            $response->assertDontSee(lang('Accounting_settings.holders_none'));
            $response->assertDontSee($this->usernameOf($ordinary));
            $response->assertSee($this->usernameOf($everyone));
            $response->assertSee($this->usernameOf($limited));
        });
    }

    public function testGetIndex_ListsWhoHoldsTheTreasuriesAndPayablesPermissionsByName(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_settings']));

        $this->whileNobodyHoldsSensitivePermissions(function (): void {
            $treasurer = $this->employeeWithGrants([AccountingGuard::TREASURIES_MANAGE]);
            $clerk     = $this->employeeWithGrants([AccountingGuard::PAYABLES_MANAGE]);

            $response = $this->get('/accounting_settings');

            $response->assertSee('sensitive_holders');
            $response->assertSee(lang('Accounting_treasuries.treasuries_manage'));
            $response->assertSee(lang('Accounting_payables.payables_manage'));
            $response->assertSee($this->usernameOf($treasurer));
            $response->assertSee($this->usernameOf($clerk));
            $response->assertDontSee(lang('Accounting_settings.holders_none'));
        });
    }

    public function testGetIndex_ListsWhoHoldsThePermissionToEditJournalTextUnderItsOwnName(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_settings']));

        $this->whileNobodyHoldsSensitivePermissions(function (): void {
            $editor = $this->employeeWithGrants([AccountingGuard::JOURNALS_EDIT]);

            $response = $this->get('/accounting_settings');

            $response->assertStatus(200);
            $response->assertSee('sensitive_holders');
            $response->assertSee(lang('Accounting_journals.journals_edit_details'));
            $response->assertSee($this->usernameOf($editor));
            $response->assertDontSee(lang('Accounting_settings.holders_none'));
        });
    }

    public function testGetIndex_SaysNobodyHoldsThemWhenNobodyDoes(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_settings']));

        $this->whileNobodyHoldsSensitivePermissions(function (): void {
            $response = $this->get('/accounting_settings');

            $response->assertSee(lang('Accounting_settings.holders_none'));
            $response->assertDontSee('sensitive_holders');
        });
    }

    public function testGetIndex_ExplainsWhoGrantsThePermissionToSomeoneWhoCannotManage(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_settings']));

        $this->get('/accounting_settings')->assertSee(lang('Accounting_periods.permission_note'));

        $this->manager();

        $this->get('/accounting_settings')->assertDontSee(lang('Accounting_periods.permission_note'));
    }

    private function usernameOf(int $personId): string
    {
        return $this->accountingDb()->table('employees')->where('person_id', $personId)->get()->getRow()->username;
    }
}
