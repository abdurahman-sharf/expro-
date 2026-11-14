<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\AgedLedgerTable;
use App\Libraries\Accounting\CreditLimit;
use App\Libraries\Accounting\CreditRefunds;
use App\Libraries\Accounting\CustomerAdvances;
use App\Libraries\Accounting\CustomerBalanceReport;
use App\Libraries\Accounting\Currencies;
use App\Libraries\Accounting\CustomerDocuments;
use App\Libraries\Accounting\CustomerLedger;
use App\Libraries\Accounting\CustomerOpening;
use App\Libraries\Accounting\CustomerPosition;
use App\Libraries\Accounting\PartyReportCsv;
use App\Libraries\Accounting\PartyReportQuery;
use App\Libraries\Accounting\Money;
use App\Libraries\Accounting\PaymentStatus;
use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\ReturnDocument;
use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Accounting\SalesDebitNotes;
use App\Libraries\Accounting\TreasuryScopes;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\MasterData\PartyContacts;
use App\Libraries\Organization\BranchService;
use App\Libraries\Pricing\SalesControlSettings;
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
    private CustomerBalanceReport $balances;
    private CustomerOpening $openings;
    private CustomerAdvances $advances;
    private CreditRefunds $refunds;

    public function __construct()
    {
        parent::__construct('accounting_receivables');

        $this->ledger    = new CustomerLedger();
        $this->balances  = new CustomerBalanceReport();
        $this->documents = new CustomerDocuments();
        $this->openings  = new CustomerOpening(null, null, $this->branchGuard());
        $this->advances  = new CustomerAdvances(null, null, $this->branchGuard());
        $this->refunds   = new CreditRefunds(CreditRefunds::CUSTOMER, null, null, $this->branchGuard());
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

        $query    = PartyReportQuery::forAging($this->request->getGet(), date('Y-m-d'));
        $position = $this->ledger->position($customerId, $query->asOf);

        if ($position->accountId === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('accounting_receivables/customer', [
            'customer_name'   => $this->customerName($customerId),
            'position'        => $position,
            'today'           => $query->day(),
            'error'           => $query->refusal === null ? null : $this->messageFor($query->refusal),
            'to'              => $query->to,
            'applications'    => $this->ledger->applications($customerId, true),
            'refund_invoices' => $this->documents->refundInvoiceIds($customerId),
            'customer_id'     => $customerId,
            'openings'        => $this->openings->history($customerId),
            'can_open'        => $this->can(AccountingGuard::OPENING_MANAGE) && (new AccountingSettings())->customerOpeningEnabled() && $this->openings->standingFor($customerId) === null,
            'can_change'      => $this->can(AccountingGuard::OPENING_MANAGE),
            'can_revise'      => (new AccountingSettings())->customerOpeningEnabled(),
            'advances'        => $this->advances->forCustomer($customerId),
            'refunds'         => $this->refunds->forParty($customerId),
            'can_manage'      => $this->can(AccountingGuard::RECEIVABLES_MANAGE),
            'can_refund'      => $this->can(AccountingGuard::RECEIVABLES_REFUND),
            'creditTerms'     => $this->creditTermsShown($customerId),
            'contacts'        => (new PartyContacts())->forParty(PartyContacts::CUSTOMER, $customerId),
            'amount'          => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'date'            => fn (string $value): string => $this->formatDate($value),
            'debit_note_of'   => static fn (int $key): string => (new SalesDebitNotes())->numberOf((int) SalesDebitNotes::idOfKey($key)),
            'debit_note_number' => static fn (int $debitNoteId): string => (new SalesDebitNotes())->numberOf($debitNoteId),
        ]);
    }

    /**
     * The credit limit and the credit days of a customer for the head of the statement, each shown
     * only while its control is on.
     *
     * @return array{limit: string|null, days: int|null}
     */
    private function creditTermsShown(int $customerId): array
    {
        $settings = new SalesControlSettings();
        $terms    = (new CreditLimit())->termsOf($customerId);

        return ['limit' => $settings->creditLimitEnabled() ? $terms['limit'] : null, 'days' => $settings->creditPeriodEnabled() ? $terms['days'] : null];
    }

    public function getOpeningForm(int $customerId): string
    {
        $this->requireOpeningManage($customerId);

        if ($this->openings->standingFor($customerId) !== null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('accounting_receivables/opening_form', [
            'action'        => 'accounting_receivables/opening/' . $customerId,
            'customer_name' => $this->customerName($customerId),
            'opening'       => null,
            'today'         => date('Y-m-d'),
        ]);
    }

    public function postOpening(int $customerId): ResponseInterface
    {
        $this->requireCompanyScope();

        return $this->attempt(function () use ($customerId): array {
            $id = $this->openings->create(
                $customerId,
                (string) $this->request->getPost('side'),
                trim((string) $this->request->getPost('amount')),
                trim((string) $this->request->getPost('opening_date')),
                (string) $this->request->getPost('note'),
                $this->employeeId(),
            );

            return ['success' => true, 'message' => lang('Accounting_receivables.opening_done'), 'id' => $id];
        });
    }

    public function getOpeningEditForm(int $openingId): string
    {
        $opening = $this->standingOpening($openingId);

        if (! (new AccountingSettings())->customerOpeningEnabled()) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('accounting_receivables/opening_form', [
            'action'        => 'accounting_receivables/opening_edit/' . $openingId,
            'customer_name' => $this->customerName((int) $opening->customer_id),
            'opening'       => $opening,
            'today'         => date('Y-m-d'),
        ]);
    }

    public function postOpeningEdit(int $openingId): ResponseInterface
    {
        $this->requireCompanyScope();

        return $this->attempt(function () use ($openingId): array {
            $id = $this->openings->revise(
                $openingId,
                (string) $this->request->getPost('side'),
                trim((string) $this->request->getPost('amount')),
                trim((string) $this->request->getPost('opening_date')),
                (string) $this->request->getPost('note'),
                (string) $this->request->getPost('reason'),
                $this->employeeId(),
            );

            return ['success' => true, 'message' => lang('Accounting_receivables.opening_revised'), 'id' => $id];
        });
    }

    public function getOpeningCancelForm(int $openingId): string
    {
        $this->standingOpening($openingId);

        return view('accounting_receivables/opening_cancel_form', ['opening_id' => $openingId]);
    }

    public function postOpeningCancel(int $openingId): ResponseInterface
    {
        $this->requireCompanyScope();

        return $this->attempt(function () use ($openingId): array {
            $this->openings->cancel($openingId, (string) $this->request->getPost('reason'), $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_receivables.opening_cancelled'), 'id' => $openingId];
        });
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
            'csvUrl'        => base_url('accounting_receivables/aged_ledger/csv') . '?' . http_build_query($query->agingFilters()),
            'error'         => $query->refusal === null ? null : $this->messageFor($query->refusal),
            'amount'        => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'date'          => fn (string $value): string => $this->formatDate($value),
        ]);
    }

    public function getAgedLedgerCsv(): ResponseInterface
    {
        $this->requireCompanyScope();

        $query = PartyReportQuery::forAging($this->request->getGet(), date('Y-m-d'));

        if ($query->refusal !== null) {
            return $this->refusedFile($this->messageFor($query->refusal));
        }

        $table = new AgedLedgerTable($query->day(), $query->days, $this->ledger->agedLedger($query->day(), $query->days));

        return $this->csvFile('aged_ledger_customer_' . $query->day() . '.csv', (new PartyReportCsv())->aged($table, 'Accounting_receivables', 'customer'));
    }

    public function getBalancesCsv(): ResponseInterface
    {
        $this->requireCompanyScope();

        $query = PartyReportQuery::forBalances($this->request->getGet(), date('Y-m-d'));

        if ($query->refusal !== null) {
            return $this->refusedFile($this->messageFor($query->refusal));
        }

        return $this->csvFile('balances_customer_' . ($query->from === '' ? '' : $query->from . '_') . $query->day() . '.csv', (new PartyReportCsv())->balances($this->balances->build($query->period, ! $query->showEmpty), 'Accounting_receivables', 'customer'));
    }

    private function refusedFile(string $message): ResponseInterface
    {
        return $this->response->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)->setBody($message);
    }

    private function csvFile(string $name, string $text): ResponseInterface
    {
        return $this->response
            ->setContentType('text/csv', 'UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $name . '"')
            ->setBody("\xEF\xBB\xBF" . $text);
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
            'csvUrl'        => base_url('accounting_receivables/balances/csv') . '?' . http_build_query($query->balanceFilters()),
            'error'         => $query->refusal === null ? null : $this->messageFor($query->refusal),
            'amount'        => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'date'          => fn (string $value): string => $this->formatDate($value),
        ]);
    }

    public function getAdvanceForm(int $customerId): string
    {
        $this->requireMay(AccountingGuard::RECEIVABLES_MANAGE, $customerId);

        return view('accounting_receivables/advance_form', [
            'customer_id'     => $customerId,
            'customer_name'   => $this->customerName($customerId),
            'payment_options' => $this->cashWays(),
            'treasuries'      => $this->treasuryChoices(TreasuryScopes::DEPOSIT),
            'today'           => date('Y-m-d'),
            'request_key'     => bin2hex(random_bytes(16)),
        ]);
    }

    public function postAdvance(int $customerId): ResponseInterface
    {
        $this->requireCompanyScope();

        return $this->attempt(function () use ($customerId): array {
            $paymentType = trim((string) $this->request->getPost('payment_type'));
            $refused     = $this->paymentMethodRefusal($paymentType);

            if ($refused !== null) {
                return $refused;
            }

            $treasuryId = (int) $this->request->getPost('treasury_id');

            $id = $this->advances->receive(
                $customerId,
                trim((string) $this->request->getPost('amount')),
                trim((string) $this->request->getPost('advance_date')),
                $paymentType,
                $treasuryId > 0 ? $treasuryId : null,
                (string) $this->request->getPost('reference_code'),
                (string) $this->request->getPost('note'),
                $this->employeeId(),
                (string) $this->request->getPost('request_key'),
            );

            return ['success' => true, 'message' => lang('Accounting_receivables.advance_done'), 'id' => $id];
        });
    }

    public function getRefundForm(int $customerId): string
    {
        $this->requireMay(AccountingGuard::RECEIVABLES_REFUND, $customerId);

        return view('accounting_receivables/refund_form', [
            'customer_id'     => $customerId,
            'customer_name'   => $this->customerName($customerId),
            'payment_options' => $this->cashWays(),
            'treasuries'      => $this->treasuryChoices(TreasuryScopes::WITHDRAW),
            'today'           => date('Y-m-d'),
            'free'            => $this->amountCell($this->formatAmount($this->refunds->available($customerId))),
            'request_key'     => bin2hex(random_bytes(16)),
        ]);
    }

    public function postRefund(int $customerId): ResponseInterface
    {
        $this->requireCompanyScope();

        return $this->attempt(function () use ($customerId): array {
            $paymentType = trim((string) $this->request->getPost('payment_type'));
            $refused     = $this->paymentMethodRefusal($paymentType);

            if ($refused !== null) {
                return $refused;
            }

            $treasuryId = (int) $this->request->getPost('treasury_id');

            $id = $this->refunds->refund(
                $customerId,
                trim((string) $this->request->getPost('amount')),
                trim((string) $this->request->getPost('refund_date')),
                $paymentType,
                $treasuryId > 0 ? $treasuryId : null,
                (string) $this->request->getPost('reference_code'),
                (string) $this->request->getPost('note'),
                $this->employeeId(),
                (string) $this->request->getPost('request_key'),
            );

            return ['success' => true, 'message' => lang('Accounting_receivables.refund_done'), 'id' => $id];
        });
    }

    public function getCancelForm(string $what, int $id): string
    {
        $this->requireCompanyScope();

        $permission = $what === CustomerAdvances::SOURCE ? AccountingGuard::RECEIVABLES_MANAGE : AccountingGuard::RECEIVABLES_REFUND;
        $branch     = match ($what) {
            CustomerAdvances::SOURCE       => $this->advances->branchOf($id),
            CreditRefunds::CUSTOMER_SOURCE => $this->refunds->branchOf($id),
            default                        => null,
        };

        if ($branch === null || ! $this->can($permission)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('accounting_receivables/cancel_form', ['what' => $what, 'id' => $id]);
    }

    public function postCancel(string $what, int $id): ResponseInterface
    {
        $this->requireCompanyScope();

        return $this->attempt(function () use ($what, $id): array {
            $reason = (string) $this->request->getPost('reason');

            match ($what) {
                CustomerAdvances::SOURCE       => $this->advances->cancel($id, $reason, $this->employeeId()),
                CreditRefunds::CUSTOMER_SOURCE => $this->refunds->cancel($id, $reason, $this->employeeId()),
                default                        => throw PageNotFoundException::forPageNotFound(),
            };

            return ['success' => true, 'message' => lang('Accounting_receivables.cancelled_done'), 'id' => $id];
        });
    }

    private function requireMay(string $permission, int $customerId): void
    {
        $this->requireCompanyScope();

        if (! $this->can($permission) || $this->ledger->position($customerId)->accountId === null) {
            throw PageNotFoundException::forPageNotFound();
        }
    }


    private function requireOpeningManage(int $customerId): void
    {
        $this->requireCompanyScope();

        if (! $this->can(AccountingGuard::OPENING_MANAGE) || ! (new AccountingSettings())->customerOpeningEnabled() || $this->ledger->position($customerId)->accountId === null) {
            throw PageNotFoundException::forPageNotFound();
        }
    }


    private function standingOpening(int $openingId): object
    {
        $this->requireCompanyScope();

        $opening = $this->can(AccountingGuard::OPENING_MANAGE) ? $this->openings->find($openingId) : null;

        if ($opening === null || $opening->status !== 'posted') {
            throw PageNotFoundException::forPageNotFound();
        }

        return $opening;
    }

    /**
     * The ways a customer pays or is paid back: the ways of payment a sale takes, less credit
     * sales, which move no money through a treasury.
     *
     * @return array<string, string>
     */
    private function cashWays(): array
    {
        $options = get_payment_options();

        unset($options[lang('Sales.due')]);

        return $options;
    }

    /**
     * The treasuries the employee may use in the direction, with the one that follows the way of payment first.
     *
     * @return array<int, string>
     */
    private function treasuryChoices(string $direction): array
    {
        $choices = [0 => lang('Accounting_receivables.treasury_default')];

        foreach ((new TreasuryScopes())->usable((new TreasuryService())->all($this->branchIds(), false), $direction, $this->employeeId()) as $treasury) {
            $choices[(int) $treasury->treasury_id] = $treasury->name . ' (' . $treasury->branch_code . ')';
        }

        return count($choices) > 2 ? $choices : [];
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
            ['returns'      => lang('Accounting_receivables.returns'), 'sortable' => false, 'escape' => false],
            ['adjustments'  => lang('Accounting_receivables.adjustments'), 'sortable' => false, 'escape' => false],
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
        $amount  = bcadd((string) $payment->amount, '0', SaleAmounts::SCALE);
        $foreign = $payment->currency_code !== null && $payment->currency_rate !== null;
        $inBase  = $foreign ? bcadd((string) $payment->amount_base, '0', SaleAmounts::SCALE) : $amount;
        $posted  = bcadd((string) $payment->posted, '0', SaleAmounts::SCALE);
        $number  = $payment->document_number !== null ? (string) $payment->document_number : (string) $payment->payment_id;
        $type   = PaymentType::fromLabel((string) $payment->payment_type);
        $status = PaymentStatus::fromStored((string) $payment->payment_status);

        $tie = match (true) {
            bccomp($posted, $inBase, SaleAmounts::SCALE) === 0 && bccomp($posted, '0', SaleAmounts::SCALE) !== 0 => 'booked',
            bccomp($posted, '0', SaleAmounts::SCALE) === 0                                                       => 'unbooked',
            default                                                                                              => 'differs',
        };

        return [
            'payment_id'    => (int) $payment->payment_id,
            'number'        => anchor('sales/receipt/' . (int) $payment->sale_id, esc($number), ['target' => '_blank', 'dir' => 'ltr']),
            'payment_time'  => esc($this->formatDateTime((string) $payment->payment_time)),
            'customer_name' => $payment->customer_id === null ? esc(lang('Accounting_receivables.counter_customer')) : $this->customerCell((int) $payment->customer_id, (string) $payment->customer_name),
            'sale'          => anchor('sales/receipt/' . (int) $payment->sale_id, esc('POS ' . (int) $payment->sale_id), ['target' => '_blank', 'dir' => 'ltr']) . ((string) $payment->invoice_number === '' ? '' : ' <bdi dir="ltr">' . esc((string) $payment->invoice_number) . '</bdi>'),
            'amount'        => $this->amountCell($foreign ? Money::format($amount, (new Currencies())->decimalsOf((string) $payment->currency_code), (string) $payment->currency_code) : $this->formatAmount($amount)),
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
        $today = date('Y-m-d');
        $aging = $position->aging($today);

        return [
            'customer_id'   => $position->customerId,
            'customer_name' => $name,
            'balance'       => $this->amountCell($this->formatAmount($position->accountBalance)),
            'open'          => $this->amountCell($this->formatAmount($position->open())),
            'credit'        => $this->amountCell($this->formatAmount($position->credit())),
            'returns'       => $this->amountCell($this->formatAmount($returns['returns'])),
            'adjustments'   => $this->amountCell($this->formatAmount($returns['adjustments'])),
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
