<?php

namespace App\Libraries\EInvoicing;

use Config\Zatca;

/**
 * Whether a request may leave the server for an environment of ZATCA. Every condition must hold, and each one that
 * does not is named: the code does not run under tests, the server turned the network on (zatca.networkEnabled), the
 * owner approved the environment (zatca.approvedEnvironments), and the integration is on in the application for that
 * very environment. The real transport asks it before every request, so no command, screen or worker sends anything
 * by accident, whatever calls it.
 */
final class ZatcaNetworkGuard
{
    public const TESTING           = 'testing';
    public const NETWORK_OFF       = 'network_off';
    public const NOT_APPROVED      = 'environment_not_approved';
    public const INTEGRATION_OFF   = 'integration_off';
    public const OTHER_ENVIRONMENT = 'environment_mismatch';
    public const UNKNOWN           = 'environment_unknown';

    private Zatca $config;
    private ZatcaIntegration $integration;

    public function __construct(?Zatca $config = null, ?ZatcaIntegration $integration = null, private readonly string $runtime = ENVIRONMENT)
    {
        $this->config      = $config ?? config(Zatca::class);
        $this->integration = $integration ?? new ZatcaIntegration();
    }

    /**
     * @return list<string> What keeps a request from leaving for the environment; nothing when it may.
     */
    public function reasons(string $environment): array
    {
        $reasons = [];
        $chosen  = ZatcaEnvironment::tryFrom($environment);

        if ($chosen === null) {
            $reasons[] = self::UNKNOWN;
        }

        if ($this->runtime === 'testing') {
            $reasons[] = self::TESTING;
        }

        if (! $this->config->networkEnabled) {
            $reasons[] = self::NETWORK_OFF;
        }

        if ($chosen !== null && ! in_array($chosen->value, $this->approved(), true)) {
            $reasons[] = self::NOT_APPROVED;
        }

        if (! $this->integration->isEnabled()) {
            $reasons[] = self::INTEGRATION_OFF;
        } elseif ($chosen !== null && $this->integration->environment() !== $chosen) {
            $reasons[] = self::OTHER_ENVIRONMENT;
        }

        return $reasons;
    }

    public function isOpen(string $environment): bool
    {
        return $this->reasons($environment) === [];
    }

    /**
     * @throws EInvoiceException When anything keeps the request from leaving.
     */
    public function assertOpen(string $environment): void
    {
        $reasons = $this->reasons($environment);

        if ($reasons !== []) {
            throw new EInvoiceException(EInvoiceError::NetworkBlocked, 'Nothing is sent to ZATCA: ' . implode(', ', $reasons) . '.', ['field' => implode(', ', $reasons)]);
        }
    }

    /**
     * @return list<string>
     */
    public function approved(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', strtolower($this->config->approvedEnvironments))), static fn (string $value): bool => $value !== ''));
    }
}
