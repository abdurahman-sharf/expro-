<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AgedLedgerTable;
use App\Libraries\Accounting\PartyReportQuery;
use App\Libraries\Accounting\SupplierAdvances;
use App\Libraries\Accounting\SupplierBalanceReport;
use App\Libraries\Accounting\SupplierLedger;
use App\Libraries\Accounting\SupplierPosition;
use App\Libraries\Accounting\TreasuryScopes;
use App\Libraries\Accounting\TreasuryService;
use App\Models\Receiving;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * What is owed to the suppliers and what is held with them in credit, by supplier and by
 * purchase. It speaks for the whole company: the credit held with a supplier is spent on
 * purchases of any branch, so a person limited to some branches does not see it.
 */
class AccountingPayables extends AccountingController
{
    private const MAX_PAGE_SIZE = 500;

    private SupplierLedger $ledger;
    private SupplierBalanceReport $balances;
    private SupplierAdvances $advances;

    public function __construct()
    {
        parent::__construct('accounting_payables');

        $this->ledger   = new SupplierLedger();
        $this->balances = new SupplierBalanceReport();
        $this->advances = new SupplierAdvances(null, null, $this->branchGuard());
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

    private function supplierName(int $supplierId): string
    {
        return $this->ledger->accountName($supplierId);
    }
}
