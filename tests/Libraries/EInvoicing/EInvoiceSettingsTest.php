<?php

namespace Tests\Libraries\EInvoicing;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\EInvoicing\EInvoiceError;
use App\Libraries\EInvoicing\EInvoiceException;
use App\Libraries\EInvoicing\EInvoiceSettings;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use App\Libraries\Tax\CompanyTaxProfile;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EInvoiceFixtureTrait;
use Tests\Support\EInvoiceSaleFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;

class EInvoiceSettingsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use EInvoiceFixtureTrait;
    use EInvoiceSaleFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private EInvoiceSettings $settings;
    private int $manager;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');
        $this->setConfigValue('accounting_enabled', '1');
        $this->configureEInvoicing([EInvoiceSettings::ENABLED => '0']);

        $this->settings = new EInvoiceSettings();
        $this->manager  = $this->employeeWithGrants([AccountingGuard::EINVOICING_MANAGE]);
    }

    protected function tearDown(): void
    {
        $this->clearEInvoices();
        $this->restoreEInvoicingConfig();
        $this->setConfigValue('accounting_enabled', '0');
        $this->removeTemporaryDirectories();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function refusal(callable $action): EInvoiceException
    {
        try {
            $action();
        } catch (EInvoiceException $failure) {
            return $failure;
        }

        $this->fail('The setting was not refused.');
    }

    public function testIsEnabled_IsOffUntilSomeoneTurnsItOnAndOnlyWhileAccountingIsOn(): void
    {
        $this->assertFalse($this->settings->isEnabled());

        $this->settings->update(['enabled' => true], $this->manager);

        $this->assertTrue($this->settings->isEnabled());
        $this->assertTrue($this->settings->stored());

        $this->setConfigValue('accounting_enabled', '0');

        $this->assertFalse($this->settings->isEnabled());
        $this->assertTrue($this->settings->stored());
    }

    public function testIsEnabled_IsOffWhereNobodyHasEverSetIt(): void
    {
        $this->accountingDb()->table('app_config')->where('key', EInvoiceSettings::ENABLED)->delete();

        $this->assertFalse($this->settings->isEnabled());
    }

    public function testUpdate_RequiresThePermissionToManageElectronicInvoicing(): void
    {
        $other = $this->employeeWithGrants([AccountingGuard::SETTINGS_MANAGE]);

        try {
            $this->settings->update(['enabled' => true], $other);
            $this->fail('The settings were changed without the permission.');
        } catch (AccountingException $failure) {
            $this->assertSame(AccountingError::PermissionDenied, $failure->error);
        }

        $this->assertFalse($this->settings->stored());
        $this->assertSame(['einvoice_enabled' => '1'], $this->settings->update(['enabled' => true], null));
    }

    public function testUpdate_RequiresEveryBranchBecauseTheSettingsAreTheCompanys(): void
    {
        $branch  = $this->createBranch('B2');
        $limited = $this->employeeWithGrants([AccountingGuard::EINVOICING_MANAGE], [$branch]);

        try {
            $this->settings->update(['enabled' => true], $limited);
            $this->fail('A restricted employee changed the settings of the company.');
        } catch (BranchException $failure) {
            $this->assertSame(BranchError::CompanyScopeNeeded, $failure->error);
        }

        $this->assertFalse($this->settings->stored());
    }

    public function testUpdate_RefusesToTurnTheLayerOnWhileAccountingIsOff(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        $this->assertSame(EInvoiceError::AccountingDisabled, $this->refusal(fn () => $this->settings->update(['enabled' => true], $this->manager))->error);
        $this->assertFalse($this->settings->stored());
    }

    public function testUpdate_AllowsTurningTheLayerOffWhileAccountingIsOff(): void
    {
        $this->settings->update(['enabled' => true], $this->manager);
        $this->setConfigValue('accounting_enabled', '0');

        $this->assertSame(['einvoice_enabled' => '0'], $this->settings->update(['enabled' => false], $this->manager));
    }

    public function testUpdate_SavesTheFieldsAndAuditsWhatTheyReplacedAndWhatTheyBecame(): void
    {
        $changes = $this->settings->update(['seller_city' => 'Jeddah', 'seller_postal_code' => '21589'], $this->manager);

        $this->assertSame(['einvoice_seller_city' => 'Jeddah', 'einvoice_seller_postal_code' => '21589'], $changes);
        $this->assertSame('Jeddah', $this->settings->all()['seller_city']);

        $audit = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'einvoice_settings', 'action' => 'settings_updated'])->get()->getRow();
        $details = json_decode((string) $audit->details, true);

        $this->assertSame((int) $this->manager, (int) $audit->employee_id);
        $this->assertSame(['einvoice_seller_city' => 'Riyadh', 'einvoice_seller_postal_code' => '12345'], $details['before']);
        $this->assertSame(['einvoice_seller_city' => 'Jeddah', 'einvoice_seller_postal_code' => '21589'], $details['after']);
    }

    public function testUpdate_WritesNothingWhenNothingChanged(): void
    {
        $this->assertSame([], $this->settings->update(['seller_city' => 'Riyadh', 'enabled' => false], $this->manager));
        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'einvoice_settings')->countAllResults());
    }

    public function testUpdate_LeavesAFieldThatIsNotInTheInputAsItWas(): void
    {
        $this->settings->update(['seller_district' => 'Olaya'], $this->manager);

        $this->assertSame('Riyadh', $this->settings->all()['seller_city']);
        $this->assertSame('Olaya', $this->settings->all()['seller_district']);
        $this->assertFalse($this->settings->stored());
    }

    public function testUpdate_RefusesAKeyOrACertificateInsideTheApplication(): void
    {
        $this->assertSame(EInvoiceError::KeyPathUnsafe, $this->refusal(fn () => $this->settings->update(['key_path' => WRITEPATH . 'key.pem'], $this->manager))->error);
        $this->assertSame(EInvoiceError::KeyPathUnsafe, $this->refusal(fn () => $this->settings->update(['certificate_path' => ROOTPATH . 'cert.pem'], $this->manager))->error);
        $this->assertSame(EInvoiceError::KeyPathUnsafe, $this->refusal(fn () => $this->settings->update(['key_path' => 'relative/key.pem'], $this->manager))->error);
        $this->assertSame('', $this->settings->all()['key_path']);
    }

    public function testUpdate_AcceptsAKeyAndACertificateOutsideTheApplicationWithoutReadingThem(): void
    {
        $directory = $this->temporaryDirectory();

        $changes = $this->settings->update(['key_path' => $directory . '/key.pem', 'certificate_path' => $directory . '/cert.pem'], $this->manager);

        $this->assertSame([$directory . '/key.pem', $directory . '/cert.pem'], array_values($changes));
        $this->assertFileDoesNotExist($directory . '/key.pem');
    }

    public function testUpdate_NeverStoresTheKeyItselfOnlyItsPath(): void
    {
        $pair = $this->skipThenPair();

        $this->settings->update(['key_path' => $pair['key'], 'certificate_path' => $pair['certificate']], $this->manager);

        $values = array_column($this->accountingDb()->table('app_config')->get()->getResultArray(), 'value', 'key');

        $this->assertSame($pair['key'], $values['einvoice_key_path']);

        foreach ($values as $key => $value) {
            $this->assertStringNotContainsString('PRIVATE KEY', (string) $value, $key);
        }
    }

    /**
     * @return array{key: string, certificate: string, directory: string}
     */
    private function skipThenPair(): array
    {
        $this->skipWithoutTheCurve();

        return $this->generateKeyPair();
    }

    public function testUpdate_RefusesAnIdentificationSchemeThatIsOnlyForPeople(): void
    {
        foreach (['NAT', 'TIN', 'IQA', 'GCC', 'PAS', 'XXX'] as $scheme) {
            $this->assertSame(EInvoiceError::IdentificationInvalid, $this->refusal(fn () => $this->settings->update(['seller_identification_type' => $scheme], $this->manager))->error, $scheme);
        }

        foreach (['CRN', 'MOM', 'MLS', 'SAG', '700', 'OTH', ''] as $scheme) {
            $this->settings->update(['seller_identification_type' => $scheme], $this->manager);

            $this->assertSame($scheme, $this->settings->all()['seller_identification_type']);
        }
    }

    public function testUpdate_RefusesAnIdentificationNumberThatIsNotLettersAndDigits(): void
    {
        $this->assertSame(EInvoiceError::IdentificationInvalid, $this->refusal(fn () => $this->settings->update(['seller_identification_number' => '1010-0100'], $this->manager))->error);
    }

    public function testUpdate_RefusesABuildingNumberAndAPostalCodeOfTheWrongLength(): void
    {
        $this->assertSame(EInvoiceError::AddressInvalid, $this->refusal(fn () => $this->settings->update(['seller_building_number' => '123'], $this->manager))->error);
        $this->assertSame(EInvoiceError::AddressInvalid, $this->refusal(fn () => $this->settings->update(['seller_building_number' => '12345'], $this->manager))->error);
        $this->assertSame(EInvoiceError::AddressInvalid, $this->refusal(fn () => $this->settings->update(['seller_postal_code' => '1234'], $this->manager))->error);
        $this->assertSame(EInvoiceError::AddressInvalid, $this->refusal(fn () => $this->settings->update(['seller_postal_code' => '123456'], $this->manager))->error);
        $this->assertSame(EInvoiceError::AddressInvalid, $this->refusal(fn () => $this->settings->update(['seller_additional_number' => '12a'], $this->manager))->error);
        $this->assertSame(EInvoiceError::AddressInvalid, $this->refusal(fn () => $this->settings->update(['seller_street' => str_repeat('x', 1001)], $this->manager))->error);
        $this->assertSame(EInvoiceError::AddressInvalid, $this->refusal(fn () => $this->settings->update(['seller_city' => str_repeat('ش', 128)], $this->manager))->error);
    }

    public function testUpdate_AcceptsTheBoundariesOfTheAddress(): void
    {
        $this->settings->update(['seller_street' => str_repeat('x', 1000), 'seller_city' => str_repeat('ش', 127), 'seller_building_number' => '0001', 'seller_postal_code' => '00001'], $this->manager);

        $this->assertSame('0001', $this->settings->all()['seller_building_number']);
        $this->assertSame(127, mb_strlen($this->settings->all()['seller_city']));
    }

    public function testUpdate_ChecksTheReasonCodeAgainstTheCategory(): void
    {
        $this->settings->update(['exempt_category' => 'Z', 'exempt_reason_code' => 'VATEX-SA-32', 'exempt_reason_text' => 'Export of goods'], $this->manager);

        $this->assertSame('Z', $this->settings->all()['exempt_category']);

        $this->assertSame(EInvoiceError::ExemptionInvalid, $this->refusal(fn () => $this->settings->update(['exempt_category' => 'E', 'exempt_reason_code' => 'VATEX-SA-32'], $this->manager))->error);
        $this->assertSame(EInvoiceError::ExemptionInvalid, $this->refusal(fn () => $this->settings->update(['exempt_category' => 'X', 'exempt_reason_code' => 'VATEX-SA-32'], $this->manager))->error);
        $this->assertSame(EInvoiceError::ExemptionInvalid, $this->refusal(fn () => $this->settings->update(['exempt_category' => 'Z', 'exempt_reason_code' => 'VATEX-SA-29'], $this->manager))->error);
        $this->assertSame(EInvoiceError::ExemptionInvalid, $this->refusal(fn () => $this->settings->update(['exempt_category' => '', 'exempt_reason_code' => 'VATEX-SA-32'], $this->manager))->error);
        $this->assertSame(EInvoiceError::ExemptionInvalid, $this->refusal(fn () => $this->settings->update(['exempt_category' => 'O', 'exempt_reason_code' => 'VATEX-SA-OOS', 'exempt_reason_text' => str_repeat('x', 1001)], $this->manager))->error);
    }

    public function testExemptionCodes_ListTheReasonsOfTheStandardForEachCategory(): void
    {
        $this->assertSame(['VATEX-SA-29', 'VATEX-SA-29-7', 'VATEX-SA-30'], EInvoiceSettings::EXEMPTION_CODES['E']);
        $this->assertSame(['VATEX-SA-OOS'], EInvoiceSettings::EXEMPTION_CODES['O']);
        $this->assertContains('VATEX-SA-EDU', EInvoiceSettings::EXEMPTION_CODES['Z']);
        $this->assertContains('VATEX-SA-34-5', EInvoiceSettings::EXEMPTION_CODES['Z']);
        $this->assertCount(12, EInvoiceSettings::EXEMPTION_CODES['Z']);
    }

    public function testSeller_ReadsTheNameAndTheTaxNumberTheShopAlreadyKeeps(): void
    {
        $this->assertSame('Maximum Speed Tech Supply LTD', $this->settings->sellerName());
        $this->assertSame('399999999900003', $this->settings->sellerVatNumber());

        $this->setConfigValue('company', '  Padded  ');
        $this->setConfigValue('tax_id', ' 399999999900003 ');

        $this->assertSame('Padded', $this->settings->sellerName());
        $this->assertSame('399999999900003', $this->settings->sellerVatNumber());
    }

    public function testUpdate_RequiresTheSettingsPermissionToChangeTheSellerInSaudiMode(): void
    {
        Database::connect()->table('company_tax_profile')->where('company_id', 1)->update(['vat_registered' => 1, 'ksa_mode' => 1, 'ksa_mode_effective_at' => '2026-01-01 00:00:00']);

        try {
            $failure = null;

            try {
                $this->settings->update(['seller_city' => 'Jeddah', 'exempt_reason_text' => ''], $this->manager);
            } catch (AccountingException $exception) {
                $failure = $exception;
            }

            $this->assertSame(AccountingError::PermissionDenied, $failure?->error);

            $this->settings->update(['seller_city' => 'Jeddah', 'exempt_reason_text' => ''], $this->employeeWithGrants([AccountingGuard::EINVOICING_MANAGE, CompanyTaxProfile::CONFIG_PERMISSION]));

            $this->assertSame('Jeddah', $this->settings->all()['seller_city']);
        } finally {
            Database::connect()->table('company_tax_profile')->where('company_id', 1)->update(['vat_registered' => null, 'ksa_mode' => 0, 'ksa_mode_effective_at' => null]);
        }
    }

    public function testUpdate_KeepsThePermissionsOfTheSellerWhileTheModeIsOff(): void
    {
        $this->settings->update(['seller_city' => 'Dammam', 'exempt_reason_text' => ''], $this->manager);

        $this->assertSame('Dammam', $this->settings->all()['seller_city']);
    }
}
