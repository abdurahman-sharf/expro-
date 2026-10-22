<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use App\Models\Appconfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

final class AccountingSettings
{
    private const ENABLED_KEY       = 'accounting_enabled';
    private const BASE_CURRENCY_KEY = 'accounting_base_currency';
    private const START_DATE_KEY    = 'accounting_start_date';
    private const AUTO_YEAR_KEY     = 'accounting_auto_fiscal_year';

    private BaseConnection $db;
    private Appconfig $config;
    private AuditLog $audit;
    private ?AccountingGuard $guard;
    private ?BranchGuard $branches;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->config   = model(Appconfig::class);
        $this->audit    = new AuditLog($this->db);
        $this->guard    = $guard;
        $this->branches = $branches;
    }

    public function baseCurrency(): string
    {
        $code = $this->config->get_value(self::BASE_CURRENCY_KEY);

        return preg_match('/^[A-Z]{3}$/', $code) === 1 ? $code : '';
    }

    public function isEnabled(): bool
    {
        return $this->config->get_value(self::ENABLED_KEY, '0') === '1';
    }

    public function startDate(): ?string
    {
        $value  = $this->config->get_value(self::START_DATE_KEY);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : null;
    }

    public function autoFiscalYear(): bool
    {
        return $this->config->get_value(self::AUTO_YEAR_KEY, '1') === '1';
    }

    /**
     * The currency and the start date are fixed once a journal has been posted.
     */
    public function isLocked(): bool
    {
        return $this->db->table('accounting_journals')
            ->where('company_id', CompanyContext::id())
            ->whereIn('status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
            ->countAllResults() > 0;
    }

    public function lastManualNumber(): int
    {
        $row = $this->db->table('accounting_sequences')->where('company_id', CompanyContext::id())->where('series', 'manual')->get()->getRow();

        return $row === null ? 0 : (int) $row->last_number;
    }

    /**
     * @param array{base_currency?: string, start_date?: string, auto_fiscal_year?: bool} $input
     *
     * @throws AccountingException|BranchException
     */
    public function update(array $input, ?int $employeeId = null): void
    {
        $this->requireManage($employeeId);

        $before  = ['base_currency' => $this->baseCurrency(), 'start_date' => $this->startDate(), 'auto_fiscal_year' => $this->autoFiscalYear()];
        $after   = $before;
        $changes = [];

        if (array_key_exists('base_currency', $input)) {
            $currency = strtoupper(trim((string) $input['base_currency']));

            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                throw new AccountingException(AccountingError::InvalidCurrency, 'The currency must be a three-letter code.');
            }

            $after['base_currency'] = $currency;
        }

        if (array_key_exists('start_date', $input)) {
            $date   = trim((string) $input['start_date']);
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                throw new AccountingException(AccountingError::InvalidDate, 'The start date must be a valid Y-m-d date.');
            }

            $after['start_date'] = $date;
        }

        if (array_key_exists('auto_fiscal_year', $input)) {
            $after['auto_fiscal_year'] = (bool) $input['auto_fiscal_year'];
        }

        foreach ($after as $key => $value) {
            if ($value !== $before[$key]) {
                $changes[$key] = $value;
            }
        }

        if ($changes === []) {
            return;
        }

        if ((isset($changes['base_currency']) || isset($changes['start_date'])) && $this->isLocked()) {
            throw new AccountingException(AccountingError::SettingsLocked, 'The currency and the start date cannot change once a journal is posted.');
        }

        if (isset($changes['start_date']) && $this->db->table('accounting_fiscal_years')->where('company_id', CompanyContext::id())->countAllResults() > 0 && ! $this->fiscalYearCovers($changes['start_date'])) {
            throw new AccountingException(AccountingError::StartDateOutsideFiscalYear, 'The start date must fall in a fiscal year.', ['date' => $changes['start_date']]);
        }

        $values = [];

        foreach ($changes as $key => $value) {
            $values[match ($key) {
                'base_currency'    => self::BASE_CURRENCY_KEY,
                'start_date'       => self::START_DATE_KEY,
                'auto_fiscal_year' => self::AUTO_YEAR_KEY,
            }] = is_bool($value) ? ($value ? '1' : '0') : $value;
        }

        $this->config->batch_save($values);
        $this->audit->write('settings', null, 'settings_updated', $employeeId, JournalOrigin::User, ['before' => array_intersect_key($before, $changes), 'after' => $changes]);
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

        if ($this->baseCurrency() === '') {
            $issues[] = new ReadinessIssue(ReadinessCode::BaseCurrencyMissing);
        }

        $startDate = $this->startDate();

        if ($startDate === null) {
            $issues[] = new ReadinessIssue(ReadinessCode::StartDateMissing);
        }

        if ($this->db->table('accounting_fiscal_years')->where('company_id', CompanyContext::id())->countAllResults() === 0) {
            $issues[] = new ReadinessIssue(ReadinessCode::NoFiscalYear);
        } elseif ($startDate !== null && ! $this->fiscalYearCovers($startDate)) {
            $issues[] = new ReadinessIssue(ReadinessCode::StartDateOutsideFiscalYear, $startDate);
        }

        return [...$issues, ...$this->routingIssues(), ...(new RoutingService($this->db))->blockingIssues(), ...$this->entityAccountIssues()];
    }

    /**
     * @return list<ReadinessIssue> What is worth knowing before turning accounting on, none of it a reason to wait.
     */
    public function notices(): array
    {
        return (new RoutingService($this->db))->notices($this->startDate());
    }

    /**
     * @throws AccountingNotReadyException
     */
    public function enable(?int $employeeId = null): void
    {
        $this->requireManage($employeeId);

        $issues = $this->readiness();

        if ($issues !== []) {
            throw new AccountingNotReadyException($issues);
        }

        $this->config->save([self::ENABLED_KEY => '1']);
        $this->audit->write('settings', null, 'accounting_enabled', $employeeId, JournalOrigin::User);
    }

    public function disable(?int $employeeId = null): void
    {
        $this->requireManage($employeeId);

        $this->config->save([self::ENABLED_KEY => '0']);
        $this->audit->write('settings', null, 'accounting_disabled', $employeeId, JournalOrigin::User);
    }

    /**
     * @throws AccountingException|BranchException
     */
    private function requireManage(?int $employeeId): void
    {
        $this->guard ??= new AccountingGuard();
        $this->branches ??= new BranchGuard($this->db);

        $this->guard->require(AccountingGuard::SETTINGS_MANAGE, $employeeId);
        $this->branches->requireCompanyScope($employeeId);
    }

    private function fiscalYearCovers(string $date): bool
    {
        return $this->db->table('accounting_fiscal_years')
            ->where('company_id', CompanyContext::id())
            ->where('start_date <=', $date)
            ->where('end_date >=', $date)
            ->countAllResults() > 0;
    }

    /**
     * @return list<ReadinessIssue>
     */
    private function entityAccountIssues(): array
    {
        $missing = array_sum((new EntityAccountService($this->db))->missingCounts());

        return $missing > 0 ? [new ReadinessIssue(ReadinessCode::EntityAccountsMissing, (string) $missing)] : [];
    }

    /**
     * @return list<ReadinessIssue>
     */
    private function routingIssues(): array
    {
        $rows = $this->db->table('accounting_routing r')
            ->select('r.routing_key, r.routing_type, r.account_id, a.is_group, a.active')
            ->join('accounting_accounts a', 'a.account_id = r.account_id', 'left')
            ->get()
            ->getResult();

        $byKey = [];

        foreach ($rows as $row) {
            $byKey[$row->routing_key] = $row;
        }

        $issues = [];

        foreach (RoutingKey::cases() as $key) {
            $row  = $byKey[$key->value] ?? null;
            $type = $row === null ? null : RoutingType::tryFrom((string) $row->routing_type);

            if (($type ?? $key->defaultType()) === RoutingType::Cancelled) {
                continue;
            }

            if ($row === null || $row->account_id === null) {
                if ($key->accountRequired()) {
                    $issues[] = new ReadinessIssue(ReadinessCode::RoutingUnmapped, $key->value);
                }
            } elseif ($row->is_group === null || (int) $row->is_group !== (int) $key->isEntity() || (int) $row->active === 0) {
                $issues[] = new ReadinessIssue(ReadinessCode::RoutingAccountInvalid, $key->value);
            }
        }

        return $issues;
    }
}
