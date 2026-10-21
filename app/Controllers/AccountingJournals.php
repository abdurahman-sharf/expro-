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
use App\Libraries\Accounting\PostingException;
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

        return view('accounting_journals/manage', [
            'table_headers' => transform_headers($this->headers(), false, true),
            'accounts'      => $accounts,
            'creators'      => $this->can(AccountingGuard::JOURNALS_ALL) ? (new AuditLog())->employees() : [],
            'can_draft'     => $this->can(AccountingGuard::JOURNALS_DRAFT),
            'can_post'      => $this->can(AccountingGuard::JOURNALS_POST),
            'can_audit'     => $this->can(AccountingGuard::JOURNALS_AUDIT),
            'notice'        => session()->getFlashdata('accounting_notice'),
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
            foreach ((new AuditLog())->search(['entity_type' => 'journal', 'entity_id' => $journalId], 'asc', 100)['rows'] as $entry) {
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

            $message = lang('Accounting_journals.journal_posted', [$this->journals->find($id, $employeeId)->number]);
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
            } catch (AccountingException|PostingException $exception) {
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

            $message = lang('Accounting_journals.journal_reversed', [$this->journals->find($reversalId, $this->employeeId())->number]);
            session()->setFlashdata('accounting_notice', $message);

            return ['success' => true, 'message' => $message, 'id' => $reversalId, 'redirect' => base_url('accounting_journals/view/' . $reversalId)];
        });
    }

    public function getAudit(): string
    {
        if (! $this->can(AccountingGuard::JOURNALS_AUDIT)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $log = new AuditLog();

        return view('accounting_journals/audit', [
            'table_headers' => transform_headers($this->auditHeaders(), true, false),
            'actions'       => $log->actions(),
            'employees'     => $log->employees(),
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
        ], $order === 'asc' ? 'asc' : 'desc', $limit, $offset);

        $rows = [];

        foreach ($result['rows'] as $entry) {
            $rows[] = [
                'audit_id'   => (int) $entry->audit_id,
                'created_at' => $this->formatDateTime($entry->created_at),
                'action'     => $this->label('action', $entry->action),
                'entity'     => $this->entityCell($entry),
                'employee'   => $entry->employee_name ?? '',
                'origin'     => $this->label('origin', $entry->origin),
                'details'    => $this->describe($entry),
            ];
        }

        return $this->response->setJSON(['total' => $result['total'], 'rows' => $rows]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function headers(): array
    {
        return [
            ['number' => lang('Accounting_journals.number')],
            ['journal_date'    => lang('Accounting_journals.date')],
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
    private function auditHeaders(): array
    {
        return [
            ['created_at' => lang('Accounting_journals.audit_time'), 'sortable' => true],
            ['action'   => lang('Accounting_journals.audit_action'), 'sortable' => false],
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
            'number'          => $journal->number ?? '',
            'journal_date'    => $this->formatDate($journal->journal_date),
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
        ];
    }

    /**
     * @return array{date: string, description: string, lines: list<array<string, mixed>>}
     */
    private function input(): array
    {
        $lines = $this->request->getPost('lines');

        return [
            'date'        => (string) $this->request->getPost('date'),
            'description' => (string) $this->request->getPost('description'),
            'lines'       => is_array($lines) ? array_values(array_filter($lines, 'is_array')) : [],
        ];
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

        return view('accounting_journals/form', [
            'journal'      => $journal,
            'accounts'     => $this->journals->postableAccounts(),
            'cost_centers' => $costCenters,
            'currency'     => (new AccountingSettings())->baseCurrency(),
            'can_post'     => $this->can(AccountingGuard::JOURNALS_POST),
            'notice'       => session()->getFlashdata('accounting_notice'),
        ]);
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

        foreach (array_slice($details, 0, self::DETAIL_LIMIT, true) as $key => $value) {
            $parts[] = $key . ': ' . (is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE));
        }

        return implode(' · ', $parts);
    }
}
