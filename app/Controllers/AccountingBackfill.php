<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\Backfill\BackfillBatch;
use App\Libraries\Accounting\Backfill\BackfillFamily;
use App\Libraries\Accounting\Backfill\BackfillRefusalText;
use App\Libraries\Accounting\Backfill\BackfillService;
use App\Libraries\Accounting\Backfill\BackfillStatus;
use App\Libraries\Accounting\ExportsCsv;
use App\Libraries\Accounting\FiscalYearService;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchService;
use Closure;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

class AccountingBackfill extends AccountingController
{
    use ExportsCsv;

    private const PAGE_SIZE       = 50;
    private const PAGE_MAX        = 100000;
    private const APPROVE_SECONDS = 10.0;

    private BackfillService $backfill;
    private FiscalYearService $years;

    public function __construct()
    {
        parent::__construct('accounting_periods');

        $this->backfill = new BackfillService(null, null, $this->branchGuard());
        $this->years    = new FiscalYearService(null, null, $this->branchGuard());
    }

    public function getIndex(): string
    {
        $canRun = $this->can(AccountingGuard::BACKFILL_MANAGE);

        return view('accounting_periods/backfill', [
            'canRun'     => $canRun,
            'batches'    => $canRun ? $this->backfill->batches($this->employeeId()) : [],
            'blockers'   => $canRun ? $this->backfill->approvalBlockers($this->employeeId()) : [],
            'notice'     => session()->getFlashdata('accounting_notice'),
            'date'       => fn (string $value): string => $this->formatDate($value),
            'amount'     => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'statusText' => static fn (BackfillBatch $batch): string => lang('Accounting_backfill.status_' . $batch->status->value),
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
        $repo    = $this->backfill->repository();

        [$documentsPage, $items]  = $this->pageOf('documents_page', static fn (int $offset): array => $repo->items($batch->id, $outcome, self::PAGE_SIZE, $offset));
        [$journalsPage, $journal] = $this->pageOf('journals_page', static fn (int $offset): array => $repo->journals($batch->id, self::PAGE_SIZE, $offset));

        $lines = [];

        foreach ($journal['rows'] as $row) {
            $lines[(int) $row->journal_id] = $repo->lines((int) $row->journal_id);
        }

        return view('accounting_periods/backfill_view', [
            'batch'          => $batch,
            'figures'        => $repo->figures($batch->id),
            'classes'        => $repo->refusalClasses($batch->id),
            'outsideYears'   => $this->outsideYears($batch),
            'canCreateYears' => $this->can(AccountingGuard::PERIODS_MANAGE) && $this->branchGuard()->isUnrestricted($this->employeeId()),
            'items'          => $items,
            'journals'       => $journal,
            'lines'          => $lines,
            'totals'         => $repo->accountTotals($batch),
            'outcome'        => $outcome,
            'documentsPage'  => $documentsPage,
            'journalsPage'   => $journalsPage,
            'pageSize'       => self::PAGE_SIZE,
            'blockers'       => $this->backfill->approvalBlockers($this->employeeId(), $batch->id),
            'notice'         => session()->getFlashdata('accounting_notice'),
            'date'           => fn (string $value): string => $this->formatDate($value),
            'amount'         => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'statusText'     => lang('Accounting_backfill.status_' . $batch->status->value),
            'viewUrl'        => fn (array $query): string => $this->viewUrl($batch->id, $query),
            'pageQuery'      => ['documents_page' => $documentsPage, 'journals_page' => $journalsPage] + ($outcome === null ? [] : ['outcome' => $outcome]),
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

    public function postCreateYears(int $batchId): ResponseInterface
    {
        $batch = $this->visibleBatch($batchId);

        return $this->attempt(function () use ($batch): array {
            $outside = $this->backfill->repository()->outsideFiscalYears($batch->id);

            if ($outside === null) {
                return ['success' => false, 'message' => lang('Accounting_backfill.outside_years_none'), 'id' => NEW_ENTRY];
            }

            $created = $this->years->createThrough($outside['to'], $this->employeeId());
            $message = lang('Accounting_backfill.outside_years_created', [(string) count($created)]);

            session()->setFlashdata('accounting_notice', $message);

            return ['success' => true, 'message' => $message, 'id' => $batch->id, 'redirect' => $this->viewUrl($batch->id)];
        });
    }

    public function getRefused(int $batchId): ResponseInterface
    {
        $batch  = $this->visibleBatch($batchId);
        $handle = fopen('php://temp', 'r+b');

        $this->csvRow($handle, [
            lang('Accounting_backfill.family'),
            lang('Accounting_backfill.source'),
            lang('Accounting_backfill.date'),
            lang('Accounting_backfill.branch'),
            lang('Accounting_backfill.reason'),
            lang('Accounting_backfill.detail'),
            lang('Accounting_backfill.amount'),
            lang('Accounting_backfill.classes_todo'),
        ]);

        foreach ($this->backfill->repository()->refusedItems($batch->id) as $item) {
            $reason = (string) $item->reason;
            $detail = $item->detail === null ? '' : (string) $item->detail;

            $this->csvRow($handle, [
                lang('Accounting_backfill.family_' . $item->family),
                (string) (int) $item->document_id,
                (string) $item->document_date,
                (string) $item->branch_code,
                BackfillRefusalText::reason($reason),
                $detail,
                bcadd((string) $item->total_debit, '0', 4),
                BackfillRefusalText::help($reason, $detail),
            ]);
        }

        return $this->response
            ->setContentType('text/csv', 'UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="backfill-' . $batch->id . '-refused.csv"')
            ->setBody("\xEF\xBB\xBF" . $this->csvText($handle));
    }

    public function getApproveForm(int $batchId): string
    {
        $batch = $this->visibleBatch($batchId);

        if (! in_array($batch->status, [BackfillStatus::Preview, BackfillStatus::Verifying, BackfillStatus::Approving], true) || $batch->fingerprint === null) {
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

            $asked = $this->request->getPost('steps');
            $steps = is_string($asked) && ctype_digit($asked) ? min(max(1, (int) $asked), BackfillService::STEP_CHUNKS) : BackfillService::STEP_CHUNKS;

            $batch    = $this->backfill->approveStep($batchId, $this->postedText('fingerprint'), $this->employeeId(), $steps, self::APPROVE_SECONDS);
            $progress = $this->backfill->progress($batch);

            if ($progress->isFinished()) {
                $message = lang('Accounting_backfill.approved');
                session()->setFlashdata('accounting_notice', $message);

                return ['success' => true, 'continue' => false, 'message' => $message, 'id' => $batchId, 'redirect' => $this->viewUrl($batchId)];
            }

            return [
                'success'  => true,
                'continue' => true,
                'message'  => lang('Accounting_backfill.approve_progress_' . $progress->phase, [(string) $progress->done, (string) $progress->total]),
                'id'       => $batchId,
                'phase'    => $progress->phase,
                'done'     => $progress->done,
                'total'    => $progress->total,
                'redirect' => $this->viewUrl($batchId),
            ];
        });
    }

    public function getCancelForm(int $batchId): string
    {
        $batch = $this->visibleBatch($batchId);

        if (! in_array($batch->status, [BackfillStatus::Collecting, BackfillStatus::Preview, BackfillStatus::Verifying, BackfillStatus::Approving], true)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $repo      = $this->backfill->repository();
        $approving = $batch->status === BackfillStatus::Approving;

        return view('accounting_periods/backfill_cancel_form', [
            'batch'     => $batch,
            'documents' => $repo->figures($batch->id)['ready'] - ($approving ? $batch->approvedCount : 0),
            'journals'  => $repo->previewCount($batch->id),
            'approving' => $approving,
        ]);
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

    /**
     * The page asked for of a list and its rows, with a page that does not exist taken for the last one that does.
     *
     * @param Closure(int): array{total: int, rows: list<object>} $fetch
     *
     * @return array{0: int, 1: array{total: int, rows: list<object>}}
     */
    private function pageOf(string $name, Closure $fetch): array
    {
        $asked = $this->request->getGet($name);
        $page  = is_string($asked) ? min(max(1, (int) $asked), self::PAGE_MAX) : 1;
        $rows  = $fetch(($page - 1) * self::PAGE_SIZE);
        $last  = max(1, (int) ceil($rows['total'] / self::PAGE_SIZE));

        return $page <= $last ? [$page, $rows] : [$last, $fetch(($last - 1) * self::PAGE_SIZE)];
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
     * @return array{documents: int, from: string, to: string, first: string, last: string, missing: int}|null
     */
    private function outsideYears(BackfillBatch $batch): ?array
    {
        $outside = $this->backfill->repository()->outsideFiscalYears($batch->id);

        if ($outside === null) {
            return null;
        }

        try {
            $missing = $this->years->missingThrough($outside['to']);
        } catch (AccountingException) {
            $missing = [];
        }

        return $outside + ['first' => $missing[0]['name'] ?? '', 'last' => $missing === [] ? '' : $missing[array_key_last($missing)]['name'], 'missing' => count($missing)];
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
