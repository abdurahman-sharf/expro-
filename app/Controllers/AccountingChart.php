<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountService;
use App\Libraries\Accounting\AccountType;
use App\Libraries\Accounting\ChartRowActions;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

class AccountingChart extends AccountingController
{
    private const SORTABLE = ['code', 'name', 'account_type', 'balance'];
    private const PAGE     = 100;
    private const FOUND    = 100;
    private const OPEN_MAX = 500;

    private AccountService $accounts;

    /**
     * @var array{manage: bool, ledger: bool}|null
     */
    private ?array $rights = null;

    public function __construct()
    {
        parent::__construct('accounting_chart');

        $this->accounts = new AccountService();
    }

    public function getIndex(): string
    {
        $selectedId = $this->selectedId();

        return view('accounting_chart/manage', [
            'table_headers' => transform_headers($this->headers(), true, true),
            'selected_id'   => $selectedId,
            'can_manage'    => $this->rights()['manage'],
            'scope_notice'  => $this->scopeNoticeNeeded(),
            'tree'          => $this->context($this->pathTo($selectedId), $selectedId),
        ]);
    }

    public function getNode(int $accountId): string
    {
        $account = $this->accounts->findFlagged($accountId);

        if ($account === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $highlight = (int) $this->request->getGet('highlight');

        return view('accounting_chart/node', [
            'account' => $account,
            'ctx'     => $this->context($this->openRequested() + $this->pathTo($highlight > 0 ? $highlight : null), null, $highlight > 0 ? $highlight : null),
        ]);
    }

    public function getBranch(int $parentId): string
    {
        $parent = $this->accounts->findFlagged($parentId);

        if ($parent === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $offset = max(0, (int) $this->request->getGet('offset'));
        $rows   = $this->accounts->entityChildren($parentId, $offset, self::PAGE);
        $shown  = $offset + count($rows);
        $total  = (int) $parent->entity_child_count;

        return view('accounting_chart/branch', [
            'rows'      => $rows,
            'ctx'       => $this->context([]),
            'parent_id' => $parentId,
            'next'      => $shown < $total ? $shown : null,
            'remaining' => max(0, $total - $shown),
        ]);
    }

    public function getFind(): string
    {
        $term = trim((string) $this->request->getGet('search'));
        $rows = $term === '' ? [] : $this->accounts->search($term, self::FOUND);

        return view('accounting_chart/find', [
            'rows'    => $rows,
            'ctx'     => $this->context([]),
            'limited' => count($rows) === self::FOUND,
        ]);
    }

    public function getSearch(): ResponseInterface
    {
        $term     = trim((string) $this->request->getGet('search'));
        $parentId = $this->selectedId();
        $sort     = in_array($this->request->getGet('sort'), self::SORTABLE, true) ? (string) $this->request->getGet('sort') : 'code';
        $order    = strtolower((string) $this->request->getGet('order')) === 'desc' ? -1 : 1;
        $limit    = max(1, min((int) $this->request->getGet('limit') ?: self::DEFAULT_PAGE_SIZE, 500));
        $offset   = max(0, (int) $this->request->getGet('offset'));

        $balances = $this->accounts->balances($this->branchIds());
        $accounts = match (true) {
            $term !== ''                                    => $this->accounts->search($term, 200),
            (string) $this->request->getGet('flat') === '1' => $this->accounts->structure(),
            default                                         => $this->accounts->children($parentId),
        };

        usort($accounts, fn (object $first, object $second): int => $order * $this->compare($first, $second, $sort, $balances));

        $names = $this->namesById();
        $rows  = [];

        foreach (array_slice($accounts, $offset, $limit) as $account) {
            $rows[] = $this->row($account, $balances[(int) $account->account_id]['balance'] ?? '0.0000', $names, $term !== '');
        }

        return $this->response->setJSON(['total' => count($accounts), 'rows' => $rows]);
    }

    public function getRow(int $accountId): ResponseInterface
    {
        $account = $this->accounts->findFlagged($accountId);

        if ($account === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->response->setJSON($this->row($account, $this->accounts->balances($this->branchIds())[$accountId]['balance'] ?? '0.0000', $this->namesById(), false));
    }

    public function getView(int $accountId = NEW_ENTRY): string
    {
        if (! $this->canManage(AccountingGuard::CHART_MANAGE)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $account  = $accountId === NEW_ENTRY ? null : $this->accountOrFail($accountId);
        $parentId = $account === null ? (int) $this->request->getGet('parent_id') : (int) $account->parent_id;
        $groups   = array_values(array_filter($this->accounts->structure(), static fn (object $item): bool => (int) $item->is_group === 1 && (int) $item->active === 1));
        $main     = $account === null && (string) $this->request->getGet('main') === '1';

        if ($main) {
            $groups = array_values(array_filter($groups, static fn (object $item): bool => $item->parent_id === null));
        }

        $parentIds = array_map(static fn (object $item): int => (int) $item->account_id, $groups);

        if ($account === null && ! in_array($parentId, $parentIds, true) && ($main || $parentId === 0) && $groups !== []) {
            $parentId = (int) $groups[0]->account_id;
        }

        $locked = $account === null && ! $main && (string) $this->request->getGet('lock_parent') === '1' && in_array($parentId, $parentIds, true);

        return view('accounting_chart/form', [
            'account'        => $account,
            'groups'         => $groups,
            'parent_id'      => $parentId,
            'main'           => $main,
            'parent_locked'  => $locked,
            'suggested_code' => $account === null && $parentId > 0 && $this->accounts->find($parentId) !== null ? $this->accounts->suggestCode($parentId) : '',
            'used'           => $account !== null && $this->accounts->isUsed($accountId),
            'contra'         => $account !== null && $account->normal_balance !== AccountType::from($account->account_type)->normalBalance()->value,
            'natural'        => [
                AccountType::Asset->value     => AccountType::Asset->normalBalance()->value,
                AccountType::Liability->value => AccountType::Liability->normalBalance()->value,
                AccountType::Equity->value    => AccountType::Equity->normalBalance()->value,
                AccountType::Revenue->value   => AccountType::Revenue->normalBalance()->value,
                AccountType::Expense->value   => AccountType::Expense->normalBalance()->value,
            ],
        ]);
    }

    public function getSuggestCode(int $parentId): ResponseInterface
    {
        return $this->attempt(fn (): array => ['success' => true, 'code' => $this->accounts->find($parentId) === null ? '' : $this->accounts->suggestCode($parentId)]);
    }

    public function postSave(int $accountId = NEW_ENTRY): ResponseInterface
    {
        return $this->attempt(function () use ($accountId): array {
            $input = [
                'name'   => (string) $this->request->getPost('name'),
                'code'   => (string) $this->request->getPost('code'),
                'contra' => $this->request->getPost('contra'),
            ];

            if ($accountId === NEW_ENTRY) {
                $id = $this->accounts->create($input + [
                    'parent_id' => (int) $this->request->getPost('parent_id'),
                    'is_group'  => $this->request->getPost('is_group'),
                ], $this->employeeId());

                return ['success' => true, 'message' => lang('Accounting_chart.successful_adding'), 'id' => $id];
            }

            $this->accounts->update($accountId, $input, $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_chart.successful_updating'), 'id' => $accountId];
        });
    }

    public function postDeactivate(int $accountId): ResponseInterface
    {
        return $this->attempt(function () use ($accountId): array {
            $this->accounts->deactivate($accountId, $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_chart.successful_deactivating'), 'id' => $accountId];
        });
    }

    public function postActivate(int $accountId): ResponseInterface
    {
        return $this->attempt(function () use ($accountId): array {
            $this->accounts->activate($accountId, $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_chart.successful_activating'), 'id' => $accountId];
        });
    }

    public function postDeleteAccount(int $accountId): ResponseInterface
    {
        return $this->attempt(function () use ($accountId): array {
            $this->accounts->delete($accountId, $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_chart.successful_deleting'), 'id' => $accountId];
        });
    }

    private function selectedId(): ?int
    {
        $id = (int) ($this->request->getGet('parent') ?? $this->request->getGet('parent_id'));

        if ($id <= 0) {
            return null;
        }

        if ($this->accounts->find($id) === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $id;
    }

    private function accountOrFail(int $accountId): object
    {
        $account = $this->accounts->find($accountId);

        if ($account === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $account;
    }

    /**
     * @return array<int, object>
     */
    private function namesById(): array
    {
        $names = [];

        foreach ($this->accounts->structure() as $account) {
            $names[(int) $account->account_id] = $account;
        }

        return $names;
    }

    /**
     * @param array<int, array{debit: string, credit: string, balance: string}> $balances
     */
    private function compare(object $first, object $second, string $sort, array $balances): int
    {
        return match ($sort) {
            'balance'      => bccomp($balances[(int) $first->account_id]['balance'] ?? '0', $balances[(int) $second->account_id]['balance'] ?? '0', 4),
            'name'         => strnatcasecmp((string) $first->name, (string) $second->name),
            'account_type' => strcmp((string) $first->account_type, (string) $second->account_type),
            default        => strnatcmp((string) $first->code, (string) $second->code),
        };
    }

    /**
     * @return list<array<string, string>>
     */
    private function headers(): array
    {
        return [
            ['code' => lang('Accounting_chart.code')],
            ['name'         => lang('Accounting_chart.name'), 'escape' => false],
            ['account_type' => lang('Accounting_chart.type'), 'sortable' => false],
            ['balance'      => lang('Accounting_chart.balance'), 'escape' => false],
            ['status'       => lang('Accounting.status'), 'sortable' => false, 'escape' => false],
        ];
    }

    /**
     * What the tree and its fragments are drawn from: the structure of the chart by parent, the balances, who may do
     * what and which branches are open.
     *
     * @param array<int, true> $open
     *
     * @return array<string, mixed>
     */
    private function context(array $open, ?int $selectedId = null, ?int $highlightId = null): array
    {
        $nodes = [];
        $byId  = [];

        foreach ($this->accounts->structure() as $account) {
            $nodes[$account->parent_id === null ? 0 : (int) $account->parent_id][] = $account;
            $byId[(int) $account->account_id]                                      = $account;
        }

        return [
            'nodes'        => $nodes,
            'by_id'        => $byId,
            'open'         => $open,
            'selected_id'  => $selectedId,
            'highlight_id' => $highlightId,
            'balances'     => $this->accounts->balances($this->branchIds()),
            'can_manage'   => $this->rights()['manage'],
            'can_ledger'   => $this->rights()['ledger'],
        ];
    }

    /**
     * @return array{manage: bool, ledger: bool} Whether the person may change the chart, and whether they may open the account statement.
     */
    private function rights(): array
    {
        return $this->rights ??= [
            'manage' => $this->canManage(AccountingGuard::CHART_MANAGE),
            'ledger' => $this->employee->has_module_grant('accounting_reports', $this->employeeId()),
        ];
    }

    /**
     * The account and the ones above it, which a link to the account leaves open.
     *
     * @return array<int, true>
     */
    private function pathTo(?int $accountId): array
    {
        $path = [];

        for ($current = $accountId; $current !== null && ! isset($path[$current]); $current = ($this->accounts->find($current)?->parent_id) === null ? null : (int) $this->accounts->find($current)->parent_id) {
            $path[$current] = true;
        }

        return $path;
    }

    /**
     * @return array<int, true> The branches the person has open, as the screen sends them.
     */
    private function openRequested(): array
    {
        $open = [];

        foreach (array_slice(explode(',', (string) $this->request->getGet('open')), 0, self::OPEN_MAX) as $id) {
            if ((int) $id > 0) {
                $open[(int) $id] = true;
            }
        }

        return $open;
    }

    /**
     * @param array<int, object> $accounts Every account of the structure by id, to show the parent of a search hit.
     *
     * @return array<string, mixed>
     */
    private function row(object $account, string $balance, array $accounts, bool $showParent): array
    {
        $id       = (int) $account->account_id;
        $name     = esc($account->name);
        $isGroup  = (int) $account->is_group === 1;
        $isSystem = (int) $account->is_system === 1;
        $active   = (int) $account->active === 1;
        $parent   = $account->parent_id !== null ? ($accounts[(int) $account->parent_id] ?? null) : null;

        $status = $this->badge($active ? 'active' : 'inactive') . ($isSystem ? ' ' . $this->badge('system') : '');

        return [
            'account_id' => $id,
            'code'       => $account->code,
            'name'       => ($isGroup ? '<i class="bi bi-folder2" aria-hidden="true"></i> ' : '') . $name
                . ($showParent && $parent !== null ? ' <small class="text-body-secondary">' . esc($parent->name) . '</small>' : ''),
            'account_type' => lang('Accounting.account_type_' . $account->account_type),
            'balance'      => $this->amountCell($this->formatAmount($balance)),
            'status'       => $status,
            'edit'         => view('accounting_chart/actions', [
                'account' => $account,
                'actions' => ChartRowActions::for(
                    $account,
                    $this->rights()['manage'],
                    $this->rights()['ledger'],
                    $parent === null || (int) $parent->active === 1,
                ),
            ]),
        ];
    }
}
