<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The search behind the pickers of accounts, customers and suppliers. A screen that would list every one of
 * them in a select box asks here whether there are more than the threshold and, if so, offers a search box that
 * asks for at most twenty entries at a time, so that a shop with thousands of customers loads a page as quickly
 * as a small one. An employee limited to some branches is never offered the stock account of a location in
 * another branch, and one with no branch is offered nothing.
 */
final class AccountPicker
{
    public const ACCOUNT  = 'account';
    public const CUSTOMER = 'customer';
    public const SUPPLIER = 'supplier';
    public const KINDS    = [self::ACCOUNT, self::CUSTOMER, self::SUPPLIER];
    public const POSTABLE = 'postable';
    public const OPENING  = 'opening';
    public const EVERY    = 'every';
    public const LEDGER   = 'ledger';
    public const MODES    = [self::POSTABLE, self::OPENING, self::EVERY, self::LEDGER];
    public const LIMIT    = 20;

    private BaseConnection $db;
    private AccountingSettings $settings;

    public function __construct(?BaseConnection $db = null, ?AccountingSettings $settings = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->settings = $settings ?? new AccountingSettings($this->db);
    }

    public function threshold(): int
    {
        return $this->settings->pickerThreshold();
    }

    /**
     * How many entries a screen lists in a select box: up to the threshold while accounting is on, because above
     * it the screen searches, and the default size while accounting is off, when there is no search and the
     * threshold cannot be set.
     */
    public function listLimit(): int
    {
        return $this->settings->isEnabled() ? $this->threshold() : AccountingSettings::DEFAULT_PICKER_SIZE;
    }

    /**
     * Whether the screen should offer a search box: there are more entries than the threshold.
     *
     * @param list<int>|null $branchIds Null is every branch.
     */
    public function searching(string $kind, string $mode, ?array $branchIds): bool
    {
        return $this->count($kind, $mode, $branchIds) > $this->threshold();
    }

    /**
     * @param list<int>|null $branchIds
     */
    public function count(string $kind, string $mode, ?array $branchIds): int
    {
        if ($branchIds === []) {
            return 0;
        }

        return $this->source($kind, $mode, $branchIds)->countAllResults();
    }

    /**
     * @param list<int>|null $branchIds
     *
     * @return array{results: list<array{id: int, code: string, label: string}>, more: bool}
     */
    public function search(string $kind, string $mode, string $term, ?array $branchIds): array
    {
        if ($branchIds === []) {
            return ['results' => [], 'more' => false];
        }

        $builder = $this->source($kind, $mode, $branchIds);
        $term    = trim($term);

        if ($term !== '') {
            $escaped = $this->db->escapeLikeString($term);

            $builder->groupStart();

            foreach ($this->matches($kind, $escaped) as $condition) {
                $builder->orWhere($condition, null, false);
            }

            $builder->groupEnd();
        }

        $rows = $this->select($builder, $kind)->limit(self::LIMIT + 1)->get()->getResult();

        return [
            'results' => array_map(fn (object $row): array => $this->entry($kind, $row), array_slice($rows, 0, self::LIMIT)),
            'more'    => count($rows) > self::LIMIT,
        ];
    }

    /**
     * The entry of the id, or null when it is not one the employee may be offered.
     *
     * @param list<int>|null $branchIds
     *
     * @return array{id: int, code: string, label: string}|null
     */
    public function find(string $kind, string $mode, int $id, ?array $branchIds): ?array
    {
        if ($branchIds === []) {
            return null;
        }

        $row = $this->select($this->source($kind, $mode, $branchIds)->where($this->idColumn($kind), $id), $kind)->limit(1)->get()->getRow();

        return $row === null ? null : $this->entry($kind, $row);
    }

    /**
     * What a screen that lists accounts needs to render: the accounts it lists, or, above the threshold, only
     * the ones it already shows and the settings of the search box that replaces the rest.
     *
     * @param list<int>                $selected   The accounts already on the screen, which keep their names either way.
     * @param list<int>|null           $branchIds
     * @param callable(): list<object> $everything What the screen lists below the threshold.
     *
     * @return array{accounts: list<object>, picker: array{url: string, selector: string, params: array<string, string>, initial: list<array{id: int, code: string, label: string}>, text: array<string, string>}|null}
     */
    public function screen(string $mode, array $selected, ?array $branchIds, string $url, string $selector, callable $everything): array
    {
        if (! $this->searching(self::ACCOUNT, $mode, $branchIds)) {
            return ['accounts' => $everything(), 'picker' => null];
        }

        $accounts = [];

        if ($selected !== []) {
            $accounts = $this->db->table('accounting_accounts')
                ->select('account_id, code, name')
                ->where('company_id', CompanyContext::id())
                ->whereIn('account_id', array_values(array_unique($selected)))
                ->orderBy('code', 'ASC')
                ->get()
                ->getResult();
        }

        return [
            'accounts' => $accounts,
            'picker'   => [
                'url'      => $url,
                'selector' => $selector,
                'params'   => ['kind' => self::ACCOUNT, 'mode' => $mode],
                'initial'  => array_map(static fn (object $account): array => ['id' => (int) $account->account_id, 'code' => (string) $account->code, 'label' => $account->code . ' - ' . $account->name], $accounts),
                'text'     => $this->text(),
            ],
        ];
    }

    /**
     * Whether the kind answers to the mode: the customers of the ledger are a way of listing customers only.
     */
    public static function accepts(string $kind, string $mode): bool
    {
        return in_array($kind, self::KINDS, true) && in_array($mode, self::MODES, true) && ($mode !== self::LEDGER || $kind === self::CUSTOMER);
    }

    /**
     * What a screen that picks a customer or a supplier needs to render: above the threshold, the settings of the
     * search box and the name of the one already chosen, and below it nothing, so that the screen keeps its list.
     * The search is answered by an accounting screen, so while accounting is off the screen keeps its list too.
     * The mode LEDGER offers the customers that have an account in the books, deleted ones included, which is
     * what the filters of the reports have always listed.
     *
     * @return array{url: string, selector: string, params: array<string, string>, initial: list<array{id: int, code: string, label: string}>, text: array<string, string>}|null
     */
    public function partyScreen(string $kind, ?int $selected, string $url, string $selector, string $mode = self::EVERY): ?array
    {
        if (! $this->settings->isEnabled() || ! $this->searching($kind, $mode, null)) {
            return null;
        }

        $entry = $selected === null ? null : $this->find($kind, $mode, $selected, null);

        return [
            'url'      => $url,
            'selector' => $selector,
            'params'   => ['kind' => $kind, 'mode' => $mode],
            'initial'  => $entry === null ? [] : [$entry],
            'text'     => $this->text(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function text(): array
    {
        return [
            'count'  => lang('Accounting_picker.count'),
            'failed' => lang('Accounting_picker.failed'),
            'hint'   => lang('Accounting_picker.hint'),
            'more'   => lang('Accounting_picker.more', [self::LIMIT]),
            'none'   => lang('Accounting_picker.none'),
        ];
    }

    /**
     * @param list<int>|null $branchIds
     */
    private function source(string $kind, string $mode, ?array $branchIds): BaseBuilder
    {
        return match ($kind) {
            self::ACCOUNT  => $this->accounts($mode, $branchIds),
            self::CUSTOMER => $this->customers($mode),
            default        => $this->db->table('suppliers s')->join('people p', 'p.person_id = s.person_id')->where('s.deleted', 0),
        };
    }

    private function customers(string $mode): BaseBuilder
    {
        $builder = $this->db->table('customers c')->join('people p', 'p.person_id = c.person_id');

        if ($mode !== self::LEDGER) {
            return $builder->where('c.deleted', 0);
        }

        $accounts = $this->db->prefixTable('accounting_accounts');

        return $builder->where("EXISTS (SELECT 1 FROM {$accounts} acc WHERE acc.company_id = " . (int) CompanyContext::id() . " AND acc.entity_type = '" . EntityAccountService::CUSTOMER . "' AND acc.entity_id = c.person_id)", null, false);
    }

    /**
     * @param list<int>|null $branchIds
     */
    private function accounts(string $mode, ?array $branchIds): BaseBuilder
    {
        $builder = $mode === self::OPENING
            ? (new OpeningBalanceService($this->db))->allowedAccountsBuilder()
            : $this->db->table('accounting_accounts a')->where('a.company_id', CompanyContext::id());

        if ($mode === self::POSTABLE) {
            $builder->where('a.is_group', 0)->where('a.active', 1);
        }

        if ($branchIds !== null) {
            $locations = $this->db->prefixTable('stock_locations');
            $ids       = implode(',', array_map('intval', $branchIds));

            $builder->where("(a.entity_type IS NULL OR a.entity_type <> '" . EntityAccountService::STOCK_LOCATION . "' OR EXISTS (SELECT 1 FROM {$locations} sl WHERE sl.location_id = a.entity_id AND sl.branch_id IN ({$ids})))", null, false);
        }

        return $builder;
    }

    private function select(BaseBuilder $builder, string $kind): BaseBuilder
    {
        return match ($kind) {
            self::ACCOUNT  => $builder->select('a.account_id AS id, a.code AS code, a.name AS label', false)->orderBy('a.code', 'ASC'),
            self::CUSTOMER => $builder->select("c.person_id AS id, COALESCE(c.account_number, '') AS code, " . $this->personName('c'), false)->orderBy('label', 'ASC')->orderBy('c.person_id', 'ASC'),
            default        => $builder->select("s.person_id AS id, COALESCE(s.account_number, '') AS code, " . $this->personName('s'), false)->orderBy('label', 'ASC')->orderBy('s.person_id', 'ASC'),
        };
    }

    private function personName(string $alias): string
    {
        return "COALESCE(NULLIF({$alias}.company_name, ''), TRIM(CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, '')))) AS label";
    }

    private function idColumn(string $kind): string
    {
        return match ($kind) {
            self::ACCOUNT  => 'a.account_id',
            self::CUSTOMER => 'c.person_id',
            default        => 's.person_id',
        };
    }

    /**
     * @return list<string>
     */
    private function matches(string $kind, string $escaped): array
    {
        $like = static fn (string $column, bool $prefix): string => "{$column} LIKE '" . ($prefix ? '' : '%') . $escaped . "%' ESCAPE '!'";

        return match ($kind) {
            self::ACCOUNT  => [$like('a.code', true), $like('a.name', false)],
            self::CUSTOMER => [$like('c.company_name', false), $like('p.first_name', false), $like('p.last_name', false), $like("CONCAT(p.first_name, ' ', p.last_name)", false), $like('c.account_number', true)],
            default        => [$like('s.company_name', false), $like('p.first_name', false), $like('p.last_name', false), $like("CONCAT(p.first_name, ' ', p.last_name)", false), $like('s.account_number', true)],
        };
    }

    /**
     * @return array{id: int, code: string, label: string}
     */
    private function entry(string $kind, object $row): array
    {
        return [
            'id'    => (int) $row->id,
            'code'  => (string) $row->code,
            'label' => $kind === self::ACCOUNT ? $row->code . ' - ' . $row->label : (string) $row->label,
        ];
    }
}
