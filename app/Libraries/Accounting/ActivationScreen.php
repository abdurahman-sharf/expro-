<?php

namespace App\Libraries\Accounting;

/**
 * What the activation wizard shows, gathered in one place so that the settings screen and the first setup show the
 * same wizard with the same words.
 */
final class ActivationScreen
{
    public function __construct(private readonly AccountingActivation $activation)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function data(?string $routingUrl): array
    {
        $inspection = $this->activation->inspect();
        $codes      = array_map(static fn (ReadinessIssue $issue): ReadinessCode => $issue->code, $inspection['automatic']);
        $needsYears = in_array(ReadinessCode::NoFiscalYear, $codes, true) || in_array(ReadinessCode::StartDateOutsideFiscalYear, $codes, true);

        return [
            'status'          => $this->activation->status(),
            'enabled'         => $inspection['enabled'],
            'locked'          => $inspection['locked'],
            'currency'        => $inspection['currency'],
            'currency_source' => $inspection['currency_source'],
            'start_date'      => $inspection['start_date'],
            'needs_years'     => $needsYears,
            'entities'        => $inspection['entities_missing'],
            'has_documents'   => $inspection['has_documents'],
            'impact'          => $inspection['impact'],
            'payment_types'   => $inspection['payment_types'],
            'blockers'        => array_map(static fn (ReadinessIssue $issue): string => self::describe($issue), $inspection['blockers']),
            'routing_url'     => $routingUrl,
            'steps'           => [AccountingActivation::STEP_YEARS, AccountingActivation::STEP_SETTINGS, AccountingActivation::STEP_ENTITIES, AccountingActivation::STEP_COMMIT, AccountingActivation::STEP_VERIFY],
            'ack_impact'      => AccountingActivation::ACK_IMPACT,
            'ack_documents'   => AccountingActivation::ACK_DOCUMENTS,
            'ack_payments'    => AccountingActivation::ACK_PAYMENT_TYPES,
        ];
    }

    private static function describe(ReadinessIssue $issue): string
    {
        return lang('Accounting_settings.readiness_' . $issue->code->value, [(string) $issue->subject]);
    }
}
