<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\OpeningBalanceService;
use App\Libraries\Organization\BranchContext;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

class AccountingOpening extends AccountingController
{
    private OpeningBalanceService $opening;

    public function __construct()
    {
        parent::__construct('accounting_periods');

        $this->opening = new OpeningBalanceService(null, null, $this->branchGuard());
    }

    public function getIndex(): string
    {
        $branches = $this->branchGuard()->selectableBranches($this->employeeId());

        if ($branches === []) {
            throw PageNotFoundException::forPageNotFound();
        }

        $branchId = $this->chosenBranch($branches);

        try {
            $state = $this->opening->state($branchId, $this->employeeId());
        } catch (BranchException) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('accounting_periods/opening', [
            'branches'    => $branches,
            'branch_id'   => $branchId,
            'accounts'    => $this->opening->allowedAccounts(),
            'state'       => $state,
            'can_manage'  => $this->can(AccountingGuard::OPENING_MANAGE),
            'start_date'  => $state['start_date'] === null ? '' : $this->formatDate($state['start_date']),
            'show_branch' => count($branches) > 1,
            'notice'      => session()->getFlashdata('accounting_notice'),
        ]);
    }

    public function postSaveDraft(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $branchId = $this->postedBranch();

            $this->opening->saveDraft($branchId, $this->postedLines(), $this->employeeId());

            $message = lang('Accounting_periods.opening_draft_saved');
            session()->setFlashdata('accounting_notice', $message);

            return ['success' => true, 'message' => $message, 'id' => $branchId, 'redirect' => $this->url($branchId)];
        });
    }

    public function postPost(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $branchId = $this->postedBranch();

            $this->opening->post($branchId, $this->postedLines(), $this->request->getPost('balance') === '1', $this->employeeId());

            $message = lang('Accounting_periods.opening_posted');
            session()->setFlashdata('accounting_notice', $message);

            return ['success' => true, 'message' => $message, 'id' => $branchId, 'redirect' => $this->url($branchId)];
        });
    }

    public function postDiscard(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $branchId = $this->postedBranch();

            $this->opening->discardDraft($branchId, $this->employeeId());

            $message = lang('Accounting_periods.opening_draft_discarded');
            session()->setFlashdata('accounting_notice', $message);

            return ['success' => true, 'message' => $message, 'id' => $branchId, 'redirect' => $this->url($branchId)];
        });
    }

    public function getReverseForm(): string
    {
        if (! $this->can(AccountingGuard::OPENING_MANAGE)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $branchId = (int) $this->request->getGet('branch');

        try {
            $state = $this->opening->state($branchId, $this->employeeId());
        } catch (BranchException) {
            throw PageNotFoundException::forPageNotFound();
        }

        if ($state['posted'] === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('accounting_periods/opening_reverse_form', ['branch_id' => $branchId, 'start_date' => (new AccountingSettings())->startDate() ?? date('Y-m-d')]);
    }

    public function postReverse(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $branchId = $this->postedBranch();

            $this->opening->reverse($branchId, (string) $this->request->getPost('date'), (string) $this->request->getPost('reason'), $this->employeeId());

            $message = lang('Accounting_periods.opening_reversed');
            session()->setFlashdata('accounting_notice', $message);

            return ['success' => true, 'message' => $message, 'id' => $branchId, 'redirect' => $this->url($branchId)];
        });
    }

    /**
     * @param list<object> $branches
     */
    private function chosenBranch(array $branches): int
    {
        $requested = (int) $this->request->getGet('branch');
        $ids       = array_map(static fn (object $branch): int => (int) $branch->branch_id, $branches);

        if ($requested > 0) {
            if (! in_array($requested, $ids, true)) {
                throw PageNotFoundException::forPageNotFound();
            }

            return $requested;
        }

        $current = (new BranchContext($this->branchGuard()))->current($this->employeeId());

        return $current ?? $ids[0];
    }

    private function postedBranch(): int
    {
        return (int) $this->request->getPost('branch_id');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function postedLines(): array
    {
        $lines = $this->request->getPost('lines');

        return is_array($lines) ? array_values(array_filter($lines, 'is_array')) : [];
    }

    private function url(int $branchId): string
    {
        return base_url('accounting_periods/opening?branch=' . $branchId);
    }
}
