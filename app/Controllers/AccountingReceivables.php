<?php

namespace App\Controllers;

use App\Libraries\Accounting\CustomerLedger;
use App\Libraries\Accounting\CustomerPosition;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * What the customers owe and what they hold in credit, by customer and by invoice. It speaks for
 * the whole company: the credit of a customer is spent on invoices of any branch, so a person
 * limited to some branches does not see it.
 */
class AccountingReceivables extends AccountingController
{
    private const MAX_PAGE_SIZE = 500;

    private CustomerLedger $ledger;

    public function __construct()
    {
        parent::__construct('accounting_receivables');

        $this->ledger = new CustomerLedger();
    }

    public function getIndex(): string
    {
        $this->requireCompanyScope();

        return view('accounting_receivables/manage', [
            'table_headers' => transform_headers($this->headers(), true, true),
        ]);
    }

    public function getSearch(): ResponseInterface
    {
        $this->requireCompanyScope();

        $limit  = max(1, min((int) $this->request->getGet('limit') ?: self::DEFAULT_PAGE_SIZE, self::MAX_PAGE_SIZE));
        $offset = max(0, (int) $this->request->getGet('offset'));
        $result = $this->ledger->customersWithBalance(
            trim((string) $this->request->getGet('search')),
            (string) $this->request->getGet('sort'),
            (string) $this->request->getGet('order'),
            $limit,
            $offset,
        );

        return $this->response->setJSON([
            'total' => $result['total'],
            'rows'  => array_map(fn (object $customer): array => $this->row($this->ledger->position((int) $customer->customer_id), (string) $customer->customer_name), $result['rows']),
        ]);
    }

    public function getRow(int $customerId): ResponseInterface
    {
        $this->requireCompanyScope();

        $position = $this->ledger->position($customerId);

        if ($position->accountId === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->response->setJSON($this->row($position, $this->customerName($customerId)));
    }

    public function getCustomer(int $customerId): string
    {
        $this->requireCompanyScope();

        $position = $this->ledger->position($customerId);

        if ($position->accountId === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('accounting_receivables/customer', [
            'customer_name' => $this->customerName($customerId),
            'position'      => $position,
            'today'         => date('Y-m-d'),
            'applications'  => $this->ledger->applications($customerId, true),
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
            ['customer_name' => lang('Accounting_receivables.customer')],
            ['balance'     => lang('Accounting_receivables.balance'), 'escape' => false],
            ['open'        => lang('Accounting_receivables.open'), 'sortable' => false, 'escape' => false],
            ['credit'      => lang('Accounting_receivables.credit'), 'sortable' => false, 'escape' => false],
            ['age_0_30'    => lang('Accounting_receivables.age_0_30'), 'sortable' => false, 'escape' => false],
            ['age_31_60'   => lang('Accounting_receivables.age_31_60'), 'sortable' => false, 'escape' => false],
            ['age_61_90'   => lang('Accounting_receivables.age_61_90'), 'sortable' => false, 'escape' => false],
            ['age_91_plus' => lang('Accounting_receivables.age_91_plus'), 'sortable' => false, 'escape' => false],
            ['agrees'      => lang('Accounting_receivables.agrees'), 'sortable' => false, 'escape' => false],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(CustomerPosition $position, string $name): array
    {
        $aging = $position->aging(date('Y-m-d'));

        return [
            'customer_id'   => $position->customerId,
            'customer_name' => $name,
            'balance'       => $this->amountCell($this->formatAmount($position->accountBalance)),
            'open'          => $this->amountCell($this->formatAmount($position->open())),
            'credit'        => $this->amountCell($this->formatAmount($position->credit())),
            'age_0_30'      => $this->amountCell($this->formatAmount($aging['0-30'])),
            'age_31_60'     => $this->amountCell($this->formatAmount($aging['31-60'])),
            'age_61_90'     => $this->amountCell($this->formatAmount($aging['61-90'])),
            'age_91_plus'   => $this->amountCell($this->formatAmount($aging['91+'])),
            'agrees'        => $position->agrees()
                ? '<span class="badge text-bg-success">' . esc(lang('Accounting_receivables.agrees_yes')) . '</span>'
                : '<span class="badge text-bg-warning">' . esc(lang('Accounting_receivables.agrees_no', [$this->formatAmount($position->unclassified())])) . '</span>',
            'edit' => anchor('accounting_receivables/customer/' . $position->customerId, '<span class="bi bi-eye" aria-hidden="true"></span>', ['title' => lang('Accounting_receivables.statement')]),
        ];
    }

    private function customerName(int $customerId): string
    {
        return $this->ledger->accountName($customerId);
    }
}
