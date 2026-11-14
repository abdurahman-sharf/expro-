<?php

namespace App\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\CustomerLedger;
use App\Libraries\Accounting\PaymentState;
use App\Libraries\Accounting\ReportScope;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The invoices that have a return, each with the credit notes and refund invoices under it: the total of the
 * invoice, the amount that was taken back, what is left of the total and the state of its payment, which
 * does not take the return into account. An invoice with no return is not here, and neither is a credit note
 * that belongs to no invoice. What was taken back is what the standing returns of the invoice came to, so
 * its total is what the net position report shows as returns for the same invoices, and the report says
 * whether it is what the ledger holds for the customers.
 */
final class CrossReferenceReport
{
    use BuildsBlocks;

    public const ORDERS   = ['date_desc', 'date_asc', 'customer', 'total_desc', 'total_asc', 'net_desc', 'net_asc'];
    private const FIGURES = ['total', 'returns', 'net'];

    private BaseConnection $db;
    private ReportDocuments $documents;

    public function __construct(?BaseConnection $db = null, ?ReportDocuments $documents = null)
    {
        $this->db        = $db ?? Database::connect();
        $this->documents = $documents ?? new ReportDocuments($this->db);
    }

    /**
     * @param list<string> $states The states of payment of the invoice, any of unpaid, partial, paid and excess.
     */
    public function build(ReportScope $scope, bool $details, bool $byCustomer, ?string $from, string $to, ?int $customerId = null, ?DocumentKind $kind = null, array $states = [], string $order = 'date_desc'): ReportTable
    {
        $refusal = $this->documents->refusal($scope, $from, $to, [DocumentKind::Invoice], $customerId);

        if ($refusal !== null) {
            return $refusal;
        }

        $invoices = array_values(array_filter(
            $this->documents->read($scope, $from, $to, [DocumentKind::Invoice], $customerId),
            static fn (ReportDocument $document): bool => $states === [] || in_array($document->paymentState()?->value ?? PaymentState::Unpaid->value, $states, true),
        ));
        $returns = $this->documents->returnsOf($scope, array_map(static fn (ReportDocument $document): int => $document->id, $invoices));
        $entries = [];

        foreach ($invoices as $invoice) {
            $listed = array_values(array_filter($returns[$invoice->id] ?? [], static fn (ReportDocument $return): bool => $kind === null || $return->kind === $kind));

            if ($listed !== []) {
                $entries[] = [$invoice, $listed];
            }
        }

        usort($entries, static fn (array $a, array $b): int => match ($order) {
            'date_asc'   => [$a[0]->date, $a[0]->id] <=> [$b[0]->date, $b[0]->id],
            'customer'   => [mb_strtolower($a[0]->customerName), $a[0]->id] <=> [mb_strtolower($b[0]->customerName), $b[0]->id],
            'total_desc' => [$b[0]->total, $b[0]->id] <=> [$a[0]->total, $a[0]->id],
            'total_asc'  => [$a[0]->total, $a[0]->id] <=> [$b[0]->total, $b[0]->id],
            'net_desc'   => [self::net($b), $b[0]->id] <=> [self::net($a), $a[0]->id],
            'net_asc'    => [self::net($a), $a[0]->id] <=> [self::net($b), $b[0]->id],
            default      => [$b[0]->date, $b[0]->id] <=> [$a[0]->date, $a[0]->id],
        });

        $headers = [];

        foreach ($entries as [$invoice, $listed]) {
            $headers[] = $this->header($invoice, $listed);
        }

        $total  = $this->addUp($headers, self::FIGURES);
        $blocks = [];

        if ($byCustomer) {
            $groups = [];

            foreach ($entries as $index => [$invoice, $listed]) {
                $groups[(string) ($invoice->customerId ?? 0)][] = $index;
            }

            $summary = [];

            foreach ($groups as $indexes) {
                $name     = $entries[$indexes[0]][0]->customerName !== '' ? $entries[$indexes[0]][0]->customerName : $this->walkIn();
                $members  = array_map(static fn (int $index): array => $headers[$index], $indexes);
                $subtotal = $this->addUp($members, self::FIGURES);

                if ($details) {
                    $rows = [];

                    foreach ($indexes as $index) {
                        $rows = [...$rows, $headers[$index], ...array_map(fn (ReportDocument $return): array => $this->returnRow($return), $entries[$index][1])];
                    }

                    $blocks[] = new ReportBlock($this->heading($name), $rows, $subtotal);
                } else {
                    $summary[] = ['customer' => $name, ...$subtotal];
                }
            }

            if (! $details) {
                $blocks = [new ReportBlock([], $summary)];
            }
        } elseif ($details) {
            foreach ($entries as $index => [$invoice, $listed]) {
                $blocks[] = new ReportBlock([], [$headers[$index], ...array_map(fn (ReportDocument $return): array => $this->returnRow($return), $listed)]);
            }
        } else {
            $blocks = [new ReportBlock([], $headers)];
        }

        return new ReportTable(
            scope: $scope,
            from: $from,
            to: $to,
            columns: $this->columns($byCustomer && ! $details, $scope),
            blocks: $blocks,
            total: $total,
            ties: $kind === null && $states === [] && $customerId === null ? ForeignAmounts::guard([$this->tie($entries)], $this->documentsOf($entries)) : [],
            notes: ['Accounting_operational_reports.note_cross_reference'],
            documents: count($entries),
        );
    }

    /**
     * @param list<array{0: ReportDocument, 1: list<ReportDocument>}> $entries
     *
     * @return list<ReportDocument>
     */
    private function documentsOf(array $entries): array
    {
        $documents = [];

        foreach ($entries as [$invoice, $listed]) {
            $documents = [...$documents, $invoice, ...$listed];
        }

        return $documents;
    }

    /**
     * What the returns listed under the invoices come to beside what the position of each customer in the
     * ledger says was taken back from the same invoices.
     *
     * @param list<array{0: ReportDocument, 1: list<ReportDocument>}> $entries
     */
    private function tie(array $entries): TieCheck
    {
        $ledger   = new CustomerLedger($this->db);
        $reported = '0';
        $held     = '0';
        $position = [];

        foreach ($entries as [$invoice, $listed]) {
            if ($invoice->customerId === null) {
                continue;
            }

            $position[$invoice->customerId] ??= $ledger->position($invoice->customerId);
            $figures = $position[$invoice->customerId]->invoice($invoice->id);

            $reported = Amounts::add($reported, Amounts::negate(Amounts::sum(array_map(static fn (ReportDocument $return): string => $return->total, $listed))));
            $held     = Amounts::add($held, $figures === null ? Amounts::ZERO : $figures->returned);
        }

        return new TieCheck('Accounting_operational_reports.tie_cross_reference', $reported, $held);
    }

    /**
     * @param array{0: ReportDocument, 1: list<ReportDocument>} $entry
     */
    private static function net(array $entry): string
    {
        return Amounts::sub($entry[0]->total, Amounts::negate(Amounts::sum(array_map(static fn (ReportDocument $return): string => $return->total, $entry[1]))));
    }

    /**
     * @param list<ReportDocument> $listed
     *
     * @return array<string, string>
     */
    private function header(ReportDocument $invoice, array $listed): array
    {
        $returns = Amounts::negate(Amounts::sum(array_map(static fn (ReportDocument $return): string => $return->total, $listed)));

        return [
            'number'   => $invoice->number,
            'kind'     => lang($invoice->kind->languageKey()),
            'date'     => $invoice->date,
            'customer' => $invoice->customerName !== '' ? $invoice->customerName : $this->walkIn(),
            'branch'   => $invoice->branchCode,
            'total'    => $invoice->total,
            'returns'  => $returns,
            'net'      => Amounts::sub($invoice->total, $returns),
            'status'   => lang('Accounting_operational_reports.state_' . ($invoice->paymentState() ?? PaymentState::Unpaid)->value),
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
            'returns'  => Amounts::negate($return->total),
            'status'   => '',
        ];
    }

    /**
     * @return list<ReportColumn>
     */
    private function columns(bool $summaryByCustomer, ReportScope $scope): array
    {
        $figures = [
            new ReportColumn('total', 'Accounting_operational_reports.col_invoice_total', ColumnKind::Amount),
            new ReportColumn('returns', 'Accounting_operational_reports.col_return_amount', ColumnKind::Amount),
            new ReportColumn('net', 'Accounting_operational_reports.col_net_amount', ColumnKind::Amount),
        ];

        if ($summaryByCustomer) {
            return [new ReportColumn('customer', 'Accounting_operational_reports.col_customer'), ...$figures];
        }

        return [
            new ReportColumn('number', 'Accounting_operational_reports.col_number'),
            new ReportColumn('kind', 'Accounting_operational_reports.col_type'),
            new ReportColumn('date', 'Accounting_operational_reports.col_date', ColumnKind::Date),
            new ReportColumn('customer', 'Accounting_operational_reports.col_customer'),
            ...($scope->choosable() ? [new ReportColumn('branch', 'Accounting_operational_reports.col_branch')] : []),
            ...$figures,
            new ReportColumn('status', 'Accounting_operational_reports.col_payment_state'),
        ];
    }
}
