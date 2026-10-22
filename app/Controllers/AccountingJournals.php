<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\AccountService;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\CostCenterService;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\JournalStatus;
use App\Libraries\Accounting\PeriodGuard;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\PostingFailureReader;
use App\Libraries\Organization\BranchContext;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

class AccountingJournals extends AccountingController
{
    private const DETAIL_LIMIT = 8;

    private JournalService $journals;

    public function __construct()
    {
        parent::__construct('accounting_journals');

        $this->journals = new JournalService();
    }

    public function getIndex(): string
    {
        $accounts = [];

        foreach ((new AccountService())->all() as $account) {
            $accounts[] = $account;
        }

        $seesBranches = $this->seesBranches();

        return view('accounting_journals/manage', [
            'table_headers'  => transform_headers($this->headers($seesBranches), false, true),
            'accounts'       => $accounts,
            'branches'       => $seesBranches ? (new BranchService())->all($this->employeeId()) : [],
            'current_branch' => $this->branchContext()->current($this->employeeId()),
            'creators'       => $this->can(AccountingGuard::JOURNALS_ALL) ? (new AuditLog())->employees($this->branchIds()) : [],
            'can_draft'      => $this->can(AccountingGuard::JOURNALS_DRAFT),
            'can_post'       => $this->can(AccountingGuard::JOURNALS_POST),
            'can_audit'      => $this->can(AccountingGuard::JOURNALS_AUDIT),
            'notice'         => session()->getFlashdata('accounting_notice'),
        ]);
    }

    public function getSearch(): ResponseInterface
    {
        $limit  = max(1, min((int) $this->request->getGet('limit') ?: self::DEFAULT_PAGE_SIZE, 500));
        $offset = max(0, (int) $this->request->getGet('offset'));

        $result = $this->journals->search(
            $this->filters(),
            (string) $this->request->getGet('sort'),
            (string) $this->request->getGet('order'),
            $limit,
            $offset,
            $this->employeeId(),
        );

        return $this->response->setJSON([
            'total' => $result['total'],
            'rows'  => array_map(fn (object $journal): array => $this->row($journal), $result['rows']),
        ]);
    }

    public function getRow(int $journalId): ResponseInterface
    {
        $journal = $this->findOrFail($journalId);

        return $this->response->setJSON($this->row($journal));
    }

    public function getNew(): string
    {
        $this->requireDraftPermission();

        return $this->form(null);
    }

    public function getEdit(int $journalId): string
    {
        $this->requireDraftPermission();

        $journal = $this->findOrFail($journalId);

        if ($journal->status !== JournalStatus::Draft->value) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->form($journal);
    }

    public function getShow(int $journalId): string
    {
        $journal = $this->findOrFail($journalId);
        $audit   = [];

        if ($this->can(AccountingGuard::JOURNALS_AUDIT)) {
            foreach ((new AuditLog())->search(['entity_type' => 'journal', 'entity_id' => $journalId], 'asc', 100, 0, $this->branchIds())['rows'] as $entry) {
                $audit[] = [
                    'time'     => $this->formatDateTime($entry->created_at),
                    'action'   => $this->label('action', $entry->action),
                    'employee' => (string) $entry->employee_name,
                    'details'  => $this->describe($entry),
                ];
            }
        }

        return view('accounting_journals/view', [
            'journal'      => $journal,
            'audit'        => $audit,
            'currency'     => (new AccountingSettings())->baseCurrency(),
            'can_draft'    => $this->can(AccountingGuard::JOURNALS_DRAFT),
            'can_post'     => $this->can(AccountingGuard::JOURNALS_POST),
            'can_reverse'  => $this->can(AccountingGuard::JOURNALS_REVERSE),
            'notice'       => session()->getFlashdata('accounting_notice'),
            'status_badge' => $this->badge($journal->status),
            'source_label' => $this->label('source', $journal->source_type),
            'number_label' => $this->displayNumber($journal->series, $journal->number),
            'show_branch'  => $this->seesBranches(),
        ]);
    }

    public function getReverseForm(int $journalId): string
    {
        $journal = $this->findOrFail($journalId);

        return view('accounting_journals/reverse_form', ['journal' => $journal]);
    }

    public function postSaveDraft(int $journalId = NEW_ENTRY): ResponseInterface
    {
        return $this->attempt(function () use ($journalId): array {
            $id      = $this->journals->saveDraft($this->input(), $this->employeeId(), $journalId === NEW_ENTRY ? null : $journalId);
            $message = lang('Accounting_journals.draft_saved');
            session()->setFlashdata('accounting_notice', $message);

            return ['success' => true, 'message' => $message, 'id' => $id, 'redirect' => base_url("accounting_journals/edit/{$id}")];
        });
    }

    public function postPost(int $journalId = NEW_ENTRY): ResponseInterface
    {
        return $this->attempt(function () use ($journalId): array {
            $employeeId = $this->employeeId();
            $id         = $journalId === NEW_ENTRY
                ? $this->journals->postNew($this->input(), $employeeId)
                : $this->journals->postDraft($journalId, $employeeId, $this->input());

            $message = lang('Accounting_journals.journal_posted', [$this->displayNumber(...$this->numberOf($id))]);
            session()->setFlashdata('accounting_notice', $message);

            return [
                'success'  => true,
                'message'  => $message,
                'id'       => $id,
                'redirect' => base_url('accounting_journals/view/' . $id),
                'new_url'  => base_url('accounting_journals/new'),
            ];
        });
    }

    public function postPostDrafts(): ResponseInterface
    {
        $ids    = $this->ids();
        $posted = 0;
        $failed = [];

        foreach ($ids as $id) {
            try {
                $this->journals->postDraft($id, $this->employeeId());
                $posted++;
            } catch (AccountingException|BranchException|PostingException $exception) {
                $failed[] = '#' . $id . ': ' . $this->messageFor($exception);
            }
        }

        return $this->response->setJSON([
            'success' => $failed === [],
            'message' => $failed === []
                ? lang('Accounting_journals.drafts_posted', [(string) $posted])
                : lang('Accounting_journals.drafts_post_partial', [(string) $posted, (string) count($failed)]) . ' ' . implode(' ', $failed),
        ]);
    }

    public function postDeleteDrafts(): ResponseInterface
    {
        $ids     = $this->ids();
        $deleted = 0;
        $failed  = [];

        foreach ($ids as $id) {
            try {
                $this->journals->deleteDraft($id, $this->employeeId());
                $deleted++;
            } catch (AccountingException $exception) {
                $failed[] = '#' . $id . ': ' . $this->messageFor($exception);
            }
        }

        return $this->response->setJSON([
            'success' => $failed === [],
            'message' => $failed === []
                ? lang('Accounting_journals.drafts_deleted', [(string) $deleted])
                : lang('Accounting_journals.drafts_delete_partial', [(string) $deleted, (string) count($failed)]) . ' ' . implode(' ', $failed),
        ]);
    }

    public function postDeleteDraft(int $journalId): ResponseInterface
    {
        return $this->attempt(function () use ($journalId): array {
            $this->journals->deleteDraft($journalId, $this->employeeId());
            session()->setFlashdata('accounting_notice', lang('Accounting_journals.drafts_deleted', ['1']));

            return ['success' => true, 'message' => lang('Accounting_journals.drafts_deleted', ['1']), 'id' => $journalId, 'redirect' => base_url('accounting_journals')];
        });
    }

    public function postReverse(int $journalId): ResponseInterface
    {
        return $this->attempt(function () use ($journalId): array {
            $reversalId = $this->journals->reverse(
                $journalId,
                (string) $this->request->getPost('date'),
                (string) $this->request->getPost('reason'),
                $this->employeeId(),
            );

            $message = lang('Accounting_journals.journal_reversed', [$this->displayNumber(...$this->numberOf($reversalId))]);
            session()->setFlashdata('accounting_notice', $message);

            return ['success' => true, 'message' => $message, 'id' => $reversalId, 'redirect' => base_url('accounting_journals/view/' . $reversalId)];
        });
    }

    public function getAudit(): string
    {
        if (! $this->can(AccountingGuard::JOURNALS_AUDIT)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $log          = new AuditLog();
        $seesBranches = $this->seesBranches();

        return view('accounting_journals/audit', [
            'table_headers'  => transform_headers($this->auditHeaders($seesBranches), true, false),
            'actions'        => $log->actions($this->branchIds()),
            'employees'      => $log->employees($this->branchIds()),
            'branches'       => $seesBranches ? (new BranchService())->all($this->employeeId()) : [],
            'current_branch' => $this->branchContext()->current($this->employeeId()),
        ]);
    }

    public function getAuditSearch(): ResponseInterface
    {
        if (! $this->can(AccountingGuard::JOURNALS_AUDIT)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $limit  = max(1, min((int) $this->request->getGet('limit') ?: self::DEFAULT_PAGE_SIZE, 500));
        $offset = max(0, (int) $this->request->getGet('offset'));
        $order  = (string) $this->request->getGet('order');

        $result = (new AuditLog())->search([
            'date_from'   => (string) $this->request->getGet('date_from'),
            'date_to'     => (string) $this->request->getGet('date_to'),
            'action'      => (string) $this->request->getGet('action'),
            'entity_type' => (string) $this->request->getGet('entity_type'),
            'employee_id' => (int) $this->request->getGet('employee_id'),
            'branch_id'   => (int) $this->request->getGet('branch_id'),
        ], $order === 'asc' ? 'asc' : 'desc', $limit, $offset, $this->branchIds());

        $rows = [];

        foreach ($result['rows'] as $entry) {
            $rows[] = [
                'audit_id'   => (int) $entry->audit_id,
                'created_at' => $this->formatDateTime($entry->created_at),
                'action'     => $this->label('action', $entry->action),
                'branch'     => (string) $entry->branch_code,
                'entity'     => $this->entityCell($entry),
                'employee'   => $entry->employee_name ?? '',
                'origin'     => $this->label('origin', $entry->origin),
                'details'    => $this->describe($entry),
            ];
        }

        return $this->response->setJSON(['total' => $result['total'], 'rows' => $rows]);
    }

    public function getFailures(): string
    {
        if (! $this->can(AccountingGuard::JOURNALS_AUDIT)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $seesBranches = $this->seesBranches();

        return view('accounting_journals/failures', [
            'table_headers'  => transform_headers($this->failureHeaders($seesBranches), true, false),
            'sources'        => array_map(fn (string $source): array => ['code' => $source, 'label' => $this->label('source', $source)], (new PostingFailureReader())->sources($this->branchIds())),
            'branches'       => $seesBranches ? (new BranchService())->all($this->employeeId()) : [],
            'current_branch' => $this->branchContext()->current($this->employeeId()),
        ]);
    }

    public function getFailuresSearch(): ResponseInterface
    {
        if (! $this->can(AccountingGuard::JOURNALS_AUDIT)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $limit  = max(1, min((int) $this->request->getGet('limit') ?: self::DEFAULT_PAGE_SIZE, 500));
        $offset = max(0, (int) $this->request->getGet('offset'));
        $order  = (string) $this->request->getGet('order');

        $result = (new PostingFailureReader())->search([
            'date_from'   => (string) $this->request->getGet('date_from'),
            'date_to'     => (string) $this->request->getGet('date_to'),
            'source_type' => (string) $this->request->getGet('source_type'),
            'branch_id'   => (int) $this->request->getGet('branch_id'),
        ], $order === 'asc' ? 'asc' : 'desc', $limit, $offset, $this->branchIds());

        $rows = [];

        foreach ($result['rows'] as $failure) {
            $reason = lang('Accounting.posting_' . $failure->error_code);

            $rows[] = [
                'failure_id' => (int) $failure->failure_id,
                'created_at' => $this->formatDateTime($failure->created_at),
                'source'     => $this->label('source', $failure->source_type) . ($failure->source_id === null ? '' : ' #' . (int) $failure->source_id),
                'branch'     => (string) $failure->branch_code,
                'reason'     => $reason === 'Accounting.posting_' . $failure->error_code ? (string) $failure->message : $reason,
                'employee'   => $failure->employee_name ?? '',
                'outcome'    => lang('Accounting_journals.failure_rolled_back'),
            ];
        }

        return $this->response->setJSON(['total' => $result['total'], 'rows' => $rows]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function headers(bool $withBranch): array
    {
        $branch = $withBranch ? [['branch_code' => lang('Accounting_journals.branch'), 'sortable' => false]] : [];

        return [
            ['number' => lang('Accounting_journals.number')],
            ['journal_date' => lang('Accounting_journals.date')],
            ...$branch,
            ['description'     => lang('Accounting_journals.description'), 'sortable' => false],
            ['source_type'     => lang('Accounting_journals.source'), 'sortable' => false],
            ['total_debit'     => lang('Accounting_journals.total_debit'), 'escape' => false],
            ['total_credit'    => lang('Accounting_journals.total_credit'), 'sortable' => false, 'escape' => false],
            ['status'          => lang('Accounting.status'), 'escape' => false],
            ['created_by_name' => lang('Accounting_journals.created_by'), 'sortable' => false],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function failureHeaders(bool $withBranch): array
    {
        $branch = $withBranch ? [['branch' => lang('Accounting_journals.branch'), 'sortable' => false]] : [];

        return [
            ['created_at' => lang('Accounting_journals.failure_time'), 'sortable' => true],
            ['source' => lang('Accounting_journals.failure_source'), 'sortable' => false],
            ...$branch,
            ['reason'   => lang('Accounting_journals.failure_reason'), 'sortable' => false],
            ['employee' => lang('Accounting_journals.failure_employee'), 'sortable' => false],
            ['outcome'  => lang('Accounting_journals.failure_outcome'), 'sortable' => false],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditHeaders(bool $withBranch): array
    {
        $branch = $withBranch ? [['branch' => lang('Accounting_journals.branch'), 'sortable' => false]] : [];

        return [
            ['created_at' => lang('Accounting_journals.audit_time'), 'sortable' => true],
            ['action' => lang('Accounting_journals.audit_action'), 'sortable' => false],
            ...$branch,
            ['entity'   => lang('Accounting_journals.audit_entity'), 'sortable' => false, 'escape' => false],
            ['employee' => lang('Accounting_journals.audit_employee'), 'sortable' => false],
            ['origin'   => lang('Accounting_journals.audit_origin'), 'sortable' => false],
            ['details'  => lang('Accounting_journals.audit_details'), 'sortable' => false],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(object $journal): array
    {
        $id      = (int) $journal->journal_id;
        $isDraft = $journal->status === JournalStatus::Draft->value;
        $html    = anchor("accounting_journals/view/{$id}", '<span class="bi bi-eye" aria-hidden="true"></span>', ['title' => lang('Accounting_journals.view')]);

        if ($isDraft && $this->can(AccountingGuard::JOURNALS_DRAFT)) {
            $html .= anchor("accounting_journals/edit/{$id}", '<span class="bi bi-pencil-square" aria-hidden="true"></span>', ['title' => lang('Accounting_journals.edit')]);
        }

        return [
            'journal_id'      => $id,
            'number'          => $this->displayNumber($journal->series, $journal->number),
            'journal_date'    => $this->formatDate($journal->journal_date),
            'branch_code'     => (string) $journal->branch_code,
            'description'     => (string) $journal->description,
            'source_type'     => $this->label('source', $journal->source_type),
            'total_debit'     => $this->amountCell($this->formatAmount($journal->total_debit)),
            'total_credit'    => $this->amountCell($this->formatAmount($journal->total_credit)),
            'status'          => $this->badge($journal->status),
            'created_by_name' => (string) $journal->created_by_name,
            'edit'            => $html,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(): array
    {
        $status = $this->request->getGet('status');

        return [
            'account_id'   => (int) $this->request->getGet('account_id'),
            'description'  => (string) $this->request->getGet('description'),
            'number'       => (string) $this->request->getGet('number'),
            'date_from'    => (string) $this->request->getGet('date_from'),
            'date_to'      => (string) $this->request->getGet('date_to'),
            'source_type'  => (string) $this->request->getGet('source_type'),
            'status'       => is_array($status) ? $status : [],
            'amount_from'  => (string) $this->request->getGet('amount_from'),
            'amount_to'    => (string) $this->request->getGet('amount_to'),
            'created_from' => (string) $this->request->getGet('created_from'),
            'created_to'   => (string) $this->request->getGet('created_to'),
            'created_by'   => (int) $this->request->getGet('created_by'),
            'branch_id'    => (int) $this->request->getGet('branch_id'),
        ];
    }

    /**
     * @return array{date: string, description: string, lines: list<array<string, mixed>>, branch_id?: int}
     */
    private function input(): array
    {
        $lines  = $this->request->getPost('lines');
        $branch = (int) $this->request->getPost('branch_id');

        return [
            'date'        => (string) $this->request->getPost('date'),
            'description' => (string) $this->request->getPost('description'),
            'lines'       => is_array($lines) ? array_values(array_filter($lines, 'is_array')) : [],
        ] + ($branch > 0 ? ['branch_id' => $branch] : []);
    }

    /**
     * @return list<int>
     */
    private function ids(): array
    {
        $ids = $this->request->getPost('ids');

        return is_array($ids) ? array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0))) : [];
    }

    private function requireDraftPermission(): void
    {
        if (! $this->can(AccountingGuard::JOURNALS_DRAFT)) {
            throw PageNotFoundException::forPageNotFound();
        }
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function numberOf(int $journalId): array
    {
        $journal = $this->journals->find($journalId, $this->employeeId());

        return [$journal->series, $journal->number];
    }

    private function findOrFail(int $journalId): object
    {
        try {
            $journal = $this->journals->find($journalId, $this->employeeId());

            if ($journal->status === JournalStatus::Preview->value) {
                throw PageNotFoundException::forPageNotFound();
            }

            return $journal;
        } catch (AccountingException $exception) {
            if (in_array($exception->error, [AccountingError::JournalNotFound, AccountingError::PermissionDenied], true)) {
                throw PageNotFoundException::forPageNotFound();
            }

            throw $exception;
        }
    }

    private function form(?object $journal): string
    {
        $costCenters = [];

        foreach ((new CostCenterService())->selectable() as $center) {
            $costCenters[] = $center;
        }

        $branches = $this->seesBranches() ? $this->branchGuard()->selectableBranches($this->employeeId()) : [];
        $branchId = $journal === null ? $this->branchContext()->formDefault($this->employeeId()) : (int) $journal->branch_id;

        return view('accounting_journals/form', [
            'journal'      => $journal,
            'branches'     => $branches,
            'branch_id'    => $branchId,
            'cutoffs'      => $this->cutoffs($branches === [] ? [$branchId] : array_map(static fn (object $branch): int => (int) $branch->branch_id, $branches)),
            'accounts'     => $this->journals->postableAccounts(),
            'cost_centers' => $costCenters,
            'currency'     => (new AccountingSettings())->baseCurrency(),
            'can_post'     => $this->can(AccountingGuard::JOURNALS_POST),
            'notice'       => session()->getFlashdata('accounting_notice'),
        ]);
    }

    /**
     * The date each branch is closed through, so the form can say so and refuse earlier dates.
     *
     * @param list<int> $branchIds
     *
     * @return array<int, array{date: string, label: string}|null>
     */
    private function cutoffs(array $branchIds): array
    {
        $guard   = new PeriodGuard();
        $cutoffs = [];

        foreach ($branchIds as $branchId) {
            $cutoff = $guard->effectiveCutoff($branchId);

            $cutoffs[$branchId] = $cutoff === null ? null : ['date' => $cutoff['date'], 'label' => $this->formatDate($cutoff['date'])];
        }

        return $cutoffs;
    }

    private function branchContext(): BranchContext
    {
        return new BranchContext($this->branchGuard());
    }

    private function entityCell(object $entry): string
    {
        $label = $this->label('entity', $entry->entity_type);
        $id    = $entry->entity_id === null ? '' : ' <bdi dir="ltr">#' . (int) $entry->entity_id . '</bdi>';

        if ($entry->entity_type === 'journal' && $entry->entity_id !== null && $this->can(AccountingGuard::JOURNALS_ALL)) {
            return '<a href="' . esc(base_url('accounting_journals/view/' . (int) $entry->entity_id), 'attr') . '">' . esc($label) . $id . '</a>';
        }

        return esc($label) . $id;
    }

    private function describe(object $entry): string
    {
        $details = $entry->details === null ? [] : json_decode($entry->details, true);

        if (! is_array($details)) {
            return '';
        }

        $parts = [];

        foreach (array_slice(array_filter($details, static fn (mixed $value): bool => $value !== null && $value !== ''), 0, self::DETAIL_LIMIT, true) as $key => $value) {
            $parts[] = $key . ': ' . (is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE));
        }

        return implode(' · ', $parts);
    }
}
