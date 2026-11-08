<?php

namespace Tests\Libraries\EInvoicing;

use App\Libraries\EInvoicing\CustomerParties;
use App\Libraries\EInvoicing\EInvoiceError;
use App\Libraries\EInvoicing\EInvoiceException;
use App\Libraries\EInvoicing\PartyIdentificationType;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EInvoiceSaleFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;

class CustomerPartiesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private CustomerParties $parties;
    private int $customerId;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->parties    = new CustomerParties();
        $this->customerId = $this->insertCustomer();
    }

    protected function tearDown(): void
    {
        $this->accountingDb()->table('customer_einvoice_parties')->truncate();
        $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'customer_einvoice_party')->delete();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    /**
     * @param array<string, string> $override
     *
     * @return array<string, string>
     */
    private function input(array $override = []): array
    {
        return [...['identification_type' => 'CRN', 'identification_number' => '1010010000', 'street' => 'King Fahd Rd', 'building_number' => '4321', 'district' => 'Olaya', 'city' => 'Riyadh', 'postal_code' => '11564', 'country_code' => 'SA'], ...$override];
    }

    private function refusal(callable $action): EInvoiceException
    {
        try {
            $action();
        } catch (EInvoiceException $failure) {
            return $failure;
        }

        $this->fail('The data was not refused.');
    }

    public function testFind_GivesEveryFieldEmptyAndSaudiArabiaForACustomerWithNothingSaved(): void
    {
        $found = $this->parties->find($this->customerId);

        $this->assertSame(CustomerParties::FIELDS, array_keys($found));
        $this->assertSame('SA', $found['country_code']);
        $this->assertSame('', $found['identification_type']);
        $this->assertSame('', $found['street']);
    }

    public function testSave_StoresTheIdentificationAndTheNationalAddressOfACustomer(): void
    {
        $this->assertTrue($this->parties->save($this->customerId, $this->input(['additional_street' => 'Side St', 'additional_number' => '8228', 'province' => 'Riyadh Region']), 1));

        $found = $this->parties->find($this->customerId);

        $this->assertSame('CRN', $found['identification_type']);
        $this->assertSame('1010010000', $found['identification_number']);
        $this->assertSame('Side St', $found['additional_street']);
        $this->assertSame('8228', $found['additional_number']);
        $this->assertSame('Riyadh Region', $found['province']);
        $this->assertSame('11564', $found['postal_code']);
    }

    public function testSave_AcceptsEachOfTheElevenCodes(): void
    {
        foreach (PartyIdentificationType::codes() as $code) {
            $this->parties->save($this->customerId, $this->input(['identification_type' => $code]), 1);

            $this->assertSame($code, $this->parties->find($this->customerId)['identification_type'], $code);
        }
    }

    public function testClean_RejectsACodeThatIsNotOneOfTheEleven(): void
    {
        $this->assertSame(EInvoiceError::IdentificationInvalid, $this->refusal(fn () => $this->parties->clean($this->input(['identification_type' => 'ABC'])))->error);
        $this->assertSame(EInvoiceError::IdentificationInvalid, $this->refusal(fn () => $this->parties->clean($this->input(['identification_type' => 'crn'])))->error);
    }

    public function testClean_RequiresASchemeAndItsNumberTogether(): void
    {
        $this->assertSame(EInvoiceError::IdentificationInvalid, $this->refusal(fn () => $this->parties->clean($this->input(['identification_number' => ''])))->error);
        $this->assertSame(EInvoiceError::IdentificationInvalid, $this->refusal(fn () => $this->parties->clean($this->input(['identification_type' => ''])))->error);
        $this->assertSame(EInvoiceError::IdentificationInvalid, $this->refusal(fn () => $this->parties->clean($this->input(['identification_number' => '10-10'])))->error);
    }

    public function testClean_RejectsABuildingNumberAndAPostalCodeOfTheWrongLengthForASaudiAddress(): void
    {
        $this->assertSame(EInvoiceError::AddressInvalid, $this->refusal(fn () => $this->parties->clean($this->input(['building_number' => '432'])))->error);
        $this->assertSame(EInvoiceError::AddressInvalid, $this->refusal(fn () => $this->parties->clean($this->input(['postal_code' => '1156'])))->error);
        $this->assertSame(EInvoiceError::AddressInvalid, $this->refusal(fn () => $this->parties->clean($this->input(['additional_number' => 'x'])))->error);
    }

    public function testClean_LetsAnAddressOutsideSaudiArabiaKeepItsOwnFormats(): void
    {
        $clean = $this->parties->clean($this->input(['country_code' => 'ae', 'building_number' => 'Tower 2', 'postal_code' => 'P.O. Box 55']));

        $this->assertSame('AE', $clean['country_code']);
        $this->assertSame('Tower 2', $clean['building_number']);
    }

    public function testClean_RejectsACountryCodeOfNotTwoLettersAndFieldsThatAreTooLong(): void
    {
        $this->assertSame(EInvoiceError::AddressInvalid, $this->refusal(fn () => $this->parties->clean($this->input(['country_code' => 'SAU'])))->error);
        $this->assertSame(EInvoiceError::AddressInvalid, $this->refusal(fn () => $this->parties->clean($this->input(['street' => str_repeat('x', 1001)])))->error);
        $this->assertSame(EInvoiceError::AddressInvalid, $this->refusal(fn () => $this->parties->clean($this->input(['district' => str_repeat('x', 128)])))->error);
        $this->assertSame(1000, mb_strlen($this->parties->clean($this->input(['street' => str_repeat('x', 1000)]))['street']));
    }

    public function testClean_DefaultsTheCountryToSaudiArabiaAndTrimsWhatItIsGiven(): void
    {
        $clean = $this->parties->clean($this->input(['country_code' => '', 'city' => '  Riyadh  ']));

        $this->assertSame('SA', $clean['country_code']);
        $this->assertSame('Riyadh', $clean['city']);
    }

    public function testSave_WritesNothingWhenTheFieldsDidNotChange(): void
    {
        $this->parties->save($this->customerId, $this->input(), 1);

        $this->assertFalse($this->parties->save($this->customerId, $this->input(), 1));
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'customer_einvoice_party', 'entity_id' => $this->customerId])->countAllResults());
    }

    public function testSave_AuditsWhatWasReplacedAndWhatItBecame(): void
    {
        $this->parties->save($this->customerId, $this->input(), 7);
        $this->parties->save($this->customerId, $this->input(['city' => 'Jeddah']), 7);

        $rows    = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'customer_einvoice_party', 'entity_id' => $this->customerId])->orderBy('audit_id', 'ASC')->get()->getResult();
        $details = json_decode((string) $rows[1]->details, true);

        $this->assertCount(2, $rows);
        $this->assertSame('Riyadh', $details['before']['city']);
        $this->assertSame('Jeddah', $details['after']['city']);
        $this->assertSame(7, (int) $rows[1]->employee_id);
    }

    public function testSave_RemovesTheRowOfACustomerWhoseFieldsAreAllEmptied(): void
    {
        $this->parties->save($this->customerId, $this->input(), 1);

        $this->assertSame(1, $this->accountingDb()->table('customer_einvoice_parties')->where('person_id', $this->customerId)->countAllResults());

        $this->parties->save($this->customerId, [], 1);

        $this->assertSame(0, $this->accountingDb()->table('customer_einvoice_parties')->where('person_id', $this->customerId)->countAllResults());
        $this->assertSame('', $this->parties->find($this->customerId)['street']);
    }

    public function testSave_KeepsTheAddressOfACustomerWhoseIdentificationIsRemoved(): void
    {
        $this->parties->save($this->customerId, $this->input(), 1);
        $this->parties->save($this->customerId, $this->input(['identification_type' => '', 'identification_number' => '']), 1);

        $found = $this->parties->find($this->customerId);

        $this->assertSame('', $found['identification_type']);
        $this->assertSame('Olaya', $found['district']);
    }
}
