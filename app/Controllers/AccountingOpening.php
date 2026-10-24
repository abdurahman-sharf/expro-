<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\OpeningBalanceService;
use App\Libraries\Accounting\OpeningStock;
use App\Libraries\Accounting\OpeningStockFinding;
use App\Libraries\Accounting\OpeningStockLine;
use App\Libraries\Accounting\OpeningStockPlan;
use App\Libraries\Accounting\OpeningStockProblem;
use App\Libraries\Accounting\OpeningStockResult;
use App\Libraries\Accounting\OpeningStockStatus;
use App\Libraries\Organization\BranchContext;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

class AccountingOpening extends AccountingController
{
    private OpeningBalanceService $opening;
    private OpeningStock $openingStock;

    public function __construct()
    {
        parent::__construct('accounting_periods');

        $this->opening      = new OpeningBalanceService(null, null, $this->branchGuard());
        $this->openingStock = new OpeningStock(null, null, $this->branchGuard());
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
            $stock = $this->openingStock->plans($branchId, $this->employeeId());
        } catch (BranchException) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('accounting_periods/opening', [
            'branches'    => $branches,
            'branch_id'   => $branchId,
            'accounts'    => $this->opening->allowedAccounts(),
            'state'       => $state,
            'stock'       => array_map(fn (OpeningStockPlan $plan): array => $this->stockRow($plan), $stock),
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

    public function postStockPost(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $branchId   = $this->postedBranch();
            $locationId = (int) $this->request->getPost('location_id');
            $plans      = $this->openingStock->plans($branchId, $this->employeeId());
            $ids        = $locationId > 0
                ? [$locationId]
                : array_map(static fn (OpeningStockPlan $plan): int => $plan->locationId, array_filter($plans, static fn (OpeningStockPlan $plan): bool => $plan->status === OpeningStockStatus::Postable));

            $settle  = $locationId > 0 && $this->request->getPost('with_differences') === '1';
            $results = $this->openingStock->post($ids, $this->employeeId(), $settle, (string) $this->request->getPost('reason'));
            $written = array_filter($results, static fn (OpeningStockResult $result): bool => $result->written());
            $posted  = count($written);
            $partly  = count(array_filter($written, static fn (OpeningStockResult $result): bool => $result->plan->status === OpeningStockStatus::PartiallyPosted));

            if ($posted === 0) {
                $blocked = array_values(array_filter($results, static fn ($result): bool => $result->plan->status === OpeningStockStatus::Blocked));

                return ['success' => false, 'message' => $blocked === [] ? lang('Accounting_periods.opening_stock_nothing_posted') : implode(' ', $this->stockRow($blocked[0]->plan)['messages']), 'id' => NEW_ENTRY];
            }

            $message = $partly === 0
                ? lang('Accounting_periods.opening_stock_posted', [(string) $posted])
                : lang('Accounting_periods.opening_stock_posted_partly', [(string) $posted, (string) $partly]);
            session()->setFlashdata('accounting_notice', $message);

            return ['success' => true, 'message' => $message, 'id' => $branchId, 'redirect' => $this->url($branchId)];
        });
    }

    public function getStockReverseForm(): string
    {
        if (! $this->can(AccountingGuard::OPENING_MANAGE)) {
            throw PageNotFoundException::forPageNotFound();
        }

        try {
            $plan = $this->openingStock->plan((int) $this->request->getGet('location'), $this->employeeId());
        } catch (AccountingException|BranchException) {
            throw PageNotFoundException::forPageNotFound();
        }

        if ($plan->activeRevision === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('accounting_periods/opening_stock_reverse_form', ['branch_id' => $plan->branchId, 'location_id' => $plan->locationId, 'location_name' => $plan->locationName, 'start_date' => (new AccountingSettings())->startDate() ?? date('Y-m-d')]);
    }

    public function getStockDifferencesForm(): string
    {
        if (! $this->can(AccountingGuard::OPENING_MANAGE)) {
            throw PageNotFoundException::forPageNotFound();
        }

        try {
            $plan = $this->openingStock->plan((int) $this->request->getGet('location'), $this->employeeId());
        } catch (AccountingException|BranchException) {
            throw PageNotFoundException::forPageNotFound();
        }

        if (! $plan->canSettleDifferences()) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('accounting_periods/opening_stock_differences_form', [
            'branch_id'     => $plan->branchId,
            'location_id'   => $plan->locationId,
            'location_name' => $plan->locationName,
            'differences'   => array_map(fn (OpeningStockLine $line): array => ['name' => $line->itemName, 'units' => to_quantity_decimals($line->units), 'value' => $this->formatAmount($line->value)], $plan->differences),
            'net'           => $this->formatAmount($plan->differenceValue()),
        ]);
    }

    public function postStockReverse(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $branchId = $this->postedBranch();

            $this->openingStock->reverse((int) $this->request->getPost('location_id'), (string) $this->request->getPost('date'), (string) $this->request->getPost('reason'), $this->employeeId());

            $message = lang('Accounting_periods.opening_stock_reversed');
            session()->setFlashdata('accounting_notice', $message);

            return ['success' => true, 'message' => $message, 'id' => $branchId, 'redirect' => $this->url($branchId)];
        });
    }

    /**
     * @return array{location_id: int, name: string, items: int, units: string, value: string, status: string, status_label: string, posted: bool, settleable: bool, messages: list<string>}
     */
    private function stockRow(OpeningStockPlan $plan): array
    {
        $posted = $plan->activeRevision !== null;

        return [
            'location_id'  => $plan->locationId,
            'name'         => $plan->locationName,
            'items'        => count($posted ? $plan->standing : $plan->lines),
            'units'        => to_quantity_decimals($posted ? $plan->postedUnits() : $plan->units()),
            'value'        => $this->formatAmount($posted ? $plan->postedValue() : $plan->value()),
            'status'       => $plan->status->value,
            'status_label' => lang('Accounting_periods.opening_stock_state_' . $plan->status->value),
            'posted'       => $posted,
            'settleable'   => $plan->canSettleDifferences(),
            'messages'     => [
                ...array_map(fn (OpeningStockFinding $finding): string => $this->findingText($finding), $plan->findings),
                ...($plan->status === OpeningStockStatus::PartiallyPosted ? [lang('Accounting_periods.opening_stock_partial_hint')] : []),
            ],
        ];
    }

    private function findingText(OpeningStockFinding $finding): string
    {
        $key = 'Accounting_periods.opening_stock_problem_' . $finding->problem->value;

        return match ($finding->problem) {
            OpeningStockProblem::AccountMissing => lang($key),
            OpeningStockProblem::AccountHoldsValue, OpeningStockProblem::PostedBefore => lang($key, [$this->formatAmount($finding->amount)]),
            OpeningStockProblem::NegativeStock, OpeningStockProblem::MovementsExceedShelves => lang($key, [(string) $finding->itemName, to_quantity_decimals($finding->amount)]),
        };
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
