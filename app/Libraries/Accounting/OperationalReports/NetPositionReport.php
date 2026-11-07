<?php

namespace App\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\CustomerLedger;
use App\Libraries\Accounting\PaymentState;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\ReturnState;
use App\Libraries\Accounting\SaleAmounts;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The position of each invoice of the sales after what was taken back from it: its total, what was settled on
 * it, what its credit notes and refund invoices took back, and the net after returns, which is the total less
 * the returns and does not take the payments off. The state is told by comparing what was settled with the
 * whole total of the invoice, whatever was returned. A credit note or a refund invoice that belongs to no
 * invoice is a document of its own, below nothing, in the state of a credit; it can be left out. Each
 * invoice is read against the position of its customer in the ledger, and the report says whether the net and
 * the settled amounts are what the ledger holds.
 */
final class NetPositionReport
{
    use BuildsBlocks;

    public const STATES   = ['unpaid', 'partial', 'paid', 'excess', 'returned', 'partial_return'];
    private const FIGURES = ['total', 'paid', 'returns', 'net'];

    private BaseConnection $db;
    private ReportDocuments $documents;

    public function __construct(?BaseConnection $db = null, ?ReportDocuments $documents = null)
    {
        $this->db        = $db ?? Database::connect();
        $this->documents = $documents ?? new ReportDocuments($this->db);
    }

    /**
     * @param list<string> $states
     */
    public function build(ReportScope $scope, bool $details, ?string $from, string $to, bool $hideCredits = false, array $states = [], ?int $customerId = null): ReportTable
    {
        $invoices = array_values(array_filter(
            $this->documents->read($scope, $from, $to, [DocumentKind::Invoice], $customerId),
            static fn (ReportDocument $document): bool => self::admits($states, $document),
        ));
        $orphans = $hideCredits || $states !== [] ? [] : $this->documents->orphanReturns($scope, $from, $to, $customerId);
        $returns = $details ? $this->documents->returnsOf($scope, array_map(static fn (ReportDocument $document): int => $document->id, $invoices)) : [];
        $nested  = $details ? $this->nested($invoices) : [];
        $blocks  = [];
        $rows    = [];

        foreach ($invoices as $invoice) {
            $row    = $this->invoiceRow($invoice);
            $rows[] = $row;

            if ($details) {
                $members = [$row];

                foreach ($nested[$invoice->id] ?? [] as $line) {
                    $members[] = $line;
                }

                foreach ($returns[$invoice->id] ?? [] as $return) {
                    $members[] = $this->returnRow($return);
                }

                $blocks[] = new ReportBlock([], $members);
            }
        }

        foreach ($orphans as $orphan) {
            $row    = $this->orphanRow($orphan);
            $rows[] = $row;

            if ($details) {
                $blocks[] = new ReportBlock([], [$row]);
            }
        }

        return new ReportTable(
            scope: $scope,
            from: $from,
            to: $to,
            columns: $this->columns($scope),
            blocks: $details ? $blocks : [new ReportBlock([], $rows)],
            total: $this->addUp($rows, self::FIGURES),
            ties: $this->ties($invoices),
            notes: ['Accounting_operational_reports.note_net_position'],
            documents: count($invoices) + count($orphans),
        );
    }

    /**
     * What the net and the settled amounts of the invoices of customers come to beside what the position of
     * each customer in the ledger says of the same invoices.
     *
     * @param list<ReportDocument> $invoices
     *
     * @return list<TieCheck>
     */
    public function ties(array $invoices): array
    {
        $ledger      = new CustomerLedger($this->db);
        $perCustomer = [];

        foreach ($invoices as $invoice) {
            if ($invoice->customerId !== null) {
                $perCustomer[$invoice->customerId][] = $invoice;
            }
        }

        $reportedNet  = '0';
        $ledgerNet    = '0';
        $reportedPaid = '0';
        $ledgerPaid   = '0';

        foreach ($perCustomer as $customerId => $members) {
            $position = $ledger->position($customerId);

            foreach ($members as $invoice) {
                $figures = $position->invoice($invoice->id);

                $reportedNet  = Amounts::add($reportedNet, Amounts::sub($invoice->total, $invoice->returnedAll()));
                $reportedPaid = Amounts::add($reportedPaid, $invoice->paid());
                $ledgerNet    = Amounts::add($ledgerNet, $figures === null ? Amounts::ZERO : Amounts::sub($figures->total, $figures->returned));
                $ledgerPaid   = Amounts::add($ledgerPaid, $figures === null ? Amounts::ZERO : $figures->settled());
            }
        }

        return [
            new TieCheck('Accounting_operational_reports.tie_net_position', $reportedNet, $ledgerNet),
            new TieCheck('Accounting_operational_reports.tie_net_paid', $reportedPaid, $ledgerPaid),
        ];
    }

    /**
     * @param list<string> $states
     */
    private static function admits(array $states, ReportDocument $invoice): bool
    {
        if ($states === []) {
            return true;
        }

        $payment = $invoice->paymentState();
        $return  = $invoice->returnState();

        return ($payment !== null && in_array($payment->value, $states, true))
            || ($return === ReturnState::Full && in_array('returned', $states, true))
            || ($return === ReturnState::Partial && in_array('partial_return', $states, true));
    }

    /**
     * @return array<string, string>
     */
    private function invoiceRow(ReportDocument $invoice): array
    {
        $returns = Amounts::negate($invoice->returnedAll());

        return [
            'number'   => $invoice->number,
            'kind'     => lang($invoice->kind->languageKey()),
            'date'     => $invoice->date,
            'customer' => $invoice->customerName !== '' ? $invoice->customerName : $this->walkIn(),
            'branch'   => $invoice->branchCode,
            'total'    => $invoice->total,
            'paid'     => $invoice->paid(),
            'returns'  => $returns,
            'net'      => Amounts::add($invoice->total, $returns),
            'status'   => lang($this->stateKey($invoice->paymentState() ?? PaymentState::Unpaid)),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function returnRow(ReportDocument $return): array
    {
        return [
            'number'   => $return->number,
            'kind'     => lang($return->kind->languageKey()),
            'date'     => $return->date,
            'customer' => '',
            'branch'   => $return->branchCode,
            'returns'  => $return->total,
            'status'   => '',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function orphanRow(ReportDocument $orphan): array
    {
        return [
            'number'   => $orphan->number,
            'kind'     => lang($orphan->kind->languageKey()),
            'date'     => $orphan->date,
            'customer' => $orphan->customerName !== '' ? $orphan->customerName : $this->walkIn(),
            'branch'   => $orphan->branchCode,
            'total'    => $orphan->total,
            'paid'     => Amounts::ZERO,
            'returns'  => Amounts::ZERO,
            'net'      => $orphan->total,
            'status'   => lang('Accounting_operational_reports.state_credit'),
        ];
    }

    /**
     * The payments that stand and the credit applied that settled each invoice, as rows to put under it, the
     * payments as what their journals credited the customer, so that the rows add up to what the invoice shows paid.
     *
     * @param list<ReportDocument> $invoices
     *
     * @return array<int, list<array<string, string>>>
     */
    private function nested(array $invoices): array
    {
        $ids    = array_map(static fn (ReportDocument $document): int => $document->id, $invoices);
        $nested = [];

        foreach (array_chunk($ids, 400) as $chunk) {
            foreach ($this->documents->paymentsOf($chunk) as $saleId => $payments) {
                foreach ($payments as $payment) {
                    if (! Amounts::isZero($payment['amount'])) {
                        $nested[$saleId][] = ['number' => '#' . $payment['id'], 'kind' => lang('Accounting_operational_reports.row_payment'), 'date' => $payment['date'], 'customer' => '', 'paid' => $payment['amount'], 'status' => ''];
                    }
                }
            }

            foreach ($this->db->table('customer_credit_applications')->select('application_id, invoice_sale_id, amount, created_at')->whereIn('invoice_sale_id', $chunk)->where('released_at', null)->orderBy('application_id', 'ASC')->get()->getResult() as $application) {
                $nested[(int) $application->invoice_sale_id][] = ['number' => '#' . $application->application_id, 'kind' => lang('Accounting_operational_reports.row_credit_applied'), 'date' => substr((string) $application->created_at, 0, 10), 'customer' => '', 'paid' => bcadd((string) $application->amount, '0', SaleAmounts::SCALE), 'status' => ''];
            }
        }

        return $nested;
    }

    private function stateKey(PaymentState $state): string
    {
        return 'Accounting_operational_reports.state_' . $state->value;
    }

    /**
     * @return list<ReportColumn>
     */
    private function columns(ReportScope $scope): array
    {
        return [
            new ReportColumn('number', 'Accounting_operational_reports.col_number'),
            new ReportColumn('kind', 'Accounting_operational_reports.col_type'),
            new ReportColumn('date', 'Accounting_operational_reports.col_date', ColumnKind::Date),
            new ReportColumn('customer', 'Accounting_operational_reports.col_customer'),
            ...($scope->choosable() ? [new ReportColumn('branch', 'Accounting_operational_reports.col_branch')] : []),
            new ReportColumn('total', 'Accounting_operational_reports.col_total', ColumnKind::Amount),
            new ReportColumn('paid', 'Accounting_operational_reports.col_paid', ColumnKind::Amount),
            new ReportColumn('returns', 'Accounting_operational_reports.col_returns', ColumnKind::Amount),
            new ReportColumn('net', 'Accounting_operational_reports.col_net_after_returns', ColumnKind::Amount),
            new ReportColumn('status', 'Accounting_operational_reports.col_status'),
        ];
    }
}
