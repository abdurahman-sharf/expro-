<?php

namespace Tests\Libraries\EInvoicing;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\EInvoicing\EInvoiceCredentials;
use App\Libraries\EInvoicing\KeyVault;
use App\Libraries\EInvoicing\OnboardingService;
use App\Libraries\EInvoicing\StampKeys;
use App\Libraries\EInvoicing\ZatcaCsid;
use App\Libraries\EInvoicing\ZatcaOnboardingClient;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;

/**
 * Onboarding a unit against a ZATCA that lives in memory: the request made here, the compliance certificate and then
 * the production one kept sealed, the unit moved on, and nobody without the onboarding permission able to do it.
 */
class OnboardingServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
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
    }

    protected function tearDown(): void
    {
        $db = Database::connect();

        $db->table('einvoice_credentials')->truncate();
        $db->table('einvoice_units')->where('is_default', 1)->update(['status' => 'draft', 'egs_serial' => '']);
        array_map('unlink', glob($this->directory . '/*') ?: []);
        rmdir($this->directory);
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function zatca(): ZatcaOnboardingClient
    {
        return new class () implements ZatcaOnboardingClient {
            public array $csrs = [];

            public function complianceCsid(string $csr, string $otp): ZatcaCsid
            {
                $this->csrs[] = $csr;

                return new ZatcaCsid('compliance-request-1', 'compliance-token', 'compliance-secret', '2026-11-21 00:00:00', '2027-11-21 00:00:00');
            }

            public function productionCsid(string $complianceRequestId): ZatcaCsid
            {
                return new ZatcaCsid('production-request-1', 'production-token-for-' . $complianceRequestId, 'production-secret', '2026-11-21 00:00:00', '2031-11-21 00:00:00');
            }
        };
    }

    private function unitId(): int
    {
        return (int) Database::connect()->table('einvoice_units')->where(['company_id' => 1, 'is_default' => 1])->get()->getRow()->unit_id;
    }

    public function testComplianceStep_KeepsTheCertificateSealedAndThenTheProductionStepActivatesTheUnit(): void
    {
        $zatca       = $this->zatca();
        $credentials = new EInvoiceCredentials(KeyVault::fromFile($this->directory . '/master.key'));
        $service     = new OnboardingService($zatca, $credentials);
        $onboarder   = $this->employeeWithGrants([AccountingGuard::EINVOICING_ONBOARD]);
        $unitId      = $this->unitId();

        $service->complianceStep($unitId, 'sandbox', self::UNIT, '123456', $onboarder);

        $this->assertStringContainsString('BEGIN CERTIFICATE REQUEST', $zatca->csrs[0]);
        $this->assertSame('compliance', Database::connect()->table('einvoice_units')->where('unit_id', $unitId)->get()->getRow()->status);
        $this->assertStringNotContainsString('compliance-secret', implode('|', array_map('strval', Database::connect()->table('einvoice_credentials')->get()->getRowArray())));

        $service->productionStep($unitId, 'sandbox', '2026-12-01 00:00:00', $onboarder);

        $production = $credentials->active($unitId, 'sandbox', EInvoiceCredentials::PRODUCTION, '2026-12-01 00:00:00');

        $this->assertSame(['production-token-for-compliance-request-1', 'production-secret'], [$production['token'], $production['secret']]);
        $this->assertStringContainsString('PRIVATE KEY', $production['private_key'], 'the key made with the request stays with the unit');
        $this->assertSame('active', Database::connect()->table('einvoice_units')->where('unit_id', $unitId)->get()->getRow()->status);
    }

    public function testComplianceStep_RequiresThePermissionToOnboard(): void
    {
        $service = new OnboardingService($this->zatca(), new EInvoiceCredentials(KeyVault::fromFile($this->directory . '/master.key')));

        $this->expectException(AccountingException::class);

        $service->complianceStep($this->unitId(), 'sandbox', self::UNIT, '123456', $this->employeeWithGrants([AccountingGuard::EINVOICING_MANAGE]));
    }
}
