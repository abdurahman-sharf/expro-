<?php

namespace App\Libraries\EInvoicing;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use SensitiveParameter;

/**
 * The onboarding of a unit with ZATCA (T10): a request made on this server, the compliance certificate asked for
 * with the one-time password of the Fatoora portal, then the production certificate. The keys and secrets are kept
 * sealed by the vault. Only whoever holds the onboarding permission, which no role grants, may do it, and the client
 * that reaches ZATCA is given from outside: Expro holds none, so nothing reaches ZATCA until an environment is
 * approved (D-10, D-20, D-21) and its client configured.
 */
final class OnboardingService
{
    private BaseConnection $db;

    public function __construct(
        private readonly ZatcaOnboardingClient $client,
        private readonly EInvoiceCredentials $credentials,
        ?BaseConnection $db = null,
        private readonly AccountingGuard $guard = new AccountingGuard(),
    ) {
        $this->db = $db ?? Database::connect();
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

        if (preg_match('/^\d{6}$/', $otp) !== 1) {
            throw new EInvoiceException(EInvoiceError::SettingInvalid, 'The one-time password of the Fatoora portal is six digits.', ['field' => 'otp']);
        }

        $request = (new CsrBuilder())->build($environment, $unit);
        $csid    = $this->client->complianceCsid($request['csr'], $otp);

        $credentialId = $this->credentials->store($unitId, $environment, EInvoiceCredentials::COMPLIANCE, $csid->token, $csid->secret, $csid->token, $request['private_key'], $csid->validFrom, $csid->validTo, $csid->requestId, $employeeId);

        $this->db->table('einvoice_units')->where('unit_id', $unitId)->update(['status' => 'compliance', 'egs_serial' => $unit['serial'], 'updated_at' => date('Y-m-d H:i:s')]);

        return $credentialId;
    }

    /**
     * @throws EInvoiceException
     */
    public function productionStep(int $unitId, string $environment, string $now, ?int $employeeId = null): int
    {
        $this->guard->require(AccountingGuard::EINVOICING_ONBOARD, $employeeId);
        $this->unit($unitId);

        $compliance = $this->credentials->active($unitId, $environment, EInvoiceCredentials::COMPLIANCE, $now);
        $row        = $this->db->table('einvoice_credentials')->select('request_id')->where('credential_id', $compliance['credential_id'])->get()->getRow();
        $csid       = $this->client->productionCsid((string) $row->request_id);

        $credentialId = $this->credentials->store($unitId, $environment, EInvoiceCredentials::PRODUCTION, $csid->token, $csid->secret, $csid->token, $compliance['private_key'], $csid->validFrom, $csid->validTo, $csid->requestId, $employeeId);

        $this->db->table('einvoice_units')->where('unit_id', $unitId)->update(['status' => 'active', 'updated_at' => date('Y-m-d H:i:s')]);

        return $credentialId;
    }

    /**
     * @throws EInvoiceException
     */
    private function unit(int $unitId): void
    {
        if ($this->db->table('einvoice_units')->where(['unit_id' => $unitId, 'company_id' => CompanyContext::id()])->countAllResults() === 0) {
            throw new EInvoiceException(EInvoiceError::SourceNotFound, 'The unit does not exist.', ['number' => (string) $unitId]);
        }
    }
}
