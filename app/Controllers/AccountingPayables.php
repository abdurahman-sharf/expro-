<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\DebitNoteList;
use App\Libraries\Accounting\PaymentStatus;
use App\Libraries\Accounting\ScopedReportRequest;
use App\Libraries\Accounting\AgedLedgerTable;
use App\Libraries\Accounting\PartyReportQuery;
use App\Libraries\Accounting\SupplierAdvances;
use App\Libraries\Accounting\SupplierBalanceReport;
use App\Libraries\Accounting\SupplierLedger;
use App\Libraries\Accounting\SupplierPaymentList;
use App\Libraries\Accounting\SupplierPosition;
use App\Libraries\Accounting\TreasuryScopes;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Models\Receiving;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * What is owed to the suppliers and what is held with them in credit, by supplier and by
 * purchase. It speaks for the whole company: the credit held with a supplier is spent on
 * purchases of any branch, so a person limited to some branches does not see it. The payments to
 * suppliers and the debit notes are documents of a branch, so their lists are cut down to the
 * branches the employee works in.
 */
class AccountingPayables extends AccountingController
{
    use ScopedReportRequest;

    private const MAX_PAGE_SIZE = 500;

    private SupplierLedger $ledger;
    private SupplierBalanceReport $balances;
    private SupplierAdvances $advances;
    private SupplierPaymentList $payments;
    private DebitNoteList $debitNotes;

    public function __construct()
    {
        parent::__construct('accounting_payables');

        $this->ledger     = new SupplierLedger();
        $this->balances   = new SupplierBalanceReport();
        $this->advances   = new SupplierAdvances(null, null, $this->branchGuard());
        $this->payments   = new SupplierPaymentList();
        $this->debitNotes = new DebitNoteList();
    }

    public function getIndex(): string
    {
        $this->requireCompanyScope();

        return view('accounting_payables/manage', [
            'table_headers' => transform_headers($this->headers(), true, true),
        ]);
    }

    public function getSearch(): ResponseInterface
    {
        $this->requireCompanyScope();

        $limit  = max(1, min((int) $this->request->getGet('limit') ?: self::DEFAULT_PAGE_SIZE, self::MAX_PAGE_SIZE));
        $offset = max(0, (int) $this->request->getGet('offset'));
        $result = $this->ledger->suppliersWithBalance(
            trim((string) $this->request->getGet('search')),
            (string) $this->request->getGet('sort'),
            (string) $this->request->getGet('order'),
            $limit,
            $offset,
        );

        return $this->response->setJSON([
            'total' => $result['total'],
            'rows'  => array_map(fn (object $supplier): array => $this->row($this->ledger->position((int) $supplier->supplier_id), (string) $supplier->supplier_name), $result['rows']),
        ]);
    }

    public function getPayments(): string
    {
        $scope = $this->requestedScope();

        return view('accounting_payables/payments', [
            'table_headers'  => transform_headers($this->paymentHeaders($this->seesBranches()), true, true),
            'branches'       => $this->branchChoices($scope),
            'selected'       => $scope->ids,
            'scope_label'    => $this->scopeLabel($scope),
            'ways'           => $this->payments->paymentTypes(),
            'statuses'       => $this->paymentStatuses(),
            'treasuries'     => (new TreasuryService())->all($scope->isCompany() ? null : $scope->ids),
            'payers'         => $this->payments->payers($scope->isCompany() ? null : $scope->ids),
            'supplier_id'    => $this->id($this->query('supplier_id')),
            'supplier_label' => $this->supplierLabel($this->id($this->query('supplier_id'))),
            'sees_balances'  => $this->branchGuard()->isUnrestricted($this->employeeId()),
        ]);
    }

    public function getPaymentsSearch(): ResponseInterface
    {
        $scope  = $this->requestedScope();
        $result = $this->payments->search(
            $scope->isCompany() ? null : $scope->ids,
            $this->filters(['search', 'date_from', 'date_to', 'supplier_id', 'payment_type', 'status', 'treasury_id', 'employee_id', 'kind']),
            $this->query('sort'),
            $this->query('order'),
            $this->limit(),
            max(0, (int) $this->query('offset')),
        );

        $purchases = (new DocumentNumbering())->numbers(DocumentType::Purchase, 'receivings', array_map(static fn (object $payment): int => (int) $payment->receiving_id, array_filter($result['rows'], static fn (object $payment): bool => $payment->receiving_id !== null)));

        return $this->response->setJSON([
            'total' => $result['total'],
            'rows'  => array_map(fn (object $payment): array => $this->paymentRow($payment, $purchases), $result['rows']),
        ]);
    }

    public function getDebitNotes(): string
    {
        $scope = $this->requestedScope();

        return view('accounting_payables/debit_notes', [
            'table_headers'  => transform_headers($this->debitNoteHeaders($this->seesBranches()), true, true),
            'branches'       => $this->branchChoices($scope),
            'selected'       => $scope->ids,
            'scope_label'    => $this->scopeLabel($scope),
            'supplier_id'    => $this->id($this->query('supplier_id')),
            'supplier_label' => $this->supplierLabel($this->id($this->query('supplier_id'))),
            'sees_balances'  => $this->branchGuard()->isUnrestricted($this->employeeId()),
        ]);
    }

    public function getDebitNotesSearch(): ResponseInterface
    {
        $scope  = $this->requestedScope();
        $result = $this->debitNotes->search(
            $scope->isCompany() ? null : $scope->ids,
            $this->filters(['search', 'date_from', 'date_to', 'supplier_id', 'employee_id']),
            $this->query('sort'),
            $this->query('order'),
            $this->limit(),
            max(0, (int) $this->query('offset')),
        );

        return $this->response->setJSON([
            'total' => $result['total'],
            'rows'  => array_map(fn (object $note): array => $this->debitNoteRow($note), $result['rows']),
        ]);
    }

    public function getRow(int $supplierId): ResponseInterface
    {
        $this->requireCompanyScope();

        $position = $this->ledger->position($supplierId);

        if ($position->accountId === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->response->setJSON($this->row($position, $this->supplierName($supplierId)));
    }

    public function getSupplier(int $supplierId): string
    {
        $this->requireCompanyScope();

        $query    = PartyReportQuery::forAging($this->request->getGet(), date('Y-m-d'));
        $position = $this->ledger->position($supplierId, $query->asOf);

        if ($position->accountId === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $advances = $this->advances->forSupplier($supplierId);

        return view('accounting_payables/supplier', [
            'supplier_id'   => $supplierId,
            'supplier_name' => $this->supplierName($supplierId),
            'position'      => $position,
            'today'         => $query->day(),
            'error'         => $query->refusal === null ? null : $this->messageFor($query->refusal),
            'to'            => $query->to,
            'applications'  => $this->ledger->applications($supplierId, true),
            'advances'      => $advances,
            'advance_kinds' => array_column($advances, 'kind', 'advance_id'),
            'can_manage'    => $this->can(AccountingGuard::PAYABLES_MANAGE),
            'amount'        => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'date'          => fn (string $value): string => $this->formatDate($value),
        ]);
    }

    public function getAgedLedger(): string
    {
        $this->requireCompanyScope();

        $query = PartyReportQuery::forAging($this->request->getGet(), date('Y-m-d'));

        return view('accounting_parties/aged_ledger', [
            'group'         => 'Accounting_payables',
            'base'          => 'accounting_payables',
            'party'         => 'supplier',
            'statement_url' => static fn (int $partyId): string => base_url('accounting_payables/supplier/' . $partyId . '?to=' . $query->day()),
            'table'         => new AgedLedgerTable($query->day(), $query->days, $this->ledger->agedLedger($query->day(), $query->days)),
            'query'         => $query,
            'error'         => $query->refusal === null ? null : $this->messageFor($query->refusal),
            'amount'        => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'date'          => fn (string $value): string => $this->formatDate($value),
        ]);
    }

    public function getBalances(): string
    {
        $this->requireCompanyScope();

        $query = PartyReportQuery::forBalances($this->request->getGet(), date('Y-m-d'));

        return view('accounting_parties/balances', [
            'group'         => 'Accounting_payables',
            'base'          => 'accounting_payables',
            'party'         => 'supplier',
            'statement_url' => static fn (int $partyId): string => base_url('accounting_payables/supplier/' . $partyId),
            'table'         => $this->balances->build($query->period, ! $query->showEmpty),
            'query'         => $query,
            'error'         => $query->refusal === null ? null : $this->messageFor($query->refusal),
            'amount'        => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'date'          => fn (string $value): string => $this->formatDate($value),
        ]);
    }

    public function getPaymentForm(int $supplierId): string
    {
        $this->requireManage($supplierId);

        $options = model(Receiving::class)->get_payment_options();

        unset($options[lang('Sales.due')]);

        $treasuries = [0 => lang('Accounting_payables.treasury_default')];

        $active = (new TreasuryService())->all($this->branchIds(), false);

        foreach ((new TreasuryScopes())->usable($active, TreasuryScopes::WITHDRAW, $this->employeeId()) as $treasury) {
            $treasuries[(int) $treasury->treasury_id] = $treasury->name . ' (' . $treasury->branch_code . ')';
        }

        return view('accounting_payables/payment_form', [
            'supplier_id'     => $supplierId,
            'supplier_name'   => $this->supplierName($supplierId),
            'payment_options' => $options,
            'treasuries'      => count($treasuries) > 2 ? $treasuries : [],
            'today'           => date('Y-m-d'),
        ]);
    }

    public function postPayment(int $supplierId): ResponseInterface
    {
        $this->requireCompanyScope();

        return $this->attempt(function () use ($supplierId): array {
            $treasuryId = (int) $this->request->getPost('treasury_id');

            $id = $this->advances->pay(
                $supplierId,
                trim((string) $this->request->getPost('amount')),
                trim((string) $this->request->getPost('advance_date')),
                trim((string) $this->request->getPost('payment_type')),
                $treasuryId > 0 ? $treasuryId : null,
                (string) $this->request->getPost('reference_code'),
                (string) $this->request->getPost('note'),
                $this->employeeId(),
            );

            return ['success' => true, 'message' => lang('Accounting_payables.payment_done'), 'id' => $id];
        });
    }

    public function getOpeningForm(int $supplierId): string
    {
        $this->requireManage($supplierId);

        return view('accounting_payables/opening_form', [
            'supplier_id'   => $supplierId,
            'supplier_name' => $this->supplierName($supplierId),
            'today'         => date('Y-m-d'),
        ]);
    }

    public function postOpening(int $supplierId): ResponseInterface
    {
        $this->requireCompanyScope();

        return $this->attempt(function () use ($supplierId): array {
            $id = $this->advances->openingBalance(
                $supplierId,
                (string) $this->request->getPost('side'),
                trim((string) $this->request->getPost('amount')),
                trim((string) $this->request->getPost('advance_date')),
                (string) $this->request->getPost('note'),
                $this->employeeId(),
            );

            return ['success' => true, 'message' => lang('Accounting_payables.opening_done'), 'id' => $id];
        });
    }

    public function getCancelForm(string $what, int $id): string
    {
        $this->requireCompanyScope();

        if (! $this->can(AccountingGuard::PAYABLES_MANAGE) || ! in_array($what, [SupplierAdvances::SOURCE, SupplierAdvances::OPENING_TYPE], true) || $this->advances->branchOf($what, $id) === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('accounting_payables/cancel_form', ['what' => $what, 'id' => $id]);
    }

    public function postCancel(string $what, int $id): ResponseInterface
    {
        $this->requireCompanyScope();

        return $this->attempt(function () use ($what, $id): array {
            $reason = (string) $this->request->getPost('reason');

            match ($what) {
                SupplierAdvances::SOURCE       => $this->advances->cancel($id, $reason, $this->employeeId()),
                SupplierAdvances::OPENING_TYPE => $this->advances->cancelOpening($id, $reason, $this->employeeId()),
                default                        => throw PageNotFoundException::forPageNotFound(),
            };

            return ['success' => true, 'message' => lang('Accounting_payables.cancelled_done'), 'id' => $id];
        });
    }

    private function requireManage(int $supplierId): void
    {
        $this->requireCompanyScope();

        if (! $this->can(AccountingGuard::PAYABLES_MANAGE) || $this->ledger->position($supplierId)->accountId === null) {
            throw PageNotFoundException::forPageNotFound();
        }
    }

    private function requireCompanyScope(): void
    {
        if (! $this->branchGuard()->isUnrestricted($this->employeeId())) {
            throw PageNotFoundException::forPageNotFound();
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function headers(): array
    {
        return [
            ['supplier_name' => lang('Accounting_payables.supplier')],
            ['balance'      => lang('Accounting_payables.balance'), 'escape' => false],
            ['open'         => lang('Accounting_payables.open'), 'sortable' => false, 'escape' => false],
            ['credit'       => lang('Accounting_payables.credit'), 'sortable' => false, 'escape' => false],
            ['overdue'      => lang('Accounting_payables.overdue'), 'sortable' => false, 'escape' => false],
            ['age_today'    => lang('Accounting_payables.age_today'), 'sortable' => false, 'escape' => false],
            ['age_1_30'     => lang('Accounting_payables.age_1_30'), 'sortable' => false, 'escape' => false],
            ['age_31_60'    => lang('Accounting_payables.age_31_60'), 'sortable' => false, 'escape' => false],
            ['age_61_90'    => lang('Accounting_payables.age_61_90'), 'sortable' => false, 'escape' => false],
            ['age_91_120'   => lang('Accounting_payables.age_91_120'), 'sortable' => false, 'escape' => false],
            ['age_over_120' => lang('Accounting_payables.age_over_120'), 'sortable' => false, 'escape' => false],
            ['agrees'       => lang('Accounting_payables.agrees'), 'sortable' => false, 'escape' => false],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(SupplierPosition $position, string $name): array
    {
        $today = date('Y-m-d');
        $aging = $position->aging($today);

        return [
            'supplier_id'   => $position->supplierId,
            'supplier_name' => $name,
            'balance'       => $this->amountCell($this->formatAmount($position->accountBalance)),
            'open'          => $this->amountCell($this->formatAmount($position->open())),
            'credit'        => $this->amountCell($this->formatAmount($position->credit())),
            'overdue'       => $this->amountCell($this->formatAmount($position->overdue($today))),
            'age_today'     => $this->amountCell($this->formatAmount($aging['today'])),
            'age_1_30'      => $this->amountCell($this->formatAmount($aging['1-30'])),
            'age_31_60'     => $this->amountCell($this->formatAmount($aging['31-60'])),
            'age_61_90'     => $this->amountCell($this->formatAmount($aging['61-90'])),
            'age_91_120'    => $this->amountCell($this->formatAmount($aging['91-120'])),
            'age_over_120'  => $this->amountCell($this->formatAmount($aging['120+'])),
            'agrees'        => $position->agrees()
                ? '<span class="badge text-bg-success">' . esc(lang('Accounting_payables.agrees_yes')) . '</span>'
                : '<span class="badge text-bg-warning">' . esc(lang('Accounting_payables.agrees_no', [$this->formatAmount($position->unclassified())])) . '</span>',
            'edit' => anchor('accounting_payables/supplier/' . $position->supplierId, '<span class="bi bi-eye" aria-hidden="true"></span>', ['title' => lang('Accounting_payables.statement')]),
        ];
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, string>
     */
    private function filters(array $names): array
    {
        $filters = [];

        foreach ($names as $name) {
            $filters[$name] = $this->query($name);
        }

        return $filters;
    }

    private function limit(): int
    {
        return max(1, min((int) $this->query('limit') ?: self::DEFAULT_PAGE_SIZE, self::MAX_PAGE_SIZE));
    }

    private function id(string $value): int
    {
        return max(0, (int) $value);
    }

    /**
     * @return array<string, string>
     */
    private function paymentStatuses(): array
    {
        $statuses = [];

        foreach (PaymentStatus::cases() as $status) {
            $statuses[$status->value] = lang($status->languageKey());
        }

        return $statuses + ['cancelled' => lang('Accounting_payables.status_cancelled')];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function paymentHeaders(bool $withBranch): array
    {
        $branch = $withBranch ? [['branch_code' => lang('Accounting_payables.branch'), 'sortable' => false]] : [];

        return [
            ['number' => lang('Accounting_payables.payment_number'), 'escape' => false],
            ['date'          => lang('Accounting_payables.date')],
            ['supplier_name' => lang('Accounting_payables.supplier')],
            ['purchase'      => lang('Accounting_payables.purchase'), 'sortable' => false, 'escape' => false],
            ['kind'          => lang('Accounting_payables.payment_kind'), 'sortable' => false],
            ['amount'        => lang('Accounting_payables.amount'), 'escape' => false],
            ['way'           => lang('Accounting_payables.way'), 'sortable' => false],
            ['treasury'      => lang('Accounting_payables.treasury'), 'sortable' => false],
            ['paid_by'       => lang('Accounting_payables.paid_by'), 'sortable' => false],
            ...$branch,
            ['reference' => lang('Accounting_payables.reference'), 'sortable' => false],
            ['status'    => lang('Accounting_payables.status'), 'sortable' => false, 'escape' => false],
        ];
    }

    /**
     * @param array<int, string> $purchases The numbers of the purchases the payments are made against.
     *
     * @return array<string, mixed>
     */
    private function paymentRow(object $payment, array $purchases): array
    {
        $purchase   = $payment->receiving_id === null ? null : (int) $payment->receiving_id;
        $isAdvance  = $payment->kind === SupplierPaymentList::ADVANCE;
        $isRefund   = bccomp((string) $payment->amount, '0', 4) < 0;
        $state      = PaymentStatus::fromStored($payment->status);
        $cancelled  = $isAdvance && $payment->status === 'cancelled';
        $statusText = $cancelled ? lang('Accounting_payables.status_cancelled') : lang($state->languageKey());
        $edit       = '';

        if ($purchase !== null && $this->can('receivings')) {
            $edit = anchor('receivings/edit/' . $purchase, '<span class="bi bi-pencil-square" aria-hidden="true"></span>', ['class' => 'modal-dlg print_hide', 'data-btn-submit' => lang('Common.submit'), 'title' => lang('Receivings.update')]);
        } elseif ($isAdvance && $this->branchGuard()->isUnrestricted($this->employeeId())) {
            $edit = anchor('accounting_payables/supplier/' . (int) $payment->supplier_id, '<span class="bi bi-eye" aria-hidden="true"></span>', ['title' => lang('Accounting_payables.statement')]);
        }

        return [
            'doc_key'       => $payment->kind . ':' . $payment->source_id,
            'number'        => $this->amountCell((string) $payment->number),
            'date'          => (int) $payment->date_entered === 1 ? $this->formatDate((string) $payment->doc_date) : $this->formatDateTime((string) $payment->saved_at),
            'supplier_name' => (string) $payment->supplier_name,
            'purchase'      => $purchase === null ? '' : $this->amountCell($purchases[$purchase] ?? DocumentType::Purchase->fallback($purchase)),
            'kind'          => lang('Accounting_payables.kind_' . ($isAdvance ? SupplierPaymentList::ADVANCE : ($isRefund ? SupplierPaymentList::REFUND : SupplierPaymentList::PURCHASE))),
            'amount'        => $this->amountCell($this->formatAmount((string) $payment->amount)),
            'way'           => (string) $payment->payment_type,
            'treasury'      => (string) ($payment->treasury_name ?? ''),
            'paid_by'       => (string) $payment->employee_name,
            'branch_code'   => (string) $payment->branch_code,
            'reference'     => (string) $payment->reference_code,
            'status'        => '<span class="badge ' . (! $cancelled && $state === PaymentStatus::Completed ? 'text-bg-success' : 'text-bg-secondary') . '">' . esc($statusText) . '</span>',
            'edit'          => $edit,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function debitNoteHeaders(bool $withBranch): array
    {
        $branch = $withBranch ? [['branch_code' => lang('Accounting_payables.branch'), 'sortable' => false]] : [];

        return [
            ['number' => lang('Accounting_payables.debit_note_number'), 'escape' => false],
            ['date'          => lang('Accounting_payables.date')],
            ['origin'        => lang('Accounting_payables.origin'), 'sortable' => false, 'escape' => false],
            ['supplier_name' => lang('Accounting_payables.supplier')],
            ['amount'        => lang('Accounting_payables.amount'), 'sortable' => false, 'escape' => false],
            ['refunded'      => lang('Accounting_payables.refunded'), 'sortable' => false, 'escape' => false],
            ['applied'       => lang('Accounting_payables.applied'), 'sortable' => false, 'escape' => false],
            ['held'          => lang('Accounting_payables.held'), 'sortable' => false, 'escape' => false],
            ['state'         => lang('Accounting_payables.status'), 'sortable' => false, 'escape' => false],
            ['employee'      => lang('Accounting_payables.created_by'), 'sortable' => false],
            ...$branch,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function debitNoteRow(object $note): array
    {
        $id     = (int) $note->receiving_id;
        $origin = $note->return_of_receiving_id === null ? null : (int) $note->return_of_receiving_id;
        $edit   = $this->can('receivings')
            ? anchor('receivings/edit/' . $id, '<span class="bi bi-pencil-square" aria-hidden="true"></span>', ['class' => 'modal-dlg print_hide', 'data-btn-submit' => lang('Common.submit'), 'title' => lang('Receivings.update')])
            : '';

        return [
            'receiving_id'  => $id,
            'number'        => $this->amountCell((string) $note->number),
            'date'          => $this->formatDate((string) $note->receiving_time),
            'origin'        => $origin === null ? '' : $this->amountCell((string) $note->origin_number),
            'supplier_name' => (string) $note->supplier_name,
            'amount'        => $this->amountCell($this->formatAmount($note->amount)),
            'refunded'      => $note->refunded === null ? '' : $this->amountCell($this->formatAmount($note->refunded)),
            'applied'       => $note->applied === null ? '' : $this->amountCell($this->formatAmount($note->applied)),
            'held'          => $note->held === null ? '' : $this->amountCell($this->formatAmount($note->held)),
            'state'         => '<span class="badge ' . ($note->state === DebitNoteList::HELD || $note->state === DebitNoteList::PARTLY_APPLIED ? 'text-bg-warning' : 'text-bg-secondary') . '">' . esc(lang('Accounting_payables.state_' . $note->state)) . '</span>',
            'employee'      => (string) $note->employee_name,
            'branch_code'   => (string) $note->branch_code,
            'edit'          => $edit,
        ];
    }

    private function supplierName(int $supplierId): string
    {
        return $this->ledger->accountName($supplierId);
    }

    private function supplierLabel(int $supplierId): string
    {
        $name = $supplierId > 0 && $this->branchGuard()->isUnrestricted($this->employeeId()) ? $this->supplierName($supplierId) : '';

        return $name !== '' ? $name : '#' . $supplierId;
    }
}
