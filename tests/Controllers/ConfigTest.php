<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\SalesTaxSettings;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Stock\StockLocationLifecycle;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Database;
use Config\OSPOS;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\TaxFixtureTrait;

class ConfigTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use TaxFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        $db = Database::connect();
        $db->table('document_numbers')->truncate();
        $db->table('document_sequences')->truncate();
        $db->table('accounting_audit_log')->where('entity_type', 'document_numbering')->delete();
        $db->table('accounting_audit_log')->where('action', 'sales_tax_settings_updated')->delete();
        (new SalesTaxSettings())->update([SalesTaxSettings::INCLUDED_KEY => false, SalesTaxSettings::TAX_ONE_KEY => false]);
        $db->table('accounting_audit_log')->where('action', 'sales_tax_settings_updated')->delete();

        parent::tearDown();
    }

    protected function resetSession(): void
    {
        $this->withSession(['person_id' => 1, 'menu_group' => 'office']);
    }

    // ========== Valid Mailpath Tests ==========

    public function testValidMailpath_AcceptsStandardPath(): void
    {
        $this->resetSession();

        $response = $this->post('/config/saveEmail', [
            'protocol' => 'sendmail',
            'mailpath' => '/usr/sbin/sendmail'
        ]);

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertTrue($result['success']);
    }

    public function testValidMailpath_AcceptsPathWithDots(): void
    {
        $this->resetSession();

        $response = $this->post('/config/saveEmail', [
            'protocol' => 'sendmail',
            'mailpath' => '/usr/local/bin/sendmail.local'
        ]);

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertTrue($result['success']);
    }

    public function testValidMailpath_AcceptsEmptyStringForNonSendmailProtocol(): void
    {
        $this->resetSession();

        $response = $this->post('/config/saveEmail', [
            'protocol' => 'mail',
            'mailpath' => ''
        ]);

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertTrue($result['success']);
    }

    public function testSendmailProtocol_RequiresMailpath(): void
    {
        $this->resetSession();

        $response = $this->post('/config/saveEmail', [
            'protocol' => 'sendmail',
            'mailpath' => ''
        ]);

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('invalid', strtolower($result['message']));
    }

    public function testNonSendmailProtocol_RejectsMaliciousMailpath(): void
    {
        $this->resetSession();

        $response = $this->post('/config/saveEmail', [
            'protocol' => 'smtp',
            'mailpath' => '/usr/sbin/sendmail; cat /etc/passwd'
        ]);

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('invalid', strtolower($result['message']));
    }

    // ========== Command Injection Prevention Tests ==========

    public function testMailpath_RejectsCommandInjection_Semicolon(): void
    {
        $this->resetSession();

        $response = $this->post('/config/saveEmail', [
            'protocol' => 'sendmail',
            'mailpath' => '/usr/sbin/sendmail; cat /etc/passwd'
        ]);

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('invalid', strtolower($result['message']));
    }

    public function testMailpath_AcceptsSendmailPathWithTrailingArgs(): void
    {
        $this->resetSession();

        $response = $this->post('/config/saveEmail', [
            'protocol' => 'sendmail',
            'mailpath' => '/usr/sbin/sendmail -t -i'
        ]);

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertTrue($result['success']);
    }

    // ========== postSaveLocale: payment_reference_code_min / max ==========

    private function baseLocalePayload(array $overrides = []): array
    {
        return array_merge([
            'language'         => 'en:english',
            'currency_symbol'  => '$',
            'currency_code'    => 'USD',
            'timezone'         => 'UTC',
            'dateformat'       => 'Y-m-d',
            'timeformat'       => 'H:i',
            'number_locale'    => 'en_US',
            'currency_decimals' => '2',
            'tax_decimals'     => '2',
            'quantity_decimals' => '2',
            'cash_decimals'    => '2',
            'country_codes'    => 'US',
            'payment_options_order' => '',
            'cash_rounding_code'    => '',
            'financial_year'        => '1',
            'payment_reference_code_min' => '3',
            'payment_reference_code_max' => '20',
        ], $overrides);
    }

    public function testSaveLocale_AcceptsValidReferenceCodeMinMax(): void
    {
        $this->resetSession();

        $response = $this->post('/config/saveLocale', $this->baseLocalePayload([
            'payment_reference_code_min' => '3',
            'payment_reference_code_max' => '20',
        ]));

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertTrue($result['success']);
    }

    public function testSaveLocale_AcceptsMinEqualToMax(): void
    {
        $this->resetSession();

        $response = $this->post('/config/saveLocale', $this->baseLocalePayload([
            'payment_reference_code_min' => '10',
            'payment_reference_code_max' => '10',
        ]));

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertTrue($result['success']);
    }

    public function testSaveLocale_RejectsNonNumericReferenceCodeLimits(): void
    {
        $this->resetSession();

        // Non-numeric values fail integer validation, so the controller returns success===false.
        $response = $this->post('/config/saveLocale', $this->baseLocalePayload([
            'payment_reference_code_min' => 'abc',
            'payment_reference_code_max' => 'xyz',
        ]));

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
    }

    public function testSaveLocale_RejectsZeroReferenceCodeMin(): void
    {
        $this->resetSession();

        $response = $this->post('/config/saveLocale', $this->baseLocalePayload([
            'payment_reference_code_min' => '0',
            'payment_reference_code_max' => '20',
        ]));

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
    }

    public function testSaveLocale_RejectsNegativeReferenceCodeMin(): void
    {
        $this->resetSession();

        $response = $this->post('/config/saveLocale', $this->baseLocalePayload([
            'payment_reference_code_min' => '-1',
            'payment_reference_code_max' => '20',
        ]));

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
    }

    public function testSaveLocale_RejectsMaxLessThanMin(): void
    {
        $this->resetSession();

        $response = $this->post('/config/saveLocale', $this->baseLocalePayload([
            'payment_reference_code_min' => '10',
            'payment_reference_code_max' => '5',
        ]));

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
    }

    // ========== postSaveLocale: language_code allow-list (GHSA) ==========

    public function testSaveLocale_RejectsPathTraversalLanguageCode(): void
    {
        $this->resetSession();

        $response = $this->post('/config/saveLocale', $this->baseLocalePayload([
            'language' => '../../public/uploads:evil',
        ]));

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('language', strtolower($result['message']));
    }

    public function testSaveLocale_RejectsLanguageCodeWithBackslash(): void
    {
        $this->resetSession();

        $response = $this->post('/config/saveLocale', $this->baseLocalePayload([
            'language' => '..\\..\\public\\uploads:evil',
        ]));

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
    }

    public function testSaveLocale_RejectsUnknownLanguage(): void
    {
        $this->resetSession();

        $response = $this->post('/config/saveLocale', $this->baseLocalePayload([
            'language' => 'xx:nonexistent',
        ]));

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
    }

    public function testSaveLocale_RejectsCaseMismatchedLanguage(): void
    {
        $this->resetSession();

        $response = $this->post('/config/saveLocale', $this->baseLocalePayload([
            'language' => 'en:English',
        ]));

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
    }

    // ========== postSaveTax: negative default tax rate ==========

    public function testSaveTax_RejectsNegativeDefaultTaxRate(): void
    {
        $this->resetSession();

        $response = $this->post('/config/saveTax', $this->baseTaxPayload([
            'default_tax_1_rate' => '-200.00',
        ]));

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
    }

    public function testSaveTax_RejectsNegativeDefaultTaxRate2(): void
    {
        $this->resetSession();

        $response = $this->post('/config/saveTax', $this->baseTaxPayload([
            'default_tax_2_rate' => '-50.00',
        ]));

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
    }

    public function testSaveTax_AcceptsZeroDefaultTaxRate(): void
    {
        $this->resetSession();

        $response = $this->post('/config/saveTax', $this->baseTaxPayload([
            'default_tax_1_rate' => '0.00',
            'default_tax_2_rate' => '0.00',
        ]));

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertTrue($result['success']);
    }

    public function testSaveTax_SavesTheDefaultInvoiceTaxModeAndTheRequirementOfATaxAndAuditsTheChange(): void
    {
        $this->resetSession();

        $result = json_decode($this->post('/config/saveTax', $this->baseTaxPayload(['tax_included' => 'tax_included', 'require_tax_1' => 'require_tax_1']))->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertTrue((new SalesTaxSettings())->defaultIncluded());
        $this->assertTrue((new SalesTaxSettings())->requireTaxOne());

        $entry   = Database::connect()->table('accounting_audit_log')->where('action', 'sales_tax_settings_updated')->get()->getRow();
        $details = json_decode((string) $entry->details, true);

        $this->assertSame(1, (int) $entry->employee_id);
        $this->assertSame([SalesTaxSettings::INCLUDED_KEY => false, SalesTaxSettings::TAX_ONE_KEY => false], $details['before']);
        $this->assertSame([SalesTaxSettings::INCLUDED_KEY => true, SalesTaxSettings::TAX_ONE_KEY => true], $details['after']);
    }

    public function testSaveTax_TurnsTheTwoSettingsOffWhenTheirBoxesAreLeftOutAndAuditsThat(): void
    {
        $this->resetSession();

        $this->post('/config/saveTax', $this->baseTaxPayload(['tax_included' => 'tax_included', 'require_tax_1' => 'require_tax_1']));
        $this->post('/config/saveTax', $this->baseTaxPayload());

        $this->assertFalse((new SalesTaxSettings())->defaultIncluded());
        $this->assertFalse((new SalesTaxSettings())->requireTaxOne());
        $this->assertSame(2, Database::connect()->table('accounting_audit_log')->where('action', 'sales_tax_settings_updated')->countAllResults());
    }

    public function testSaveTax_AuditsNothingWhenTheTwoSettingsStayAsTheyWere(): void
    {
        $this->resetSession();

        $this->post('/config/saveTax', $this->baseTaxPayload());
        $this->post('/config/saveTax', $this->baseTaxPayload(['default_tax_1_rate' => '7.00']));

        $this->assertSame(0, Database::connect()->table('accounting_audit_log')->where('action', 'sales_tax_settings_updated')->countAllResults());
    }

    public function testSaveTax_ChangesNeitherSettingWhenTheRateIsRefused(): void
    {
        $this->resetSession();

        $this->post('/config/saveTax', $this->baseTaxPayload(['default_tax_1_rate' => '-1.00', 'tax_included' => 'tax_included', 'require_tax_1' => 'require_tax_1']));

        $this->assertFalse((new SalesTaxSettings())->defaultIncluded());
        $this->assertFalse((new SalesTaxSettings())->requireTaxOne());
    }

    public function testTaxConfigView_OffersTheTwoTaxSettingsAndALinkToTheTaxListAndShowsWhatIsSaved(): void
    {
        $data = ['tax_code_options' => [], 'tax_category_options' => [], 'tax_jurisdiction_options' => [], 'controller_name' => 'config'];

        $off = view('configs/tax_config', $data + ['config' => [...config(OSPOS::class)->settings, 'tax_included' => '0', 'require_tax_1' => '0']]);

        $this->assertStringContainsString('name="require_tax_1"', $off);
        $this->assertStringContainsString(lang('Config.tax_included_desc'), $off);
        $this->assertStringContainsString(lang('Config.require_tax_1_desc'), $off);
        $this->assertMatchesRegularExpression('/id="tax_list"[^>]*href="[^"]*tax_definitions"/', $off);
        $this->assertStringNotContainsString('for="tax_list"', $off, 'a label points at a field and not at a link');
        $this->assertDoesNotMatchRegularExpression('/name="require_tax_1"[^>]*checked/', $off);

        $on = view('configs/tax_config', $data + ['config' => [...config(OSPOS::class)->settings, 'tax_included' => '1', 'require_tax_1' => '1']]);

        $this->assertMatchesRegularExpression('/name="require_tax_1"[^>]*checked/', $on);
        $this->assertMatchesRegularExpression('/name="tax_included"[^>]*checked/', $on);
    }

    /**
     * @param array<string, string> $fields
     *
     * @return array<string, mixed>
     */
    private function saveNumbering(string $type, array $fields): array
    {
        return json_decode($this->post('/config/saveNumbering/' . $type, $fields)->getJSON(), true);
    }

    public function testSaveNumbering_SavesTheSettingsOfAKindOfDocument(): void
    {
        $this->resetSession();

        $result = $this->saveNumbering('credit_note', ['enabled' => '1', 'prefix' => 'CN-', 'padding' => '5', 'next_number' => '']);

        $sequence = (new DocumentNumbering())->sequence(DocumentType::CreditNote);

        $this->assertTrue($result['success']);
        $this->assertSame(lang('Numbering.saved'), $result['message']);
        $this->assertSame(1, $result['next']);
        $this->assertSame('CN-00001', $result['preview']);
        $this->assertTrue($sequence->enabled);
        $this->assertSame('CN-', $sequence->prefix);
        $this->assertSame(5, $sequence->padding);
    }

    public function testSaveNumbering_NamesTheEmployeeWhoSavedItInTheAuditLog(): void
    {
        $this->resetSession();

        $this->saveNumbering('credit_note', ['enabled' => '1', 'prefix' => 'CN-', 'padding' => '5']);

        $entry = Database::connect()->table('accounting_audit_log')->where('entity_type', 'document_numbering')->get()->getRow();

        $this->assertNotNull($entry);
        $this->assertSame(1, (int) $entry->employee_id);
        $this->assertSame('user', $entry->origin);
        $this->assertSame('credit_note', json_decode($entry->details, true)['doc_type']);
    }

    public function testSaveNumbering_SwitchesTheSequenceOffWhenTheBoxIsLeftOut(): void
    {
        $this->resetSession();
        $this->saveNumbering('credit_note', ['enabled' => '1', 'prefix' => 'CN-', 'padding' => '5']);

        $result = $this->saveNumbering('credit_note', ['prefix' => 'CN-', 'padding' => '5']);

        $this->assertTrue($result['success']);
        $this->assertFalse((new DocumentNumbering())->sequence(DocumentType::CreditNote)->enabled);
    }

    public function testSaveNumbering_ReadsEmptyDigitsAsNone(): void
    {
        $this->resetSession();

        $result = $this->saveNumbering('credit_note', ['enabled' => '1', 'prefix' => 'CN-', 'padding' => '']);

        $this->assertTrue($result['success']);
        $this->assertSame('CN-1', $result['preview']);
    }

    public function testSaveNumbering_RejectsAKindOfDocumentThatDoesNotExist(): void
    {
        $this->resetSession();

        $result = $this->saveNumbering('invoice_of_the_moon', ['enabled' => '1']);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_numbering_type_unknown'), $result['message']);
        $this->assertSame(0, Database::connect()->table('document_sequences')->countAllResults());
    }

    public function testSaveNumbering_RejectsALowerNextNumberAndKeepsTheSequence(): void
    {
        $this->resetSession();
        $this->saveNumbering('credit_note', ['enabled' => '1', 'prefix' => 'CN-', 'padding' => '5', 'next_number' => '40']);

        $result = $this->saveNumbering('credit_note', ['enabled' => '1', 'prefix' => 'CN-', 'padding' => '5', 'next_number' => '39']);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_numbering_next_too_low', [40]), $result['message']);
        $this->assertSame(40, (new DocumentNumbering())->sequence(DocumentType::CreditNote)->nextNumber);
    }

    public function testSaveNumbering_RejectsAnInvalidPrefixOrDigitsOrNextNumber(): void
    {
        $this->resetSession();

        $prefix  = $this->saveNumbering('credit_note', ['enabled' => '1', 'prefix' => 'CN %', 'padding' => '5']);
        $padding = $this->saveNumbering('credit_note', ['enabled' => '1', 'prefix' => 'CN-', 'padding' => '11']);
        $next    = $this->saveNumbering('credit_note', ['enabled' => '1', 'prefix' => 'CN-', 'padding' => '5', 'next_number' => '0']);

        $this->assertSame(lang('Accounting.error_numbering_prefix_invalid', [DocumentNumbering::MAX_PREFIX_LENGTH]), $prefix['message']);
        $this->assertSame(lang('Accounting.error_numbering_padding_invalid', [DocumentNumbering::MAX_PADDING]), $padding['message']);
        $this->assertSame(lang('Accounting.error_numbering_next_invalid', [DocumentNumbering::MAX_NEXT]), $next['message']);
        $this->assertSame(0, Database::connect()->table('document_sequences')->countAllResults());
    }

    public function testSaveNumbering_KeepsTheDuplicateOptionOnlyForTheCustomerSequence(): void
    {
        $this->resetSession();

        $this->saveNumbering('credit_note', ['enabled' => '1', 'prefix' => 'CN-', 'padding' => '5']);
        $this->saveNumbering('customer', ['enabled' => '1', 'prefix' => 'C', 'padding' => '5']);

        $numbering = new DocumentNumbering();

        $this->assertTrue($numbering->sequence(DocumentType::CreditNote)->preventDuplicates);
        $this->assertFalse($numbering->sequence(DocumentType::Customer)->preventDuplicates);

        $this->saveNumbering('customer', ['enabled' => '1', 'prefix' => 'C', 'padding' => '5', 'prevent_duplicates' => '1']);

        $this->assertTrue($numbering->sequence(DocumentType::Customer)->preventDuplicates);
    }

    public function testSaveNumbering_RefusesAnEmployeeWithoutTheSettingsPermission(): void
    {
        $employee = $this->createEmployee(grants: [['permission_id' => 'sales', 'menu_group' => 'home']]);
        $this->withSession(['person_id' => $employee, 'menu_group' => 'home']);

        $response = $this->post('/config/saveNumbering/credit_note', ['enabled' => '1', 'prefix' => 'CN-', 'padding' => '5']);

        $response->assertRedirect();
        $this->assertStringContainsString('no_access', $response->getRedirectUrl());
        $this->assertSame(0, Database::connect()->table('document_sequences')->countAllResults());
    }

    public function testSaveNumbering_RejectsFieldsThatComeAsListsInsteadOfThrowing(): void
    {
        $this->resetSession();

        $prefix  = $this->saveNumbering('credit_note', ['enabled' => '1', 'prefix' => ['a', 'b'], 'padding' => '5']);
        $padding = $this->saveNumbering('credit_note', ['enabled' => '1', 'prefix' => 'CN-', 'padding' => ['5']]);
        $next    = $this->saveNumbering('credit_note', ['enabled' => '1', 'prefix' => 'CN-', 'padding' => '5', 'next_number' => ['7']]);

        $this->assertFalse($prefix['success']);
        $this->assertSame(lang('Accounting.error_numbering_prefix_invalid', [DocumentNumbering::MAX_PREFIX_LENGTH]), $prefix['message']);
        $this->assertSame(lang('Accounting.error_numbering_padding_invalid', [DocumentNumbering::MAX_PADDING]), $padding['message']);
        $this->assertSame(lang('Accounting.error_numbering_next_invalid', [DocumentNumbering::MAX_NEXT]), $next['message']);
        $this->assertSame(0, Database::connect()->table('document_sequences')->countAllResults());
    }

    public function testSaveNumbering_ReadsMissingFieldsAsAnEmptyPrefixAndNoDigits(): void
    {
        $this->resetSession();

        $result = $this->saveNumbering('credit_note', ['enabled' => '1']);

        $this->assertTrue($result['success']);
        $this->assertSame('1', $result['preview']);
    }

    private function settingsPage(): TestResponse
    {
        $directory = getcwd();
        $server    = $_SERVER;
        chdir(FCPATH);
        $_SERVER += ['HTTP_USER_AGENT' => 'PHPUnit', 'SERVER_SOFTWARE' => 'PHPUnit', 'SERVER_PORT' => '80'];

        try {
            return $this->get('/config');
        } finally {
            $_SERVER = $server;
            chdir($directory);
        }
    }

    private function nextNumberInput(string $page, DocumentType $type): string
    {
        preg_match('/<input[^>]*id="numbering_next_' . $type->value . '"[^>]*>/', $page, $matches);

        return $matches[0] ?? '';
    }

    public function testGetIndex_ShowsTheNumberingTabWithAFormForEveryKindOfDocument(): void
    {
        $this->resetSession();
        (new DocumentNumbering())->configure(DocumentType::CreditNote, ['enabled' => true, 'prefix' => 'CN-', 'padding' => 5]);

        $response = $this->settingsPage();
        $page     = $response->getBody();

        $response->assertStatus(200);
        $this->assertStringContainsString('id="settings_tab_numbering"', $page);
        $this->assertStringContainsString('id="numbering_tab"', $page);

        foreach (DocumentType::cases() as $type) {
            $this->assertStringContainsString('id="numbering_form_' . $type->value . '"', $page);
            $this->assertStringContainsString(lang($type->labelKey()), $page);
        }

        $this->assertStringContainsString('CN-00001', $page);
        $this->assertSame(1, substr_count($page, 'name="prevent_duplicates"'));
        $this->assertSame(2, substr_count($page, lang('Numbering.invoice_note')));
    }

    public function testGetIndex_OffersTheTaxListOnlyToWhoHoldsTheTaxesModule(): void
    {
        $withTaxes    = $this->createEmployee(grants: [['permission_id' => 'config', 'menu_group' => 'office'], ['permission_id' => 'taxes', 'menu_group' => 'office']]);
        $withoutTaxes = $this->createEmployee(grants: [['permission_id' => 'config', 'menu_group' => 'office']]);

        $this->withSession(['person_id' => $withTaxes, 'menu_group' => 'office']);

        $this->assertStringContainsString('id="tax_list"', $this->settingsPage()->getBody());

        $this->withSession(['person_id' => $withoutTaxes, 'menu_group' => 'office']);

        $response = $this->settingsPage();

        $response->assertStatus(200);
        $this->assertStringNotContainsString('id="tax_list"', $response->getBody());
        $this->assertStringContainsString('name="require_tax_1"', $response->getBody());
    }

    public function testGetIndex_KeepsTheNextNumberOfARunningSequenceNoLowerThanItsCurrentOne(): void
    {
        $this->resetSession();
        (new DocumentNumbering())->configure(DocumentType::CreditNote, ['enabled' => true, 'next_number' => 40]);

        $input = $this->nextNumberInput($this->settingsPage()->getBody(), DocumentType::CreditNote);

        $this->assertStringContainsString('value="40"', $input);
        $this->assertStringContainsString('min="40"', $input);
    }

    public function testGetIndex_LetsASequenceThatIsOffStartLowerWhenItsPrefixChanges(): void
    {
        $this->resetSession();
        $numbering = new DocumentNumbering();
        $numbering->configure(DocumentType::CreditNote, ['enabled' => true, 'next_number' => 40]);
        $numbering->configure(DocumentType::CreditNote, ['enabled' => false]);

        $input = $this->nextNumberInput($this->settingsPage()->getBody(), DocumentType::CreditNote);

        $this->assertStringContainsString('value="40"', $input);
        $this->assertStringContainsString('min="1"', $input);
    }

    // ========== postSaveLocations: status, primary flag and removal ==========

    /** @var list<int> */
    private array $spareLocations = [];

    /** @var list<int> */
    private array $spareItems = [];

    private function spareItem(): int
    {
        return $this->spareItems[] = $this->createTestItem();
    }

    private function spareLocation(string $name): int
    {
        $db = Database::connect();
        $db->table('stock_locations')->insert(['location_name' => $name . ' ' . uniqid(), 'deleted' => 0, 'branch_id' => 1]);

        return $this->spareLocations[] = (int) $db->insertID();
    }

    private function restoreLocations(): void
    {
        $db = Database::connect();

        foreach ($this->spareLocations as $locationId) {
            $db->table('item_quantities')->where('location_id', $locationId)->delete();
            $db->table('stock_locations')->where('location_id', $locationId)->delete();
        }

        $this->spareLocations = [];

        foreach ($this->spareItems as $itemId) {
            $db->table('item_quantities')->where('item_id', $itemId)->delete();
            $db->table('items')->where('item_id', $itemId)->delete();
        }

        $this->spareItems = [];

        $db->query('UPDATE ' . $db->prefixTable('stock_locations') . ' SET status = 1, is_primary = 0');
        $db->query('UPDATE ' . $db->prefixTable('stock_locations') . ' SET is_primary = 1 WHERE location_id = 1');
        $db->table('accounting_audit_log')->where('entity_type', 'stock_location')->delete();
    }

    /**
     * What the stock locations form posts: every live location by its name, so that none is removed.
     *
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function locationsForm(array $extra = []): array
    {
        $names = [];

        foreach (Database::connect()->table('stock_locations')->where('deleted', 0)->get()->getResultArray() as $row) {
            $names[(int) $row['location_id']] = $row['location_name'];
        }

        return ['stock_location' => $names] + $extra;
    }

    private function locationRow(int $locationId): array
    {
        return Database::connect()->table('stock_locations')->where('location_id', $locationId)->get()->getRowArray();
    }

    public function testSaveLocations_MakesALocationInactiveAndMovesThePrimaryFlagAndAuditsBoth(): void
    {
        $this->resetSession();
        $quiet = $this->spareLocation('Quiet');
        $new   = $this->spareLocation('New primary');

        $result = json_decode($this->post('/config/saveLocations', $this->locationsForm(['location_status' => [$quiet => '0'], 'location_primary' => (string) $new]))->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertSame('0', (string) $this->locationRow($quiet)['status']);
        $this->assertSame('1', (string) $this->locationRow($new)['is_primary']);
        $this->assertSame('0', (string) $this->locationRow(1)['is_primary']);
        $this->assertSame(1, Database::connect()->table('accounting_audit_log')->where(['entity_type' => 'stock_location', 'entity_id' => $quiet, 'action' => 'stock_location_deactivated'])->countAllResults());
        $this->assertSame(1, Database::connect()->table('accounting_audit_log')->where(['entity_type' => 'stock_location', 'entity_id' => $new, 'action' => 'stock_location_primary_changed'])->countAllResults());

        $this->restoreLocations();
    }

    public function testSaveLocations_LetsTheFormerPrimaryLocationBeMadeInactiveInTheSameSave(): void
    {
        $this->resetSession();
        $new = $this->spareLocation('New primary');

        $result = json_decode($this->post('/config/saveLocations', $this->locationsForm(['location_status' => [1 => '0'], 'location_primary' => (string) $new]))->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertSame('0', (string) $this->locationRow(1)['status']);
        $this->assertSame('1', (string) $this->locationRow($new)['is_primary']);

        $this->restoreLocations();
    }

    public function testSaveLocations_RejectsMakingThePrimaryLocationInactive(): void
    {
        $this->resetSession();
        $this->spareLocation('Spare');

        $result = json_decode($this->post('/config/saveLocations', $this->locationsForm(['location_status' => [1 => '0']]))->getJSON(), true);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_stock_location_is_primary', [$this->locationRow(1)['location_name']]), $result['message']);
        $this->assertSame('1', (string) $this->locationRow(1)['status']);

        $this->restoreLocations();
    }

    public function testSaveLocations_RejectsMakingALocationThatHoldsStockInactiveAndKeepsEveryOtherChangeOfTheSave(): void
    {
        $this->resetSession();
        $loaded = $this->spareLocation('Loaded');
        $other  = $this->spareLocation('Other');
        $db     = Database::connect();
        $itemId = $this->spareItem();

        $db->table('item_quantities')->replace(['item_id' => $itemId, 'location_id' => $loaded, 'quantity' => 5]);
        $form = $this->locationsForm(['location_status' => [$loaded => '0'], 'location_primary' => (string) $other]);
        $form['stock_location'][$other] = 'Renamed ' . uniqid();

        $result = json_decode($this->post('/config/saveLocations', $form)->getJSON(), true);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_stock_location_holds_stock', [$this->locationRow($loaded)['location_name'], 1]), $result['message']);
        $this->assertSame('1', (string) $this->locationRow($loaded)['status']);
        $this->assertNotSame($form['stock_location'][$other], $this->locationRow($other)['location_name'], 'The refusal undoes the rename of the same save');
        $this->assertSame('1', (string) $this->locationRow(1)['is_primary']);

        $this->restoreLocations();
    }

    public function testSaveLocations_RejectsMakingAnInactiveLocationPrimary(): void
    {
        $this->resetSession();
        $quiet = $this->spareLocation('Quiet');
        Database::connect()->table('stock_locations')->where('location_id', $quiet)->update(['status' => 0]);

        $result = json_decode($this->post('/config/saveLocations', $this->locationsForm(['location_primary' => (string) $quiet]))->getJSON(), true);

        $this->assertFalse($result['success']);
        $this->assertSame('1', (string) $this->locationRow(1)['is_primary']);

        $this->restoreLocations();
    }

    public function testSaveLocations_RefusesToRemoveThePrimaryLocation(): void
    {
        $this->resetSession();
        $spare = $this->spareLocation('Spare');
        $form  = $this->locationsForm();
        unset($form['stock_location'][1]);

        $result = json_decode($this->post('/config/saveLocations', $form)->getJSON(), true);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_stock_location_is_primary', [$this->locationRow(1)['location_name']]), $result['message']);
        $this->assertSame('0', (string) $this->locationRow(1)['deleted']);
        $this->assertSame('0', (string) $this->locationRow($spare)['deleted']);

        $this->restoreLocations();
    }

    public function testSaveLocations_RemovesALocationThatHoldsNothingAndIsNotPrimary(): void
    {
        $this->resetSession();
        $spare = $this->spareLocation('Spare');
        $form  = $this->locationsForm();
        unset($form['stock_location'][$spare]);

        $result = json_decode($this->post('/config/saveLocations', $form)->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertSame('1', (string) $this->locationRow($spare)['deleted']);

        $this->restoreLocations();
    }

    public function testSaveLocations_RefusesToRemoveALocationThatHoldsStock(): void
    {
        $this->resetSession();
        $loaded = $this->spareLocation('Loaded');
        $db     = Database::connect();
        $itemId = $this->spareItem();

        $db->table('item_quantities')->replace(['item_id' => $itemId, 'location_id' => $loaded, 'quantity' => 2]);
        $form = $this->locationsForm();
        unset($form['stock_location'][$loaded]);

        $result = json_decode($this->post('/config/saveLocations', $form)->getJSON(), true);

        $this->assertFalse($result['success']);
        $this->assertSame('0', (string) $this->locationRow($loaded)['deleted']);

        $this->restoreLocations();
    }

    public function testSaveLocations_KeepsALocationActiveWhenItsStatusIsPostedAsActive(): void
    {
        $this->resetSession();
        $spare = $this->spareLocation('Spare');
        $lifecycle = new StockLocationLifecycle();
        $lifecycle->deactivate($spare, null);

        $result = json_decode($this->post('/config/saveLocations', $this->locationsForm(['location_status' => [$spare => '1']]))->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertSame('1', (string) $this->locationRow($spare)['status']);

        $this->restoreLocations();
    }

    public function testSaveLocations_RedirectsAnEmployeeWithoutTheSettingsPermissionAndChangesNothing(): void
    {
        $stranger = $this->createEmployee(grants: [['permission_id' => 'items', 'menu_group' => 'home']]);
        $spare    = $this->spareLocation('Spare');
        $this->withSession(['person_id' => $stranger, 'menu_group' => 'home']);

        $response = $this->post('/config/saveLocations', $this->locationsForm(['location_status' => [$spare => '0']]));

        $this->assertTrue($response->isRedirect());
        $this->assertSame('1', (string) $this->locationRow($spare)['status']);

        $this->restoreLocations();
    }
}
