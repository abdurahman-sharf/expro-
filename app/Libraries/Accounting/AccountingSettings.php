<?php

namespace App\Libraries\Accounting;

use App\Models\Appconfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

final class AccountingSettings
{
    private const ENABLED_KEY       = 'accounting_enabled';
    private const BASE_CURRENCY_KEY = 'accounting_base_currency';
    private const START_DATE_KEY    = 'accounting_start_date';

    private BaseConnection $db;
    private Appconfig $config;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db     = $db ?? Database::connect();
        $this->config = model(Appconfig::class);
        $this->audit  = new AuditLog($this->db);
    }

    public function isEnabled(): bool
    {
        return $this->config->get_value(self::ENABLED_KEY, '0') === '1';
    }

    /**
     * @return list<ReadinessIssue>
     */
    public function readiness(): array
    {
        $issues = [];

        if ($this->db->table('accounting_accounts')->countAllResults() === 0) {
            $issues[] = new ReadinessIssue(ReadinessCode::NoAccounts);
        }

        if (preg_match('/^[A-Z]{3}$/', $this->config->get_value(self::BASE_CURRENCY_KEY)) !== 1) {
            $issues[] = new ReadinessIssue(ReadinessCode::BaseCurrencyMissing);
        }

        $startDate = $this->startDate();

        if ($startDate === null) {
            $issues[] = new ReadinessIssue(ReadinessCode::StartDateMissing);
        }

        if ($this->db->table('accounting_fiscal_years')->countAllResults() === 0) {
            $issues[] = new ReadinessIssue(ReadinessCode::NoFiscalYear);
        } elseif ($startDate !== null && ! $this->fiscalYearCovers($startDate)) {
            $issues[] = new ReadinessIssue(ReadinessCode::StartDateOutsideFiscalYear, $startDate);
        }

        return [...$issues, ...$this->routingIssues()];
    }

    /**
     * @throws AccountingNotReadyException
     */
    public function enable(?int $employeeId = null): void
    {
        $issues = $this->readiness();

        if ($issues !== []) {
            throw new AccountingNotReadyException($issues);
        }

        $this->config->save([self::ENABLED_KEY => '1']);
        $this->audit->write('settings', null, 'accounting_enabled', $employeeId, JournalOrigin::User);
    }

    public function disable(?int $employeeId = null): void
    {
        $this->config->save([self::ENABLED_KEY => '0']);
        $this->audit->write('settings', null, 'accounting_disabled', $employeeId, JournalOrigin::User);
    }

    private function startDate(): ?string
    {
        $value  = $this->config->get_value(self::START_DATE_KEY);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : null;
    }

    private function fiscalYearCovers(string $date): bool
    {
        return $this->db->table('accounting_fiscal_years')
            ->where('start_date <=', $date)
            ->where('end_date >=', $date)
            ->countAllResults() > 0;
    }

    /**
     * @return list<ReadinessIssue>
     */
    private function routingIssues(): array
    {
        $rows = $this->db->table('accounting_routing r')
            ->select('r.routing_key, r.account_id, a.is_group, a.active')
            ->join('accounting_accounts a', 'a.account_id = r.account_id', 'left')
            ->get()
            ->getResult();

        $byKey = [];

        foreach ($rows as $row) {
            $byKey[$row->routing_key] = $row;
        }

        $issues = [];

        foreach (RoutingKey::cases() as $key) {
            $row = $byKey[$key->value] ?? null;

            if ($row === null || $row->account_id === null) {
                $issues[] = new ReadinessIssue(ReadinessCode::RoutingUnmapped, $key->value);
            } elseif ($row->is_group === null || (int) $row->is_group === 1 || (int) $row->active === 0) {
                $issues[] = new ReadinessIssue(ReadinessCode::RoutingAccountInvalid, $key->value);
            }
        }

        return $issues;
    }
}
