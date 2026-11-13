<?php

namespace App\Libraries\Accounting\Ai;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Models\Appconfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The master switch of the agent layer and the thresholds of its rules. The switch is off until somebody
 * who may change the accounting settings turns it on, and it is read from the database every time, so that
 * turning it off stops the creation of proposals, their approval and the scan at once, in every request. A
 * threshold that was never set has the default written in THRESHOLDS. Every change is in the audit log with
 * what it was before.
 */
final class AiSettings
{
    public const ENABLED_KEY = 'accounting_ai_enabled';
    private const PREFIX     = 'accounting_ai_';

    /**
     * Each threshold: its default, whether it is a whole number or an amount, and the bounds a whole number keeps.
     *
     * @var array<string, array{default: string, type: 'amount'|'days'|'int', min?: int, max?: int}>
     */
    public const THRESHOLDS = [
        'proposal_expiry_days' => ['default' => '14', 'type' => 'int', 'min' => 1, 'max' => 365],
        'lookback_days'        => ['default' => '90', 'type' => 'int', 'min' => 1, 'max' => 730],
        'reclass_min_count'    => ['default' => '2', 'type' => 'int', 'min' => 1, 'max' => 1000],
        'reclass_min_share'    => ['default' => '80', 'type' => 'int', 'min' => 1, 'max' => 100],
        'recurring_months'     => ['default' => '3', 'type' => 'int', 'min' => 2, 'max' => 12],
        'recurring_tolerance'  => ['default' => '10', 'type' => 'int', 'min' => 0, 'max' => 100],
        'weekend_days'         => ['default' => '5,6', 'type' => 'days'],
        'night_start'          => ['default' => '22', 'type' => 'int', 'min' => 0, 'max' => 23],
        'night_end'            => ['default' => '6', 'type' => 'int', 'min' => 0, 'max' => 23],
        'offhours_share'       => ['default' => '10', 'type' => 'int', 'min' => 0, 'max' => 100],
        'offhours_history'     => ['default' => '20', 'type' => 'int', 'min' => 1, 'max' => 100000],
        'round_unit'           => ['default' => '1000', 'type' => 'amount'],
        'round_min'            => ['default' => '10000', 'type' => 'amount'],
        'duplicate_days'       => ['default' => '3', 'type' => 'int', 'min' => 0, 'max' => 90],
        'jump_days'            => ['default' => '30', 'type' => 'int', 'min' => 1, 'max' => 365],
        'jump_percent'         => ['default' => '100', 'type' => 'int', 'min' => 1, 'max' => 100000],
        'jump_min'             => ['default' => '5000', 'type' => 'amount'],
        'reversal_chain'       => ['default' => '3', 'type' => 'int', 'min' => 2, 'max' => 100],
        'backdate_days'        => ['default' => '7', 'type' => 'int', 'min' => 1, 'max' => 365],
        'lock_window_days'     => ['default' => '3', 'type' => 'int', 'min' => 1, 'max' => 90],
    ];

    private const AMOUNT_PATTERN = '/^\d{1,11}(\.\d{1,4})?$/';
    private const DAYS_PATTERN   = '/^[0-6](,[0-6]){0,6}$/';

    private BaseConnection $db;
    private Appconfig $config;
    private AuditLog $audit;
    private AccountingGuard $guard;
    private BranchGuard $branches;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->config   = model(Appconfig::class);
        $this->audit    = new AuditLog($this->db);
        $this->guard    = $guard ?? new AccountingGuard();
        $this->branches = $branches ?? new BranchGuard($this->db);
    }

    /**
     * Whether the agent works: its switch is on and the books are on. Where accounting is off there is nothing for it
     * to look at.
     */
    public function isEnabled(): bool
    {
        return $this->isSwitchedOn() && (new AccountingSettings($this->db))->isEnabled();
    }

    public function isSwitchedOn(): bool
    {
        return $this->config->get_value(self::ENABLED_KEY, '0') === '1';
    }

    /**
     * @throws AccountingException
     */
    public function assertEnabled(): void
    {
        if (! $this->isEnabled()) {
            throw new AccountingException(AccountingError::AiDisabled, 'The agent layer is switched off, or the books are.');
        }
    }

    /**
     * Turning it on needs the permission to change the accounting settings and every branch, as the switch
     * is the company's. Turning it off is the kill switch, so whoever may use the agent screens may do it
     * too, from any branch.
     *
     * @throws AccountingException|BranchException
     */
    public function setEnabled(bool $enabled, ?int $employeeId): void
    {
        if ($enabled) {
            $this->requireManage($employeeId);
        } elseif (! $this->guard->has(AccountingGuard::SETTINGS_MANAGE, $employeeId) && ! $this->guard->has(AccountingGuard::AI_USE, $employeeId)) {
            $this->guard->require(AccountingGuard::AI_USE, $employeeId);
        }

        if ($this->isSwitchedOn() === $enabled) {
            return;
        }

        $this->config->batch_save([self::ENABLED_KEY => $enabled ? '1' : '0']);
        $this->audit->write('ai_settings', null, $enabled ? 'ai_enabled' : 'ai_disabled', $employeeId, $employeeId === null ? JournalOrigin::System : JournalOrigin::User);
    }

    public function value(string $name): string
    {
        $spec = self::THRESHOLDS[$name] ?? null;

        if ($spec === null) {
            throw new AccountingException(AccountingError::AiSettingInvalid, "The threshold {$name} does not exist.", ['name' => $name]);
        }

        $stored = $this->config->get_value(self::PREFIX . $name, $spec['default']);

        return $this->normalized($name, $stored) ?? (string) $this->normalized($name, $spec['default']);
    }

    public function int(string $name): int
    {
        return (int) $this->value($name);
    }

    /**
     * @return list<int>
     */
    public function weekendDays(): array
    {
        $value = $this->value('weekend_days');

        return $value === '' ? [] : array_map('intval', explode(',', $value));
    }

    /**
     * @return array<string, string>
     */
    public function thresholds(): array
    {
        $values = [];

        foreach (array_keys(self::THRESHOLDS) as $name) {
            $values[$name] = $this->value($name);
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $input A threshold that is not given stays as it is.
     *
     * @throws AccountingException|BranchException
     */
    public function update(array $input, ?int $employeeId): void
    {
        $this->requireManage($employeeId);

        $before  = $this->thresholds();
        $changes = [];

        foreach ($input as $name => $raw) {
            if (! is_string($name) || ! isset(self::THRESHOLDS[$name])) {
                throw new AccountingException(AccountingError::AiSettingInvalid, 'The threshold ' . (is_string($name) ? $name : '') . ' does not exist.', ['name' => (string) $name]);
            }

            $value = is_string($raw) || is_int($raw) ? $this->normalized($name, trim((string) $raw)) : null;

            if ($value === null) {
                throw new AccountingException(AccountingError::AiSettingInvalid, "The value of {$name} is not valid.", ['name' => $name]);
            }

            if ($value !== $before[$name]) {
                $changes[$name] = $value;
            }
        }

        if (($changes['night_start'] ?? $before['night_start']) === ($changes['night_end'] ?? $before['night_end'])) {
            throw new AccountingException(AccountingError::AiSettingInvalid, 'The night cannot start and end at the same hour.', ['name' => 'night_start']);
        }

        if ($changes === []) {
            return;
        }

        $this->config->batch_save(array_combine(
            array_map(static fn (string $name): string => self::PREFIX . $name, array_keys($changes)),
            array_values($changes),
        ));

        $this->audit->write('ai_settings', null, 'ai_settings_updated', $employeeId, $employeeId === null ? JournalOrigin::System : JournalOrigin::User, [
            'before' => array_intersect_key($before, $changes),
            'after'  => $changes,
        ]);
    }

    /**
     * @throws AccountingException|BranchException
     */
    private function requireManage(?int $employeeId): void
    {
        $this->guard->require(AccountingGuard::SETTINGS_MANAGE, $employeeId);
        $this->branches->requireCompanyScope($employeeId);
    }

    private function normalized(string $name, string $value): ?string
    {
        $spec = self::THRESHOLDS[$name];

        if ($spec['type'] === 'int') {
            if (preg_match('/^\d{1,9}$/', $value) !== 1 || (int) $value < $spec['min'] || (int) $value > $spec['max']) {
                return null;
            }

            return (string) (int) $value;
        }

        if ($spec['type'] === 'days') {
            if ($value === '') {
                return '';
            }

            $days = array_unique(array_map('intval', explode(',', $value)));
            sort($days);

            return preg_match(self::DAYS_PATTERN, $value) === 1 ? implode(',', $days) : null;
        }

        if (preg_match(self::AMOUNT_PATTERN, $value) !== 1 || bccomp($value, '0', 4) <= 0) {
            return null;
        }

        return bcadd($value, '0', 4);
    }
}
