<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Models\Appconfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The settings of what a journal line may carry: a distribution over more than 100 percent, a cost
 * center that income and expense lines must have, the default distribution of an account applied to
 * automatic journals, tags and a tax note. All are off until someone turns them on, so a shop that
 * never opens them posts as it always did. A change needs the permission to manage the accounting
 * settings and is written to the audit log with what it was before.
 */
final class JournalSettings
{
    public const OVER_100          = 'accounting_journal_allow_over_100';
    public const CENTER_REQUIRED   = 'accounting_journal_cost_center_required';
    public const AUTOMATIC_DEFAULT = 'accounting_journal_default_distribution_automatic';
    public const LINE_TAGS         = 'accounting_journal_line_tags';
    public const LINE_TAX          = 'accounting_journal_line_tax';

    private const KEYS = [
        'allow_over_100'     => self::OVER_100,
        'cost_center_required' => self::CENTER_REQUIRED,
        'automatic_default'  => self::AUTOMATIC_DEFAULT,
        'line_tags'          => self::LINE_TAGS,
        'line_tax'           => self::LINE_TAX,
    ];

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

    public function allowOver100(): bool
    {
        return $this->flag(self::OVER_100);
    }

    public function costCenterRequired(): bool
    {
        return $this->flag(self::CENTER_REQUIRED);
    }

    public function defaultsOnAutomaticJournals(): bool
    {
        return $this->flag(self::AUTOMATIC_DEFAULT);
    }

    public function lineTags(): bool
    {
        return $this->flag(self::LINE_TAGS);
    }

    public function lineTax(): bool
    {
        return $this->flag(self::LINE_TAX);
    }

    /**
     * @return array{allow_over_100: bool, cost_center_required: bool, automatic_default: bool, line_tags: bool, line_tax: bool}
     */
    public function all(): array
    {
        return [
            'allow_over_100'       => $this->allowOver100(),
            'cost_center_required' => $this->costCenterRequired(),
            'automatic_default'    => $this->defaultsOnAutomaticJournals(),
            'line_tags'            => $this->lineTags(),
            'line_tax'             => $this->lineTax(),
        ];
    }

    /**
     * @param array{allow_over_100?: bool, cost_center_required?: bool, automatic_default?: bool, line_tags?: bool, line_tax?: bool} $input What is left out stays as it is.
     *
     * @throws AccountingException|BranchException
     */
    public function update(array $input, ?int $employeeId = null): void
    {
        $this->guard ??= new AccountingGuard();
        $this->branches ??= new BranchGuard($this->db);

        $this->guard->require(AccountingGuard::SETTINGS_MANAGE, $employeeId);
        $this->branches->requireCompanyScope($employeeId);

        $before = $this->all();
        $after  = $before;

        foreach (array_keys(self::KEYS) as $name) {
            if (array_key_exists($name, $input)) {
                $after[$name] = (bool) $input[$name];
            }
        }

        $changes = array_filter($after, static fn (bool $value, string $name): bool => $value !== $before[$name], ARRAY_FILTER_USE_BOTH);

        if ($changes === []) {
            return;
        }

        $values = [];

        foreach ($changes as $name => $value) {
            $values[self::KEYS[$name]] = $value ? '1' : '0';
        }

        $this->config->batch_save($values);
        $this->audit->write('settings', null, 'settings_updated', $employeeId, JournalOrigin::User, ['before' => array_intersect_key($before, $changes), 'after' => $changes]);
    }

    private function flag(string $key): bool
    {
        return $this->config->get_value($key, '0') === '1';
    }
}
