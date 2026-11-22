<?php

namespace App\Libraries\EInvoicing;

use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Where the integration with ZATCA stands, for its screen: what keeps each environment closed, the switch of the
 * application and the second phase, the default unit, its certificates (read without their secrets, with the days
 * left of each) and the queue of the documents of production.
 */
final class ZatcaStatus
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null, private readonly ?ZatcaNetworkGuard $guard = null, private readonly ?ZatcaIntegration $integration = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return array{environments: array<string, list<string>>, enabled: bool, environment: string, phase_two: bool, unit: object|null, certificates: list<array<string, mixed>>, queue: array<string, int>}
     */
    public function overview(string $now): array
    {
        $integration  = $this->integration ?? new ZatcaIntegration($this->db);
        $guard        = $this->guard ?? new ZatcaNetworkGuard(null, $integration);
        $environments = [];

        foreach (ZatcaEnvironment::cases() as $environment) {
            $environments[$environment->value] = $guard->reasons($environment->value);
        }

        $unit = $this->db->table('einvoice_units')->where(['company_id' => CompanyContext::id(), 'is_default' => 1])->get()->getRow();

        return [
            'environments' => $environments,
            'enabled'      => $integration->isEnabled(),
            'environment'  => $integration->environment()->value,
            'phase_two'    => $integration->phaseTwo(),
            'unit'         => $unit,
            'certificates' => $unit === null ? [] : $this->certificates((int) $unit->unit_id, $now),
            'queue'        => (new SubmissionService(null, $this->db, ZatcaEnvironment::Production->value))->summary(),
        ];
    }

    /**
     * @return list<array{credential_id: int, environment: string, kind: string, status: string, valid_from: string, valid_to: string, days_left: int|null}>
     */
    private function certificates(int $unitId, string $now): array
    {
        $rows = $this->db->table('einvoice_credentials')
            ->select('credential_id, environment, kind, status, valid_from, valid_to')
            ->where('unit_id', $unitId)
            ->orderBy('credential_id', 'DESC')
            ->get()
            ->getResult();

        return array_map(static fn (object $row): array => [
            'credential_id' => (int) $row->credential_id,
            'environment'   => (string) $row->environment,
            'kind'          => (string) $row->kind,
            'status'        => (string) $row->status,
            'valid_from'    => (string) $row->valid_from,
            'valid_to'      => (string) $row->valid_to,
            'days_left'     => $row->valid_to === null ? null : (int) floor(((int) strtotime((string) $row->valid_to) - (int) strtotime($now)) / 86400),
        ], $rows);
    }
}
