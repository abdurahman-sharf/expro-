<?php

namespace App\Controllers;

use App\Libraries\Accounting\CustomerDocuments;
use App\Libraries\Accounting\CustomerLedger;
use App\Libraries\Accounting\CustomerPosition;
use App\Libraries\Accounting\PaymentStatus;
use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\ReturnDocument;
use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Organization\BranchService;
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
    private CustomerDocuments $documents;

    public function __construct()
    {
        parent::__construct('accounting_receivables');

        $this->ledger    = new CustomerLedger();
        $this->documents = new CustomerDocuments();
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

        $returns = $this->documents->returnTotals(array_map(static fn (object $customer): int => (int) $customer->customer_id, $result['rows']));

        return $this->response->setJSON([
            'total' => $result['total'],
            'rows'  => array_map(fn (object $customer): array => $this->row($this->ledger->position((int) $customer->customer_id), (string) $customer->customer_name, $returns[(int) $customer->customer_id]), $result['rows']),
        ]);
    }

    public function getRow(int $customerId): ResponseInterface
    {
        $this->requireCompanyScope();

        $position = $this->ledger->position($customerId);

        if ($position->accountId === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->response->setJSON($this->row($position, $this->customerName($customerId), $this->documents->returnTotals([$customerId])[$customerId]));
    }

    public function getCustomer(int $customerId): string
    {
        $this->requireCompanyScope();

        $position = $this->ledger->position($customerId);

        if ($position->accountId === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('accounting_receivables/customer', [
            'customer_name'   => $this->customerName($customerId),
            'position'        => $position,
            'today'           => date('Y-m-d'),
            'applications'    => $this->ledger->applications($customerId, true),
            'refund_invoices' => $this->documents->refundInvoiceIds($customerId),
            'amount'          => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'date'            => fn (string $value): string => $this->formatDate($value),
        ]);
    }

    public function getPayments(): string
    {
        $seesBranches = $this->seesBranches();

        return view('accounting_receivables/payments', [
            'table_headers' => transform_headers($this->paymentHeaders($seesBranches), true, false),
            'branches'      => $seesBranches ? (new BranchService())->all($this->employeeId()) : [],
            'collectors'    => $this->documents->collectors($this->branchIds()),
            'ways'          => $this->wayOptions(),
            'statuses'      => $this->statusOptions(),
            'customer_id'   => max(0, (int) $this->request->getGet('customer_id')),
            'limited'       => $this->scopeNoticeNeeded(),
            'company_scope' => $this->branchGuard()->isUnrestricted($this->employeeId()),
        ]);
    }

    public function getPaymentsSearch(): ResponseInterface
    {
        $limit  = max(1, min((int) $this->request->getGet('limit') ?: self::DEFAULT_PAGE_SIZE, self::MAX_PAGE_SIZE));
        $offset = max(0, (int) $this->request->getGet('offset'));
        $result = $this->documents->payments(
            [
                'date_from'   => (string) $this->request->getGet('date_from'),
                'date_to'     => (string) $this->request->getGet('date_to'),
                'customer_id' => (int) $this->request->getGet('customer_id'),
                'customer'    => (string) $this->request->getGet('customer'),
                'way'         => (string) $this->request->getGet('way'),
                'employee_id' => (int) $this->request->getGet('employee_id'),
                'branch_id'   => (int) $this->request->getGet('branch_id'),
                'status'      => (string) $this->request->getGet('status'),
                'search'      => trim((string) $this->request->getGet('search')),
            ],
            (string) $this->request->getGet('sort'),
            (string) $this->request->getGet('order'),
            $limit,
            $offset,
            $this->branchIds(),
        );

        $seesBranches = $this->seesBranches();

        return $this->response->setJSON([
            'total'      => $result['total'],
            'rows'       => array_map(fn (object $payment): array => $this->paymentRow($payment, $seesBranches), $result['rows']),
            'sum_amount' => $this->amountCell($this->formatAmount($result['amount'])),
            'sum_posted' => $this->amountCell($this->formatAmount($result['posted'])),
        ]);
    }

    public function getCreditNotes(): string
    {
        $this->requireCompanyScope();

        return view('accounting_receivables/credit_notes', [
            'table_headers' => transform_headers($this->creditNoteHeaders(), true, false),
            'branches'      => $this->seesBranches() ? (new BranchService())->all($this->employeeId()) : [],
            'documents'     => [ReturnDocument::CreditNote, ReturnDocument::RefundInvoice],
            'customer_id'   => max(0, (int) $this->request->getGet('customer_id')),
        ]);
    }

    public function getCreditNotesSearch(): ResponseInterface
    {
        $this->requireCompanyScope();

        $limit  = max(1, min((int) $this->request->getGet('limit') ?: self::DEFAULT_PAGE_SIZE, self::MAX_PAGE_SIZE));
        $offset = max(0, (int) $this->request->getGet('offset'));
        $result = $this->documents->returns(
            [
                'date_from'   => (string) $this->request->getGet('date_from'),
                'date_to'     => (string) $this->request->getGet('date_to'),
                'customer_id' => (int) $this->request->getGet('customer_id'),
                'customer'    => (string) $this->request->getGet('customer'),
                'document'    => (string) $this->request->getGet('document'),
                'branch_id'   => (int) $this->request->getGet('branch_id'),
                'search'      => trim((string) $this->request->getGet('search')),
            ],
            (string) $this->request->getGet('sort'),
            (string) $this->request->getGet('order'),
            $limit,
            $offset,
        );

        $seesBranches = $this->seesBranches();

        return $this->response->setJSON([
            'total' => $result['total'],
            'rows'  => array_map(fn (array $return): array => $this->creditNoteRow($return, $seesBranches), $result['rows']),
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
            ['returns'     => lang('Accounting_receivables.returns'), 'sortable' => false, 'escape' => false],
            ['adjustments' => lang('Accounting_receivables.adjustments'), 'sortable' => false, 'escape' => false],
            ['age_0_30'    => lang('Accounting_receivables.age_0_30'), 'sortable' => false, 'escape' => false],
            ['age_31_60'   => lang('Accounting_receivables.age_31_60'), 'sortable' => false, 'escape' => false],
            ['age_61_90'   => lang('Accounting_receivables.age_61_90'), 'sortable' => false, 'escape' => false],
            ['age_91_plus' => lang('Accounting_receivables.age_91_plus'), 'sortable' => false, 'escape' => false],
            ['agrees'      => lang('Accounting_receivables.agrees'), 'sortable' => false, 'escape' => false],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function paymentHeaders(bool $seesBranches): array
    {
        return [
            ['number' => lang('Accounting_receivables.number'), 'escape' => false],
            ['payment_time'  => lang('Accounting_receivables.date'), 'escape' => false],
            ['customer_name' => lang('Accounting_receivables.customer'), 'escape' => false],
            ['sale'          => lang('Accounting_receivables.sale'), 'sortable' => false, 'escape' => false],
            ['amount'        => lang('Accounting_receivables.amount'), 'escape' => false],
            ['posted'        => lang('Accounting_receivables.posted'), 'sortable' => false, 'escape' => false],
            ['way'           => lang('Accounting_receivables.way'), 'sortable' => false, 'escape' => false],
            ['treasury'      => lang('Accounting_receivables.treasury'), 'sortable' => false, 'escape' => false],
            ['collected_by'  => lang('Accounting_receivables.collected_by'), 'sortable' => false, 'escape' => false],
            ['status'        => lang('Accounting_receivables.status'), 'sortable' => false, 'escape' => false],
            ...($seesBranches ? [['branch' => lang('Accounting_receivables.branch'), 'escape' => false]] : []),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function creditNoteHeaders(): array
    {
        return [
            ['number' => lang('Accounting_receivables.number'), 'escape' => false],
            ['document'      => lang('Accounting_receivables.document'), 'sortable' => false, 'escape' => false],
            ['sale_time'     => lang('Accounting_receivables.date'), 'escape' => false],
            ['origin'        => lang('Accounting_receivables.original_invoice'), 'sortable' => false, 'escape' => false],
            ['customer_name' => lang('Accounting_receivables.customer'), 'escape' => false],
            ['amount'        => lang('Accounting_receivables.amount'), 'sortable' => false, 'escape' => false],
            ['settled'       => lang('Accounting_receivables.settled'), 'sortable' => false, 'escape' => false],
            ['open'          => lang('Accounting_receivables.still_open'), 'sortable' => false, 'escape' => false],
            ...($this->seesBranches() ? [['branch' => lang('Accounting_receivables.branch'), 'escape' => false]] : []),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function wayOptions(): array
    {
        $ways = [];

        foreach (PaymentType::cases() as $type) {
            if ($type !== PaymentType::Due) {
                $ways[$type->value] = lang($type->languageKey());
            }
        }

        return $ways;
    }

    /**
     * @return array<string, string>
     */
    private function statusOptions(): array
    {
        $statuses = [];

        foreach (PaymentStatus::cases() as $status) {
            $statuses[$status->value] = lang($status->languageKey());
        }

        return $statuses;
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentRow(object $payment, bool $seesBranches): array
    {
        $amount = bcadd((string) $payment->amount, '0', SaleAmounts::SCALE);
        $posted = bcadd((string) $payment->posted, '0', SaleAmounts::SCALE);
        $number = $payment->document_number !== null ? (string) $payment->document_number : (string) $payment->payment_id;
        $type   = PaymentType::fromLabel((string) $payment->payment_type);
        $status = PaymentStatus::fromStored((string) $payment->payment_status);

        $tie = match (true) {
            bccomp($posted, $amount, SaleAmounts::SCALE) === 0 && bccomp($posted, '0', SaleAmounts::SCALE) !== 0 => 'booked',
            bccomp($posted, '0', SaleAmounts::SCALE) === 0                                                       => 'unbooked',
            default                                                                                              => 'differs',
        };

        return [
            'payment_id'    => (int) $payment->payment_id,
            'number'        => anchor('sales/receipt/' . (int) $payment->sale_id, esc($number), ['target' => '_blank', 'dir' => 'ltr']),
            'payment_time'  => esc($this->formatDateTime((string) $payment->payment_time)),
            'customer_name' => $payment->customer_id === null ? esc(lang('Accounting_receivables.counter_customer')) : $this->customerCell((int) $payment->customer_id, (string) $payment->customer_name),
            'sale'          => anchor('sales/receipt/' . (int) $payment->sale_id, esc('POS ' . (int) $payment->sale_id), ['target' => '_blank', 'dir' => 'ltr']) . ((string) $payment->invoice_number === '' ? '' : ' <bdi dir="ltr">' . esc((string) $payment->invoice_number) . '</bdi>'),
            'amount'        => $this->amountCell($this->formatAmount($amount)),
            'posted'        => $this->amountCell($this->formatAmount($posted)) . ' <span class="badge ' . ($tie === 'booked' ? 'text-bg-success' : ($tie === 'differs' ? 'text-bg-warning' : 'text-bg-secondary')) . '">' . esc(lang('Accounting_receivables.tie_' . $tie)) . '</span>',
            'way'           => esc($type === null ? (string) $payment->payment_type : lang($type->languageKey()) . (str_contains((string) $payment->payment_type, ':') ? ':' . explode(':', (string) $payment->payment_type, 2)[1] : '')) . ((string) $payment->reference_code === '' ? '' : ' <bdi dir="ltr">' . esc((string) $payment->reference_code) . '</bdi>'),
            'treasury'      => esc((string) ($payment->treasury_name ?? '')),
            'collected_by'  => esc((string) ($payment->employee_name ?? '')),
            'status'        => '<span class="badge ' . ($status->posts() ? 'text-bg-success' : 'text-bg-secondary') . '">' . esc(lang($status->languageKey())) . '</span>',
            ...($seesBranches ? ['branch' => esc($payment->branch_code . ' - ' . $payment->branch_name)] : []),
        ];
    }

    /**
     * @param array<string, mixed> $return
     *
     * @return array<string, mixed>
     */
    private function creditNoteRow(array $return, bool $seesBranches): array
    {
        $document = $return['document'];
        $number   = $return['document_number'] ?? 'POS ' . $return['sale_id'];
        $origin   = $return['origin_id'] === null
            ? esc(lang('Accounting_receivables.no_original'))
            : anchor('sales/receipt/' . $return['origin_id'], esc($return['origin_number'] !== '' ? $return['origin_number'] : 'POS ' . $return['origin_id']), ['target' => '_blank', 'dir' => 'ltr']);

        return [
            'sale_id'       => $return['sale_id'],
            'number'        => anchor('sales/receipt/' . $return['sale_id'], esc($number), ['target' => '_blank', 'dir' => 'ltr']),
            'document'      => esc(lang($document->languageKey())),
            'sale_time'     => esc($this->formatDateTime($return['sale_time'])),
            'origin'        => $origin,
            'customer_name' => $return['customer_id'] === null ? esc(lang('Accounting_receivables.counter_customer')) : $this->customerCell($return['customer_id'], $return['customer_name']),
            'amount'        => $this->amountCell($this->formatAmount($return['amount'])),
            'settled'       => $return['settled'] === null ? '<span class="badge text-bg-secondary">' . esc(lang('Accounting_receivables.not_in_books')) . '</span>' : $this->amountCell($this->formatAmount($return['settled'])),
            'open'          => $return['open'] === null ? '' : $this->amountCell($this->formatAmount($return['open'])),
            ...($seesBranches ? ['branch' => esc($return['branch_code'] . ' - ' . $return['branch_name'])] : []),
        ];
    }

    private function customerCell(int $customerId, string $name): string
    {
        return $this->branchGuard()->isUnrestricted($this->employeeId())
            ? anchor('accounting_receivables/customer/' . $customerId, esc($name))
            : esc($name);
    }

    /**
     * @param array{returns: numeric-string, adjustments: numeric-string} $returns
     *
     * @return array<string, mixed>
     */
    private function row(CustomerPosition $position, string $name, array $returns): array
    {
        $aging = $position->aging(date('Y-m-d'));

        return [
            'customer_id'   => $position->customerId,
            'customer_name' => $name,
            'balance'       => $this->amountCell($this->formatAmount($position->accountBalance)),
            'open'          => $this->amountCell($this->formatAmount($position->open())),
            'credit'        => $this->amountCell($this->formatAmount($position->credit())),
            'returns'       => $this->amountCell($this->formatAmount($returns['returns'])),
            'adjustments'   => $this->amountCell($this->formatAmount($returns['adjustments'])),
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
