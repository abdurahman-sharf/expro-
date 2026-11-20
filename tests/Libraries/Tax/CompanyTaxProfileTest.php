<?php

namespace Tests\Libraries\Tax;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Tax\CompanyTaxProfile;
use App\Libraries\Tax\TaxProfileError;
use App\Libraries\Tax\TaxProfileException;
use App\Libraries\Tax\VatRegistration;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;

class CompanyTaxProfileTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private const VALID   = '399999999900003';
    private const ANOTHER = '311111111111113';

    private string $savedTaxId;
    private int $manager;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $db               = Database::connect();
        $this->savedTaxId = (string) ($db->table('app_config')->where('key', 'tax_id')->get()->getRow()->value ?? '');

        $this->resetProfile('');
        $this->setConfigValue('accounting_enabled', '1');

        $this->manager = $this->employeeWithGrants([CompanyTaxProfile::CONFIG_PERMISSION, AccountingGuard::EINVOICING_MANAGE]);
    }

    protected function tearDown(): void
    {
        $db = Database::connect();

        $db->table('einvoice_documents')->where('document_number', 'TAX1-TEST')->delete();
        $db->table('accounting_audit_log')->where('entity_type', 'tax_profile')->delete();
        $this->resetProfile($this->savedTaxId);
        $this->setConfigValue('accounting_enabled', '0');
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function resetProfile(string $number): void
    {
        $db = Database::connect();

        $db->table('company_tax_profile')->replace([
            'company_id'            => 1,
            'vat_registered'        => null,
            'vat_number'            => $number === '' ? null : $number,
            'ksa_mode'              => 0,
            'ksa_mode_effective_at' => null,
            'ksa_mode_activated_at' => null,
            'ksa_mode_activated_by' => null,
        ]);
        $this->setConfigValue('tax_id', $number);
        $db->table('companies')->where('company_id', 1)->update(['tax_id' => $number === '' ? null : $number]);
    }

    private function profile(?string $now = null): CompanyTaxProfile
    {
        return new CompanyTaxProfile(null, null, null, $now === null ? null : static fn (): string => $now);
    }

    private function inSaudiMode(): void
    {
        $this->profile()->updateVatIdentity(VatRegistration::Registered, self::VALID, '', $this->manager);
        $this->profile()->enableSaudiMode('', $this->manager);
    }

    private function issueDocument(): void
    {
        Database::connect()->table('einvoice_documents')->insert([
            'company_id'      => 1,
            'branch_id'       => 1,
            'source_type'     => 'sale',
            'source_id'       => 999999,
            'doc_kind'        => 'invoice',
            'subtype'         => 'simplified',
            'document_number' => 'TAX1-TEST',
            'uuid'            => '00000000-0000-4000-8000-000000000001',
            'icv'             => 999999,
            'previous_hash'   => 'x',
            'invoice_hash'    => 'y',
            'issue_date'      => '2099-01-01',
            'issue_time'      => '00:00:00',
            'tax_exclusive'   => '1.00',
            'tax_amount'      => '0.15',
            'tax_inclusive'   => '1.15',
            'xml'             => '<Invoice/>',
            'created_at'      => '2099-01-01 00:00:00',
        ]);
    }

    private function refusal(callable $action): TaxProfileException
    {
        try {
            $action();
        } catch (TaxProfileException $failure) {
            return $failure;
        }

        $this->fail('The change was not refused.');
    }

    private function denial(callable $action): AccountingException
    {
        try {
            $action();
        } catch (AccountingException $failure) {
            return $failure;
        }

        $this->fail('The change was not denied.');
    }

    public function testCurrent_StartsUnknownAndOffWithTheNumberOfTheSettings(): void
    {
        $this->resetProfile('12345');

        $profile = $this->profile()->current();

        $this->assertSame(VatRegistration::Unknown, $profile->registration);
        $this->assertSame('12345', $profile->vatNumber);
        $this->assertFalse($profile->saudiMode);
        $this->assertFalse($this->profile()->isSaudiMode());
    }

    public function testCurrent_ReadsTheSettingWhenTheCompanyHasNoProfile(): void
    {
        $this->setConfigValue('tax_id', '777');
        Database::connect()->table('company_tax_profile')->where('company_id', 1)->delete();

        $profile = $this->profile()->current();

        $this->assertSame('777', $profile->vatNumber);
        $this->assertSame(VatRegistration::Unknown, $profile->registration);
    }

    public function testUpdateVatIdentity_AcceptsAnyNumberWhenNotRegisteredAndModeOff(): void
    {
        $plainEmployee = $this->employeeWithGrants([CompanyTaxProfile::CONFIG_PERMISSION]);

        $changes = $this->profile()->updateVatIdentity(VatRegistration::NotRegistered, '200-123-456', '', $plainEmployee);

        $this->assertSame('200-123-456', $changes['vat_number']['after']);
        $this->assertSame('200-123-456', $this->profile()->current()->vatNumber);
    }

    public function testUpdateVatIdentity_RejectsAMalformedNumberOfARegisteredBusiness(): void
    {
        $failure = $this->refusal(fn () => $this->profile()->updateVatIdentity(VatRegistration::Registered, '300000000000001', '', $this->manager));

        $this->assertSame(TaxProfileError::NumberInvalid, $failure->error);
        $this->assertSame('', $this->profile()->current()->vatNumber);
    }

    public function testUpdateVatIdentity_WritesBothMirrorsInOneTransaction(): void
    {
        $this->profile()->updateVatIdentity(VatRegistration::Registered, self::VALID, '', $this->manager);

        $db = Database::connect();

        $this->assertSame(self::VALID, (string) $db->table('app_config')->where('key', 'tax_id')->get()->getRow()->value);
        $this->assertSame(self::VALID, (string) $db->table('companies')->where('company_id', 1)->get()->getRow()->tax_id);
        $this->assertSame(VatRegistration::Registered, $this->profile()->current()->registration);
    }

    public function testUpdateVatIdentity_AuditsTheChangeWithBeforeAndAfter(): void
    {
        $this->profile()->updateVatIdentity(VatRegistration::Registered, self::VALID, '', $this->manager);

        $entry   = Database::connect()->table('accounting_audit_log')->where(['entity_type' => 'tax_profile', 'action' => 'vat_identity_changed'])->get()->getRow();
        $details = json_decode((string) $entry->details, true);

        $this->assertSame($this->manager, (int) $entry->employee_id);
        $this->assertSame(['before' => '', 'after' => self::VALID], $details['changes']['vat_number']);
        $this->assertSame(['before' => '', 'after' => '1'], $details['changes']['vat_registered']);
    }

    public function testUpdateVatIdentity_LeavesAnUnchangedNumberToAnyConfigHolder(): void
    {
        $this->inSaudiMode();

        $plainEmployee = $this->employeeWithGrants([CompanyTaxProfile::CONFIG_PERMISSION]);

        $this->assertSame([], $this->profile()->updateVatIdentity(null, self::VALID, '', $plainEmployee));
        $this->assertSame([], $this->profile()->updateVatIdentity(VatRegistration::Registered, self::VALID, '', $plainEmployee));
    }

    public function testUpdateVatIdentity_RequiresBothPermissionsInSaudiMode(): void
    {
        $this->inSaudiMode();

        $configOnly     = $this->employeeWithGrants([CompanyTaxProfile::CONFIG_PERMISSION]);
        $einvoicingOnly = $this->employeeWithGrants([AccountingGuard::EINVOICING_MANAGE]);

        $this->assertSame(AccountingError::PermissionDenied, $this->denial(fn () => $this->profile()->updateVatIdentity(null, self::ANOTHER, '', $configOnly))->error);
        $this->assertSame(AccountingError::PermissionDenied, $this->denial(fn () => $this->profile()->updateVatIdentity(null, self::ANOTHER, '', $einvoicingOnly))->error);

        $this->profile()->updateVatIdentity(null, self::ANOTHER, '', $this->manager);

        $this->assertSame(self::ANOTHER, $this->profile()->current()->vatNumber);
    }

    public function testUpdateVatIdentity_RejectsAMalformedNumberInSaudiMode(): void
    {
        $this->inSaudiMode();

        $failure = $this->refusal(fn () => $this->profile()->updateVatIdentity(null, '12345', '', $this->manager));

        $this->assertSame(TaxProfileError::NumberInvalid, $failure->error);
        $this->assertSame(self::VALID, $this->profile()->current()->vatNumber);
    }

    public function testUpdateVatIdentity_RefusesToLeaveTheRegistrationWhileTheModeIsOn(): void
    {
        $this->inSaudiMode();

        $failure = $this->refusal(fn () => $this->profile()->updateVatIdentity(VatRegistration::NotRegistered, self::VALID, '', $this->manager));

        $this->assertSame(TaxProfileError::ModeNeedsRegistered, $failure->error);
    }

    public function testUpdateVatIdentity_RequiresAReasonOnceADocumentWasIssued(): void
    {
        $this->profile()->updateVatIdentity(VatRegistration::Registered, self::VALID, '', $this->manager);
        $this->issueDocument();

        $failure = $this->refusal(fn () => $this->profile()->updateVatIdentity(null, self::ANOTHER, '', $this->manager));

        $this->assertSame(TaxProfileError::ReasonRequired, $failure->error);

        $this->profile()->updateVatIdentity(null, self::ANOTHER, 'The registration certificate was corrected', $this->manager);

        $details = json_decode((string) Database::connect()->table('accounting_audit_log')->where(['entity_type' => 'tax_profile', 'action' => 'vat_identity_changed'])->orderBy('audit_id', 'DESC')->get(1)->getRow()->details, true);

        $this->assertSame('The registration certificate was corrected', $details['reason']);
    }

    public function testUpdateVatIdentity_IsTheOnlyWriterOfTheCompanyVatNumber(): void
    {
        $writes  = ["/batch_save\\(\\s*\\[[^\\]]*'tax_id'/s", "/saveSettings\\(\\s*\\[[^\\]]*'tax_id'/s", "/table\\('companies'\\)[^;]*'tax_id'/s", '/\'tax_id\'\s*=>\s*\$this->request->getPost\(\'tax_id\'/'];
        $parties = ['Controllers' . DIRECTORY_SEPARATOR . 'Customers.php', 'Controllers' . DIRECTORY_SEPARATOR . 'Suppliers.php'];
        $writers = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APPPATH)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php' || str_contains($file->getPathname(), DIRECTORY_SEPARATOR . 'Language' . DIRECTORY_SEPARATOR) || in_array(str_replace(APPPATH, '', $file->getPathname()), $parties, true)) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            foreach ($writes as $write) {
                if (preg_match($write, $source) === 1) {
                    $writers[] = str_replace(APPPATH, '', $file->getPathname());
                }
            }
        }

        $this->assertSame(['Libraries/Tax/CompanyTaxProfile.php', 'Libraries/Tax/CompanyTaxProfile.php'], $writers);
    }

    public function testEnableSaudiMode_RequiresAccounting(): void
    {
        $this->profile()->updateVatIdentity(VatRegistration::Registered, self::VALID, '', $this->manager);
        $this->setConfigValue('accounting_enabled', '0');

        $this->assertSame(TaxProfileError::AccountingDisabled, $this->refusal(fn () => $this->profile()->enableSaudiMode('', $this->manager))->error);
        $this->assertFalse($this->profile()->current()->saudiMode);
    }

    public function testEnableSaudiMode_RequiresARegisteredBusinessWithAValidNumber(): void
    {
        $this->assertSame(TaxProfileError::ModeNeedsRegistered, $this->refusal(fn () => $this->profile()->enableSaudiMode('', $this->manager))->error);

        $this->profile()->updateVatIdentity(VatRegistration::NotRegistered, '12345', '', $this->manager);
        $this->assertSame(TaxProfileError::ModeNeedsRegistered, $this->refusal(fn () => $this->profile()->enableSaudiMode('', $this->manager))->error);

        Database::connect()->table('company_tax_profile')->where('company_id', 1)->update(['vat_registered' => 1]);
        $this->assertSame(TaxProfileError::ModeNeedsNumber, $this->refusal(fn () => $this->profile()->enableSaudiMode('', $this->manager))->error);
    }

    public function testEnableSaudiMode_RequiresBothPermissions(): void
    {
        $this->profile()->updateVatIdentity(VatRegistration::Registered, self::VALID, '', $this->manager);

        $configOnly = $this->employeeWithGrants([CompanyTaxProfile::CONFIG_PERMISSION]);

        $this->assertSame(AccountingError::PermissionDenied, $this->denial(fn () => $this->profile()->enableSaudiMode('', $configOnly))->error);
        $this->assertFalse($this->profile()->current()->saudiMode);
    }

    public function testEnableSaudiMode_RejectsAnEffectiveDateInThePast(): void
    {
        $this->profile()->updateVatIdentity(VatRegistration::Registered, self::VALID, '', $this->manager);

        $failure = $this->refusal(fn () => $this->profile('2026-11-20 10:30:00')->enableSaudiMode('2026-11-19', $this->manager));

        $this->assertSame(TaxProfileError::EffectiveDateInPast, $failure->error);
    }

    public function testEnableSaudiMode_TakesTodayAsNowAndAuditsIt(): void
    {
        $this->profile()->updateVatIdentity(VatRegistration::Registered, self::VALID, '', $this->manager);

        $this->profile('2026-11-20 10:30:15')->enableSaudiMode('2026-11-20', $this->manager);

        $profile = $this->profile()->current();

        $this->assertTrue($profile->saudiMode);
        $this->assertSame('2026-11-20 10:30:00', $profile->effectiveAt);
        $this->assertSame($this->manager, $profile->activatedBy);
        $this->assertSame(1, Database::connect()->table('accounting_audit_log')->where(['entity_type' => 'tax_profile', 'action' => 'saudi_mode_enabled'])->countAllResults());
    }

    public function testIsSaudiMode_RequiresTheEffectiveDateToHaveCome(): void
    {
        $this->profile()->updateVatIdentity(VatRegistration::Registered, self::VALID, '', $this->manager);
        $this->profile('2026-11-20 10:30:00')->enableSaudiMode('2026-12-01', $this->manager);

        $this->assertFalse($this->profile('2026-11-30 23:59:59')->isSaudiMode());
        $this->assertTrue($this->profile('2026-12-01 00:00:00')->isSaudiMode());
    }

    public function testDisableSaudiMode_TurnsOffWithBothPermissionsBeforeAnyDocument(): void
    {
        $this->inSaudiMode();

        $this->profile()->disableSaudiMode('', $this->manager);

        $this->assertFalse($this->profile()->current()->saudiMode);
        $this->assertSame(1, Database::connect()->table('accounting_audit_log')->where(['entity_type' => 'tax_profile', 'action' => 'saudi_mode_disabled'])->countAllResults());
    }

    public function testDisableSaudiMode_RequiresTheOnboardPermissionOnceADocumentExists(): void
    {
        $this->inSaudiMode();
        $this->issueDocument();

        $this->assertSame(AccountingError::PermissionDenied, $this->denial(fn () => $this->profile()->disableSaudiMode('Testing', $this->manager))->error);

        $onboarder = $this->employeeWithGrants([AccountingGuard::EINVOICING_ONBOARD]);

        $this->assertSame(TaxProfileError::ReasonRequired, $this->refusal(fn () => $this->profile()->disableSaudiMode('', $onboarder))->error);

        $this->profile()->disableSaudiMode('The business left the integration wave by mistake', $onboarder);

        $this->assertFalse($this->profile()->current()->saudiMode);
    }

    public function testDisableSaudiMode_RefusesWhenTheModeIsOff(): void
    {
        $this->assertSame(TaxProfileError::ModeAlreadyOff, $this->refusal(fn () => $this->profile()->disableSaudiMode('', $this->manager))->error);
    }
}
