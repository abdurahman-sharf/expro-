<?php

namespace Tests\Libraries\EInvoicing;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\EInvoicing\EInvoiceCredentials;
use App\Libraries\EInvoicing\EInvoiceError;
use App\Libraries\EInvoicing\EInvoiceException;
use App\Libraries\EInvoicing\KeyVault;
use App\Libraries\EInvoicing\OnboardingService;
use App\Libraries\EInvoicing\StampKeys;
use App\Libraries\EInvoicing\ZatcaEnvironment;
use App\Libraries\EInvoicing\ZatcaHttpClient;
use App\Libraries\EInvoicing\ZatcaOutcome;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\Zatca;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EInvoiceSaleFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\FakeZatcaAuthority;

/**
 * Onboarding a unit against a ZATCA that lives in memory and issues real certificates: the request made here, the
 * compliance certificate kept sealed, the compliance checks of every kind of document stamped with it, the production
 * certificate given only after they pass, its renewal with a new key, every step audited without a secret, and nobody
 * without the onboarding permission able to take one.
 */
class OnboardingServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EInvoiceSaleFixtureTrait;
    use EmployeeFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private const UNIT = [
        'common_name'       => 'EGS1-886431145',
        'organization'      => 'Maximum Speed Tech Supply LTD',
        'organization_unit' => 'Riyadh Branch',
        'serial'            => '1-Expro|2-4.0|3-ed22f1d8',
        'vat_number'        => '399999999900003',
        'invoice_types'     => '1100',
        'address'           => 'RRRD2929',
        'business'          => 'Supply activities',
    ];

    private string $directory;

    public static function setUpBeforeClass(): void
    {
        Database::seeder('tests')->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! StampKeys::curveAvailable()) {
            $this->markTestSkipped('The OpenSSL of this machine has no secp256k1 curve.');
        }

        $this->directory = sys_get_temp_dir() . '/expro-onboarding-' . bin2hex(random_bytes(4));
        mkdir($this->directory, 0o700);
        file_put_contents($this->directory . '/master.key', KeyVault::newKeyLine('v1') . "\n");
        chmod($this->directory . '/master.key', 0o600);

        $this->configureEInvoicing();
    }

    protected function tearDown(): void
    {
        $db = Database::connect();

        $db->table('einvoice_credentials')->truncate();
        $db->table('einvoice_units')->where('is_default', 1)->update(['status' => 'draft', 'egs_serial' => '']);
        $db->table('accounting_audit_log')->where('entity_type', 'einvoice_unit')->delete();
        array_map('unlink', glob($this->directory . '/*') ?: []);
        rmdir($this->directory);
        $this->restoreEInvoicingConfig();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function credentials(): EInvoiceCredentials
    {
        return new EInvoiceCredentials(KeyVault::fromFile($this->directory . '/master.key'));
    }

    private function service(FakeZatcaAuthority $authority): OnboardingService
    {
        $credentials = $this->credentials();
        $transport   = $authority->transport();

        return new OnboardingService(
            new ZatcaHttpClient($transport, ZatcaEnvironment::Sandbox, null, new Zatca()),
            $credentials,
            null,
            new AccountingGuard(),
            new ZatcaHttpClient($transport, ZatcaEnvironment::Sandbox, $credentials, new Zatca(), static fn (): string => date('Y-m-d H:i:s')),
        );
    }

    private function unitId(): int
    {
        return (int) Database::connect()->table('einvoice_units')->where(['company_id' => 1, 'is_default' => 1])->get()->getRow()->unit_id;
    }

    private function unitStatus(): string
    {
        return (string) Database::connect()->table('einvoice_units')->where('unit_id', $this->unitId())->get()->getRow()->status;
    }

    public function testComplianceChecks_GiveTheProductionCertificateOnlyOnceEveryKindOfDocumentPasses(): void
    {
        $authority = new FakeZatcaAuthority();
        $service   = $this->service($authority);
        $onboarder = $this->employeeWithGrants([AccountingGuard::EINVOICING_ONBOARD]);
        $unitId    = $this->unitId();
        $now       = date('Y-m-d H:i:s');

        $service->complianceStep($unitId, 'sandbox', self::UNIT, FakeZatcaAuthority::OTP, $onboarder);

        $this->assertSame(OnboardingService::COMPLIANCE, $this->unitStatus());
        $this->assertStringContainsString('BEGIN CERTIFICATE REQUEST', (string) base64_decode(json_decode($authority->requests[0]->body, true)['csr'], true));
        $this->assertStringNotContainsString('PRIVATE KEY', implode('|', array_map(static fn ($request): string => $request->body, $authority->requests)), 'the key never leaves the server');

        try {
            $service->productionStep($unitId, 'sandbox', $now, $onboarder);
            $this->fail('A production certificate was asked for before the compliance checks.');
        } catch (EInvoiceException $refusal) {
            $this->assertSame(EInvoiceError::SettingInvalid, $refusal->error);
        }

        $results = $service->complianceChecks($unitId, 'sandbox', $now, $onboarder);

        $this->assertSame(['standard:invoice', 'standard:credit_note', 'standard:debit_note', 'simplified:invoice', 'simplified:credit_note', 'simplified:debit_note'], array_map(static fn (array $result): string => $result['subtype'] . ':' . $result['kind'], $results));
        $this->assertSame(array_fill(0, 6, ZatcaOutcome::Accepted->value), array_column($results, 'outcome'), 'each sample stamped with the compliance certificate and verified by ZATCA');
        $this->assertSame(OnboardingService::COMPLIANT, $this->unitStatus());

        $service->productionStep($unitId, 'sandbox', $now, $onboarder);

        $production = $this->credentials()->active($unitId, 'sandbox', EInvoiceCredentials::PRODUCTION, $now);
        $compliance = Database::connect()->table('einvoice_credentials')->where(['unit_id' => $unitId, 'kind' => 'compliance'])->get()->getRow();

        $this->assertSame(OnboardingService::ACTIVE, $this->unitStatus());
        $this->assertNotFalse(StampKeys::fromPem($production['private_key'], $production['certificate']), 'the production certificate is the one of the key made with the request');
        $this->assertStringNotContainsString($production['secret'], implode('|', array_map('strval', Database::connect()->table('einvoice_credentials')->get()->getRowArray())), 'secrets are kept sealed');
        $this->assertNotSame('', (string) $compliance->certificate);
        $this->assertSame(['compliance_certificate', 'compliance_checks_passed', 'production_certificate'], array_column(Database::connect()->table('accounting_audit_log')->where('entity_type', 'einvoice_unit')->orderBy('audit_id', 'ASC')->get()->getResultArray(), 'action'));
    }

    public function testComplianceChecks_KeepTheUnitWhereItIsWhenZatcaRefusesASample(): void
    {
        $authority = new FakeZatcaAuthority();
        $service   = $this->service($authority);
        $onboarder = $this->employeeWithGrants([AccountingGuard::EINVOICING_ONBOARD]);
        $unitId    = $this->unitId();

        $service->complianceStep($unitId, 'sandbox', self::UNIT, FakeZatcaAuthority::OTP, $onboarder);
        $authority->next['*'] = 'reject';

        $results = $service->complianceChecks($unitId, 'sandbox', date('Y-m-d H:i:s'), $onboarder);

        $this->assertSame(ZatcaOutcome::Rejected->value, $results[0]['outcome']);
        $this->assertContains('BR-KSA-08: The buyer identification is not valid', $results[0]['errors']);
        $this->assertSame(OnboardingService::COMPLIANCE, $this->unitStatus());
    }

    public function testRenew_ReplacesTheProductionCertificateWithOneOfANewKey(): void
    {
        $authority = new FakeZatcaAuthority();
        $service   = $this->service($authority);
        $onboarder = $this->employeeWithGrants([AccountingGuard::EINVOICING_ONBOARD]);
        $unitId    = $this->unitId();
        $now       = date('Y-m-d H:i:s');

        $service->complianceStep($unitId, 'sandbox', self::UNIT, FakeZatcaAuthority::OTP, $onboarder);
        $service->complianceChecks($unitId, 'sandbox', $now, $onboarder);
        $service->productionStep($unitId, 'sandbox', $now, $onboarder);

        $before = $this->credentials()->active($unitId, 'sandbox', EInvoiceCredentials::PRODUCTION, $now);

        $service->renew($unitId, 'sandbox', self::UNIT, FakeZatcaAuthority::OTP, $now, $onboarder);

        $after = $this->credentials()->active($unitId, 'sandbox', EInvoiceCredentials::PRODUCTION, $now);

        $this->assertNotSame($before['credential_id'], $after['credential_id']);
        $this->assertNotSame($before['private_key'], $after['private_key'], 'a new key for the new request');
        $this->assertSame('retired', (string) Database::connect()->table('einvoice_credentials')->where('credential_id', $before['credential_id'])->get()->getRow()->status);
        $this->assertNotFalse(StampKeys::fromPem($after['private_key'], $after['certificate']));
    }

    public function testComplianceStep_RequiresThePermissionToOnboardAndAPasswordOfSixDigits(): void
    {
        $service = $this->service(new FakeZatcaAuthority());

        try {
            $service->complianceStep($this->unitId(), 'sandbox', self::UNIT, FakeZatcaAuthority::OTP, $this->employeeWithGrants([AccountingGuard::EINVOICING_MANAGE]));
            $this->fail('A manager onboarded the unit.');
        } catch (AccountingException) {
        }

        try {
            $service->complianceStep($this->unitId(), 'sandbox', self::UNIT, '12345', $this->employeeWithGrants([AccountingGuard::EINVOICING_ONBOARD]));
            $this->fail('A password of five digits was taken.');
        } catch (EInvoiceException $refusal) {
            $this->assertSame(EInvoiceError::SettingInvalid, $refusal->error);
        }

        $this->assertSame(0, Database::connect()->table('einvoice_credentials')->countAllResults());
    }
}
