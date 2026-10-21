<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountService;
use App\Libraries\Accounting\AccountType;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

class AccountingChart extends AccountingController
{
    private const SORTABLE = ['code', 'name', 'account_type', 'balance'];

    private AccountService $accounts;

    public function __construct()
    {
        parent::__construct('accounting_chart');

        $this->accounts = new AccountService();
    }

    public function getIndex(): string
    {
        $parentId = $this->selectedId();

        return view('accounting_chart/manage', [
            'table_headers' => transform_headers($this->headers(), true, true),
            'parent_id'     => $parentId,
            'can_manage'    => $this->can(AccountingGuard::CHART_MANAGE),
            'tree'          => $this->treeData($parentId),
            'panel'         => $this->panelData($parentId),
        ]);
    }

    public function getTree(): string
    {
        return view('accounting_chart/tree', $this->treeData($this->selectedId()));
    }

    public function getPanel(): string
    {
        return view('accounting_chart/panel', $this->panelData($this->selectedId()) + ['can_manage' => $this->can(AccountingGuard::CHART_MANAGE)]);
    }

    public function getSearch(): ResponseInterface
    {
        $term     = trim((string) $this->request->getGet('search'));
        $parentId = $this->selectedId();
        $sort     = in_array($this->request->getGet('sort'), self::SORTABLE, true) ? (string) $this->request->getGet('sort') : 'code';
        $order    = strtolower((string) $this->request->getGet('order')) === 'desc' ? -1 : 1;
        $limit    = max(1, min((int) $this->request->getGet('limit') ?: self::DEFAULT_PAGE_SIZE, 500));
        $offset   = max(0, (int) $this->request->getGet('offset'));

        $balances = $this->accounts->balances();
        $accounts = $term !== '' ? $this->accounts->search($term, 200) : $this->accounts->children($parentId);

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

        return $this->response->setJSON($this->row($account, $this->accounts->balances()[$accountId]['balance'] ?? '0.0000', $this->namesById(), false));
    }

    public function getView(int $accountId = NEW_ENTRY): string
    {
        $account  = $accountId === NEW_ENTRY ? null : $this->accountOrFail($accountId);
        $parentId = $account === null ? (int) $this->request->getGet('parent_id') : (int) $account->parent_id;
        $groups   = array_values(array_filter($this->accounts->all(), static fn (object $item): bool => (int) $item->is_group === 1 && (int) $item->active === 1));

        if ($account === null && $parentId === 0 && $groups !== []) {
            $parentId = (int) $groups[0]->account_id;
        }

        return view('accounting_chart/form', [
            'account'        => $account,
            'groups'         => $groups,
            'parent_id'      => $parentId,
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

        foreach ($this->accounts->all() as $account) {
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
     * @return array{nodes: array<int|string, list<object>>, open: array<int, true>, selected_id: ?int}
     */
    private function treeData(?int $selectedId): array
    {
        $byParent = [];
        $byId     = [];

        foreach ($this->accounts->all() as $account) {
            $byParent[$account->parent_id === null ? 0 : (int) $account->parent_id][] = $account;
            $byId[(int) $account->account_id]                                         = $account;
        }

        $open = [];

        for ($current = $selectedId; $current !== null; $current = isset($byId[$current]) && $byId[$current]->parent_id !== null ? (int) $byId[$current]->parent_id : null) {
            $open[$current] = true;
        }

        return ['nodes' => $byParent, 'open' => $open, 'selected_id' => $selectedId];
    }

    /**
     * @return array{account: ?object, path: list<object>, balance: string}
     */
    private function panelData(?int $selectedId): array
    {
        if ($selectedId === null) {
            return ['account' => null, 'path' => [], 'balance' => '0'];
        }

        $byId = [];

        foreach ($this->accounts->all() as $account) {
            $byId[(int) $account->account_id] = $account;
        }

        $path = [];

        for ($current = $selectedId; $current !== null; $current = $byId[$current]->parent_id === null ? null : (int) $byId[$current]->parent_id) {
            array_unshift($path, $byId[$current]);
        }

        return [
            'account' => $byId[$selectedId],
            'path'    => $path,
            'balance' => $this->accounts->balances()[$selectedId]['balance'] ?? '0.0000',
        ];
    }

    /**
     * @param array<int, object> $accounts Every account by id, to show the parent of a search hit.
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

        $parentName = $account->parent_id !== null && isset($accounts[(int) $account->parent_id]) ? $accounts[(int) $account->parent_id]->name : '';

        $status = $this->badge($active ? 'active' : 'inactive') . ($isSystem ? ' ' . $this->badge('system') : '');

        return [
            'account_id' => $id,
            'code'       => $account->code,
            'name'       => ($isGroup ? '<a href="' . esc(base_url("accounting_chart?parent={$id}"), 'attr') . '"><i class="bi bi-folder2" aria-hidden="true"></i> ' . $name . '</a>' : $name)
                . ($showParent && $parentName !== '' ? ' <small class="text-body-secondary">' . esc($parentName) . '</small>' : ''),
            'account_type' => lang('Accounting.account_type_' . $account->account_type),
            'balance'      => $this->amountCell($this->formatAmount($balance)),
            'status'       => $status,
            'edit'         => $this->actions($account),
        ];
    }

    private function actions(object $account): string
    {
        if (! $this->can(AccountingGuard::CHART_MANAGE)) {
            return '';
        }

        $id       = (int) $account->account_id;
        $active   = (int) $account->active === 1;
        $isSystem = (int) $account->is_system === 1;
        $html     = anchor(
            "accounting_chart/view/{$id}",
            '<span class="bi bi-pencil-square" aria-hidden="true"></span>',
            ['class' => 'modal-dlg', 'data-btn-submit' => lang('Common.submit'), 'title' => lang('Accounting_chart.update')],
        );

        if ($active && ! $isSystem && ! (int) ($account->routed ?? 0)) {
            $html .= '<button type="button" class="btn btn-link p-0 acc-action" data-acc-action="deactivate" data-acc-id="' . $id . '" title="' . esc(lang('Accounting_chart.deactivate'), 'attr') . '" aria-label="' . esc(lang('Accounting_chart.deactivate'), 'attr') . '"><span class="bi bi-slash-circle" aria-hidden="true"></span></button>';
        }

        if (! $active) {
            $html .= '<button type="button" class="btn btn-link p-0 acc-action" data-acc-action="activate" data-acc-id="' . $id . '" title="' . esc(lang('Accounting_chart.activate'), 'attr') . '" aria-label="' . esc(lang('Accounting_chart.activate'), 'attr') . '"><span class="bi bi-check-circle" aria-hidden="true"></span></button>';
        }

        if (! $isSystem && ! (int) ($account->has_children ?? 0) && ! (int) ($account->used ?? 0) && ! (int) ($account->routed ?? 0)) {
            $html .= '<button type="button" class="btn btn-link p-0 acc-action text-danger" data-acc-action="delete" data-acc-id="' . $id . '" title="' . esc(lang('Common.delete'), 'attr') . '" aria-label="' . esc(lang('Common.delete'), 'attr') . '"><span class="bi bi-trash" aria-hidden="true"></span></button>';
        }

        return $html;
    }
}
