<?php

namespace App\Controllers;

use App\Libraries\Accounting\AgedLedgerTable;
use App\Libraries\Accounting\CustomerBalanceReport;
use App\Libraries\Accounting\CustomerLedger;
use App\Libraries\Accounting\CustomerPosition;
use App\Libraries\Accounting\PartyReportQuery;
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
    private CustomerBalanceReport $balances;

    public function __construct()
    {
        parent::__construct('accounting_receivables');

        $this->ledger   = new CustomerLedger();
        $this->balances = new CustomerBalanceReport();
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

        $query    = PartyReportQuery::forAging($this->request->getGet(), date('Y-m-d'));
        $position = $this->ledger->position($customerId, $query->asOf);

        if ($position->accountId === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('accounting_receivables/customer', [
            'customer_name' => $this->customerName($customerId),
            'position'      => $position,
            'today'         => $query->day(),
            'error'         => $query->refusal === null ? null : $this->messageFor($query->refusal),
            'to'            => $query->to,
            'applications'  => $this->ledger->applications($customerId, true),
            'amount'        => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'date'          => fn (string $value): string => $this->formatDate($value),
        ]);
    }

    public function getAgedLedger(): string
    {
        $this->requireCompanyScope();

        $query = PartyReportQuery::forAging($this->request->getGet(), date('Y-m-d'));

        return view('accounting_parties/aged_ledger', [
            'group'         => 'Accounting_receivables',
            'base'          => 'accounting_receivables',
            'party'         => 'customer',
            'statement_url' => static fn (int $partyId): string => base_url('accounting_receivables/customer/' . $partyId . '?to=' . $query->day()),
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
            'group'         => 'Accounting_receivables',
            'base'          => 'accounting_receivables',
            'party'         => 'customer',
            'statement_url' => static fn (int $partyId): string => base_url('accounting_receivables/customer/' . $partyId),
            'table'         => $this->balances->build($query->period, ! $query->showEmpty),
            'query'         => $query,
            'error'         => $query->refusal === null ? null : $this->messageFor($query->refusal),
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
            ['balance'      => lang('Accounting_receivables.balance'), 'escape' => false],
            ['open'         => lang('Accounting_receivables.open'), 'sortable' => false, 'escape' => false],
            ['credit'       => lang('Accounting_receivables.credit'), 'sortable' => false, 'escape' => false],
            ['overdue'      => lang('Accounting_receivables.overdue'), 'sortable' => false, 'escape' => false],
            ['age_today'    => lang('Accounting_receivables.age_today'), 'sortable' => false, 'escape' => false],
            ['age_1_30'     => lang('Accounting_receivables.age_1_30'), 'sortable' => false, 'escape' => false],
            ['age_31_60'    => lang('Accounting_receivables.age_31_60'), 'sortable' => false, 'escape' => false],
            ['age_61_90'    => lang('Accounting_receivables.age_61_90'), 'sortable' => false, 'escape' => false],
            ['age_91_120'   => lang('Accounting_receivables.age_91_120'), 'sortable' => false, 'escape' => false],
            ['age_over_120' => lang('Accounting_receivables.age_over_120'), 'sortable' => false, 'escape' => false],
            ['agrees'       => lang('Accounting_receivables.agrees'), 'sortable' => false, 'escape' => false],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(CustomerPosition $position, string $name): array
    {
        $today = date('Y-m-d');
        $aging = $position->aging($today);

        return [
            'customer_id'   => $position->customerId,
            'customer_name' => $name,
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
