<?php

namespace App\Libraries\EInvoicing;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use SensitiveParameter;

/**
 * The onboarding of a unit with ZATCA (T10), in the order ZATCA asks for it: a request made on this server and the
 * compliance certificate asked for with the one-time password of the Fatoora portal; the compliance checks, a sample
 * of every kind of document the unit issues stamped with that certificate and accepted by ZATCA; then the production
 * certificate; and, before it runs out, a new production certificate for a new request and a new password. The keys
 * and secrets are kept sealed by the vault, every step is audited without them, and only whoever holds the onboarding
 * permission, which no role grants, may take a step. The clients are given from outside, and the real one sends only
 * through the guard (ZatcaNetworkGuard): nothing reaches ZATCA until the owner approves the environment.
 */
final class OnboardingService
{
    public const DRAFT      = 'draft';
    public const COMPLIANCE = 'compliance';
    public const COMPLIANT  = 'compliant';
    public const ACTIVE     = 'active';

    private BaseConnection $db;
    private AuditLog $audit;

    public function __construct(
        private readonly ZatcaOnboardingClient $client,
        private readonly EInvoiceCredentials $credentials,
        ?BaseConnection $db = null,
        private readonly AccountingGuard $guard = new AccountingGuard(),
        private readonly ?ZatcaClient $checker = null,
    ) {
        $this->db    = $db ?? Database::connect();
        $this->audit = new AuditLog($this->db);
    }

    /**
     * @param array{common_name: string, organization: string, organization_unit: string, serial: string, vat_number: string, invoice_types: string, address: string, business: string} $unit
     *
     * @throws EInvoiceException
     */
    public function complianceStep(int $unitId, string $environment, array $unit, #[SensitiveParameter] string $otp, ?int $employeeId = null): int
    {
        $this->guard->require(AccountingGuard::EINVOICING_ONBOARD, $employeeId);
        $this->unit($unitId);
        $this->assertOtp($otp);

        $request = (new CsrBuilder())->build($environment, $unit);
        $csid    = $this->client->complianceCsid($request['csr'], $otp);

        $credentialId = $this->credentials->store($unitId, $environment, EInvoiceCredentials::COMPLIANCE, self::certificateOf($csid), $csid->secret, $csid->token, $request['private_key'], $csid->validFrom, $csid->validTo, $csid->requestId, $employeeId);

        $this->db->table('einvoice_units')->where('unit_id', $unitId)->update(['status' => self::COMPLIANCE, 'egs_serial' => $unit['serial'], 'invoice_types' => $unit['invoice_types'], 'updated_at' => date('Y-m-d H:i:s')]);
        $this->audit->write('einvoice_unit', $unitId, 'compliance_certificate', $employeeId, JournalOrigin::User, ['environment' => $environment, 'request_id' => $csid->requestId, 'valid_to' => $csid->validTo]);

        return $credentialId;
    }

    /**
     * Sends the compliance samples of the unit, stamped with its compliance certificate, and moves the unit on when
     * ZATCA accepts every one of them.
     *
     * @param string $now Local time as Y-m-d H:i:s.
     *
     * @return list<array{number: string, kind: string, subtype: string, outcome: string, warnings: list<string>, errors: list<string>}> What ZATCA said of each sample.
     *
     * @throws EInvoiceException
     */
    public function complianceChecks(int $unitId, string $environment, string $now, ?int $employeeId = null): array
    {
        $this->guard->require(AccountingGuard::EINVOICING_ONBOARD, $employeeId);

        $row = $this->unit($unitId);

        if ($this->checker === null) {
            throw new EInvoiceException(EInvoiceError::NetworkBlocked, 'No client is given for the compliance checks.', ['field' => 'client']);
        }

        $compliance = $this->credentials->active($unitId, $environment, EInvoiceCredentials::COMPLIANCE, $now);
        $material   = StampKeys::fromPem($compliance['private_key'], $compliance['certificate']);
        $seller     = (new EInvoiceParties($this->db))->seller();
        $stamper    = new DocumentStamper();
        $results    = [];

        foreach ((new ComplianceSamples())->documents($seller, (string) ($row->invoice_types ?: '1100'), $now) as $document) {
            $stamped  = $stamper->signed($document, $material, gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($now)));
            $response = $this->checker->submit(new ZatcaRequest(ZatcaRequest::COMPLIANCE, $environment, $document->uuid, $stamped->hash, $stamped->xml, $unitId));

            $results[] = ['number' => $document->number, 'kind' => $document->kind->value, 'subtype' => $document->subtype->value, 'outcome' => $response->outcome->value, 'warnings' => $response->warnings, 'errors' => $response->errors];
        }

        $passed = $results !== [] && array_reduce($results, static fn (bool $all, array $result): bool => $all && ZatcaOutcome::from($result['outcome'])->accepted(), true);

        if ($passed) {
            $this->db->table('einvoice_units')->where('unit_id', $unitId)->update(['status' => self::COMPLIANT, 'updated_at' => date('Y-m-d H:i:s')]);
        }

        $this->audit->write('einvoice_unit', $unitId, $passed ? 'compliance_checks_passed' : 'compliance_checks_failed', $employeeId, JournalOrigin::User, ['environment' => $environment, 'results' => array_map(static fn (array $result): array => ['number' => $result['number'], 'outcome' => $result['outcome'], 'errors' => array_slice($result['errors'], 0, 5)], $results)]);

        return $results;
    }

    /**
     * @throws EInvoiceException When the unit did not pass its compliance checks.
     */
    public function productionStep(int $unitId, string $environment, string $now, ?int $employeeId = null): int
    {
        $this->guard->require(AccountingGuard::EINVOICING_ONBOARD, $employeeId);

        $row = $this->unit($unitId);

        if ((string) $row->status !== self::COMPLIANT) {
            throw new EInvoiceException(EInvoiceError::SettingInvalid, 'The unit must pass its compliance checks before it is given its production certificate.', ['field' => 'compliance_checks']);
        }

        $compliance = $this->credentials->active($unitId, $environment, EInvoiceCredentials::COMPLIANCE, $now);
        $request    = $this->db->table('einvoice_credentials')->select('request_id')->where('credential_id', $compliance['credential_id'])->get()->getRow();
        $csid       = $this->client->productionCsid((string) $request->request_id, new ZatcaAuth($compliance['token'], $compliance['secret']));

        $credentialId = $this->credentials->store($unitId, $environment, EInvoiceCredentials::PRODUCTION, self::certificateOf($csid), $csid->secret, $csid->token, $compliance['private_key'], $csid->validFrom, $csid->validTo, $csid->requestId, $employeeId);

        $this->db->table('einvoice_units')->where('unit_id', $unitId)->update(['status' => self::ACTIVE, 'updated_at' => date('Y-m-d H:i:s')]);
        $this->audit->write('einvoice_unit', $unitId, 'production_certificate', $employeeId, JournalOrigin::User, ['environment' => $environment, 'request_id' => $csid->requestId, 'valid_to' => $csid->validTo]);

        return $credentialId;
    }

    /**
     * A new production certificate for a unit whose certificate runs out: a new key and request on this server, the
     * new password of the Fatoora portal, and the certificate in place of the one it replaces, in one step.
     *
     * @param array{common_name: string, organization: string, organization_unit: string, serial: string, vat_number: string, invoice_types: string, address: string, business: string} $unit
     *
     * @throws EInvoiceException
     */
    public function renew(int $unitId, string $environment, array $unit, #[SensitiveParameter] string $otp, string $now, ?int $employeeId = null): int
    {
        $this->guard->require(AccountingGuard::EINVOICING_ONBOARD, $employeeId);
        $this->unit($unitId);
        $this->assertOtp($otp);

        $production = $this->credentials->active($unitId, $environment, EInvoiceCredentials::PRODUCTION, $now);
        $request    = (new CsrBuilder())->build($environment, $unit);
        $csid       = $this->client->renewProductionCsid($request['csr'], $otp, new ZatcaAuth($production['token'], $production['secret']));

        $credentialId = $this->credentials->store($unitId, $environment, EInvoiceCredentials::PRODUCTION, self::certificateOf($csid), $csid->secret, $csid->token, $request['private_key'], $csid->validFrom, $csid->validTo, $csid->requestId, $employeeId);

        $this->audit->write('einvoice_unit', $unitId, 'production_certificate_renewed', $employeeId, JournalOrigin::User, ['environment' => $environment, 'request_id' => $csid->requestId, 'valid_to' => $csid->validTo, 'replaces' => $production['credential_id']]);

        return $credentialId;
    }

    /**
     * The certificate a CSID carries, in base64 as the stamp names it; the token of a CSID is that base64 in base64.
     */
    private static function certificateOf(ZatcaCsid $csid): string
    {
        if ($csid->certificate !== '') {
            return $csid->certificate;
        }

        $decoded = base64_decode($csid->token, true);

        return $decoded !== false && preg_match('/^[A-Za-z0-9+\/=\s]+$/', $decoded) === 1 ? $decoded : $csid->token;
    }

    /**
     * @throws EInvoiceException
     */
    private function assertOtp(#[SensitiveParameter] string $otp): void
    {
        if (preg_match('/^\d{6}$/', $otp) !== 1) {
            throw new EInvoiceException(EInvoiceError::SettingInvalid, 'The one-time password of the Fatoora portal is six digits.', ['field' => 'otp']);
        }
    }

    /**
     * @throws EInvoiceException
     */
    private function unit(int $unitId): object
    {
        $row = $this->db->table('einvoice_units')->where(['unit_id' => $unitId, 'company_id' => CompanyContext::id()])->get()->getRow();

        if ($row === null) {
            throw new EInvoiceException(EInvoiceError::SourceNotFound, 'The unit does not exist.', ['number' => (string) $unitId]);
        }

        return $row;
    }
}
