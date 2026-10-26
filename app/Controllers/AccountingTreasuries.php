<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Accounting\TreasuryTransfers;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The treasuries and bank accounts with what each holds, the transfers of money between them and
 * the treasury each employee works from. A person limited to some branches sees only the
 * treasuries of those branches, and changes only them.
 */
class AccountingTreasuries extends AccountingController
{
    private const MAX_PAGE_SIZE   = 500;
    private const STATEMENT_ROWS = 200;

    private TreasuryService $treasuries;
    private TreasuryTransfers $transfers;

    public function __construct()
    {
        parent::__construct('accounting_treasuries');

        $this->treasuries = new TreasuryService(null, null, $this->branchGuard());
        $this->transfers  = new TreasuryTransfers(null, null, $this->branchGuard());
    }

    public function getIndex(): string
    {
        return view('accounting_treasuries/manage', [
            'table_headers' => transform_headers($this->headers(), true, true),
            'can_manage'    => $this->can(AccountingGuard::TREASURIES_MANAGE),
            'scope_notice'  => $this->scopeNoticeNeeded(),
        ]);
    }

    public function getSearch(): ResponseInterface
    {
        $term   = mb_strtolower(trim((string) $this->request->getGet('search')));
        $limit  = max(1, min((int) $this->request->getGet('limit') ?: self::DEFAULT_PAGE_SIZE, self::MAX_PAGE_SIZE));
        $offset = max(0, (int) $this->request->getGet('offset'));
        $rows   = $this->treasuries->all($this->branchIds());

        if ($term !== '') {
            $rows = array_values(array_filter($rows, static fn (object $treasury): bool => str_contains(mb_strtolower($treasury->name), $term)));
        }

        return $this->response->setJSON([
            'total' => count($rows),
            'rows'  => array_map(fn (object $treasury): array => $this->row($treasury), array_slice($rows, $offset, $limit)),
        ]);
    }

    public function getRow(int $treasuryId): ResponseInterface
    {
        return $this->response->setJSON($this->row($this->visible($treasuryId)));
    }

    public function getStatement(int $treasuryId): string
    {
        $treasury  = $this->visible($treasuryId);
        $position  = $this->treasuries->position($treasuryId);
        $movements = $this->treasuries->movements($treasuryId);

        return view('accounting_treasuries/statement', [
            'treasury'      => $treasury,
            'balance'       => $position->balance,
            'rows'          => array_slice(array_reverse($movements['rows']), 0, self::STATEMENT_ROWS),
            'total_rows'    => count($movements['rows']),
            'crossings'     => array_reverse($movements['crossings']),
            'lowest'        => $movements['lowest'],
            'journal_links' => $this->can('accounting_journals'),
            'amount'        => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'date'          => fn (string $value): string => $this->formatDate($value),
        ]);
    }

    public function getView(int $treasuryId = NEW_ENTRY): string
    {
        $this->requireManage();

        $treasury = $treasuryId === NEW_ENTRY ? null : $this->visible($treasuryId);

        return view('accounting_treasuries/form', [
            'treasury' => $treasury,
            'branches' => $this->branchGuard()->selectableBranches($this->employeeId()),
            'kinds'    => [TreasuryService::CASH => lang('Accounting_treasuries.kind_cash'), TreasuryService::BANK => lang('Accounting_treasuries.kind_bank')],
        ]);
    }

    public function postSave(int $treasuryId = NEW_ENTRY): ResponseInterface
    {
        return $this->attempt(function () use ($treasuryId): array {
            $input = [
                'name'        => (string) $this->request->getPost('name'),
                'kind'        => (string) $this->request->getPost('kind'),
                'description' => (string) $this->request->getPost('description'),
                'branch_id'   => (int) $this->request->getPost('branch_id'),
            ];

            if ($treasuryId === NEW_ENTRY) {
                return ['success' => true, 'message' => lang('Accounting_treasuries.successful_adding'), 'id' => $this->treasuries->create($input, $this->employeeId())];
            }

            $this->visible($treasuryId);
            $this->treasuries->update($treasuryId, $input, $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_treasuries.successful_updating'), 'id' => $treasuryId];
        });
    }

    public function postDeactivate(int $treasuryId): ResponseInterface
    {
        return $this->attempt(function () use ($treasuryId): array {
            $this->visible($treasuryId);
            $this->treasuries->deactivate($treasuryId, $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_treasuries.successful_deactivating'), 'id' => $treasuryId];
        });
    }

    public function postActivate(int $treasuryId): ResponseInterface
    {
        return $this->attempt(function () use ($treasuryId): array {
            $this->visible($treasuryId);
            $this->treasuries->activate($treasuryId, $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_treasuries.successful_activating'), 'id' => $treasuryId];
        });
    }

    public function postDeleteTreasury(int $treasuryId): ResponseInterface
    {
        return $this->attempt(function () use ($treasuryId): array {
            $this->visible($treasuryId);
            $this->treasuries->delete($treasuryId, $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_treasuries.successful_deleting'), 'id' => $treasuryId];
        });
    }

    public function getTransfers(): string
    {
        return view('accounting_treasuries/transfers', [
            'table_headers' => transform_headers($this->transferHeaders(), true, true),
            'can_manage'    => $this->can(AccountingGuard::TREASURIES_MANAGE),
        ]);
    }

    public function getTransfersSearch(): ResponseInterface
    {
        $limit  = max(1, min((int) $this->request->getGet('limit') ?: self::DEFAULT_PAGE_SIZE, self::MAX_PAGE_SIZE));
        $offset = max(0, (int) $this->request->getGet('offset'));
        $result = $this->transfers->search($this->branchIds(), $limit, $offset);

        return $this->response->setJSON([
            'total' => $result['total'],
            'rows'  => array_map(fn (object $transfer): array => $this->transferRow($transfer), $result['rows']),
        ]);
    }

    public function getTransferRow(int $transferId): ResponseInterface
    {
        $transfer = $this->transfers->find($transferId);

        if ($transfer === null || ! $this->branchGuard()->canAccess((int) $transfer->branch_id, $this->employeeId())) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->response->setJSON($this->transferRow($transfer));
    }

    public function getTransferForm(): string
    {
        $this->requireManage();

        return view('accounting_treasuries/transfer_form', [
            'treasuries' => $this->treasuries->all($this->branchIds(), false),
            'today'      => date('Y-m-d'),
        ]);
    }

    public function postTransfer(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $id = $this->transfers->transfer(
                (int) $this->request->getPost('from_treasury_id'),
                (int) $this->request->getPost('to_treasury_id'),
                trim((string) $this->request->getPost('amount')),
                trim((string) $this->request->getPost('transfer_date')),
                (string) $this->request->getPost('note'),
                $this->employeeId(),
            );

            return ['success' => true, 'message' => lang('Accounting_treasuries.transfer_done'), 'id' => $id];
        });
    }

    public function getCancelTransferForm(int $transferId): string
    {
        $this->requireManage();

        $transfer = $this->transfers->find($transferId);

        if ($transfer === null || ! $this->branchGuard()->canAccess((int) $transfer->branch_id, $this->employeeId())) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('accounting_treasuries/cancel_form', ['transfer' => $transfer]);
    }

    public function postCancelTransfer(int $transferId): ResponseInterface
    {
        return $this->attempt(function () use ($transferId): array {
            $this->transfers->cancel($transferId, (string) $this->request->getPost('reason'), $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_treasuries.transfer_cancelled'), 'id' => $transferId];
        });
    }

    public function getDefaults(): string
    {
        $this->requireManage();

        return view('accounting_treasuries/defaults', [
            'employees'  => $this->treasuries->employees(),
            'treasuries' => $this->treasuries->all($this->branchIds(), false),
        ]);
    }

    public function postDefault(int $personId): ResponseInterface
    {
        return $this->attempt(function () use ($personId): array {
            $treasuryId = (int) $this->request->getPost('treasury_id');

            if ($treasuryId > 0) {
                $this->visible($treasuryId);
            }

            $this->treasuries->setDefaultFor($personId, $treasuryId > 0 ? $treasuryId : null, $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_treasuries.default_saved'), 'id' => $personId];
        });
    }

    private function requireManage(): void
    {
        if (! $this->can(AccountingGuard::TREASURIES_MANAGE)) {
            throw PageNotFoundException::forPageNotFound();
        }
    }

    /**
     * A treasury of a branch the person may see, or a page that is not there.
     */
    private function visible(int $treasuryId): object
    {
        $treasury = $this->treasuries->find($treasuryId);

        if ($treasury === null || ! $this->branchGuard()->canAccess((int) $treasury->branch_id, $this->employeeId())) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $treasury;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function headers(): array
    {
        return [
            ['name' => lang('Accounting_treasuries.name'), 'sortable' => false, 'escape' => false],
            ['kind'        => lang('Accounting_treasuries.kind'), 'sortable' => false],
            ['branch_code' => lang('Accounting_treasuries.branch'), 'sortable' => false],
            ['balance'     => lang('Accounting_treasuries.balance'), 'sortable' => false, 'escape' => false],
            ['by_hand'     => lang('Accounting_treasuries.by_hand'), 'sortable' => false, 'escape' => false],
            ['status'      => lang('Accounting_treasuries.status'), 'sortable' => false, 'escape' => false],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function transferHeaders(): array
    {
        return [
            ['transfer_id' => lang('Accounting_treasuries.transfer_number'), 'sortable' => false],
            ['transfer_date' => lang('Accounting_treasuries.date'), 'sortable' => false],
            ['from_name'     => lang('Accounting_treasuries.from_treasury'), 'sortable' => false],
            ['to_name'       => lang('Accounting_treasuries.to_treasury'), 'sortable' => false],
            ['amount'        => lang('Accounting_treasuries.amount'), 'sortable' => false, 'escape' => false],
            ['branch_code'   => lang('Accounting_treasuries.branch'), 'sortable' => false],
            ['note'          => lang('Accounting_treasuries.note_label'), 'sortable' => false],
            ['status'        => lang('Accounting_treasuries.status'), 'sortable' => false, 'escape' => false],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(object $treasury): array
    {
        $position = $this->treasuries->position((int) $treasury->treasury_id);
        $name     = esc($treasury->name) . ((int) $treasury->is_main === 1 ? ' <span class="badge text-bg-primary">' . esc(lang('Accounting_treasuries.main')) . '</span>' : '');

        return [
            'treasury_id' => (int) $treasury->treasury_id,
            'name'        => $name,
            'kind'        => lang('Accounting_treasuries.kind_' . $treasury->kind),
            'branch_code' => $treasury->branch_code,
            'balance'     => $this->balanceCell($position->balance),
            'by_hand'     => $position->agrees()
                ? '<span class="text-body-secondary">' . esc(lang('Accounting_treasuries.by_hand_none')) . '</span>'
                : '<span class="badge text-bg-warning">' . $this->amountCell($this->formatAmount($position->manual)) . '</span>',
            'status' => $this->badge((int) $treasury->active === 1 ? 'active' : 'inactive'),
            'edit'   => $this->actions($treasury),
        ];
    }

    private function balanceCell(string $balance): string
    {
        $cell = $this->amountCell($this->formatAmount($balance));

        if (bccomp($balance, '0', 4) >= 0) {
            return $cell;
        }

        return '<span class="badge text-bg-danger" title="' . esc(lang('Accounting_treasuries.negative_note'), 'attr') . '">' . esc(lang('Accounting_treasuries.negative')) . '</span> ' . $cell;
    }

    private function actions(object $treasury): string
    {
        $id        = (int) $treasury->treasury_id;
        $statement = anchor(
            "accounting_treasuries/statement/{$id}",
            '<span class="bi bi-journal-text" aria-hidden="true"></span>',
            ['title' => lang('Accounting_treasuries.statement'), 'aria-label' => lang('Accounting_treasuries.statement')],
        );

        if (! $this->can(AccountingGuard::TREASURIES_MANAGE)) {
            return $statement;
        }

        $html = $statement . anchor(
            "accounting_treasuries/view/{$id}",
            '<span class="bi bi-pencil-square" aria-hidden="true"></span>',
            ['class' => 'modal-dlg', 'data-btn-submit' => lang('Common.submit'), 'title' => lang('Accounting_treasuries.update')],
        );

        if ((int) $treasury->is_main === 1) {
            return $html;
        }

        $active = (int) $treasury->active === 1;
        $toggle = $active ? 'deactivate' : 'activate';
        $label  = esc(lang('Accounting_treasuries.confirm_' . $toggle), 'attr');
        $icon   = $active ? 'bi-slash-circle' : 'bi-check-circle';

        $html .= '<button type="button" class="btn btn-link p-0 acc-action" data-acc-action="' . $toggle . '" data-acc-id="' . $id . '" title="' . $label . '" aria-label="' . $label . '"><span class="bi ' . $icon . '" aria-hidden="true"></span></button>';

        $delete = esc(lang('Common.delete'), 'attr');

        return $html . '<button type="button" class="btn btn-link p-0 acc-action text-danger" data-acc-action="delete" data-acc-id="' . $id . '" title="' . $delete . '" aria-label="' . $delete . '"><span class="bi bi-trash" aria-hidden="true"></span></button>';
    }

    /**
     * @return array<string, mixed>
     */
    private function transferRow(object $transfer): array
    {
        $id        = (int) $transfer->transfer_id;
        $cancelled = $transfer->status === 'cancelled';

        $edit = '';

        if (! $cancelled && $this->can(AccountingGuard::TREASURIES_MANAGE)) {
            $edit = anchor(
                "accounting_treasuries/cancel_transfer_form/{$id}",
                '<span class="bi bi-x-circle" aria-hidden="true"></span>',
                ['class' => 'modal-dlg', 'data-btn-submit' => lang('Common.submit'), 'title' => lang('Accounting_treasuries.cancel_transfer')],
            );
        }

        return [
            'transfer_id'   => $id,
            'transfer_date' => $this->formatDate((string) $transfer->transfer_date),
            'from_name'     => $transfer->from_name,
            'to_name'       => $transfer->to_name,
            'amount'        => $this->amountCell($this->formatAmount((string) $transfer->amount)),
            'branch_code'   => $transfer->branch_code,
            'note'          => $transfer->note,
            'status'        => '<span class="badge ' . ($cancelled ? 'text-bg-secondary' : 'text-bg-success') . '">' . esc(lang('Accounting_treasuries.status_' . $transfer->status)) . '</span>',
            'edit'          => $edit,
        ];
    }
}
