<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\InterBranchBalances;
use App\Libraries\Accounting\InterBranchSettlements;
use App\Libraries\Accounting\PairBalance;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\ReportScopeKind;
use App\Libraries\Accounting\TreasuryScopes;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Which branch owes which, between every two branches, and the settlements that clear it. What an
 * employee sees is cut down to the branches they work in, and a settlement is made and taken back by
 * whoever works in both branches of the pair and holds the permission to settle.
 */
class AccountingInterBranch extends AccountingController
{
    private const MAX_PAGE_SIZE = 500;

    private InterBranchBalances $balances;
    private InterBranchSettlements $settlements;
    private TreasuryService $treasuries;
    private TreasuryScopes $scopes;

    public function __construct()
    {
        parent::__construct('accounting_treasuries');

        $this->balances    = new InterBranchBalances();
        $this->settlements = new InterBranchSettlements(null, null, $this->branchGuard());
        $this->treasuries  = new TreasuryService(null, null, $this->branchGuard());
        $this->scopes      = new TreasuryScopes(null, $this->branchGuard());
    }

    public function getIndex(): string
    {
        return view('accounting_treasuries/inter_branch', [
            'table_headers' => transform_headers($this->settlementHeaders(), true, true),
            ...$this->pairsData(),
        ]);
    }

    public function getPairs(): string
    {
        return view('accounting_treasuries/inter_branch_pairs', $this->pairsData());
    }

    public function getSettlementsSearch(): ResponseInterface
    {
        $limit  = max(1, min((int) $this->request->getGet('limit') ?: self::DEFAULT_PAGE_SIZE, self::MAX_PAGE_SIZE));
        $offset = max(0, (int) $this->request->getGet('offset'));
        $result = $this->settlements->search($this->branchIds(), $limit, $offset);

        return $this->response->setJSON([
            'total' => $result['total'],
            'rows'  => array_map(fn (object $settlement): array => $this->settlementRow($settlement), $result['rows']),
        ]);
    }

    public function getSettlementsRow(int $settlementId): ResponseInterface
    {
        $settlement = $this->settlements->find($settlementId);

        if ($settlement === null || ! $this->canSee($settlement)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->response->setJSON($this->settlementRow($settlement));
    }

    public function getSettleForm(): string
    {
        $this->requireSettle();

        $branchId = (int) $this->request->getGet('branch');
        $other    = (int) $this->request->getGet('other');
        $date     = $this->dateOf($this->request->getGet('date'));

        try {
            $plan = $this->settlements->plan($branchId, $other, $date, $this->employeeId());
        } catch (AccountingException|BranchException) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('accounting_treasuries/settle_form', [
            'plan'            => $plan,
            'from_treasuries' => $this->choices($plan->payerBranchId, TreasuryScopes::WITHDRAW),
            'to_treasuries'   => $this->choices($plan->payeeBranchId, TreasuryScopes::DEPOSIT),
            'request_key'     => bin2hex(random_bytes(16)),
            'amount'          => fn (string $value): string => $this->formatAmount($value),
            'payer_code'      => $plan->payerBranchId === null ? '' : ($plan->payerBranchId === $plan->firstBranchId ? $plan->firstCode : $plan->secondCode),
            'payee_code'      => $plan->payeeBranchId === null ? '' : ($plan->payeeBranchId === $plan->firstBranchId ? $plan->firstCode : $plan->secondCode),
        ]);
    }

    public function postSettle(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $fromId = (int) $this->request->getPost('from_treasury_id');
            $toId   = (int) $this->request->getPost('to_treasury_id');
            $key    = $this->request->getPost('request_key');

            $id = $this->settlements->settle(
                (int) $this->request->getPost('branch'),
                (int) $this->request->getPost('other'),
                trim((string) $this->request->getPost('date')),
                $fromId > 0 ? $fromId : null,
                $toId > 0 ? $toId : null,
                (string) $this->request->getPost('note'),
                is_string($key) ? $key : null,
                $this->employeeId(),
            );

            return ['success' => true, 'message' => lang('Accounting_treasuries.settle_done'), 'id' => $id];
        });
    }

    public function getCancelForm(int $settlementId): string
    {
        $this->requireSettle();

        $settlement = $this->settlements->find($settlementId);

        if ($settlement === null || ! $this->canSee($settlement, true)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('accounting_treasuries/settlement_cancel_form', ['settlement' => $settlement, 'amount' => $this->formatAmount((string) $settlement->amount)]);
    }

    public function postCancel(int $settlementId): ResponseInterface
    {
        return $this->attempt(function () use ($settlementId): array {
            $this->settlements->cancel($settlementId, (string) $this->request->getPost('reason'), $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_treasuries.settlement_cancelled_done'), 'id' => $settlementId];
        });
    }

    private function requireSettle(): void
    {
        if (! $this->can(AccountingGuard::INTERBRANCH_SETTLE)) {
            throw PageNotFoundException::forPageNotFound();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function pairsData(): array
    {
        $requested = $this->request->getGet('branches');
        $scope     = ReportScope::resolve(is_array($requested) ? array_map('intval', $requested) : null, $this->branchIds());
        $error     = null;

        try {
            $asOf = ReportPeriod::of(null, $this->asOfOf($this->request->getGet('as_of')))->to;
        } catch (AccountingException $refusal) {
            $error = $this->messageFor($refusal);
            $asOf  = date('Y-m-d');
        }

        $report     = $this->balances->report($scope->isCompany() ? null : $scope->ids, $asOf);
        $canSeeBoth = fn (PairBalance $row): bool => $this->branchGuard()->canAccess($row->branchId, $this->employeeId()) && $this->branchGuard()->canAccess($row->counterpartId, $this->employeeId());

        return [
            'rows' => array_map(fn (PairBalance $row): array => [
                'code'        => $row->code,
                'counterpart' => $row->counterpartCode,
                'receivable'  => $this->formatAmount($row->receivable),
                'payable'     => $this->formatAmount($row->payable),
                'owed'        => $this->formatAmount($row->owed),
                'sign'        => bccomp($row->owed, '0', 4),
                'basis'       => $row->basis,
                'agrees'      => $row->agrees,
                'settle_url'  => $row->branchId < $row->counterpartId && $canSeeBoth($row) && (bccomp($row->receivable, '0', 4) !== 0 || bccomp($row->payable, '0', 4) !== 0)
                    ? base_url('accounting_treasuries/inter_branch/settle_form') . '?' . http_build_query(['branch' => $row->branchId, 'other' => $row->counterpartId, 'date' => $asOf])
                    : null,
            ], $report->rows),
            'unattributed' => array_map(fn (object $row): array => [
                'code'       => $row->code,
                'receivable' => $this->formatAmount($row->receivable),
                'payable'    => $this->formatAmount($row->payable),
            ], $report->unattributed),
            'difference'    => $report->difference !== null && bccomp($report->difference, '0', 4) !== 0 ? $this->formatAmount($report->difference) : null,
            'error'         => $error,
            'as_of'         => $asOf,
            'branches'      => $scope->choosable() ? $scope->choices : [],
            'selected'      => $scope->ids,
            'scope_label'   => $scope->label(),
            'scope_empty'   => $scope->isEmpty(),
            'scope_partial' => $scope->kind === ReportScopeKind::Partial,
            'can_settle'    => $this->can(AccountingGuard::INTERBRANCH_SETTLE),
        ];
    }

    private function asOfOf(mixed $value): string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : date('Y-m-d');
    }

    private function dateOf(mixed $value): string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : date('Y-m-d');
    }

    /**
     * The active treasuries of a branch the employee may use in a direction.
     *
     * @return list<object>
     */
    private function choices(?int $branchId, string $direction): array
    {
        if ($branchId === null) {
            return [];
        }

        return $this->scopes->usable($this->treasuries->all([$branchId], false), $direction, $this->employeeId());
    }

    /**
     * A settlement is seen by whoever works in a branch of its pair, and taken back by whoever works
     * in both.
     */
    private function canSee(object $settlement, bool $inBoth = false): bool
    {
        $allowed = array_map(fn (int $branchId): bool => $this->branchGuard()->canAccess($branchId, $this->employeeId()), InterBranchSettlements::branchesOf($settlement));

        return $inBoth ? ! in_array(false, $allowed, true) : in_array(true, $allowed, true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function settlementHeaders(): array
    {
        return [
            ['settlement_id' => lang('Accounting_treasuries.settlement_number'), 'sortable' => false],
            ['settle_date' => lang('Accounting_treasuries.date'), 'sortable' => false],
            ['branches'    => lang('Accounting_treasuries.settlement_pair'), 'sortable' => false, 'escape' => false],
            ['kind'        => lang('Accounting_treasuries.settlement_kind'), 'sortable' => false],
            ['amount'      => lang('Accounting_treasuries.settlement_amount'), 'sortable' => false, 'escape' => false],
            ['treasuries'  => lang('Accounting_treasuries.settlement_treasuries'), 'sortable' => false],
            ['note'        => lang('Accounting_treasuries.note_label'), 'sortable' => false],
            ['status'      => lang('Accounting_treasuries.status'), 'sortable' => false, 'escape' => false],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function settlementRow(object $settlement): array
    {
        $id        = (int) $settlement->settlement_id;
        $cancelled = $settlement->status === 'cancelled';
        $edit      = '';

        if (! $cancelled && $this->can(AccountingGuard::INTERBRANCH_SETTLE) && $this->canSee($settlement, true)) {
            $edit = anchor(
                "accounting_treasuries/inter_branch/cancel_form/{$id}",
                '<span class="bi bi-x-circle" aria-hidden="true"></span>',
                ['class' => 'modal-dlg', 'data-btn-submit' => lang('Common.submit'), 'title' => lang('Accounting_treasuries.settlement_cancel'), 'aria-label' => lang('Accounting_treasuries.settlement_cancel')],
            );
        }

        return [
            'settlement_id' => $id,
            'settle_date'   => $this->formatDate((string) $settlement->settle_date),
            'branches'      => $this->amountCell((string) $settlement->branch_code) . ' <span class="bi bi-arrow-left-right" aria-hidden="true"></span> ' . $this->amountCell((string) $settlement->counterpart_code),
            'kind'          => lang('Accounting_treasuries.settlement_kind_' . $settlement->kind),
            'amount'        => $this->amountCell($this->formatAmount((string) $settlement->amount)),
            'treasuries'    => $settlement->from_name === null ? '' : $settlement->from_name . ' → ' . $settlement->to_name,
            'note'          => $settlement->note,
            'status'        => '<span class="badge ' . ($cancelled ? 'text-bg-secondary' : 'text-bg-success') . '">' . esc(lang('Accounting_treasuries.status_' . $settlement->status)) . '</span>',
            'edit'          => $edit,
        ];
    }
}
