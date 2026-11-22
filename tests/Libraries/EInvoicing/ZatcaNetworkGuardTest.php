<?php

namespace Tests\Libraries\EInvoicing;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\EInvoicing\EInvoiceError;
use App\Libraries\EInvoicing\EInvoiceException;
use App\Libraries\EInvoicing\ZatcaIntegration;
use App\Libraries\EInvoicing\ZatcaNetworkGuard;
use App\Models\Appconfig;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\Zatca;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;

/**
 * The guard every request to ZATCA passes: closed by default, closed under tests whatever is configured, and open only
 * when the server turns the network on, the owner approved the environment and the application turned the integration
 * on for that very environment; each thing that keeps it closed is named.
 */
class ZatcaNetworkGuardTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        Database::seeder('tests')->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        model(Appconfig::class)->batch_save([ZatcaIntegration::ENABLED => '0', ZatcaIntegration::ENVIRONMENT => 'sandbox']);
        Database::connect()->table('accounting_audit_log')->where('entity_type', 'einvoice_integration')->delete();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function allowed(string $approved = 'sandbox'): Zatca
    {
        $config                       = new Zatca();
        $config->networkEnabled       = true;
        $config->approvedEnvironments = $approved;

        return $config;
    }

    public function testReasons_NamesEverythingThatKeepsARequestFromLeavingAsItStandsByDefault(): void
    {
        $this->assertSame([ZatcaNetworkGuard::TESTING, ZatcaNetworkGuard::NETWORK_OFF, ZatcaNetworkGuard::NOT_APPROVED, ZatcaNetworkGuard::INTEGRATION_OFF], (new ZatcaNetworkGuard(new Zatca()))->reasons('sandbox'));
        $this->assertSame([ZatcaNetworkGuard::NETWORK_OFF, ZatcaNetworkGuard::NOT_APPROVED, ZatcaNetworkGuard::INTEGRATION_OFF], (new ZatcaNetworkGuard(new Zatca(), null, 'production'))->reasons('production'), 'on a server, as it is installed');
        $this->assertContains(ZatcaNetworkGuard::UNKNOWN, (new ZatcaNetworkGuard($this->allowed(), null, 'production'))->reasons('elsewhere'));
    }

    public function testReasons_OpensOnlyWhenTheServerTheOwnerAndTheApplicationAllowTheSameEnvironment(): void
    {
        $onboarder = $this->employeeWithGrants([AccountingGuard::EINVOICING_ONBOARD]);

        (new ZatcaIntegration())->enable('sandbox', 'Approved for the sandbox (D-10)', $onboarder);

        $guard = new ZatcaNetworkGuard($this->allowed('sandbox'), null, 'production');

        $this->assertSame([], $guard->reasons('sandbox'));
        $this->assertTrue($guard->isOpen('sandbox'));
        $this->assertSame([ZatcaNetworkGuard::NOT_APPROVED, ZatcaNetworkGuard::OTHER_ENVIRONMENT], $guard->reasons('production'), 'the sandbox opens the sandbox only');
        $this->assertSame([ZatcaNetworkGuard::NOT_APPROVED], (new ZatcaNetworkGuard($this->allowed('simulation'), null, 'production'))->reasons('sandbox'));
    }

    public function testAssertOpen_StaysClosedUnderTestsWhateverIsConfigured(): void
    {
        $onboarder = $this->employeeWithGrants([AccountingGuard::EINVOICING_ONBOARD]);

        (new ZatcaIntegration())->enable('sandbox', 'Approved for the sandbox (D-10)', $onboarder);

        try {
            (new ZatcaNetworkGuard($this->allowed('sandbox')))->assertOpen('sandbox');
            $this->fail('The guard opened under tests.');
        } catch (EInvoiceException $refusal) {
            $this->assertSame(EInvoiceError::NetworkBlocked, $refusal->error);
            $this->assertSame(ZatcaNetworkGuard::TESTING, $refusal->context['field']);
        }
    }
}
