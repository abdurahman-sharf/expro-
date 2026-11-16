<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use App\Models\Appconfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use NumberFormatter;
use Throwable;

/**
 * Turns accounting on as one run the manager asks for and confirms: the currency and the start date they chose, the
 * first fiscal years, the accounts every customer, supplier and stock location still lacks, and the switch itself.
 * Each step can be repeated without a second effect, and the run is kept in the settings after every step, so a
 * connection that drops leaves a run that the next request carries on. Only one request works on a run at a time.
 * Success is told only after the books were read back and hold what the manager confirmed.
 */
final class AccountingActivation
{
    use RunsInTransaction;

    public const RUNNING           = 'running';
    public const FAILED            = 'failed';
    public const DONE              = 'done';
    public const STEP_YEARS        = 'years';
    public const STEP_SETTINGS     = 'settings';
    public const STEP_ENTITIES     = 'entities';
    public const STEP_COMMIT       = 'commit';
    public const STEP_VERIFY       = 'verify';
    public const ACK_IMPACT        = 'impact';
    public const ACK_DOCUMENTS     = 'documents_from_start';
    public const ACK_PAYMENT_TYPES = 'payment_types';
    public const STEP_SECONDS      = 8.0;
    public const MAX_ENTITY_SWEEPS = 3;
    private const STATE_KEY        = 'accounting_activation';
    private const SWEEP_SECONDS    = 20.0;

    /**
     * What the run settles by itself. Anything else the readiness check reports needs a person.
     *
     * @var list<ReadinessCode>
     */
    private const AUTOMATIC = [
        ReadinessCode::BaseCurrencyMissing,
        ReadinessCode::StartDateMissing,
        ReadinessCode::NoFiscalYear,
        ReadinessCode::StartDateOutsideFiscalYear,
        ReadinessCode::EntityAccountsMissing,
    ];

    /**
     * The documents a start date divides into those before it and those from it: the table, its date column, and what
     * makes a row a document of the books.
     *
     * @var array<string, array{table: string, column: string, where: array<string, int>}>
     */
    private const DOCUMENTS = [
        'sales'      => ['table' => 'sales', 'column' => 'sale_time', 'where' => ['sale_status' => COMPLETED]],
        'receivings' => ['table' => 'receivings', 'column' => 'receiving_time', 'where' => []],
        'expenses'   => ['table' => 'expenses', 'column' => 'date', 'where' => ['deleted' => 0]],
        'cashups'    => ['table' => 'cash_up', 'column' => 'open_date', 'where' => ['deleted' => 0]],
    ];

    private BaseConnection $db;
    private AccountingSettings $settings;
    private FiscalYearService $years;
    private EntityAccountService $entities;
    private Appconfig $config;
    private AuditLog $audit;
    private AccountingGuard $guard;
    private BranchGuard $branches;

    public function __construct(
        ?BaseConnection $db = null,
        ?AccountingSettings $settings = null,
        ?FiscalYearService $years = null,
        ?EntityAccountService $entities = null,
        ?AccountingGuard $guard = null,
        ?BranchGuard $branches = null,
    ) {
        $this->db       = $db ?? Database::connect();
        $this->settings = $settings ?? new AccountingSettings($this->db);
        $this->years    = $years ?? new FiscalYearService($this->db);
        $this->entities = $entities ?? new EntityAccountService($this->db);
        $this->config   = model(Appconfig::class);
        $this->audit    = new AuditLog($this->db);
        $this->guard    = $guard ?? new AccountingGuard();
        $this->branches = $branches ?? new BranchGuard($this->db);
    }

    /**
     * What the manager is shown before anything is asked of them.
     *
     * @return array{enabled: bool, locked: bool, currency: string, currency_source: string, start_date: string, entities_missing: int, automatic: list<ReadinessIssue>, blockers: list<ReadinessIssue>, payment_types: list<string>, impact: array{before: array<string, int>, from: array<string, int>}, has_documents: bool}
     */
    public function inspect(): array
    {
        $automatic = [];
        $blockers  = [];

        foreach ($this->settings->readiness() as $issue) {
            if (in_array($issue->code, self::AUTOMATIC, true)) {
                $automatic[] = $issue;
            } else {
                $blockers[] = $issue;
            }
        }

        $currency = $this->currencySuggestion();
        $date     = $this->suggestStartDate();

        return [
            'enabled'          => $this->settings->isEnabled(),
            'locked'           => $this->settings->isLocked(),
            'currency'         => $currency['code'],
            'currency_source'  => $currency['source'],
            'start_date'       => $date,
            'entities_missing' => array_sum($this->entities->missingCounts()),
            'automatic'        => $automatic,
            'blockers'         => $blockers,
            'payment_types'    => $this->unknownPaymentTypes(),
            'impact'           => $this->impactFor($date),
            'has_documents'    => $this->hasDocuments(),
        ];
    }

    /**
     * The date the books are suggested to start on: the one already chosen, else the first day of this month for a
     * database with no document, and today for a database that has some, because what was written before the books
     * began cannot be posted afterwards and is carried in by the opening balances.
     */
    public function suggestStartDate(): string
    {
        $configured = $this->settings->startDate();

        if ($configured !== null) {
            return $configured;
        }

        return $this->hasDocuments() ? date('Y-m-d') : date('Y-m-01');
    }

    /**
     * How many documents lie before a start date and how many from it on, by kind.
     *
     * @return array{before: array<string, int>, from: array<string, int>}
     *
     * @throws AccountingException
     */
    public function impactFor(string $date): array
    {
        $this->assertDate($date);

        $before = [];
        $from   = [];

        foreach (self::DOCUMENTS as $kind => $source) {
            $before[$kind] = $this->countDocuments($source, '<', $date);
            $from[$kind]   = $this->countDocuments($source, '>=', $date);
        }

        return ['before' => $before, 'from' => $from];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function status(): ?array
    {
        $state = $this->state();

        return $state === null ? null : $this->summary($state);
    }

    /**
     * Records what the manager confirmed and opens the run. It refuses what cannot be settled by the run itself, and
     * what the manager has not acknowledged.
     *
     * @param list<string> $acknowledged
     *
     * @return array<string, mixed>
     *
     * @throws AccountingException|AccountingNotReadyException|BranchException
     */
    public function begin(int $employeeId, string $currency, string $startDate, array $acknowledged): array
    {
        $this->requireManage($employeeId);

        if ($this->settings->isEnabled()) {
            throw new AccountingException(AccountingError::ActivationAlreadyOn, 'Accounting is already on.');
        }

        $currency = strtoupper(trim($currency));

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new AccountingException(AccountingError::InvalidCurrency, 'The currency must be a three-letter code.');
        }

        $this->assertDate($startDate);
        $this->assertStartDateUsable($startDate);

        $inspection = $this->inspect();

        if ($inspection['blockers'] !== []) {
            throw new AccountingNotReadyException($inspection['blockers']);
        }

        $missing = $this->unacknowledged($startDate, $acknowledged, $inspection['payment_types']);

        if ($missing !== []) {
            throw new AccountingException(AccountingError::ActivationUnconfirmed, 'What turning accounting on means has not been confirmed.', ['name' => implode(', ', $missing)]);
        }

        $lock  = new NamedLock($this->db, 0);
        $taken = $lock->take($this->lockName());

        if ($taken !== LockOutcome::Taken) {
            throw new AccountingException(AccountingError::ActivationBusy, 'Another run of turning accounting on is working.', ['lock' => $taken->value]);
        }

        try {
            $now   = date('Y-m-d H:i:s');
            $state = [
                'run_id'           => bin2hex(random_bytes(8)),
                'status'           => self::RUNNING,
                'step'             => self::STEP_YEARS,
                'currency'         => $currency,
                'start_date'       => $startDate,
                'acknowledged'     => array_values(array_unique($acknowledged)),
                'started_by'       => $employeeId,
                'started_at'       => $now,
                'updated_at'       => $now,
                'entities_total'   => $inspection['entities_missing'],
                'entities_created' => 0,
                'sweeps'           => 0,
                'error'            => null,
                'error_name'       => null,
            ];

            $this->saveState($state);
            $this->audit->write('settings', null, 'accounting_activation_started', $employeeId, JournalOrigin::User, ['run' => $state['run_id'], 'currency' => $currency, 'start_date' => $startDate, 'acknowledged' => $state['acknowledged']]);
        } finally {
            $lock->release($this->lockName());
        }

        return $this->summary($state);
    }

    /**
     * Works on the run until the time given is spent or the run ends, and says where it stands. A run that failed is
     * carried on from the step that failed.
     *
     * @return array<string, mixed>
     *
     * @throws AccountingException|AccountingNotReadyException|BranchException
     */
    public function step(string $runId, int $employeeId, float $seconds = self::STEP_SECONDS): array
    {
        $this->requireManage($employeeId);
        $this->requireRun($runId);

        $lock  = new NamedLock($this->db, 0);
        $taken = $lock->take($this->lockName());

        if ($taken !== LockOutcome::Taken) {
            throw new AccountingException(AccountingError::ActivationBusy, 'Another request is working on turning accounting on.', ['lock' => $taken->value]);
        }

        $state = $this->requireRun($runId);

        try {
            if ($state['status'] === self::DONE) {
                return $this->summary($state);
            }

            $state['status'] = self::RUNNING;
            $state['error']  = null;
            $deadline        = microtime(true) + $seconds;

            while ($state['status'] === self::RUNNING) {
                $state = $this->advance($state, $employeeId, max(0.0, $deadline - microtime(true)));
                $this->saveState($state);

                if (microtime(true) >= $deadline) {
                    break;
                }
            }
        } catch (Throwable $failure) {
            $this->recordFailure($state, $employeeId, $failure);

            throw $failure;
        } finally {
            $lock->release($this->lockName());
        }

        return $this->summary($state);
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private function advance(array $state, int $employeeId, float $seconds): array
    {
        switch ($state['step']) {
            case self::STEP_YEARS:
                $this->ensureYears($state['start_date'], $employeeId);
                $state['step'] = self::STEP_SETTINGS;

                return $state;

            case self::STEP_SETTINGS:
                $this->settings->update(['base_currency' => $state['currency'], 'start_date' => $state['start_date']], $employeeId);
                $state['step'] = self::STEP_ENTITIES;

                return $state;

            case self::STEP_ENTITIES:
                $result                    = $this->entities->fillWithin($seconds, $employeeId);
                $state['entities_created'] = (int) $state['entities_created'] + array_sum($result['created']);

                if ($result['remaining'] === 0) {
                    $state['step'] = self::STEP_COMMIT;
                }

                return $state;

            case self::STEP_COMMIT:
                return $this->commit($state, $employeeId);

            default:
                $this->verify($state, $employeeId);

                $state['status'] = self::DONE;

                $this->audit->write('settings', null, 'accounting_activated', $employeeId, JournalOrigin::User, ['run' => $state['run_id'], 'currency' => $state['currency'], 'start_date' => $state['start_date'], 'entities_opened' => $state['entities_created']]);

                return $state;
        }
    }

    /**
     * The switch and the years after the first are written together, and the readiness is asked again inside the
     * same transaction, so the books never say "on" while something they need is missing. An entity that appeared
     * while the accounts were being opened sends the run back to opening them.
     *
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private function commit(array $state, int $employeeId): array
    {
        $through = (max((int) substr($state['start_date'], 0, 4), (int) date('Y')) + 1) . '-12-31';

        try {
            $this->transactional(function () use ($through, $employeeId): void {
                $this->years->createThrough($through);
                $this->settings->enable($employeeId);
            });
        } catch (AccountingNotReadyException $notReady) {
            $onlyEntities = array_filter($notReady->issues, static fn (ReadinessIssue $issue): bool => $issue->code !== ReadinessCode::EntityAccountsMissing) === [];

            if (! $onlyEntities || (int) $state['sweeps'] >= self::MAX_ENTITY_SWEEPS) {
                throw $notReady;
            }

            $state['sweeps'] = (int) $state['sweeps'] + 1;
            $state['step']   = self::STEP_ENTITIES;

            return $state;
        }

        $state['step'] = self::STEP_VERIFY;

        return $state;
    }

    /**
     * Reads the books back from the database, not from what the request holds, and asks for every thing the manager
     * confirmed. A customer added while the switch was being turned is given the account it lacks first.
     *
     * @param array<string, mixed> $state
     *
     * @throws AccountingException
     */
    private function verify(array $state, int $employeeId): void
    {
        $stored = fn (string $key): string => (string) ($this->db->table('app_config')->where('key', $key)->get()->getRow()->value ?? '');

        if ($stored('accounting_enabled') !== '1') {
            $this->verificationFailed('enabled');
        }

        if ($stored('accounting_base_currency') !== $state['currency']) {
            $this->verificationFailed('currency');
        }

        if ($stored('accounting_start_date') !== $state['start_date']) {
            $this->verificationFailed('start_date');
        }

        if ($this->years->covering($state['start_date']) === null) {
            $this->verificationFailed('fiscal_year');
        }

        if (array_sum($this->entities->missingCounts()) > 0) {
            $this->entities->fillWithin(self::SWEEP_SECONDS, $employeeId);

            if (array_sum($this->entities->missingCounts()) > 0) {
                $this->verificationFailed('entity_accounts');
            }
        }

        if ($this->settings->readiness() !== []) {
            $this->verificationFailed('readiness');
        }
    }

    /**
     * @throws AccountingException
     */
    private function verificationFailed(string $check): never
    {
        throw new AccountingException(AccountingError::ActivationVerification, 'Accounting was turned on, but the books do not hold what was confirmed.', ['name' => $check]);
    }

    private function ensureYears(string $startDate, int $employeeId): void
    {
        if ($this->years->covering($startDate) !== null) {
            return;
        }

        if ($this->firstYearStart() === null) {
            $this->years->createFirst($startDate, $employeeId);

            return;
        }

        $this->years->createThrough($startDate);

        if ($this->years->covering($startDate) === null) {
            throw new AccountingException(AccountingError::StartDateOutsideFiscalYear, 'The start date must fall in a fiscal year.', ['date' => $startDate]);
        }
    }

    /**
     * A start date before the first fiscal year cannot be given a year by this run, which only follows the last one.
     *
     * @throws AccountingException
     */
    private function assertStartDateUsable(string $startDate): void
    {
        $first = $this->firstYearStart();

        if ($first !== null && $startDate < $first) {
            throw new AccountingException(AccountingError::StartDateOutsideFiscalYear, 'The start date must fall in a fiscal year.', ['date' => $startDate]);
        }
    }

    private function firstYearStart(): ?string
    {
        $row = $this->db->table('accounting_fiscal_years')->where('company_id', CompanyContext::id())->orderBy('start_date', 'ASC')->limit(1)->get()->getRow();

        return $row === null ? null : (string) $row->start_date;
    }

    /**
     * @param list<string> $acknowledged
     * @param list<string> $paymentTypes
     *
     * @return list<string>
     */
    private function unacknowledged(string $startDate, array $acknowledged, array $paymentTypes): array
    {
        $required = [self::ACK_IMPACT];

        if (array_sum($this->impactFor($startDate)['from']) > 0) {
            $required[] = self::ACK_DOCUMENTS;
        }

        if ($paymentTypes !== []) {
            $required[] = self::ACK_PAYMENT_TYPES;
        }

        return array_values(array_diff($required, $acknowledged));
    }

    /**
     * @return list<string>
     */
    private function unknownPaymentTypes(): array
    {
        $names = [];

        foreach ($this->settings->notices() as $notice) {
            if ($notice->code === ReadinessCode::PaymentTypeUnknown && $notice->subject !== null) {
                $names[] = $notice->subject;
            }
        }

        return $names;
    }

    /**
     * @return array{code: string, source: string}
     */
    private function currencySuggestion(): array
    {
        $saved = $this->settings->baseCurrency();

        if ($saved !== '') {
            return ['code' => $saved, 'source' => 'saved'];
        }

        $configured = strtoupper(trim($this->config->get_value('currency_code')));

        if (preg_match('/^[A-Z]{3}$/', $configured) === 1) {
            return ['code' => $configured, 'source' => 'configured'];
        }

        $locale = $this->config->get_value('number_locale');

        if ($locale !== '' && class_exists(NumberFormatter::class)) {
            $guessed = (new NumberFormatter($locale, NumberFormatter::CURRENCY))->getTextAttribute(NumberFormatter::CURRENCY_CODE);

            if (is_string($guessed) && preg_match('/^[A-Z]{3}$/', $guessed) === 1) {
                return ['code' => $guessed, 'source' => 'guessed'];
            }
        }

        return ['code' => '', 'source' => 'none'];
    }

    private function hasDocuments(): bool
    {
        foreach (self::DOCUMENTS as $source) {
            $builder = $this->db->table($source['table']);

            foreach ($source['where'] as $column => $value) {
                $builder->where($column, $value);
            }

            if ($builder->countAllResults() > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{table: string, column: string, where: array<string, int>} $source
     */
    private function countDocuments(array $source, string $operator, string $date): int
    {
        $builder = $this->db->table($source['table'])->where("{$source['column']} {$operator}", $date . ' 00:00:00');

        foreach ($source['where'] as $column => $value) {
            $builder->where($column, $value);
        }

        return $builder->countAllResults();
    }

    /**
     * @throws AccountingException
     */
    private function assertDate(string $date): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new AccountingException(AccountingError::InvalidDate, 'The date must be a valid Y-m-d date.');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function state(): ?array
    {
        $json = $this->config->get_value(self::STATE_KEY);

        if ($json === '') {
            return null;
        }

        $state = json_decode($json, true);

        return is_array($state) && isset($state['run_id'], $state['status'], $state['step']) ? $state : null;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AccountingException
     */
    private function requireRun(string $runId): array
    {
        $state = $this->state();

        if ($state === null || ! hash_equals((string) $state['run_id'], $runId)) {
            throw new AccountingException(AccountingError::ActivationUnknown, 'There is no such run of turning accounting on.');
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function saveState(array $state): void
    {
        $state['updated_at'] = date('Y-m-d H:i:s');

        $this->config->save([self::STATE_KEY => json_encode($state, JSON_UNESCAPED_UNICODE)]);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function recordFailure(array $state, int $employeeId, Throwable $failure): void
    {
        if ($failure instanceof AccountingException) {
            $state['error']      = $failure->error->value;
            $state['error_name'] = isset($failure->context['name']) ? (string) $failure->context['name'] : null;
        } elseif ($failure instanceof AccountingNotReadyException) {
            $state['error']      = 'not_ready';
            $state['error_name'] = implode(', ', array_map(static fn (ReadinessIssue $issue): string => $issue->code->value, $failure->issues));
        } else {
            $state['error']      = 'unexpected';
            $state['error_name'] = null;
            log_message('error', 'Turning accounting on stopped: ' . $failure->getMessage());
        }

        $state['status'] = self::FAILED;

        $this->saveState($state);
        $this->audit->write('settings', null, 'accounting_activation_failed', $employeeId, JournalOrigin::User, ['run' => $state['run_id'], 'step' => $state['step'], 'error' => $state['error'], 'name' => $state['error_name']]);
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private function summary(array $state): array
    {
        return [
            'run_id'           => $state['run_id'],
            'status'           => $state['status'],
            'step'             => $state['step'],
            'currency'         => $state['currency'],
            'start_date'       => $state['start_date'],
            'entities_total'   => (int) $state['entities_total'],
            'entities_created' => (int) $state['entities_created'],
            'error'            => $state['error'],
            'error_name'       => $state['error_name'],
            'done'             => $state['status'] === self::DONE,
        ];
    }

    private function lockName(): string
    {
        return 'ospos_activation_' . substr(sha1($this->db->getDatabase()), 0, 10);
    }

    /**
     * @throws AccountingException|BranchException
     */
    private function requireManage(int $employeeId): void
    {
        $this->guard->require(AccountingGuard::SETTINGS_MANAGE, $employeeId);
        $this->branches->requireCompanyScope($employeeId);
    }
}
