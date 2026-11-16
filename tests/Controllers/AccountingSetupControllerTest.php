<?php

namespace Tests\Controllers;

use App\Controllers\AccountingSetup;
use App\Libraries\Accounting\AccountingActivation;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\JournalSettings;
use App\Libraries\Accounting\OperationsHold;
use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\ShippingCompanies;
use App\Libraries\Pricing\SalesControlSettings;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClassConstant;
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
        $this->resetJournalSettings();
        $this->clearJournals();
        $this->accountingDb()->table('app_config')->where('key', ShippingCompanies::ENABLED_KEY)->delete();
        $this->accountingDb()->table('app_config')->whereIn('key', ['accounting_activation', 'accounting_operations_hold'])->delete();
        $this->accountingDb()->table('app_config')->whereIn('key', ['accounting_sales_adjustment', 'accounting_sales_shipping', 'accounting_shipping_tax_percent', 'accounting_debit_note', 'accounting_journal_description_sales', 'accounting_journal_description_purchases'])->delete();

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

        $viewer->assertDontSee('accounting_settings/activate');
        $viewer->assertDontSee('btn-primary btn-sm">' . lang('Common.submit'));

        $this->manager();

        $this->get('/accounting_settings')->assertSee('accounting_settings/activate');
    }

    public function testGetIndex_HidesTheButtonsFromSomeoneLimitedToBranches(): void
    {
        $this->manager([1]);

        $this->get('/accounting_settings')->assertDontSee('accounting_settings/activate');
    }

    public function testGetIndex_ListsWhatIsMissingBeforeAccountingCanBeTurnedOn(): void
    {
        $this->setConfigValue('accounting_base_currency', '');
        $this->setConfigValue('accounting_start_date', '');
        $this->accountingDb()->table('accounting_fiscal_years')->where('company_id', 1)->delete();
        $this->manager();

        $response = $this->get('/accounting_settings');

        $response->assertSee('acc-readiness');
        $response->assertSee(lang('Accounting_activation.auto_settings'));
        $response->assertSee(lang('Accounting_activation.auto_years'));
        $response->assertDontSee(lang('Accounting_settings.readiness_base_currency_missing'));
        $response->assertSee('id="first_year_form"');
        $response->assertDontSee('disabled>' . lang('Accounting_settings.enable'));
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
        $response->assertSee('accounting_settings/activate');
    }

    public function testGetIndex_ListsNoticesWithoutDisablingTheButton(): void
    {
        $this->accountingDb()->table('sales')->insert(['sale_time' => '2025-06-01 10:00:00', 'employee_id' => $this->employeeWithGrants([]), 'comment' => 'earlier sale']);
        $saleId = (int) $this->accountingDb()->insertID();
        $this->manager();

        try {
            $response = $this->get('/accounting_settings');

            $response->assertSee(lang('Accounting_settings.readiness_notices_title'));
            $response->assertSee('accounting_settings/activate');
        } finally {
            $this->accountingDb()->table('sales')->where('sale_id', $saleId)->delete();
        }
    }

    public function testGetIndex_ShowsReadyWhenEverythingIsSet(): void
    {
        $this->manager();

        $response = $this->get('/accounting_settings');

        $response->assertSee(lang('Accounting_settings.activation_ready'));
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
        $this->assertStringContainsString('id="expense_account_required" value="1" checked', $this->get('/accounting_settings')->getBody());

        $this->setConfigValue('accounting_expense_account_required', '0');
        $this->assertStringContainsString('id="expense_account_required" value="1"', $this->get('/accounting_settings')->getBody());
        $this->assertStringNotContainsString('id="expense_account_required" value="1" checked', $this->get('/accounting_settings')->getBody());
    }

    public function testPostSave_SavesWhetherCustomersMayBeGivenAnOpeningBalanceAndLeavesItAloneWhenNotSent(): void
    {
        $this->manager();

        $form = ['base_currency' => 'usd', 'start_date' => '2026-01-01', 'auto_fiscal_year' => '1'];

        $this->assertTrue($this->jsonOf($this->post('/accounting_settings/save', $form + ['customer_opening' => '1']))['success']);
        $this->assertTrue((new AccountingSettings())->customerOpeningEnabled());

        $this->assertTrue($this->jsonOf($this->post('/accounting_settings/save', $form))['success']);
        $this->assertTrue((new AccountingSettings())->customerOpeningEnabled(), 'A form without the switch leaves it as it was');

        $this->assertTrue($this->jsonOf($this->post('/accounting_settings/save', $form + ['customer_opening' => '0']))['success']);
        $this->assertFalse((new AccountingSettings())->customerOpeningEnabled());
    }

    public function testGetIndex_ShowsWhetherCustomersMayBeGivenAnOpeningBalance(): void
    {
        $this->manager();

        $this->setConfigValue('accounting_customer_opening', '1');
        $this->assertStringContainsString('id="customer_opening" value="1" checked', $this->get('/accounting_settings')->getBody());

        $this->setConfigValue('accounting_customer_opening', '0');
        $this->assertStringContainsString('id="customer_opening" value="1"', $this->get('/accounting_settings')->getBody());
        $this->assertStringNotContainsString('id="customer_opening" value="1" checked', $this->get('/accounting_settings')->getBody());
    }

    public function testPostSave_SavesWhetherAReceiptVoucherNeedsAnAccountAndKeepsItWhenTheFormDoesNotSendIt(): void
    {
        $this->manager();

        $form = ['base_currency' => 'usd', 'start_date' => '2026-01-01', 'auto_fiscal_year' => '1'];

        $this->assertTrue($this->jsonOf($this->post('/accounting_settings/save', $form + ['revenue_account_required' => '1']))['success']);
        $this->assertTrue((new AccountingSettings())->revenueAccountRequired());

        $this->assertTrue($this->jsonOf($this->post('/accounting_settings/save', $form))['success']);
        $this->assertTrue((new AccountingSettings())->revenueAccountRequired(), 'A form without the field leaves it alone');

        $this->assertTrue($this->jsonOf($this->post('/accounting_settings/save', $form + ['revenue_account_required' => '0']))['success']);
        $this->assertFalse((new AccountingSettings())->revenueAccountRequired());
    }

    public function testGetIndex_ShowsWhetherAReceiptVoucherNeedsAnAccount(): void
    {
        $this->manager();

        $this->setConfigValue('accounting_revenue_account_required', '1');
        $this->assertStringContainsString('id="revenue_account_required" value="1" checked', $this->get('/accounting_settings')->getBody());

        $this->setConfigValue('accounting_revenue_account_required', '0');
        $this->assertStringContainsString('id="revenue_account_required" value="1"', $this->get('/accounting_settings')->getBody());
        $this->assertStringNotContainsString('id="revenue_account_required" value="1" checked', $this->get('/accounting_settings')->getBody());
    }

    /**
     * @return array<string, array{string, string}> The name of the field in the form and the key of the setting.
     */
    public static function switchProvider(): array
    {
        return [
            'the adjustment of the header' => ['sales_adjustment', 'accounting_sales_adjustment'],
            'the shipping of the header'   => ['sales_shipping', 'accounting_sales_shipping'],
            'the sales debit note'         => ['debit_note', 'accounting_debit_note'],
        ];
    }

    #[DataProvider('switchProvider')]
    public function testPostSave_SavesTheSwitchAndLeavesItAloneWhenTheFormDoesNotSendIt(string $field, string $key): void
    {
        $this->manager();

        $form = ['base_currency' => 'usd', 'start_date' => '2026-01-01', 'auto_fiscal_year' => '1'];
        $read = fn (): ?string => $this->accountingDb()->table('app_config')->where('key', $key)->get()->getRow()?->value;

        $this->assertTrue($this->jsonOf($this->post('/accounting_settings/save', $form + [$field => '1']))['success']);
        $this->assertSame('1', $read());

        $this->assertTrue($this->jsonOf($this->post('/accounting_settings/save', $form))['success']);
        $this->assertSame('1', $read(), 'A form without the switch leaves it as it was');

        $this->assertTrue($this->jsonOf($this->post('/accounting_settings/save', $form + [$field => '0']))['success']);
        $this->assertNotSame('1', $read());
    }

    #[DataProvider('switchProvider')]
    public function testGetIndex_ShowsWhetherTheSwitchIsOnAndNamesItsField(string $field, string $key): void
    {
        $this->manager();

        $this->setConfigValue($key, '1');
        $this->assertStringContainsString('name="' . $field . '" id="' . $field . '" value="1" checked', $this->get('/accounting_settings')->getBody(), 'the switch posts under the name the controller reads');
        $this->assertStringContainsString('<input type="hidden" name="' . $field . '" value="0">', $this->get('/accounting_settings')->getBody());

        $this->setConfigValue($key, '0');
        $this->assertStringContainsString('name="' . $field . '" id="' . $field . '" value="1"', $this->get('/accounting_settings')->getBody());
        $this->assertStringNotContainsString('name="' . $field . '" id="' . $field . '" value="1" checked', $this->get('/accounting_settings')->getBody());
    }

    public function testPostSave_SavesTheTaxRateOfShippingAndRefusesWhatIsNotAPercentage(): void
    {
        $this->manager();

        $form = ['base_currency' => 'usd', 'start_date' => '2026-01-01', 'auto_fiscal_year' => '1'];

        $this->assertTrue($this->jsonOf($this->post('/accounting_settings/save', $form + ['shipping_tax_percent' => '15']))['success']);
        $this->assertSame('15', (new AccountingSettings())->shippingTaxPercent());

        foreach (['100.01', '-1', 'ten', '15%'] as $invalid) {
            $answer = $this->jsonOf($this->post('/accounting_settings/save', $form + ['shipping_tax_percent' => $invalid]));

            $this->assertFalse($answer['success'], $invalid);
            $this->assertSame(lang('Accounting.error_shipping_tax_percent_invalid'), $answer['message'], $invalid);
            $this->assertSame('15', (new AccountingSettings())->shippingTaxPercent(), 'a refused rate leaves the one that stood');
        }

        $this->assertTrue($this->jsonOf($this->post('/accounting_settings/save', $form))['success']);
        $this->assertSame('15', (new AccountingSettings())->shippingTaxPercent(), 'a form without the field leaves it alone');
    }

    public function testGetIndex_ShowsTheTaxRateOfShippingInItsField(): void
    {
        $this->manager();

        $this->setConfigValue('accounting_shipping_tax_percent', '7.5');

        $this->assertStringContainsString('name="shipping_tax_percent" id="shipping_tax_percent" value="7.5"', $this->get('/accounting_settings')->getBody());
    }

    public function testPostSave_SavesTheNumberOfEntriesBeforeASearchBoxAndRefusesWhatIsNotAWholeNumber(): void
    {
        $this->manager();

        $form = ['base_currency' => 'usd', 'start_date' => '2026-01-01', 'auto_fiscal_year' => '1'];

        $this->assertTrue($this->jsonOf($this->post('/accounting_settings/save', $form + ['picker_threshold' => '350']))['success']);
        $this->assertSame(350, (new AccountingSettings())->pickerThreshold());

        foreach (['0', '-3', 'many', '12.5', '100001'] as $invalid) {
            $answer = $this->jsonOf($this->post('/accounting_settings/save', $form + ['picker_threshold' => $invalid]));

            $this->assertFalse($answer['success'], $invalid);
            $this->assertSame(lang('Accounting.error_picker_threshold_invalid', [(string) AccountingSettings::MAX_PICKER_SIZE]), $answer['message'], $invalid);
            $this->assertSame(350, (new AccountingSettings())->pickerThreshold(), 'a refused number leaves the one that stood');
        }

        $this->assertTrue($this->jsonOf($this->post('/accounting_settings/save', $form))['success']);
        $this->assertSame(350, (new AccountingSettings())->pickerThreshold(), 'a form without the field leaves it alone');
    }

    public function testPostSave_RejectsANumberOfEntriesSentAsAnArray(): void
    {
        $this->manager();

        $form = ['base_currency' => 'usd', 'start_date' => '2026-01-01', 'auto_fiscal_year' => '1'];

        $this->assertTrue($this->jsonOf($this->post('/accounting_settings/save', $form + ['picker_threshold' => '120']))['success']);

        $response = $this->post('/accounting_settings/save', $form + ['picker_threshold' => ['5', '6']]);
        $answer   = $this->jsonOf($response);

        $response->assertStatus(400);
        $this->assertFalse($answer['success']);
        $this->assertSame(lang('Accounting_settings.malformed_request'), $answer['message']);
        $this->assertSame(120, (new AccountingSettings())->pickerThreshold());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function textFieldsOfTheSettings(): array
    {
        return [
            'the base currency'              => ['base_currency'],
            'the start date'                 => ['start_date'],
            'the tax on shipping'            => ['shipping_tax_percent'],
            'the description of the sales'   => ['journal_description_sales'],
            'the description of the buying'  => ['journal_description_purchases'],
            'the number of entries'          => ['picker_threshold'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function settingsSnapshot(): array
    {
        return [
            $this->accountingDb()->table('app_config')->like('key', 'accounting_', 'after')->orderBy('key', 'ASC')->get()->getResultArray(),
            $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'settings')->countAllResults(),
        ];
    }

    #[DataProvider('textFieldsOfTheSettings')]
    public function testPostSave_RejectsATextFieldSentAsAnArrayWithoutSavingAnything(string $field): void
    {
        $this->manager();

        $form = ['base_currency' => 'usd', 'start_date' => '2026-01-01', 'auto_fiscal_year' => '1', 'shipping_tax_percent' => '15', 'journal_description_sales' => 'Invoice {number}', 'journal_description_purchases' => 'Bill {number}', 'picker_threshold' => '120'];

        $this->assertTrue($this->jsonOf($this->post('/accounting_settings/save', $form))['success']);

        $before = $this->settingsSnapshot();

        foreach ([['x'], [], ['a' => ['b']]] as $malformed) {
            $response = $this->post('/accounting_settings/save', [$field => $malformed, 'sales_shipping' => '1', 'debit_note' => '1'] + $form);

            $response->assertStatus(400);
            $this->assertFalse($this->jsonOf($response)['success'], $field);
            $this->assertSame(lang('Accounting_settings.malformed_request'), $this->jsonOf($response)['message'], $field);
        }

        $this->assertSame($before, $this->settingsSnapshot(), 'a refused request leaves every setting and the audit log as they were');
        $this->assertFalse((new AccountingSettings())->salesShippingEnabled(), 'the valid switches of the same form are not saved either');
        $this->assertSame('Invoice {number}', (new AccountingSettings())->journalDescriptionTemplate('sales'), 'an array does not take the template away');
    }

    public function testPostSave_AcceptsEveryTextFieldSentAsText(): void
    {
        $this->manager();

        $form = ['base_currency' => 'usd', 'start_date' => '2026-01-01', 'auto_fiscal_year' => '1', 'shipping_tax_percent' => '15', 'journal_description_sales' => 'Invoice {number}', 'journal_description_purchases' => 'Bill {number}', 'picker_threshold' => '120'];

        $response = $this->post('/accounting_settings/save', $form);

        $response->assertStatus(200);
        $this->assertTrue($this->jsonOf($response)['success']);
        $this->assertSame('15', (new AccountingSettings())->shippingTaxPercent());
        $this->assertSame('Bill {number}', (new AccountingSettings())->journalDescriptionTemplate('purchases'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function textFieldsOfTheControls(): array
    {
        return [
            'the default list'     => ['default_list'],
            'the maximum discount' => ['max_discount'],
        ];
    }

    #[DataProvider('textFieldsOfTheControls')]
    public function testPostSaveControls_RejectsATextFieldSentAsAnArrayWithoutSavingAnything(string $field): void
    {
        $this->resetSalesControlSettings();
        $this->manager();

        $response = $this->post('/accounting_settings/save_controls', [$field => ['5'], 'minimum_price' => '1']);

        $response->assertStatus(400);
        $this->assertFalse($this->jsonOf($response)['success']);
        $this->assertSame(lang('Accounting_settings.malformed_request'), $this->jsonOf($response)['message']);
        $this->assertFalse((new SalesControlSettings())->minimumPriceEnabled());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function textFieldsOfTheShippingCompany(): array
    {
        return [
            'the name'    => ['name'],
            'the account' => ['account_id'],
        ];
    }

    #[DataProvider('textFieldsOfTheShippingCompany')]
    public function testPostSaveShippingCompany_RejectsATextFieldSentAsAnArrayWithoutSavingAnything(string $field): void
    {
        $this->manager();

        $count    = $this->accountingDb()->table('shipping_companies')->countAllResults();
        $response = $this->post('/accounting_settings/save_shipping_company', [$field => ['x']] + ['name' => 'Fast Ship', 'account_id' => '']);

        $response->assertStatus(400);
        $this->assertFalse($this->jsonOf($response)['success']);
        $this->assertSame($count, $this->accountingDb()->table('shipping_companies')->countAllResults());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function textFieldsOfTheFirstYear(): array
    {
        return [
            'the name'       => ['name'],
            'the start date' => ['start_date'],
            'the end date'   => ['end_date'],
        ];
    }

    #[DataProvider('textFieldsOfTheFirstYear')]
    public function testPostFirstYear_RejectsATextFieldSentAsAnArrayWithoutCreatingAYear(string $field): void
    {
        $this->accountingDb()->table('accounting_fiscal_years')->where('company_id', 1)->delete();
        $this->manager();

        $response = $this->post('/accounting_settings/first_year', [$field => ['x']] + ['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

        $response->assertStatus(400);
        $this->assertFalse($this->jsonOf($response)['success']);
        $this->assertSame(0, $this->accountingDb()->table('accounting_fiscal_years')->where('company_id', 1)->countAllResults());
    }

    public function testGetIndex_ShowsTheNumberOfEntriesBeforeASearchBoxInItsField(): void
    {
        $this->manager();

        $this->setConfigValue('accounting_picker_threshold', '60');

        $this->assertStringContainsString('name="picker_threshold" id="picker_threshold" value="60"', $this->get('/accounting_settings')->getBody());
    }

    public function testPostSave_SavesTheDescriptionTemplatesOfSalesAndPurchasesAndRefusesAnUnknownPlaceholder(): void
    {
        $this->manager();

        $form = ['base_currency' => 'usd', 'start_date' => '2026-01-01', 'auto_fiscal_year' => '1'];

        $this->assertTrue($this->jsonOf($this->post('/accounting_settings/save', $form + ['journal_description_sales' => 'Invoice {number} for {party}', 'journal_description_purchases' => 'Bill {number} from {party} on {date}']))['success']);
        $this->assertSame('Invoice {number} for {party}', (new AccountingSettings())->journalDescriptionTemplate('sales'));
        $this->assertSame('Bill {number} from {party} on {date}', (new AccountingSettings())->journalDescriptionTemplate('purchases'));

        foreach (['journal_description_sales', 'journal_description_purchases'] as $field) {
            foreach (['Total {amount}', '<b>{number}</b>', str_repeat('x', 201)] as $invalid) {
                $answer = $this->jsonOf($this->post('/accounting_settings/save', $form + [$field => $invalid]));

                $this->assertFalse($answer['success'], $field);
                $this->assertSame(lang('Accounting.error_journal_template_invalid', [200]), $answer['message'], $field);
            }
        }

        $this->assertSame('Invoice {number} for {party}', (new AccountingSettings())->journalDescriptionTemplate('sales'), 'a refused template leaves the one that stood');

        $this->assertTrue($this->jsonOf($this->post('/accounting_settings/save', $form))['success']);
        $this->assertSame('Bill {number} from {party} on {date}', (new AccountingSettings())->journalDescriptionTemplate('purchases'), 'a form without the field leaves it alone');

        $this->assertTrue($this->jsonOf($this->post('/accounting_settings/save', $form + ['journal_description_sales' => '', 'journal_description_purchases' => '']))['success']);
        $this->assertSame('', (new AccountingSettings())->journalDescriptionTemplate('sales'), 'an empty field takes the template away');
    }

    public function testGetIndex_ShowsTheDescriptionTemplatesEscapedWithTheirPlaceholders(): void
    {
        $this->manager();

        $this->setConfigValue('accounting_journal_description_sales', 'Invoice {number} "{party}"');
        $this->setConfigValue('accounting_journal_description_purchases', 'Bill {number}');

        $body = $this->get('/accounting_settings')->response()->getBody();

        $this->assertSame(1, preg_match('/id="journal_description_sales" value=(["\'])(.*?)\1 maxlength/s', $body, $sales), 'the quotes of the text do not end the attribute');
        $this->assertSame(1, preg_match('/id="journal_description_purchases" value=(["\'])(.*?)\1 maxlength/s', $body, $purchases));
        $this->assertSame('Invoice {number} "{party}"', html_entity_decode($sales[2]));
        $this->assertSame('Bill {number}', html_entity_decode($purchases[2]));
        $this->assertStringContainsString('id="journal_description_sales" value="' . esc('Invoice {number} "{party}"', 'attr') . '"', $body);
        $this->assertStringContainsString('id="journal_description_purchases" value="' . esc('Bill {number}', 'attr') . '"', $body);
        $this->assertStringNotContainsString('value="Invoice {number} "{party}""', $body);
        $this->assertStringContainsString('{number} {party} {type} {date}', $body);
        $this->assertStringContainsString('maxlength="200"', $body);
    }

    public function testPostSave_RefusesTheNewSettingsToAnEmployeeWithoutThePermissionToManageSettings(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_settings']));

        $answer = $this->jsonOf($this->post('/accounting_settings/save', ['base_currency' => 'usd', 'start_date' => '2026-01-01', 'auto_fiscal_year' => '1', 'sales_shipping' => '1', 'debit_note' => '1', 'journal_description_sales' => 'Invoice {number}']));

        $this->assertFalse($answer['success']);
        $this->assertFalse((new AccountingSettings())->salesShippingEnabled());
        $this->assertFalse((new AccountingSettings())->debitNoteEnabled());
        $this->assertSame('', (new AccountingSettings())->journalDescriptionTemplate('sales'));
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

    public function testPostSave_ReportsTheLockWhileOnlyADraftJournalExists(): void
    {
        $this->setConfigValue('accounting_base_currency', 'SAR');
        (new JournalService())->saveDraft($this->journalInput());
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_settings/save', ['base_currency' => 'USD', 'start_date' => '2026-01-01', 'auto_fiscal_year' => '1']));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_settings_locked'), $result['message']);
        $this->assertSame('SAR', (new AccountingSettings())->baseCurrency());
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

    public function testPostEnable_CreatesTheFiscalYearsThroughTheYearAfterTheCurrentOne(): void
    {
        $this->accountingDb()->table('accounting_fiscal_years')->where('company_id', 1)->delete();
        $this->accountingDb()->table('accounting_fiscal_years')->insert(['name' => '2024', 'start_date' => '2024-01-01', 'end_date' => '2024-12-31']);
        $this->setConfigValue('accounting_start_date', '2024-03-01');
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_settings/enable'));

        $this->assertTrue($result['success']);
        $this->assertSame(array_map('strval', range(2024, max(2024, (int) date('Y') + 1))), array_column($this->accountingDb()->table('accounting_fiscal_years')->where('company_id', 1)->orderBy('start_date')->get()->getResultArray(), 'name'));
    }

    public function testPostEnable_CreatesNoYearWhenAccountingCannotBeTurnedOnYet(): void
    {
        $this->accountingDb()->table('accounting_fiscal_years')->where('company_id', 1)->delete();
        $this->accountingDb()->table('accounting_fiscal_years')->insert(['name' => '2024', 'start_date' => '2024-01-01', 'end_date' => '2024-12-31']);
        $this->setConfigValue('accounting_start_date', '2024-03-01');
        $this->setConfigValue('accounting_base_currency', '');
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_settings/enable'));

        $this->assertFalse($result['success']);
        $this->assertSame(1, $this->accountingDb()->table('accounting_fiscal_years')->where('company_id', 1)->countAllResults());
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

    public function testPostDisable_TakesBackTurningAccountingOnWhenTheWordIsTyped(): void
    {
        $this->setConfigValue('accounting_enabled', '1');
        $this->clearJournals();
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_settings/disable', ['confirm' => ' ' . lang('Accounting_settings.undo_word') . ' ']));

        $this->assertTrue($result['success']);
        $this->assertFalse((new AccountingSettings())->isEnabled());
    }

    public function testPostDisable_ChangesNothingWhenTheWordTypedIsWrongOrMissing(): void
    {
        $this->setConfigValue('accounting_enabled', '1');
        $this->manager();

        $wrong   = $this->jsonOf($this->post('/accounting_settings/disable', ['confirm' => 'yes']));
        $missing = $this->jsonOf($this->post('/accounting_settings/disable'));

        $this->assertFalse($wrong['success']);
        $this->assertFalse($missing['success']);
        $this->assertTrue((new AccountingSettings())->isEnabled());
    }

    public function testPostDisable_RefusesOnceTheBooksHoldAJournal(): void
    {
        $this->setConfigValue('accounting_enabled', '1');
        $this->clearJournals();
        (new PostingService())->post($this->balancedDraft());
        $this->manager();

        try {
            $result = $this->jsonOf($this->post('/accounting_settings/disable', ['confirm' => lang('Accounting_settings.undo_word')]));
        } finally {
            $this->clearJournals();
        }

        $this->assertFalse($result['success']);
        $this->assertTrue((new AccountingSettings())->isEnabled());
    }

    public function testGetIndex_OffersTheUndoOnlyBeforeTheFirstJournalAndNeverAPlainSwitchOff(): void
    {
        $this->setConfigValue('accounting_enabled', '1');
        $this->clearJournals();
        $this->manager();

        $response = $this->get('/accounting_settings');

        $response->assertSee('data-settings-action="disable"');
        $response->assertSee(lang('Accounting_settings.undo_button'));
        $response->assertDontSee('>' . lang('Accounting_settings.disable') . '<');

        (new PostingService())->post($this->balancedDraft());

        try {
            $locked = $this->get('/accounting_settings');
        } finally {
            $this->clearJournals();
        }

        $locked->assertDontSee('data-settings-action="disable"');
        $locked->assertSee(lang('Accounting_settings.books_in_use'));
    }

    public function testPostHold_FreezesOperationsWithAReasonAndRefusesWithoutOne(): void
    {
        $this->setConfigValue('accounting_enabled', '1');
        $this->manager();

        $refused = $this->jsonOf($this->post('/accounting_settings/hold', ['reason' => '  ']));
        $frozen  = $this->jsonOf($this->post('/accounting_settings/hold', ['reason' => 'Fixing the routing']));

        $this->assertFalse($refused['success']);
        $this->assertTrue($frozen['success']);
        $this->assertSame('Fixing the routing', (new OperationsHold())->state()['reason']);

        $this->get('/accounting_settings')->assertSee('Fixing the routing');
    }

    public function testPostHold_RefusesSomeoneLimitedToBranches(): void
    {
        $this->setConfigValue('accounting_enabled', '1');
        $this->manager([1]);

        $result = $this->jsonOf($this->post('/accounting_settings/hold', ['reason' => 'Fixing']));

        $this->assertFalse($result['success']);
        $this->assertFalse((new OperationsHold())->isOn());
    }

    public function testPostReleaseHold_LiftsTheFreezeOnceNothingStopsAPosting(): void
    {
        $this->setConfigValue('accounting_enabled', '1');
        $person = $this->manager();
        (new OperationsHold())->engage('Fixing the routing', $person);

        $result = $this->jsonOf($this->post('/accounting_settings/release_hold'));

        $this->assertTrue($result['success']);
        $this->assertFalse((new OperationsHold())->isOn());
    }

    public function testPostReleaseHold_KeepsTheFreezeAndSaysWhatStopsAPosting(): void
    {
        $this->setConfigValue('accounting_enabled', '1');
        $person = $this->manager();
        (new OperationsHold())->engage('Fixing the routing', $person);
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.cogs')->update(['account_id' => null]);

        $result = $this->jsonOf($this->post('/accounting_settings/release_hold'));

        $this->assertFalse($result['success']);
        $this->assertTrue((new OperationsHold())->isOn());
    }

    public function testGetIndex_ShowsTheFreezeBannerOnEveryPageWhileOperationsAreFrozen(): void
    {
        $this->setConfigValue('accounting_enabled', '1');
        $person = $this->manager();
        (new OperationsHold())->engage('Fixing the routing', $person);

        $this->get('/accounting_settings')->assertSee('id="operations_hold_banner"');
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

    public function testGetIndex_ListsWhoHoldsThePermissionToSettleBetweenBranchesUnderItsOwnName(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_settings']));

        $this->whileNobodyHoldsSensitivePermissions(function (): void {
            $accountant = $this->employeeWithGrants([AccountingGuard::INTERBRANCH_SETTLE]);

            $response = $this->get('/accounting_settings');

            $response->assertStatus(200);
            $response->assertSee('sensitive_holders');
            $response->assertSee(lang('Accounting_treasuries.interbranch_settle'));
            $response->assertSee($this->usernameOf($accountant));
            $response->assertDontSee(lang('Accounting_settings.holders_none'));
        });
    }

    public function testGetIndex_ListsWhoHoldsThePermissionToCloseTheYearUnderItsOwnName(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_settings']));

        $this->whileNobodyHoldsSensitivePermissions(function (): void {
            $accountant = $this->employeeWithGrants([AccountingGuard::YEAR_END_CLOSE]);

            $response = $this->get('/accounting_settings');

            $response->assertStatus(200);
            $response->assertSee('sensitive_holders');
            $response->assertSee(lang('Accounting_periods.year_end_close'));
            $response->assertSee($this->usernameOf($accountant));
            $response->assertDontSee(lang('Accounting_settings.holders_none'));
        });
    }

    public function testGetIndex_ListsEverySensitivePermissionOfTheGuardByName(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_settings']));

        $labels = (new ReflectionClassConstant(AccountingSetup::class, 'PERMISSION_LABELS'))->getValue();

        $this->assertEqualsCanonicalizing(AccountingGuard::SENSITIVE, array_keys($labels));

        $this->whileNobodyHoldsSensitivePermissions(function () use ($labels): void {
            $holder = $this->employeeWithGrants(AccountingGuard::SENSITIVE);

            $response = $this->get('/accounting_settings');

            $response->assertStatus(200);
            $response->assertSee($this->usernameOf($holder));

            foreach ($labels as $label) {
                $response->assertSee(lang($label));
            }
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

    public function testPostSave_SavesWhetherThePastMayBePostedAndLeavesItAloneWhenNotSent(): void
    {
        $this->manager();

        $form = ['base_currency' => 'usd', 'start_date' => '2026-01-01', 'auto_fiscal_year' => '1'];

        $this->assertTrue($this->jsonOf($this->post('/accounting_settings/save', $form + ['backfill_enabled' => '1']))['success']);
        $this->assertTrue((new AccountingSettings())->backfillEnabled());

        $this->assertTrue($this->jsonOf($this->post('/accounting_settings/save', $form))['success']);
        $this->assertTrue((new AccountingSettings())->backfillEnabled(), 'A form without the switch leaves it as it was');

        $this->assertTrue($this->jsonOf($this->post('/accounting_settings/save', $form + ['backfill_enabled' => '0']))['success']);
        $this->assertFalse((new AccountingSettings())->backfillEnabled());
    }

    public function testGetIndex_ShowsWhetherThePastMayBePostedAndNamesThePermissionAmongTheSensitiveOnes(): void
    {
        $this->manager();

        $this->setConfigValue('accounting_backfill_enabled', '1');
        $this->assertStringContainsString('id="backfill_enabled" value="1" checked', $this->get('/accounting_settings')->getBody());

        $this->setConfigValue('accounting_backfill_enabled', '0');
        $this->assertStringNotContainsString('id="backfill_enabled" value="1" checked', $this->get('/accounting_settings')->getBody());

        $holder = $this->employeeWithGrants([AccountingGuard::BACKFILL_MANAGE]);

        $this->assertGreaterThan(0, $holder);
        $this->assertStringContainsString(lang('Accounting_periods.backfill_manage'), $this->get('/accounting_settings')->getBody());
    }

    // ---------------------------------------------------------------------------------------
    // WP36: the settings of the price lists and of the controls on prices and on credit
    // ---------------------------------------------------------------------------------------

    private function resetSalesControlSettings(): void
    {
        $this->accountingDb()->table('app_config')->whereIn('key', [
            SalesControlSettings::PRICE_LISTS, SalesControlSettings::DEFAULT_LIST, SalesControlSettings::CHANGE_LIST, SalesControlSettings::MINIMUM_PRICE, SalesControlSettings::MAX_DISCOUNT_ON,
            SalesControlSettings::MAX_DISCOUNT, SalesControlSettings::EXEMPT_CATEGORIES, SalesControlSettings::CREDIT_LIMIT, SalesControlSettings::CREDIT_PERIOD,
        ])->delete();
        $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'settings', 'action' => 'settings_updated'])->delete();
        $this->accountingDb()->table('price_lists')->where('price_list_id >', 0)->delete();
    }

    public function testGetIndex_ShowsTheControlsOfPricesAndCreditSwitchedOffToWhoeverOpensTheScreen(): void
    {
        $this->resetSalesControlSettings();
        $this->actAs($this->employeeWithGrants(['accounting_settings']));

        $page = $this->get('/accounting_settings');

        $page->assertSee('id="controls_form"');
        $page->assertSee(lang('Accounting_settings.control_credit_limit'));

        foreach (['price_lists', 'change_list', 'minimum_price', 'max_discount_enabled', 'credit_limit', 'credit_period'] as $name) {
            $this->assertDoesNotMatchRegularExpression('/<input[^>]*id="control_' . $name . '"[^>]*checked/', $page->getBody(), $name);
        }
    }

    public function testGetIndex_ListsTheSensitivePermissionsToOverridePricesAndCreditAmongTheHolders(): void
    {
        $holder = $this->employeeWithGrants([AccountingGuard::PRICE_OVERRIDE, AccountingGuard::CREDIT_OVERRIDE]);

        $this->actAs($this->employeeWithGrants(['accounting_settings']));

        $page = $this->get('/accounting_settings');

        $page->assertSee(lang('Sales.price_override'));
        $page->assertSee(lang('Sales.credit_override'));
        $page->assertSee($this->usernameOf($holder));
    }

    public function testGetIndex_ShowsTheNameOfAListInTheDefaultListChoiceAsTextAndNeverAsMarkup(): void
    {
        $this->resetSalesControlSettings();

        $this->accountingDb()->table('price_lists')->insert(['name' => '<b>Gold</b> & <i>VIP</i>']);
        $this->actAs($this->employeeWithGrants(['accounting_settings']));

        $body = $this->get('/accounting_settings')->response()->getBody();

        $this->assertStringContainsString('&lt;b&gt;Gold&lt;/b&gt; &amp; &lt;i&gt;VIP&lt;/i&gt;', $body);
        $this->assertStringNotContainsString('<b>Gold</b>', $body);

        $this->resetSalesControlSettings();
    }

    public function testPostSaveControls_TurnsTheControlsOnStoresTheirValuesAndWritesWhatChanged(): void
    {
        $this->resetSalesControlSettings();

        $manager = $this->manager();

        $this->accountingDb()->table('price_lists')->insert(['name' => 'Wholesale']);
        $listId = (int) $this->accountingDb()->insertID();

        $result = $this->jsonOf($this->post('/accounting_settings/save_controls', [
            'price_lists'          => '1',
            'change_list'          => '1',
            'minimum_price'        => '1',
            'max_discount_enabled' => '1',
            'credit_limit'         => '1',
            'credit_period'        => '0',
            'default_list'         => (string) $listId,
            'max_discount'         => '15',
            'exempt_categories'    => ['Services', 'Gifts'],
        ]));

        $this->assertTrue($result['success']);
        $this->assertSame(lang('Accounting_settings.controls_saved'), $result['message']);

        $settings = (new SalesControlSettings())->all();

        $this->assertSame([true, true, true, true, true, false], [$settings['price_lists'], $settings['change_list'], $settings['minimum_price'], $settings['max_discount_enabled'], $settings['credit_limit'], $settings['credit_period']]);
        $this->assertSame([$listId, '15.00', ['Gifts', 'Services']], [$settings['default_list'], $settings['max_discount'], $settings['exempt_categories']]);

        $entry = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'settings', 'action' => 'settings_updated'])->get()->getRow();

        $this->assertSame($manager, (int) $entry->employee_id);
        $this->assertSame(true, json_decode($entry->details, true)['after']['credit_limit']);

        $this->resetSalesControlSettings();
    }

    public function testPostSaveControls_ChangesOnlyWhatTheFormSent(): void
    {
        $this->resetSalesControlSettings();
        $this->manager();

        $this->post('/accounting_settings/save_controls', ['minimum_price' => '1', 'max_discount' => '5']);
        $this->post('/accounting_settings/save_controls', ['credit_limit' => '1']);

        $settings = new SalesControlSettings();

        $this->assertTrue($settings->minimumPriceEnabled());
        $this->assertSame('5.00', $settings->maxDiscountPercent());
        $this->assertTrue($settings->creditLimitEnabled());

        $this->resetSalesControlSettings();
    }

    public function testPostSaveControls_RefusesSomeoneWhoMayNotChangeTheSettingsAndChangesNothing(): void
    {
        $this->resetSalesControlSettings();
        $this->actAs($this->employeeWithGrants(['accounting_settings', AccountingGuard::PRICE_OVERRIDE]));

        $result = $this->jsonOf($this->post('/accounting_settings/save_controls', ['credit_limit' => '1', 'minimum_price' => '1']));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $result['message']);
        $this->assertFalse((new SalesControlSettings())->creditLimitEnabled());
        $this->assertFalse((new SalesControlSettings())->minimumPriceEnabled());
        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'settings', 'action' => 'settings_updated'])->countAllResults());
    }

    public function testPostSaveControls_RedirectsAUserWithoutTheModuleGrant(): void
    {
        $this->actAs($this->employeeWithGrants([AccountingGuard::SETTINGS_MANAGE]));

        $this->post('/accounting_settings/save_controls', ['credit_limit' => '1'])->assertRedirect();
        $this->assertFalse((new SalesControlSettings())->creditLimitEnabled());
    }

    public function testPostSaveControls_SaysWhyAValueWasRefusedAndChangesNothing(): void
    {
        $this->resetSalesControlSettings();
        $this->manager();

        $percent = $this->jsonOf($this->post('/accounting_settings/save_controls', ['minimum_price' => '1', 'max_discount' => '120']));
        $list    = $this->jsonOf($this->post('/accounting_settings/save_controls', ['minimum_price' => '1', 'default_list' => '987654']));

        foreach ([$percent, $list] as $result) {
            $this->assertFalse($result['success']);
            $this->assertSame(lang('Accounting.error_control_setting_invalid'), $result['message']);
        }

        $this->assertFalse((new SalesControlSettings())->minimumPriceEnabled());
    }

    public function testPostSaveControls_ClearsTheExcludedCategoriesWhenTheFormSendsOnlyTheEmptyFallback(): void
    {
        $this->resetSalesControlSettings();
        $manager = $this->manager();

        $this->post('/accounting_settings/save_controls', ['exempt_categories' => ['Services']]);

        $this->assertSame(['Services'], (new SalesControlSettings())->discountExemptCategories());

        $result = $this->jsonOf($this->post('/accounting_settings/save_controls', ['exempt_categories' => ['']]));

        $this->assertTrue($result['success']);
        $this->assertSame([], (new SalesControlSettings())->discountExemptCategories());

        $entry = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'settings', 'action' => 'settings_updated'])->orderBy('audit_id', 'DESC')->get()->getRow();

        $this->assertSame($manager, (int) $entry->employee_id);
        $this->assertSame([], json_decode($entry->details, true)['after']['exempt_categories']);

        $this->resetSalesControlSettings();
    }

    public function testGetIndex_SendsAnEmptyCategoryBeforeTheChoiceSoThatClearingTheLastExcludedCategoryReachesTheServer(): void
    {
        $this->resetSalesControlSettings();
        $this->manager();

        $body = $this->get('/accounting_settings')->getBody();

        $this->assertMatchesRegularExpression('/<input type="hidden" name="exempt_categories\[\]" value="">\s*<select name="exempt_categories\[\]"/', $body);

        $this->resetSalesControlSettings();
    }

    public function testGetIndex_ShowsTheSettingsOfTheJournalLinesWithTheirSwitchesOff(): void
    {
        $this->manager();

        $page = $this->get('/accounting_settings')->response()->getBody();

        $this->assertStringContainsString('id="journal_form"', $page);

        foreach (['allow_over_100', 'cost_center_required', 'automatic_default', 'line_tags', 'line_tax'] as $name) {
            $this->assertStringContainsString('name="' . $name . '"', $page, $name);
            $this->assertStringContainsString(lang('Accounting_settings.journal_' . $name), $page, $name);
        }

        $this->assertStringNotContainsString('id="journal_allow_over_100" value="1" checked', $page);
    }

    public function testPostSaveJournal_TurnsTheSettingsOnAndWritesWhatChangedToTheAuditLog(): void
    {
        $manager = $this->manager();

        $result = $this->jsonOf($this->post('/accounting_settings/save_journal', ['allow_over_100' => '1', 'cost_center_required' => '1', 'automatic_default' => '0', 'line_tags' => '1', 'line_tax' => '0']));

        $this->assertTrue($result['success']);
        $this->assertSame(lang('Accounting_settings.journal_saved'), $result['message']);
        $this->assertSame(['allow_over_100' => true, 'cost_center_required' => true, 'automatic_default' => false, 'line_tags' => true, 'line_tax' => false], (new JournalSettings())->all());

        $entry = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'settings', 'action' => 'settings_updated'])->get()->getRow();

        $this->assertSame($manager, (int) $entry->employee_id);
        $this->assertSame(true, json_decode($entry->details, true)['after']['allow_over_100']);
    }

    public function testPostSaveJournal_ChangesOnlyWhatTheFormSent(): void
    {
        $this->manager();

        $this->post('/accounting_settings/save_journal', ['line_tags' => '1']);
        $this->post('/accounting_settings/save_journal', ['line_tax' => '1']);

        $this->assertTrue((new JournalSettings())->lineTags());
        $this->assertTrue((new JournalSettings())->lineTax());
        $this->assertFalse((new JournalSettings())->allowOver100());
    }

    public function testPostSaveJournal_RefusesSomeoneWhoMayNotChangeTheSettingsAndChangesNothing(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_settings']));

        $result = $this->jsonOf($this->post('/accounting_settings/save_journal', ['allow_over_100' => '1']));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $result['message']);
        $this->assertFalse((new JournalSettings())->allowOver100());
    }

    public function testPostSaveJournal_RefusesAnEmployeeLimitedToSomeBranches(): void
    {
        $this->manager([1]);

        $result = $this->jsonOf($this->post('/accounting_settings/save_journal', ['allow_over_100' => '1']));

        $this->assertFalse($result['success']);
        $this->assertFalse((new JournalSettings())->allowOver100());
    }

    public function testPostSaveJournal_RedirectsAUserWithoutTheModuleGrant(): void
    {
        $this->actAs($this->employeeWithGrants([AccountingGuard::SETTINGS_MANAGE]));

        $this->post('/accounting_settings/save_journal', ['allow_over_100' => '1'])->assertRedirect();
        $this->assertFalse((new JournalSettings())->allowOver100());
    }

    public function testGetIndex_ShowsTheShippingCompaniesCardWithTheCompaniesAndTheAccountsTheyMayTake(): void
    {
        $this->manager();
        $account = $this->customAccount('2212', '22121', 'Owed to Fast Ship');
        $company = (new ShippingCompanies())->create(['name' => 'Fast Ship', 'account_id' => $account]);

        $page = $this->get('/accounting_settings')->response()->getBody();

        $this->assertStringContainsString('id="shipping_switch_form"', $page);
        $this->assertStringContainsString('id="shipping_company_form"', $page);
        $this->assertStringContainsString('Fast Ship', $page);
        $this->assertStringContainsString('22121', $page);
        $this->assertStringContainsString('data-id="' . $company . '"', $page);
        $this->assertStringContainsString('>' . lang('Accounting_settings.shipping_company_edit') . '</button>', $page);
        $this->assertStringContainsString('<option value="' . $account . '">22121 - Owed to Fast Ship</option>', $page);
        $this->assertStringNotContainsString('<option value="' . $this->accountId('cash') . '">', substr($page, (int) strpos($page, 'id="shipping_company_account"')));
    }

    public function testGetIndex_ShowsTheCompaniesButNoFormToWhoMayNotChangeTheSettings(): void
    {
        (new ShippingCompanies())->create(['name' => 'Fast Ship']);
        $this->actAs($this->employeeWithGrants(['accounting_settings']));

        $page = $this->get('/accounting_settings')->response()->getBody();

        $this->assertStringContainsString('Fast Ship', $page);
        $this->assertStringNotContainsString('id="shipping_company_form"', $page);
        $this->assertStringNotContainsString('data-account=', $page);
    }

    public function testPostSaveShippingCompanies_TurnsTheSettingOnAndOff(): void
    {
        $this->manager();

        $on = $this->jsonOf($this->post('/accounting_settings/save_shipping_companies', ['enabled' => '1']));

        $this->assertTrue($on['success']);
        $this->assertSame(lang('Accounting_settings.shipping_saved'), $on['message']);
        $this->assertTrue((new ShippingCompanies())->enabled());

        $this->post('/accounting_settings/save_shipping_companies', ['enabled' => '0']);

        $this->assertFalse((new ShippingCompanies())->enabled());
    }

    public function testPostSaveShippingCompany_AddsAndChangesACompanyWithItsAccount(): void
    {
        $manager = $this->manager();
        $account = $this->customAccount('2212', '22121', 'Owed to Fast Ship');

        $added = $this->jsonOf($this->post('/accounting_settings/save_shipping_company', ['name' => 'Fast Ship', 'account_id' => (string) $account]));

        $this->assertTrue($added['success']);
        $this->assertSame(lang('Accounting_settings.shipping_company_saved'), $added['message']);
        $this->assertSame($account, (new ShippingCompanies())->accountOf($added['id']));

        $renamed = $this->jsonOf($this->post('/accounting_settings/save_shipping_company/' . $added['id'], ['name' => 'Fast Ship Ltd', 'account_id' => '']));

        $this->assertTrue($renamed['success']);
        $this->assertSame('Fast Ship Ltd', (new ShippingCompanies())->find($added['id'])->name);
        $this->assertNull((new ShippingCompanies())->accountOf($added['id']));
        $this->assertSame($manager, (int) $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'shipping_company')->where('action', 'updated')->get()->getRow()->employee_id);
    }

    public function testPostSaveShippingCompany_RefusesTheAccountOfASupplierAndDoesNotOfferIt(): void
    {
        $this->manager();
        $supplierAccount = (new EntityAccountService())->ensure(EntityAccountService::SUPPLIER, 7, 'Acme Supplies');

        $refused = $this->jsonOf($this->post('/accounting_settings/save_shipping_company', ['name' => 'Fast Ship', 'account_id' => (string) $supplierAccount]));

        $this->assertFalse($refused['success']);
        $this->assertSame(lang('Accounting.error_shipping_account_invalid'), $refused['message']);
        $this->assertSame([], (new ShippingCompanies())->all());

        $page = $this->get('/accounting_settings')->response()->getBody();

        $this->assertStringNotContainsString('<option value="' . $supplierAccount . '">', substr($page, (int) strpos($page, 'id="shipping_company_account"')));
    }

    public function testPostSaveShippingCompany_SaysWhyACompanyIsRefusedAndKeepsWhatWasThere(): void
    {
        $this->manager();
        $companies = new ShippingCompanies();
        $companies->create(['name' => 'Fast Ship']);

        $duplicate = $this->jsonOf($this->post('/accounting_settings/save_shipping_company', ['name' => 'Fast Ship']));
        $cash      = $this->jsonOf($this->post('/accounting_settings/save_shipping_company', ['name' => 'Other', 'account_id' => (string) $this->accountId('cash')]));

        $this->assertFalse($duplicate['success']);
        $this->assertSame(lang('Accounting.error_name_taken', ['Fast Ship']), $duplicate['message']);
        $this->assertFalse($cash['success']);
        $this->assertSame(lang('Accounting.error_shipping_account_invalid'), $cash['message']);
        $this->assertCount(1, $companies->all());
    }

    public function testPostToggleShippingCompany_SwitchesACompanyOffAndOn(): void
    {
        $this->manager();
        $companies = new ShippingCompanies();
        $id        = $companies->create(['name' => 'Fast Ship']);

        $this->assertTrue($this->jsonOf($this->post('/accounting_settings/toggle_shipping_company/' . $id))['success']);
        $this->assertSame('0', (string) $companies->find($id)->active);

        $this->assertTrue($this->jsonOf($this->post('/accounting_settings/toggle_shipping_company/' . $id))['success']);
        $this->assertSame('1', (string) $companies->find($id)->active);
        $this->assertFalse($this->jsonOf($this->post('/accounting_settings/toggle_shipping_company/999999'))['success']);
    }

    public function testPostSaveShippingCompany_RefusesSomeoneWhoMayNotChangeTheSettingsAndAnEmployeeOfSomeBranches(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_settings']));

        $refused = $this->jsonOf($this->post('/accounting_settings/save_shipping_company', ['name' => 'Fast Ship']));

        $this->assertFalse($refused['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $refused['message']);

        $this->manager([1]);

        $this->assertFalse($this->jsonOf($this->post('/accounting_settings/save_shipping_company', ['name' => 'Fast Ship']))['success']);
        $this->assertFalse($this->jsonOf($this->post('/accounting_settings/save_shipping_companies', ['enabled' => '1']))['success']);
        $this->assertSame([], (new ShippingCompanies())->all());
        $this->assertFalse((new ShippingCompanies())->enabled());
    }

    private function insertSale(string $time): void
    {
        $this->accountingDb()->table('sales')->insert(['comment' => 'activation-controller-test', 'sale_time' => $time, 'sale_status' => COMPLETED, 'employee_id' => 1]);
    }

    private function removeInsertedSales(): void
    {
        $this->accountingDb()->table('sales')->where('comment', 'activation-controller-test')->delete();
    }

    public function testGetIndex_OffersTheWizardToSomeoneWhoManagesTheSettings(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_settings']));

        $this->get('/accounting_settings')->assertDontSee('accounting_settings/activate');

        $this->manager();

        $response = $this->get('/accounting_settings');

        $response->assertSee('accounting_settings/activate');
        $response->assertSee(lang('Accounting_settings.activation_open'));
        $response->assertDontSee('disabled>' . lang('Accounting_settings.enable'));
    }

    public function testGetIndex_OffersToCarryOnARunThatWasLeftUnfinished(): void
    {
        $person = $this->manager();
        (new AccountingActivation())->begin($person, 'SAR', '2026-11-01', [AccountingActivation::ACK_IMPACT]);

        $this->get('/accounting_settings')->assertSee(lang('Accounting_settings.activation_resume'));
    }

    public function testGetActivate_ShowsTheWizardWithTheSuggestedCurrencyAndDate(): void
    {
        $this->setConfigValue('accounting_base_currency', 'EGP');
        $this->setConfigValue('accounting_start_date', '2026-03-01');
        $this->manager();

        $response = $this->get('/accounting_settings/activate');

        $response->assertStatus(200);
        $response->assertSee('activation_wizard');
        $response->assertSee('value="EGP"');
        $response->assertSee('value="2026-03-01"');
    }

    public function testGetActivate_RedirectsSomeoneWhoMayNotManageTheSettings(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_settings']));

        $this->get('/accounting_settings/activate')->assertRedirect();
    }

    public function testGetActivate_RedirectsSomeoneLimitedToBranches(): void
    {
        $this->manager([1]);

        $this->get('/accounting_settings/activate')->assertRedirect();
    }

    public function testGetActivate_SendsThemBackWhenAccountingIsAlreadyOn(): void
    {
        $this->setConfigValue('accounting_enabled', '1');
        $this->manager();

        $this->get('/accounting_settings/activate')->assertRedirect();
    }

    public function testGetActivate_OffersToResumeARunThatWasLeftUnfinished(): void
    {
        $person = $this->manager();
        (new AccountingActivation())->begin($person, 'SAR', '2026-11-01', [AccountingActivation::ACK_IMPACT]);

        $this->get('/accounting_settings/activate')->assertSee('id="resume_run"');
    }

    public function testGetActivate_ListsWhatOnlyAPersonCanSettleAndBlocksContinuing(): void
    {
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.cogs')->update(['account_id' => null]);
        $this->manager();

        $response = $this->get('/accounting_settings/activate');

        $response->assertSee(lang('Accounting_activation.blockers_title'));
        $response->assertSee('id="notices_next" disabled');
    }

    public function testGetActivationImpact_CountsTheDocumentsAroundTheDate(): void
    {
        $this->manager();
        $this->insertSale('2026-05-01 10:00:00');
        $this->insertSale('2026-07-01 10:00:00');

        try {
            $result = $this->jsonOf($this->get('/accounting_settings/activation_impact', ['date' => '2026-06-01']));
        } finally {
            $this->removeInsertedSales();
        }

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['impact']['before']['sales']);
        $this->assertSame(1, $result['impact']['from']['sales']);
    }

    public function testGetActivationImpact_RefusesADateThatIsNotADate(): void
    {
        $this->manager();

        $result = $this->jsonOf($this->get('/accounting_settings/activation_impact', ['date' => '2026-13-45']));

        $this->assertFalse($result['success']);
    }

    public function testGetActivationImpact_RefusesSomeoneWhoMayNotManageTheSettings(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_settings']));

        $this->get('/accounting_settings/activation_impact', ['date' => '2026-06-01'])->assertStatus(403);
    }

    public function testPostActivationBegin_OpensTheRunWhenTheImpactIsAcknowledged(): void
    {
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_settings/activation_begin', ['currency' => 'SAR', 'start_date' => '2026-11-01', 'ack' => [AccountingActivation::ACK_IMPACT]]));

        $this->assertTrue($result['success']);
        $this->assertSame(['running', 'years'], [$result['run']['status'], $result['run']['step']]);
        $this->assertFalse((new AccountingSettings())->isEnabled());
    }

    public function testPostActivationBegin_RefusesWithoutTheAcknowledgement(): void
    {
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_settings/activation_begin', ['currency' => 'SAR', 'start_date' => '2026-11-01']));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString(AccountingActivation::ACK_IMPACT, $result['message']);
        $this->assertNull((new AccountingActivation())->status());
    }

    public function testPostActivationBegin_RefusesSomeoneLimitedToBranches(): void
    {
        $this->manager([1]);

        $this->post('/accounting_settings/activation_begin', ['currency' => 'SAR', 'start_date' => '2026-11-01', 'ack' => [AccountingActivation::ACK_IMPACT]])->assertStatus(403);
        $this->assertNull((new AccountingActivation())->status());
    }

    public function testPostActivationBegin_RefusesAValueThatIsNotText(): void
    {
        $this->manager();

        $this->post('/accounting_settings/activation_begin', ['currency' => ['SAR'], 'start_date' => '2026-11-01'])->assertStatus(400);
    }

    public function testPostActivationStep_RunsToTheEndAndTurnsAccountingOn(): void
    {
        $this->setConfigValue('accounting_start_date', '');
        $this->accountingDb()->table('accounting_fiscal_years')->where('company_id', 1)->delete();
        $person = $this->manager();
        $run    = (new AccountingActivation())->begin($person, 'SAR', '2026-11-01', [AccountingActivation::ACK_IMPACT]);

        $result = [];

        for ($turn = 0; $turn < 20; $turn++) {
            $result = $this->jsonOf($this->post('/accounting_settings/activation_step', ['run_id' => $run['run_id']]));

            $this->assertTrue($result['success']);

            if ($result['run']['done']) {
                break;
            }
        }

        $this->assertTrue($result['run']['done']);
        $this->assertTrue((new AccountingSettings())->isEnabled());
    }

    public function testPostActivationStep_RefusesARunThatIsNotTheOneInProgress(): void
    {
        $this->manager();

        $result = $this->jsonOf($this->post('/accounting_settings/activation_step', ['run_id' => '0000000000000000']));

        $this->assertFalse($result['success']);
    }

    public function testPostActivationStep_RefusesSomeoneWhoMayNotManageTheSettings(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_settings']));

        $this->post('/accounting_settings/activation_step', ['run_id' => '0000000000000000'])->assertStatus(403);
    }
}
