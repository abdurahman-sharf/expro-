<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use App\Models\Appconfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Throwable;

/**
 * How the lines of the cash flow report are set up, kept as one setting of the company. A line the
 * report starts with is renamed, given other accounts or another formula, and hidden, never deleted;
 * a line added is given a title, a part to be drawn in, accounts and a formula, and is deleted by
 * removing it. A line cannot take from an account of a treasury or a bank, which it would count
 * twice, nor from an account together with an account under it. Saving needs the permission to manage
 * the settings and every branch, because the setting is the company's, and every change is written to
 * the audit log with what it was and what it became, in the same transaction as the setting, so that
 * neither is kept without the other. The setting is locked while it is read and rewritten, and a page
 * that was opened before someone else saved is refused rather than allowed to put the old lines back.
 * A setting that cannot be read is read as the lines the report starts with.
 */
final class CashFlowSettings
{
    use RunsInTransaction;

    public const KEY              = 'accounting_cash_flow_lines';
    public const MAX_CUSTOM_LINES = 20;
    public const TITLE_MAX        = 80;
    public const MAX_BYTES        = 30000;

    private BaseConnection $db;
    private Appconfig $config;
    private AuditLog $audit;
    private ChartLookup $chart;
    private ?AccountingGuard $guard;
    private ?BranchGuard $branches;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null, private readonly int $maxBytes = self::MAX_BYTES)
    {
        $this->db       = $db ?? Database::connect();
        $this->config   = model(Appconfig::class);
        $this->audit    = new AuditLog($this->db);
        $this->chart    = new ChartLookup($this->db);
        $this->guard    = $guard;
        $this->branches = $branches;
    }

    /**
     * @return list<CashFlowLineConfig> The lines the report starts with, as set up, then the lines added.
     */
    public function lines(): array
    {
        return $this->linesOf($this->config->get_value(self::KEY));
    }

    /**
     * What the lines were when they were read, to be sent back with a save so that a page opened before
     * someone else saved cannot put the old lines back.
     */
    public function version(): string
    {
        return sha1($this->config->get_value(self::KEY));
    }

    /**
     * @return list<CashFlowLineConfig>
     */
    private function linesOf(string $text): array
    {
        $stored = $this->stored($text);
        $lines  = [];

        foreach (CashFlowDefaults::lines() as $default) {
            $row     = $stored[$default['key']] ?? [];
            $lines[] = new CashFlowLineConfig(
                $default['key'],
                $default['section'],
                $this->titleOf($row),
                $default['key'] === CashFlowDefaults::NET_INCOME ? [] : $this->idsOf($row),
                $default['key'] === CashFlowDefaults::NET_INCOME ? $default['formula'] : $this->formulaOf($row, $default['formula']),
                ! array_key_exists('visible', $row) || $row['visible'] === true,
                false,
            );
        }

        foreach ($stored as $key => $row) {
            if (($row['custom'] ?? false) !== true || isset(CashFlowDefaults::byKey()[$key])) {
                continue;
            }

            $lines[] = new CashFlowLineConfig(
                (string) $key,
                CashFlowSection::tryFrom((string) ($row['section'] ?? '')) ?? CashFlowSection::Operating,
                $this->titleOf($row),
                $this->idsOf($row),
                $this->formulaOf($row, CashFlowFormula::CreditInPeriod),
                ! array_key_exists('visible', $row) || $row['visible'] === true,
                true,
            );
        }

        return $lines;
    }

    /**
     * The accounts a line can be pointed at when choosing from a list: every account of the chart
     * except the account of a customer, a supplier, a location or a treasury, of which there can be a
     * thousand, and except the accounts of the treasuries and the banks, which a line cannot take
     * from.
     *
     * @return list<object{account_id: int, code: string, name: string, is_group: bool}>
     */
    public function accountChoices(): array
    {
        $cash    = $this->chart->cashAccounts();
        $choices = [];

        foreach ($this->db->table('accounting_accounts')->select('account_id, code, name, is_group')->where('company_id', CompanyContext::id())->where('entity_type IS NULL', null, false)->orderBy('code', 'ASC')->get()->getResult() as $account) {
            if (! isset($cash[(int) $account->account_id])) {
                $choices[] = (object) ['account_id' => (int) $account->account_id, 'code' => (string) $account->code, 'name' => (string) $account->name, 'is_group' => (int) $account->is_group === 1];
            }
        }

        return $choices;
    }

    /**
     * @param array{lines?: mixed} $input   Each line as {key, title, formula, visible, accounts, section, remove}: a key that is empty adds a line, a line that is not named is left as it is.
     * @param string|null          $version What {@see version()} said when the lines were shown, null to save without comparing.
     *
     * @throws AccountingException|BranchException
     */
    public function save(array $input, ?int $employeeId = null, ?string $version = null): void
    {
        $this->guard ??= new AccountingGuard();
        $this->branches ??= new BranchGuard($this->db);

        $this->guard->require(AccountingGuard::SETTINGS_MANAGE, $employeeId);
        $this->branches->requireCompanyScope($employeeId);

        $this->transactional(function () use ($input, $employeeId, $version): void {
            $text = $this->lockedText();

            if ($version !== null && ! hash_equals(sha1($text), $version)) {
                throw new AccountingException(AccountingError::CashFlowLinesChanged, 'The lines changed since they were shown.');
            }

            $before = $this->linesOf($text);
            $after  = $this->applied($before, $input);

            if ($this->arrays($before) === $this->arrays($after)) {
                return;
            }

            $json = json_encode(['lines' => $this->arrays($after)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            if (strlen($json) > $this->maxBytes) {
                throw new AccountingException(AccountingError::CashFlowSetupTooLarge, 'The setup is too large to be kept.', ['max' => $this->maxBytes]);
            }

            $this->config->save([self::KEY => $json]);

            if ($this->config->get_value(self::KEY) !== $json) {
                throw new AccountingException(AccountingError::WriteFailed, 'The lines were not kept as they were written.');
            }

            $this->audit->write('settings', null, 'cash_flow_lines_updated', $employeeId, JournalOrigin::User, ['before' => $this->arrays($before), 'after' => $this->arrays($after)]);
        });
    }

    /**
     * The setting as it stands now, read with its row locked until the transaction ends, so that two
     * saves follow one another and the second starts from what the first left.
     *
     * @throws AccountingException
     */
    private function lockedText(): string
    {
        $row = LockedRead::row($this->db, 'SELECT `value` FROM ' . $this->db->prefixTable('app_config') . ' WHERE `key` = ? FOR UPDATE', [self::KEY]);

        return (string) ($row->value ?? '');
    }

    /**
     * @param list<CashFlowLineConfig> $before
     * @param array{lines?: mixed}     $input
     *
     * @return list<CashFlowLineConfig>
     *
     * @throws AccountingException
     */
    private function applied(array $before, array $input): array
    {
        $next = [];

        foreach ($before as $line) {
            $next[$line->key] = $line;
        }

        $rows = $input['lines'] ?? [];

        if (! is_array($rows)) {
            throw new AccountingException(AccountingError::CashFlowLineInvalid, 'The lines are not a list.', ['name' => '', 'max' => self::MAX_CUSTOM_LINES]);
        }

        $customs = 0;

        foreach ($before as $line) {
            $customs = $line->custom ? max($customs, (int) substr($line->key, 7)) : $customs;
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new AccountingException(AccountingError::CashFlowLineInvalid, 'A line is not a set of values.', ['name' => '', 'max' => self::MAX_CUSTOM_LINES]);
            }

            $row = $this->withoutEmptyAccounts($row);
            $key = is_string($row['key'] ?? '') ? trim((string) ($row['key'] ?? '')) : '';

            if (in_array($key, ['', 'new'], true)) {
                if ($this->postedTitle($row, '') === '' && ($row['accounts'] ?? []) === []) {
                    continue;
                }

                $key = 'custom_' . ++$customs;
            } elseif (! isset($next[$key])) {
                throw new AccountingException(AccountingError::CashFlowLineInvalid, "There is no line {$key}.", ['name' => $key, 'max' => self::MAX_CUSTOM_LINES]);
            }

            $line = $next[$key] ?? new CashFlowLineConfig($key, CashFlowSection::Operating, '', [], CashFlowFormula::CreditInPeriod, true, true);

            if (! empty($row['remove'])) {
                if (! $line->custom) {
                    throw new AccountingException(AccountingError::CashFlowLineInvalid, 'A line the report starts with is hidden, not deleted.', ['name' => $line->title === '' ? $key : $line->title, 'max' => self::MAX_CUSTOM_LINES]);
                }

                unset($next[$key]);

                continue;
            }

            $next[$key] = $this->merged($line, $row);
        }

        $addedCustoms = count(array_filter($next, static fn (CashFlowLineConfig $line): bool => $line->custom));

        if ($addedCustoms > self::MAX_CUSTOM_LINES) {
            throw new AccountingException(AccountingError::CashFlowLineInvalid, 'There are more added lines than the report takes.', ['name' => '', 'max' => self::MAX_CUSTOM_LINES]);
        }

        return $this->ordered(array_values($next));
    }

    /**
     * A browser sends nothing for a list of choices with none chosen, so the form sends an empty
     * choice with every list, and the empty choice is no account.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function withoutEmptyAccounts(array $row): array
    {
        if (isset($row['accounts']) && is_array($row['accounts'])) {
            $row['accounts'] = array_values(array_filter($row['accounts'], static fn (mixed $account): bool => $account !== ''));
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws AccountingException
     */
    private function postedTitle(array $row, string $key): string
    {
        $title = $row['title'] ?? '';

        if (! is_string($title) || ! mb_check_encoding($title, 'UTF-8')) {
            throw new AccountingException(AccountingError::CashFlowLineInvalid, 'The title is not text.', ['name' => $key, 'max' => self::MAX_CUSTOM_LINES]);
        }

        return trim($title);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws AccountingException
     */
    private function merged(CashFlowLineConfig $line, array $row): CashFlowLineConfig
    {
        $title = array_key_exists('title', $row) ? $this->postedTitle($row, $line->key) : $line->title;
        $shown = $title === '' ? $line->key : $title;

        if (mb_strlen($title) > self::TITLE_MAX || ($line->custom && $title === '')) {
            throw new AccountingException(AccountingError::CashFlowLineInvalid, 'The title is empty or too long.', ['name' => $shown, 'max' => self::MAX_CUSTOM_LINES]);
        }

        $formula = $line->formula;

        if (array_key_exists('formula', $row)) {
            $formula = is_string($row['formula']) ? CashFlowFormula::tryFrom($row['formula']) : null;

            if ($formula === null || ($line->key === CashFlowDefaults::NET_INCOME && $formula !== CashFlowFormula::CreditInPeriod)) {
                throw new AccountingException(AccountingError::CashFlowLineInvalid, 'The formula is not one the report knows.', ['name' => $shown, 'max' => self::MAX_CUSTOM_LINES]);
            }
        }

        $section = $line->section;

        if ($line->custom && array_key_exists('section', $row)) {
            $section = is_string($row['section']) ? CashFlowSection::tryFrom($row['section']) : null;

            if ($section === null) {
                throw new AccountingException(AccountingError::CashFlowLineInvalid, 'The part is not one the report draws.', ['name' => $shown, 'max' => self::MAX_CUSTOM_LINES]);
            }
        }

        $accountIds = $line->accountIds;

        if (array_key_exists('accounts', $row)) {
            $accountIds = $this->accountIdsOf($row['accounts'], $shown);

            if ($line->key === CashFlowDefaults::NET_INCOME && $accountIds !== []) {
                throw new AccountingException(AccountingError::CashFlowAccountInvalid, 'The net income takes from every revenue and expense account.', ['name' => $shown]);
            }
        }

        if ($line->custom && $accountIds === []) {
            throw new AccountingException(AccountingError::CashFlowAccountInvalid, 'A line that was added needs an account.', ['name' => $shown]);
        }

        $visible = array_key_exists('visible', $row) ? in_array($row['visible'], [true, 1, '1', 'on'], true) : $line->visible;

        return new CashFlowLineConfig($line->key, $section, $title, $accountIds, $formula, $visible, $line->custom);
    }

    /**
     * @return list<int>
     *
     * @throws AccountingException
     */
    private function accountIdsOf(mixed $value, string $title): array
    {
        if (! is_array($value)) {
            throw new AccountingException(AccountingError::CashFlowAccountInvalid, 'The accounts are not a list.', ['name' => $title]);
        }

        $accounts = $this->chart->accounts();
        $cash     = $this->chart->cashAccounts();
        $ids      = [];

        foreach ($value as $candidate) {
            if ((! is_string($candidate) && ! is_int($candidate)) || ! ctype_digit((string) $candidate) || ! isset($accounts[(int) $candidate]) || isset($cash[(int) $candidate])) {
                throw new AccountingException(AccountingError::CashFlowAccountInvalid, 'An account is not one the line can take from.', ['name' => $title]);
            }

            $ids[(int) $candidate] = (int) $candidate;
        }

        foreach ($ids as $id) {
            $parent = $accounts[$id]->parent_id;
            $seen   = [];

            while ($parent !== null && isset($accounts[$parent]) && ! isset($seen[$parent])) {
                if (isset($ids[$parent])) {
                    throw new AccountingException(AccountingError::CashFlowAccountInvalid, 'An account is chosen together with an account above it.', ['name' => $title]);
                }

                $seen[$parent] = true;
                $parent        = $accounts[$parent]->parent_id;
            }
        }

        return array_values($ids);
    }

    /**
     * @param list<CashFlowLineConfig> $lines
     *
     * @return list<CashFlowLineConfig> The lines the report starts with in their order, then those added in the order they were added.
     */
    private function ordered(array $lines): array
    {
        $rank = array_flip(array_column(CashFlowDefaults::lines(), 'key'));

        usort($lines, static fn (CashFlowLineConfig $a, CashFlowLineConfig $b): int => [$a->custom ? 1 : 0, $rank[$a->key] ?? (int) substr($a->key, 7)] <=> [$b->custom ? 1 : 0, $rank[$b->key] ?? (int) substr($b->key, 7)]);

        return $lines;
    }

    /**
     * @param list<CashFlowLineConfig> $lines
     *
     * @return list<array{key: string, section: string, title: string, accounts: list<int>, formula: string, visible: bool, custom: bool}>
     */
    private function arrays(array $lines): array
    {
        return array_map(static fn (CashFlowLineConfig $line): array => $line->toArray(), $lines);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function stored(string $text): array
    {
        if ($text === '') {
            return [];
        }

        try {
            $decoded = json_decode($text, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        $stored = [];

        foreach (is_array($decoded) && is_array($decoded['lines'] ?? null) ? $decoded['lines'] : [] as $row) {
            if (is_array($row) && is_string($row['key'] ?? null) && preg_match('/^[a-z_0-9]{1,40}$/', $row['key']) === 1) {
                $stored[$row['key']] = $row;
            }
        }

        return $stored;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function titleOf(array $row): string
    {
        return is_string($row['title'] ?? null) ? mb_substr(trim($row['title']), 0, self::TITLE_MAX) : '';
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<int>
     */
    private function idsOf(array $row): array
    {
        $ids = [];

        foreach (is_array($row['accounts'] ?? null) ? $row['accounts'] : [] as $id) {
            if (is_int($id) && $id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function formulaOf(array $row, CashFlowFormula $default): CashFlowFormula
    {
        return is_string($row['formula'] ?? null) ? (CashFlowFormula::tryFrom($row['formula']) ?? $default) : $default;
    }
}
