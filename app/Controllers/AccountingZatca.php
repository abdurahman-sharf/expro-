<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\EInvoicing\CurlZatcaHttp;
use App\Libraries\EInvoicing\EInvoiceCredentials;
use App\Libraries\EInvoicing\EInvoiceError;
use App\Libraries\EInvoicing\EInvoiceException;
use App\Libraries\EInvoicing\EInvoiceService;
use App\Libraries\EInvoicing\EInvoiceSettings;
use App\Libraries\EInvoicing\KeyVault;
use App\Libraries\EInvoicing\OnboardingService;
use App\Libraries\EInvoicing\SubmissionService;
use App\Libraries\EInvoicing\ZatcaEnvironment;
use App\Libraries\EInvoicing\ZatcaHttpClient;
use App\Libraries\EInvoicing\ZatcaIntegration;
use App\Libraries\EInvoicing\ZatcaNetworkGuard;
use App\Libraries\EInvoicing\ZatcaStatus;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Zatca;

/**
 * The screen of the integration with ZATCA: where it stands (what keeps each environment closed, the unit, its
 * certificates and the queue), the switch of the application and of the second phase, the steps of the onboarding,
 * and the documents of the queue with what can be done with each (sent again at once, or issued again once refused).
 * Every step that changes the integration needs the onboarding permission, which no role grants; sending a document
 * again and issuing a refused one again need the e-invoicing permission. Nothing here reaches ZATCA unless the guard
 * is open for the environment, which it never is under tests.
 */
class AccountingZatca extends AccountingController
{
    private const PAGE     = 50;
    private const STATUSES = [SubmissionService::PENDING, SubmissionService::RETRY, SubmissionService::UNKNOWN, SubmissionService::REPORTED, SubmissionService::CLEARED, SubmissionService::REJECTED];
    private const UNIT     = ['common_name', 'organization', 'organization_unit', 'serial', 'vat_number', 'invoice_types', 'address', 'business'];

    private ZatcaIntegration $integration;

    public function __construct()
    {
        parent::__construct('accounting_einvoicing');

        $this->integration = new ZatcaIntegration();
    }

    public function getIndex(): string
    {
        $this->requireOne();

        $status   = $this->request->getGet('status');
        $status   = in_array($status, self::STATUSES, true) ? $status : null;
        $offset   = max(0, (int) $this->request->getGet('offset'));
        $queue    = new SubmissionService(null, null, ZatcaEnvironment::Production->value);
        $settings = new EInvoiceSettings(null, null, $this->branchGuard());

        return view('accounting_zatca/manage', [
            'overview'  => (new ZatcaStatus())->overview(date('Y-m-d H:i:s')),
            'documents' => array_map(fn (object $row): array => [
                'document_id' => (int) $row->document_id,
                'number'      => (string) $row->document_number,
                'kind'        => (string) $row->doc_kind,
                'subtype'     => (string) $row->subtype,
                'date'        => $this->formatDate((string) $row->issue_date),
                'total'       => $this->formatAmount((string) $row->tax_inclusive),
                'status'      => (string) $row->status,
                'attempts'    => (int) $row->attempts,
                'next'        => (string) ($row->next_attempt_at ?? ''),
                'error'       => (string) ($row->last_error_code ?? ''),
                'warnings'    => self::messages($row->warnings),
                'errors'      => self::messages($row->errors),
            ], $queue->tracked($status, self::PAGE, $offset)),
            'status'       => $status,
            'statuses'     => self::STATUSES,
            'offset'       => $offset,
            'page'         => self::PAGE,
            'environments' => ZatcaEnvironment::values(),
            'can_onboard'  => $this->can(AccountingGuard::EINVOICING_ONBOARD),
            'can_manage'   => $this->can(AccountingGuard::EINVOICING_MANAGE),
            'unit_fields'  => [
                'common_name'       => 'EGS1-' . substr(preg_replace('/\D/', '', $settings->sellerVatNumber()) ?? '', 0, 10),
                'organization'      => $settings->sellerName(),
                'organization_unit' => '',
                'serial'            => '1-Expro|2-1.0|3-' . substr(hash('sha256', $settings->sellerVatNumber()), 0, 8),
                'vat_number'        => $settings->sellerVatNumber(),
                'invoice_types'     => '1100',
                'address'           => '',
                'business'          => '',
            ],
        ]);
    }

    public function postIntegration(): ResponseInterface
    {
        return $this->respond(function (): string {
            $reason = (string) $this->request->getPost('reason');

            if ($this->request->getPost('action') === 'enable') {
                $this->integration->enable((string) $this->request->getPost('environment'), $reason, $this->employeeId());

                return lang('Accounting_zatca.integration_enabled');
            }

            $this->integration->disable($reason, $this->employeeId());

            return lang('Accounting_zatca.integration_disabled');
        });
    }

    public function postPhaseTwo(): ResponseInterface
    {
        return $this->respond(function (): string {
            $reason = (string) $this->request->getPost('reason');

            if ($this->request->getPost('action') === 'enable') {
                $this->integration->enablePhaseTwo($reason, $this->employeeId(), date('Y-m-d H:i:s'));

                return lang('Accounting_zatca.phase_two_enabled');
            }

            $this->integration->disablePhaseTwo($reason, $this->employeeId());

            return lang('Accounting_zatca.phase_two_disabled');
        });
    }

    public function postCompliance(): ResponseInterface
    {
        return $this->respond(function (): string {
            $environment = $this->openEnvironment();

            $this->onboarding($environment)->complianceStep($this->unitId(), $environment->value, $this->unitFields(), (string) $this->request->getPost('otp'), $this->employeeId());

            return lang('Accounting_zatca.compliance_done');
        });
    }

    public function postComplianceChecks(): ResponseInterface
    {
        try {
            $environment = $this->openEnvironment();
            $results     = $this->onboarding($environment)->complianceChecks($this->unitId(), $environment->value, date('Y-m-d H:i:s'), $this->employeeId());
        } catch (EInvoiceException $exception) {
            return $this->response->setJSON(['success' => false, 'message' => $exception->describe(), 'id' => NEW_ENTRY]);
        } catch (AccountingException|BranchException $exception) {
            return $this->response->setJSON(['success' => false, 'message' => AccountingMessage::of($exception), 'id' => NEW_ENTRY]);
        }

        $passed = array_filter($results, static fn (array $result): bool => in_array($result['outcome'], ['accepted', 'accepted_with_warnings'], true));

        return $this->response->setJSON(['success' => count($passed) === count($results), 'message' => lang('Accounting_zatca.checks_result', [count($passed), count($results)]), 'results' => $results, 'id' => 0]);
    }

    public function postProduction(): ResponseInterface
    {
        return $this->respond(function (): string {
            $environment = $this->openEnvironment();

            $this->onboarding($environment)->productionStep($this->unitId(), $environment->value, date('Y-m-d H:i:s'), $this->employeeId());

            return lang('Accounting_zatca.production_done');
        });
    }

    public function postRenew(): ResponseInterface
    {
        return $this->respond(function (): string {
            $environment = $this->openEnvironment();

            $this->onboarding($environment)->renew($this->unitId(), $environment->value, $this->unitFields(), (string) $this->request->getPost('otp'), date('Y-m-d H:i:s'), $this->employeeId());

            return lang('Accounting_zatca.renew_done');
        });
    }

    public function postRetry(int $documentId): ResponseInterface
    {
        return $this->respond(function () use ($documentId): string {
            $this->requireGrant(AccountingGuard::EINVOICING_MANAGE);

            (new SubmissionService(null, null, ZatcaEnvironment::Production->value))->retryNow($documentId, $this->employeeId());

            return lang('Accounting_zatca.retry_done');
        });
    }

    public function postReissue(int $documentId): ResponseInterface
    {
        return $this->respond(function () use ($documentId): string {
            $document = (new EInvoiceService(null, null, $this->branchGuard()))->reissueRejected($documentId, $this->employeeId());

            return lang('Accounting_zatca.reissue_done', [$document->number, $document->icv]);
        });
    }

    /**
     * The environment asked for, refused before anything is opened while the guard keeps it closed.
     *
     * @throws AccountingException|EInvoiceException
     */
    private function openEnvironment(): ZatcaEnvironment
    {
        $this->requireGrant(AccountingGuard::EINVOICING_ONBOARD);

        $environment = ZatcaEnvironment::tryFrom((string) $this->request->getPost('environment'));

        if ($environment === null) {
            throw new EInvoiceException(EInvoiceError::SettingInvalid, 'The environment is not one of ZATCA.', ['field' => 'environment']);
        }

        (new ZatcaNetworkGuard(null, $this->integration))->assertOpen($environment->value);

        return $environment;
    }

    /**
     * @throws EInvoiceException When the vault cannot be opened.
     */
    private function onboarding(ZatcaEnvironment $environment): OnboardingService
    {
        $credentials = new EInvoiceCredentials(KeyVault::fromFile((string) config(Zatca::class)->keyRing));
        $transport   = new CurlZatcaHttp(new ZatcaNetworkGuard(null, $this->integration));

        return new OnboardingService(new ZatcaHttpClient($transport, $environment), $credentials, null, new AccountingGuard(), new ZatcaHttpClient($transport, $environment, $credentials));
    }

    private function unitId(): int
    {
        return (int) (db_connect()->table('einvoice_units')->select('unit_id')->where(['company_id' => CompanyContext::id(), 'is_default' => 1])->get()->getRow()?->unit_id ?? 0);
    }

    /**
     * @return array{common_name: string, organization: string, organization_unit: string, serial: string, vat_number: string, invoice_types: string, address: string, business: string}
     */
    private function unitFields(): array
    {
        $fields = [];

        foreach (self::UNIT as $field) {
            $fields[$field] = trim((string) $this->request->getPost($field));
        }

        return $fields;
    }

    /**
     * @return list<string>
     */
    private static function messages(mixed $stored): array
    {
        $decoded = json_decode((string) $stored, true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    private function requireOne(): void
    {
        if (! $this->can(AccountingGuard::EINVOICING_MANAGE) && ! $this->can(AccountingGuard::EINVOICING_ONBOARD)) {
            $this->requireGrant(AccountingGuard::EINVOICING_MANAGE);
        }
    }

    /**
     * @param callable(): string $action
     */
    private function respond(callable $action): ResponseInterface
    {
        try {
            return $this->response->setJSON(['success' => true, 'message' => $action(), 'id' => 0]);
        } catch (EInvoiceException $exception) {
            return $this->response->setJSON(['success' => false, 'message' => $exception->describe(), 'id' => NEW_ENTRY]);
        } catch (AccountingException|BranchException $exception) {
            return $this->response->setJSON(['success' => false, 'message' => AccountingMessage::of($exception), 'id' => NEW_ENTRY]);
        }
    }
}
