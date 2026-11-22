<?php

namespace Tests\Libraries\EInvoicing;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\EInvoicing\EInvoiceError;
use App\Libraries\EInvoicing\EInvoiceException;
use App\Libraries\EInvoicing\ZatcaEnvironment;
use App\Libraries\EInvoicing\ZatcaIntegration;
use App\Models\Appconfig;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;

/**
 * The switch of the integration in the application: off and on the sandbox by default, turned only by an onboarding
 * holder, with a reason the audit keeps with what changed.
 */
class ZatcaIntegrationTest extends CIUnitTestCase
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

    public function testEnable_TurnsTheIntegrationOnForAnEnvironmentAndAuditsItWithItsReason(): void
    {
        $integration = new ZatcaIntegration();
        $onboarder   = $this->employeeWithGrants([AccountingGuard::EINVOICING_ONBOARD]);

        $this->assertSame([false, ZatcaEnvironment::Sandbox], [$integration->isEnabled(), $integration->environment()], 'off by default');

        $integration->enable('simulation', 'Approved for the simulation (D-20)', $onboarder);

        $this->assertSame([true, ZatcaEnvironment::Simulation], [$integration->isEnabled(), $integration->environment()]);

        $integration->disable('Simulation over', $onboarder);

        $audit = Database::connect()->table('accounting_audit_log')->where('entity_type', 'einvoice_integration')->orderBy('audit_id', 'ASC')->get()->getResult();

        $this->assertFalse($integration->isEnabled());
        $this->assertSame(['integration_enabled', 'integration_disabled'], array_column($audit, 'action'));
        $this->assertSame(['before' => ['enabled' => '0', 'environment' => 'sandbox'], 'after' => ['enabled' => '1', 'environment' => 'simulation'], 'reason' => 'Approved for the simulation (D-20)'], json_decode((string) $audit[0]->details, true));
    }

    public function testEnable_RequiresThePermissionToOnboardAReasonAndAnEnvironmentOfZatca(): void
    {
        $integration = new ZatcaIntegration();

        try {
            $integration->enable('sandbox', 'a reason', $this->employeeWithGrants([AccountingGuard::EINVOICING_MANAGE]));
            $this->fail('A manager turned the integration on.');
        } catch (AccountingException) {
        }

        $onboarder = $this->employeeWithGrants([AccountingGuard::EINVOICING_ONBOARD]);

        foreach ([['sandbox', '  ', EInvoiceError::ReasonRequired], ['testing', 'a reason', EInvoiceError::SettingInvalid]] as [$environment, $reason, $error]) {
            try {
                $integration->enable($environment, $reason, $onboarder);
                $this->fail('The integration was turned on.');
            } catch (EInvoiceException $refusal) {
                $this->assertSame($error, $refusal->error);
            }
        }

        $this->assertFalse($integration->isEnabled());
    }
}
