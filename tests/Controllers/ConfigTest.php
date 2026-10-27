<?php

namespace Tests\Controllers;

use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\TaxFixtureTrait;

class ConfigTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use TaxFixtureTrait;
    use EmployeeFixtureTrait;

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

    public function testNumberingConfigView_ListsEveryKindOfDocumentWithItsSettings(): void
    {
        helper('form');
        (new DocumentNumbering())->configure(DocumentType::CreditNote, ['enabled' => true, 'prefix' => 'CN-', 'padding' => 5]);

        $html = view('configs/numbering_config', ['numbering' => (new DocumentNumbering())->overview()]);

        foreach (DocumentType::cases() as $type) {
            $this->assertStringContainsString('id="numbering_form_' . $type->value . '"', $html);
            $this->assertStringContainsString(lang($type->labelKey()), $html);
        }

        $this->assertStringContainsString('CN-00001', $html);
        $this->assertSame(1, substr_count($html, 'name="prevent_duplicates"'));
        $this->assertSame(2, substr_count($html, lang('Numbering.invoice_note')));
    }

    public function testNumberingConfigView_KeepsTheNextNumberNoLowerThanTheOneThatMayBeGiven(): void
    {
        helper('form');
        (new DocumentNumbering())->configure(DocumentType::CreditNote, ['enabled' => true, 'next_number' => 40]);

        $html = view('configs/numbering_config', ['numbering' => (new DocumentNumbering())->overview()]);

        $this->assertMatchesRegularExpression('/name="next_number"[^>]*value="40"[^>]*min="40"/', preg_replace('/\s+/', ' ', $html) ?? '');
    }
}
