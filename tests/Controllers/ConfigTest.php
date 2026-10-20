<?php

namespace Tests\Controllers;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\TaxFixtureTrait;

class ConfigTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use TaxFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    protected function setUp(): void
    {
        parent::setUp();
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
}
