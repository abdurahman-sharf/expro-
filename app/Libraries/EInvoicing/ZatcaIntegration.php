<?php

namespace App\Libraries\EInvoicing;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Organization\CompanyContext;
use App\Models\Appconfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Zatca;

/**
 * The switch of the integration with ZATCA in the application and the environment it is for, off until someone who
 * holds the onboarding permission, which no role grants, turns it on with a reason, which the audit keeps. Turning it
 * on sends nothing by itself: the server must also allow the network and the environment (ZatcaNetworkGuard).
 */
final class ZatcaIntegration
{
    public const ENABLED     = 'einvoice_integration_enabled';
    public const ENVIRONMENT = 'einvoice_integration_environment';

    private BaseConnection $db;
    private Appconfig $config;

    public function __construct(?BaseConnection $db = null, private readonly AccountingGuard $guard = new AccountingGuard())
    {
        $this->db     = $db ?? Database::connect();
        $this->config = model(Appconfig::class);
    }

    public function isEnabled(): bool
    {
        return $this->config->get_value(self::ENABLED, '0') === '1';
    }

    public function environment(): ZatcaEnvironment
    {
        return ZatcaEnvironment::tryFrom($this->config->get_value(self::ENVIRONMENT, ZatcaEnvironment::Sandbox->value)) ?? ZatcaEnvironment::Sandbox;
    }

    /**
     * @throws EInvoiceException When the environment is not one of ZATCA or no reason is given.
     */
    public function enable(string $environment, string $reason, ?int $employeeId): void
    {
        $this->guard->require(AccountingGuard::EINVOICING_ONBOARD, $employeeId);

        $chosen = ZatcaEnvironment::tryFrom($environment);

        if ($chosen === null) {
            throw new EInvoiceException(EInvoiceError::SettingInvalid, 'The environment is not one of ZATCA.', ['field' => 'environment']);
        }

        $this->change(['enabled' => '1', 'environment' => $chosen->value], $reason, $employeeId, 'integration_enabled');
    }

    /**
     * @throws EInvoiceException When no reason is given.
     */
    public function disable(string $reason, ?int $employeeId): void
    {
        $this->guard->require(AccountingGuard::EINVOICING_ONBOARD, $employeeId);

        $this->change(['enabled' => '0', 'environment' => $this->environment()->value], $reason, $employeeId, 'integration_disabled');
    }

    public function phaseTwo(): bool
    {
        return $this->config->get_value(EInvoiceSettings::PHASE_TWO, '0') === '1';
    }

    /**
     * Turns on the second phase, in which every document is stamped with the production certificate of its unit and
     * queued to be sent: only for a unit that ZATCA onboarded in production and whose production certificate runs,
     * which nothing but the real onboarding gives. Queuing sends nothing by itself.
     *
     * @throws EInvoiceException When the unit is not onboarded in production or no reason is given.
     */
    public function enablePhaseTwo(string $reason, ?int $employeeId, string $now, ?EInvoiceCredentials $credentials = null): void
    {
        $this->guard->require(AccountingGuard::EINVOICING_ONBOARD, $employeeId);

        $unit = $this->db->table('einvoice_units')->where(['company_id' => CompanyContext::id(), 'is_default' => 1])->get()->getRow();

        if ($unit === null || (string) $unit->status !== OnboardingService::ACTIVE) {
            throw new EInvoiceException(EInvoiceError::SettingInvalid, 'The unit is not onboarded.', ['field' => 'unit']);
        }

        ($credentials ?? new EInvoiceCredentials(KeyVault::fromFile((string) config(Zatca::class)->keyRing), $this->db))->active((int) $unit->unit_id, ZatcaEnvironment::Production->value, EInvoiceCredentials::PRODUCTION, $now);

        $this->switchPhaseTwo('1', $reason, $employeeId);
    }

    /**
     * @throws EInvoiceException When no reason is given.
     */
    public function disablePhaseTwo(string $reason, ?int $employeeId): void
    {
        $this->guard->require(AccountingGuard::EINVOICING_ONBOARD, $employeeId);

        $this->switchPhaseTwo('0', $reason, $employeeId);
    }

    /**
     * @throws EInvoiceException
     */
    private function switchPhaseTwo(string $value, string $reason, ?int $employeeId): void
    {
        if (trim($reason) === '') {
            throw new EInvoiceException(EInvoiceError::ReasonRequired, 'A change of the second phase needs a reason.');
        }

        $before = $this->phaseTwo() ? '1' : '0';

        $this->config->save([EInvoiceSettings::PHASE_TWO => $value]);

        (new AuditLog($this->db))->write('einvoice_integration', null, $value === '1' ? 'phase_two_enabled' : 'phase_two_disabled', $employeeId, JournalOrigin::User, ['before' => $before, 'after' => $value, 'reason' => mb_substr(trim($reason), 0, 500)]);
    }

    /**
     * @param array{enabled: string, environment: string} $after
     *
     * @throws EInvoiceException
     */
    private function change(array $after, string $reason, ?int $employeeId, string $action): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new EInvoiceException(EInvoiceError::ReasonRequired, 'A change of the integration with ZATCA needs a reason.');
        }

        $before = ['enabled' => $this->isEnabled() ? '1' : '0', 'environment' => $this->environment()->value];

        $this->config->batch_save([self::ENABLED => $after['enabled'], self::ENVIRONMENT => $after['environment']]);

        (new AuditLog($this->db))->write('einvoice_integration', null, $action, $employeeId, JournalOrigin::User, ['before' => $before, 'after' => $after, 'reason' => mb_substr($reason, 0, 500)]);
    }
}
