<?php

namespace App\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\ReportScope;
use CodeIgniter\Database\BaseConnection;

/**
 * The sales of each customer document by document: its value, what its discount took off, its shipping and
 * adjustment, its tax and its total. The value is what the lines come to after their own discounts and
 * before the discount on the whole invoice, so the total is the value less the discount plus the shipping,
 * the adjustment and the tax, and every column adds up. A credit note and a refund invoice are negative. The
 * documents that are read are the invoices and the returns unless one kind is asked for, and a debit note
 * is read only when it is asked for, so that the total of every kind is the net sales of the revenue
 * report.
 */
final class ClientsSalesReport
{
    use BuildsBlocks;

    public const ORDERS   = ['date_desc', 'date_asc', 'number_desc', 'number_asc'];
    private const FIGURES = ['shipping', 'adjustment', 'value', 'discount', 'tax', 'total'];

    private ReportDocuments $documents;

    public function __construct(?BaseConnection $db = null, ?ReportDocuments $documents = null)
    {
        $this->documents = $documents ?? new ReportDocuments($db);
    }

    public function build(ReportScope $scope, ?DocumentKind $kind, bool $byCustomer, bool $details, ?string $from, string $to, ?int $customerId = null, string $order = 'date_desc'): ReportTable
    {
        $documents = $this->documents->read($scope, $from, $to, $kind === null ? [] : [$kind], $customerId);

        usort($documents, static fn (ReportDocument $a, ReportDocument $b): int => match ($order) {
            'date_asc'    => [$a->date, $a->id] <=> [$b->date, $b->id],
            'number_desc' => strnatcasecmp($b->number, $a->number) ?: $b->id <=> $a->id,
            'number_asc'  => strnatcasecmp($a->number, $b->number) ?: $a->id <=> $b->id,
            default       => [$b->date, $b->id] <=> [$a->date, $a->id],
        });

        $rows   = array_map(fn (ReportDocument $document): array => $this->row($document), $documents);
        $blocks = [];

        if ($byCustomer) {
            $groups = [];

            foreach ($documents as $index => $document) {
                $groups[(string) ($document->customerId ?? 0)][] = $index;
            }

            uasort($groups, static fn (array $a, array $b): int => [mb_strtolower($documents[$a[0]]->customerName), $documents[$a[0]]->customerId ?? 0] <=> [mb_strtolower($documents[$b[0]]->customerName), $documents[$b[0]]->customerId ?? 0]);

            $summary = [];

            foreach ($groups as $indexes) {
                $members  = array_map(static fn (int $index): array => $rows[$index], $indexes);
                $name     = $documents[$indexes[0]]->customerName !== '' ? $documents[$indexes[0]]->customerName : $this->walkIn();
                $subtotal = $this->addUp($members, self::FIGURES);

                if ($details) {
                    $blocks[] = new ReportBlock($this->heading($name), $members, $subtotal);
                } else {
                    $summary[] = ['customer' => $name, ...$subtotal];
                }
            }

            if (! $details) {
                $blocks = [new ReportBlock([], $summary)];
            }
        } else {
            $blocks = [new ReportBlock([], $rows)];
        }

        return new ReportTable(
            scope: $scope,
            from: $from,
            to: $to,
            columns: $this->columns($byCustomer, $details, $scope),
            blocks: $blocks,
            total: $this->addUp($rows, self::FIGURES),
            ties: SalesTies::of($documents),
            notes: ['Accounting_operational_reports.note_clients_sales'],
            documents: count($documents),
        );
    }

    /**
     * @return array<string, string>
     */
    private function row(ReportDocument $document): array
    {
        return [
            'date'       => $document->date,
            'kind'       => lang($document->kind->languageKey()),
            'customer'   => $document->customerName !== '' ? $document->customerName : $this->walkIn(),
            'number'     => $document->number,
            'branch'     => $document->branchCode,
            'shipping'   => $document->shipping,
            'adjustment' => $document->adjustment,
            'value'      => $document->value,
            'discount'   => $document->discount,
            'tax'        => $document->tax,
            'total'      => $document->total,
        ];
    }

    /**
     * @return list<ReportColumn>
     */
    private function columns(bool $byCustomer, bool $details, ReportScope $scope): array
    {
        $figures = [
            new ReportColumn('shipping', 'Accounting_operational_reports.col_shipping', ColumnKind::Amount),
            new ReportColumn('adjustment', 'Accounting_operational_reports.col_adjustment', ColumnKind::Amount),
            new ReportColumn('value', 'Accounting_operational_reports.col_value', ColumnKind::Amount),
            new ReportColumn('discount', 'Accounting_operational_reports.col_discount', ColumnKind::Amount),
            new ReportColumn('tax', 'Accounting_operational_reports.col_tax', ColumnKind::Amount),
            new ReportColumn('total', 'Accounting_operational_reports.col_total', ColumnKind::Amount),
        ];

        if ($byCustomer && ! $details) {
            return [new ReportColumn('customer', 'Accounting_operational_reports.col_customer'), ...$figures];
        }

        return [
            new ReportColumn('date', 'Accounting_operational_reports.col_date', ColumnKind::Date),
            new ReportColumn('kind', 'Accounting_operational_reports.col_type'),
            ...($byCustomer ? [] : [new ReportColumn('customer', 'Accounting_operational_reports.col_customer')]),
            new ReportColumn('number', 'Accounting_operational_reports.col_number'),
            ...($scope->choosable() ? [new ReportColumn('branch', 'Accounting_operational_reports.col_branch')] : []),
            ...$figures,
        ];
    }
}
