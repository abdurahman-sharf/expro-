<?php

namespace App\Controllers;

use App\Libraries\Accounting\SupplierLedger;
use App\Libraries\Accounting\SupplierPosition;
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

    public function __construct()
    {
        parent::__construct('accounting_payables');

        $this->ledger = new SupplierLedger();
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

        $position = $this->ledger->position($supplierId);

        if ($position->accountId === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('accounting_payables/supplier', [
            'supplier_name' => $this->supplierName($supplierId),
            'position'      => $position,
            'today'         => date('Y-m-d'),
            'applications'  => $this->ledger->applications($supplierId, true),
            'amount'        => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'date'          => fn (string $value): string => $this->formatDate($value),
        ]);
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
            ['balance'     => lang('Accounting_payables.balance'), 'escape' => false],
            ['open'        => lang('Accounting_payables.open'), 'sortable' => false, 'escape' => false],
            ['credit'      => lang('Accounting_payables.credit'), 'sortable' => false, 'escape' => false],
            ['age_0_30'    => lang('Accounting_payables.age_0_30'), 'sortable' => false, 'escape' => false],
            ['age_31_60'   => lang('Accounting_payables.age_31_60'), 'sortable' => false, 'escape' => false],
            ['age_61_90'   => lang('Accounting_payables.age_61_90'), 'sortable' => false, 'escape' => false],
            ['age_91_plus' => lang('Accounting_payables.age_91_plus'), 'sortable' => false, 'escape' => false],
            ['agrees'      => lang('Accounting_payables.agrees'), 'sortable' => false, 'escape' => false],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(SupplierPosition $position, string $name): array
    {
        $aging = $position->aging(date('Y-m-d'));

        return [
            'supplier_id'   => $position->supplierId,
            'supplier_name' => $name,
            'balance'       => $this->amountCell($this->formatAmount($position->accountBalance)),
            'open'          => $this->amountCell($this->formatAmount($position->open())),
            'credit'        => $this->amountCell($this->formatAmount($position->credit())),
            'age_0_30'      => $this->amountCell($this->formatAmount($aging['0-30'])),
            'age_31_60'     => $this->amountCell($this->formatAmount($aging['31-60'])),
            'age_61_90'     => $this->amountCell($this->formatAmount($aging['61-90'])),
            'age_91_plus'   => $this->amountCell($this->formatAmount($aging['91+'])),
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
