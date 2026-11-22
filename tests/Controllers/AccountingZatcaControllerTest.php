<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\EInvoicing\EInvoiceService;
use App\Libraries\EInvoicing\SubmissionService;
use App\Libraries\EInvoicing\ZatcaIntegration;
use App\Models\Appconfig;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\AccountingControllerFixtureTrait;
use Tests\Support\EInvoiceFixtureTrait;
use Tests\Support\EInvoiceSaleFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The screen of the integration with ZATCA: where it stands and why nothing is sent, the switches and the steps of the
 * onboarding for the onboarding holder alone, the documents of the queue with what can be done with each, and no step
 * that reaches ZATCA under tests.
 */
class AccountingZatcaControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingControllerFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use EInvoiceFixtureTrait;
    use EInvoiceSaleFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->startSelling();
        $this->configureEInvoicing();
    }

    protected function tearDown(): void
    {
        model(Appconfig::class)->batch_save([ZatcaIntegration::ENABLED => '0', ZatcaIntegration::ENVIRONMENT => 'sandbox']);
        $this->accountingDb()->table('accounting_audit_log')->whereIn('entity_type', ['einvoice_integration', 'einvoice_document'])->delete();
        $this->accountingDb()->table('einvoice_submissions')->truncate();
        $this->accountingDb()->table('einvoice_outbox')->truncate();
        $this->clearEInvoices();
        $this->restoreEInvoicingConfig();
        $this->stopSelling();
        $this->resetBranches();

        parent::tearDown();
    }

    private function as(array $grants): int
    {
        $personId = $this->employeeWithGrants(['accounting_einvoicing', ...$grants]);

        $this->actAs($personId);

        return $personId;
    }

    private function queued(string $status): int
    {
        $documentId = (new EInvoiceService())->issueSale($this->referenceSale(), null, '', null)->documentId;

        $this->accountingDb()->table('einvoice_outbox')->insert(['document_id' => $documentId, 'company_id' => 1, 'environment' => 'production', 'status' => $status, 'attempts' => 1, 'next_attempt_at' => date('Y-m-d H:i:s', time() + 3600), 'last_error_code' => 'BR-KSA-08']);

        return $documentId;
    }

    public function testGetIndex_ShowsEveryEnvironmentClosedAndWhyAndNoStepsToAManager(): void
    {
        $this->as([AccountingGuard::EINVOICING_MANAGE]);

        $page = $this->get('/accounting_zatca');
        $html = html_entity_decode($page->response()->getBody(), ENT_QUOTES);

        $page->assertOK();
        $this->assertStringContainsString(lang('Accounting_zatca.offline_title'), $html);

        foreach (['sandbox', 'simulation', 'production'] as $environment) {
            $this->assertMatchesRegularExpression('/data-environment="' . $environment . '".*?' . preg_quote(lang('Accounting_zatca.closed'), '/') . '.*?' . preg_quote(lang('Accounting_zatca.reason_testing'), '/') . '/s', $html);
        }

        $this->assertStringNotContainsString('zatca_compliance_form', $html, 'the steps are for the onboarding holder');
        $this->assertStringNotContainsString('zatca_integration_form', $html);
    }

    public function testGetIndex_GivesTheOnboardingHolderTheSwitchesAndTheSteps(): void
    {
        $this->as([AccountingGuard::EINVOICING_ONBOARD]);

        $html = (string) $this->get('/accounting_zatca')->response()->getBody();

        foreach (['zatca_integration_form', 'zatca_phase_two_form', 'zatca_compliance_form', 'zatca_compliance_checks_form', 'zatca_production_form', 'zatca_renew_form'] as $form) {
            $this->assertStringContainsString('id="' . $form . '"', $html);
        }

        $this->assertStringContainsString('autocomplete="one-time-code"', $html);
    }

    public function testGetIndex_RefusesWhoHoldsNeitherPermission(): void
    {
        $this->as([]);

        $this->assertStringContainsString('no_access/accounting_einvoicing/' . AccountingGuard::EINVOICING_MANAGE, $this->get('/accounting_zatca')->getRedirectUrl() ?? '');
    }

    public function testPostIntegration_TurnsTheIntegrationOnForTheOnboardingHolderWithAReason(): void
    {
        $this->as([AccountingGuard::EINVOICING_ONBOARD]);

        $refused  = $this->jsonOf($this->post('/accounting_zatca/integration', ['action' => 'enable', 'environment' => 'sandbox', 'reason' => '']));
        $accepted = $this->jsonOf($this->post('/accounting_zatca/integration', ['action' => 'enable', 'environment' => 'sandbox', 'reason' => 'Approved for the sandbox']));

        $this->assertFalse($refused['success']);
        $this->assertTrue($accepted['success']);
        $this->assertTrue((new ZatcaIntegration())->isEnabled());
    }

    public function testPostCompliance_SendsNothingUnderTestsAndSaysWhy(): void
    {
        $this->as([AccountingGuard::EINVOICING_ONBOARD]);

        (new ZatcaIntegration())->enable('sandbox', 'Approved for the sandbox', $this->employeeWithGrants([AccountingGuard::EINVOICING_ONBOARD]));

        $json = $this->jsonOf($this->post('/accounting_zatca/compliance', ['environment' => 'sandbox', 'otp' => '123456', 'common_name' => 'EGS1', 'organization' => 'Taxpayer', 'organization_unit' => 'Riyadh', 'serial' => '1-Expro|2-1.0|3-1', 'vat_number' => self::SELLER_VAT, 'invoice_types' => '1100', 'address' => 'RRRD2929', 'business' => 'Supply']));

        $this->assertFalse($json['success']);
        $this->assertStringContainsString(lang('Accounting_einvoicing.error_network_blocked', ['testing, network_off, environment_not_approved']), $json['message']);
        $this->assertSame(0, $this->accountingDb()->table('einvoice_credentials')->countAllResults());
    }

    public function testPostRetry_MakesAWaitingDocumentDueAndPostReissueRefusesOneThatWasNotRefused(): void
    {
        $this->as([AccountingGuard::EINVOICING_MANAGE]);

        $waiting = $this->queued(SubmissionService::RETRY);
        $retried = $this->jsonOf($this->post('/accounting_zatca/retry/' . $waiting));
        $refused = $this->jsonOf($this->post('/accounting_zatca/reissue/' . $waiting));

        $this->assertTrue($retried['success']);
        $this->assertNull($this->accountingDb()->table('einvoice_outbox')->where('document_id', $waiting)->get()->getRow()->next_attempt_at);
        $this->assertFalse($refused['success']);
        $this->assertSame(lang('Accounting_einvoicing.error_submission_not_rejected', [$this->accountingDb()->table('einvoice_documents')->where('document_id', $waiting)->get()->getRow()->document_number]), $refused['message']);

        $page = html_entity_decode((string) $this->get('/accounting_zatca?status=retry')->response()->getBody(), ENT_QUOTES);

        $this->assertStringContainsString('data-document="' . $waiting . '"', $page);
        $this->assertStringContainsString('accounting_zatca/retry/' . $waiting, $page);
        $this->assertStringContainsString('BR-KSA-08', $page);
    }
}
