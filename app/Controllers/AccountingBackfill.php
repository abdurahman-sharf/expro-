<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\Backfill\BackfillBatch;
use App\Libraries\Accounting\Backfill\BackfillFamily;
use App\Libraries\Accounting\Backfill\BackfillService;
use App\Libraries\Accounting\Backfill\BackfillStatus;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

class AccountingBackfill extends AccountingController
{
    private const PAGE_SIZE = 50;

    private BackfillService $backfill;

    public function __construct()
    {
        parent::__construct('accounting_periods');

        $this->backfill = new BackfillService(null, null, $this->branchGuard());
    }

    public function getIndex(): string
    {
        $canRun = $this->can(AccountingGuard::BACKFILL_MANAGE);

        return view('accounting_periods/backfill', [
            'can_run'     => $canRun,
            'batches'     => $canRun ? $this->backfill->batches($this->employeeId()) : [],
            'blockers'    => $canRun ? $this->backfill->approvalBlockers() : [],
            'notice'      => session()->getFlashdata('accounting_notice'),
            'date'        => fn (string $value): string => $this->formatDate($value),
            'amount'      => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'status_text' => static fn (BackfillBatch $batch): string => lang('Accounting_backfill.status_' . $batch->status->value),
        ]);
    }

    public function getNewForm(): string
    {
        $this->requirePermission();

        return view('accounting_periods/backfill_new_form', [
            'branches' => $this->branchOptions(),
            'families' => BackfillFamily::ordered(),
            'chunk'    => BackfillService::CHUNK_DEFAULT,
        ]);
    }

    public function postCreate(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $families = $this->request->getPost('families');
            $batchId  = $this->backfill->create([
                'name'       => $this->postedText('name'),
                'from'       => $this->postedText('from'),
                'to'         => $this->postedText('to'),
                'branch_id'  => $this->postedText('branch_id'),
                'families'   => is_array($families) ? array_values(array_filter($families, 'is_string')) : null,
                'chunk_size' => $this->postedText('chunk_size'),
            ], $this->employeeId());

            $message = lang('Accounting_backfill.created');
            session()->setFlashdata('accounting_notice', $message);

            return ['success' => true, 'message' => $message, 'id' => $batchId, 'redirect' => $this->viewUrl($batchId)];
        });
    }

    public function getBatch(int $batchId): string
    {
        $batch   = $this->visibleBatch($batchId);
        $outcome = $this->request->getGet('outcome');
        $outcome = in_array($outcome, ['ready', 'refused'], true) ? $outcome : null;
        $docPage = max(1, (int) $this->request->getGet('documents_page'));
        $jrnPage = max(1, (int) $this->request->getGet('journals_page'));
        $repo    = $this->backfill->repository();
        $items   = $repo->items($batch->id, $outcome, self::PAGE_SIZE, ($docPage - 1) * self::PAGE_SIZE);
        $journal = $repo->journals($batch->id, self::PAGE_SIZE, ($jrnPage - 1) * self::PAGE_SIZE);
        $lines   = [];

        foreach ($journal['rows'] as $row) {
            $lines[(int) $row->journal_id] = $repo->lines((int) $row->journal_id);
        }

        return view('accounting_periods/backfill_view', [
            'batch'          => $batch,
            'figures'        => $repo->figures($batch->id),
            'reasons'        => $repo->reasons($batch->id),
            'items'          => $items,
            'journals'       => $journal,
            'lines'          => $lines,
            'totals'         => $repo->accountTotals($batch),
            'outcome'        => $outcome,
            'documents_page' => $docPage,
            'journals_page'  => $jrnPage,
            'page_size'      => self::PAGE_SIZE,
            'blockers'       => $this->backfill->approvalBlockers($batch->branchId),
            'notice'         => session()->getFlashdata('accounting_notice'),
            'date'           => fn (string $value): string => $this->formatDate($value),
            'amount'         => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'status_text'    => lang('Accounting_backfill.status_' . $batch->status->value),
            'view_url'       => fn (array $query): string => $this->viewUrl($batch->id, $query),
        ]);
    }

    public function postPreview(int $batchId): ResponseInterface
    {
        return $this->attempt(function () use ($batchId): array {
            $batch   = $this->backfill->preview($batchId, $this->employeeId(), 10);
            $message = lang($batch->status === BackfillStatus::Collecting ? 'Accounting_backfill.preview_continues' : 'Accounting_backfill.previewed');

            session()->setFlashdata('accounting_notice', $message);

            return ['success' => true, 'message' => $message, 'id' => $batchId, 'redirect' => $this->viewUrl($batchId)];
        });
    }

    public function getApproveForm(int $batchId): string
    {
        $batch = $this->visibleBatch($batchId);

        if (! in_array($batch->status, [BackfillStatus::Preview, BackfillStatus::Approving], true) || $batch->fingerprint === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $shown = $this->request->getGet('fingerprint');

        if (! is_string($shown) || preg_match('/^[0-9a-f]{64}$/', $shown) !== 1) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('accounting_periods/backfill_approve_form', ['batch' => $batch, 'fingerprint' => $shown]);
    }

    public function postApprove(int $batchId): ResponseInterface
    {
        return $this->attempt(function () use ($batchId): array {
            if ($this->request->getPost('confirm') !== '1') {
                return ['success' => false, 'message' => lang('Accounting_backfill.confirm_required'), 'id' => NEW_ENTRY];
            }

            $this->backfill->approve($batchId, $this->postedText('fingerprint'), $this->employeeId());

            $message = lang('Accounting_backfill.approved');
            session()->setFlashdata('accounting_notice', $message);

            return ['success' => true, 'message' => $message, 'id' => $batchId, 'redirect' => $this->viewUrl($batchId)];
        });
    }

    public function getCancelForm(int $batchId): string
    {
        $batch = $this->visibleBatch($batchId);

        if (! in_array($batch->status, [BackfillStatus::Collecting, BackfillStatus::Preview], true)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('accounting_periods/backfill_cancel_form', ['batch' => $batch]);
    }

    public function postCancel(int $batchId): ResponseInterface
    {
        return $this->attempt(function () use ($batchId): array {
            if ($this->request->getPost('confirm') !== '1') {
                return ['success' => false, 'message' => lang('Accounting_backfill.confirm_required'), 'id' => NEW_ENTRY];
            }

            $this->backfill->cancel($batchId, $this->employeeId());

            $message = lang('Accounting_backfill.cancelled');
            session()->setFlashdata('accounting_notice', $message);

            return ['success' => true, 'message' => $message, 'id' => $batchId, 'redirect' => base_url('accounting_periods/backfill')];
        });
    }

    private function requirePermission(): void
    {
        if (! $this->can(AccountingGuard::BACKFILL_MANAGE)) {
            throw PageNotFoundException::forPageNotFound();
        }
    }

    private function visibleBatch(int $batchId): BackfillBatch
    {
        $this->requirePermission();

        try {
            return $this->backfill->batch($batchId, $this->employeeId());
        } catch (AccountingException|BranchException) {
            throw PageNotFoundException::forPageNotFound();
        }
    }

    /**
     * @param array<string, scalar> $query
     */
    private function viewUrl(int $batchId, array $query = []): string
    {
        return base_url('accounting_periods/backfill/view/' . $batchId) . ($query === [] ? '' : '?' . http_build_query($query));
    }

    /**
     * What the employee may run a batch for: the company when they work in every branch, and each branch they work in.
     *
     * @return array<string, string>
     */
    private function branchOptions(): array
    {
        $options = [];

        if ($this->branchGuard()->isUnrestricted($this->employeeId())) {
            $options[''] = lang('Accounting_backfill.scope_company');
        }

        foreach ((new BranchService(null, $this->branchGuard()))->all($this->employeeId()) as $branch) {
            $options[(string) $branch->branch_id] = $branch->code . ' - ' . $branch->name;
        }

        return $options;
    }

    private function postedText(string $name): string
    {
        $value = $this->request->getPost($name);

        return is_string($value) ? $value : '';
    }
}
