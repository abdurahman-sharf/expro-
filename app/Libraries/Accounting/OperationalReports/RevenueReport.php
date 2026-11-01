<?php

namespace App\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\ReportScope;
use CodeIgniter\Database\BaseConnection;

/**
 * The sales of the days of a report, split by what was settled on each invoice: what was paid, what is
 * left and what was taken back by refund invoices. One rule gives the three for every grouping and every
 * view: on an invoice the paid is what was paid into it and the credit applied to it, the returned is what
 * its refund invoices took back, and the unpaid is what remains of the total, which is below nothing when
 * the two together exceed it. A credit note and a refund invoice are rows of their own whose total is
 * negative and whose whole amount is in the unpaid column, so that every column adds up and the total is
 * the invoices less what was returned, the same in every grouping. Beside the table it holds, the report
 * reads the journals of the same documents and says whether the total, the tax and the revenue agree.
 */
final class RevenueReport
{
    use BuildsBlocks;

    private const FIGURES = ['paid', 'unpaid', 'returned', 'not_taxed', 'total'];

    private ReportDocuments $documents;

    public function __construct(?BaseConnection $db = null, ?ReportDocuments $documents = null)
    {
        $this->documents = $documents ?? new ReportDocuments($db);
    }

    /**
     * @param list<StatusFilter> $statuses
     */
    public function build(ReportScope $scope, RevenueGroup $group, bool $details, ?string $from, string $to, string $today, array $statuses = [], ?int $customerId = null, ?int $employeeId = null, bool $notTaxed = false): ReportTable
    {
        $period = $group->period();
        $from ??= $period?->defaultFrom($to);

        $documents = array_values(array_filter(
            $this->documents->read($scope, $from, $to, [], $customerId, $employeeId),
            static fn (ReportDocument $document): bool => StatusFilter::admits($statuses, $document, $today),
        ));

        $groups  = $this->grouped($documents, $group, $from, $to);
        $rows    = [];
        $blocks  = [];
        $summary = [];

        foreach ($groups as $key => $members) {
            $rows[$key] = array_map(fn (ReportDocument $document): array => $this->row($document), $members);
            $subtotal   = $this->addUp($rows[$key], self::FIGURES);
            $label      = $this->label($group, $key, $members);

            if ($details) {
                $blocks[] = new ReportBlock($this->heading($label['text'], $label['kind']), $rows[$key], $subtotal);
            } else {
                $summary[] = [...$label['cells'], ...$subtotal];
            }
        }

        return new ReportTable(
            scope: $scope,
            from: $from,
            to: $to,
            columns: $this->columns($group, $details, $scope, $notTaxed),
            blocks: $details ? $blocks : [new ReportBlock([], $summary)],
            total: $this->addUp(array_merge([], ...array_values($rows)), self::FIGURES),
            ties: SalesTies::of($documents),
            notes: ['Accounting_operational_reports.note_revenue_rule'],
            documents: count($documents),
        );
    }

    /**
     * @param list<ReportDocument> $documents
     *
     * @return array<string, list<ReportDocument>> In the order of the groups, the periods with no document included.
     */
    private function grouped(array $documents, RevenueGroup $group, ?string $from, string $to): array
    {
        $period = $group->period();
        $groups = [];

        if ($period !== null) {
            $start = $from ?? ($documents === [] ? null : $documents[0]->date);

            foreach ($start === null ? [] : $period->buckets($start, $to) as $bucket) {
                $groups[$bucket->key] = [];
            }

            foreach ($documents as $document) {
                $groups[$period->bucketOf($document->date)->key][] = $document;
            }

            ksort($groups);

            return $groups;
        }

        foreach ($documents as $document) {
            $key = $group === RevenueGroup::Customer ? (string) ($document->customerId ?? 0) : (string) ($document->employeeId ?? 0);

            $groups[$key][] = $document;
        }

        uasort($groups, fn (array $a, array $b): int => [$this->nameOf($group, $a[0]), $this->idOf($group, $a[0])] <=> [$this->nameOf($group, $b[0]), $this->idOf($group, $b[0])]);

        return $groups;
    }

    private function nameOf(RevenueGroup $group, ReportDocument $document): string
    {
        return mb_strtolower($group === RevenueGroup::Customer ? $document->customerName : $document->employeeName);
    }

    private function idOf(RevenueGroup $group, ReportDocument $document): int
    {
        return $group === RevenueGroup::Customer ? ($document->customerId ?? 0) : ($document->employeeId ?? 0);
    }

    /**
     * @param list<ReportDocument> $members
     *
     * @return array{text: string, kind: string, cells: array<string, string>}
     */
    private function label(RevenueGroup $group, string $key, array $members): array
    {
        $period = $group->period();

        if ($period !== null) {
            $bucket = $period->bucketOf($key);

            return match ($period) {
                PeriodGroup::Day   => ['text' => $bucket->key, 'kind' => 'date', 'cells' => ['period' => $bucket->key]],
                PeriodGroup::Week  => ['text' => $bucket->from . '|' . $bucket->to, 'kind' => 'range', 'cells' => ['period_from' => $bucket->from, 'period_to' => $bucket->to]],
                PeriodGroup::Month => ['text' => substr($bucket->key, 0, 7), 'kind' => 'text', 'cells' => ['period' => substr($bucket->key, 0, 7)]],
                PeriodGroup::Year  => ['text' => substr($bucket->key, 0, 4), 'kind' => 'text', 'cells' => ['period' => substr($bucket->key, 0, 4)]],
            };
        }

        $name = $group === RevenueGroup::Customer
            ? ($members[0]->customerName !== '' ? $members[0]->customerName : $this->walkIn())
            : ($members[0]->employeeName !== '' ? $members[0]->employeeName : $this->nobody());

        return ['text' => $name, 'kind' => 'text', 'cells' => [$group->value => $name]];
    }

    /**
     * @return array<string, string>
     */
    private function row(ReportDocument $document): array
    {
        $isReturn = $document->kind->isReturn();

        return [
            'number'    => $document->number,
            'kind'      => lang($document->kind->languageKey()),
            'date'      => $document->date,
            'customer'  => $document->customerName !== '' ? $document->customerName : $this->walkIn(),
            'user'      => $document->employeeName !== '' ? $document->employeeName : $this->nobody(),
            'branch'    => $document->branchCode,
            'paid'      => $isReturn ? Amounts::ZERO : $document->paid(),
            'unpaid'    => $document->unpaid(),
            'returned'  => $isReturn ? Amounts::ZERO : $document->returnedByRefund,
            'not_taxed' => $document->revenue(),
            'total'     => $document->total,
        ];
    }

    /**
     * @return list<ReportColumn>
     */
    private function columns(RevenueGroup $group, bool $details, ReportScope $scope, bool $notTaxed): array
    {
        $figures = [
            new ReportColumn('paid', 'Accounting_operational_reports.col_paid', ColumnKind::Amount),
            new ReportColumn('unpaid', 'Accounting_operational_reports.col_unpaid', ColumnKind::Amount),
            new ReportColumn('returned', 'Accounting_operational_reports.col_returned', ColumnKind::Amount),
            ...($notTaxed ? [new ReportColumn('not_taxed', 'Accounting_operational_reports.col_not_taxed', ColumnKind::Amount)] : []),
            new ReportColumn('total', 'Accounting_operational_reports.col_total', ColumnKind::Amount),
        ];

        if (! $details) {
            return [...match ($group) {
                RevenueGroup::Day      => [new ReportColumn('period', 'Accounting_operational_reports.col_date', ColumnKind::Date)],
                RevenueGroup::Week     => [new ReportColumn('period_from', 'Accounting_operational_reports.col_week_start', ColumnKind::Date), new ReportColumn('period_to', 'Accounting_operational_reports.col_week_end', ColumnKind::Date)],
                RevenueGroup::Month    => [new ReportColumn('period', 'Accounting_operational_reports.col_month')],
                RevenueGroup::Year     => [new ReportColumn('period', 'Accounting_operational_reports.col_year')],
                RevenueGroup::Customer => [new ReportColumn('customer', 'Accounting_operational_reports.col_customer')],
                RevenueGroup::User     => [new ReportColumn('user', 'Accounting_operational_reports.col_user')],
            }, ...$figures];
        }

        return [
            new ReportColumn('number', 'Accounting_operational_reports.col_number'),
            new ReportColumn('kind', 'Accounting_operational_reports.col_type'),
            new ReportColumn('date', 'Accounting_operational_reports.col_date', ColumnKind::Date),
            ...($group === RevenueGroup::Customer ? [] : [new ReportColumn('customer', 'Accounting_operational_reports.col_customer')]),
            ...($group === RevenueGroup::User ? [] : [new ReportColumn('user', 'Accounting_operational_reports.col_user')]),
            ...($scope->choosable() ? [new ReportColumn('branch', 'Accounting_operational_reports.col_branch')] : []),
            ...$figures,
        ];
    }
}
