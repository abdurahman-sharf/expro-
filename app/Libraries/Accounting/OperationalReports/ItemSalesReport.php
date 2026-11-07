<?php

namespace App\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\SaleAmounts;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * What was sold, by item, category, customer, employee or period: the units, the discount of the line with
 * its share of the discount on the whole invoice, the tax and the total, a return counting against what
 * it takes back. The price of a group is the average of the prices weighted by the net units. The lines
 * come from the documents that stand in the books, so their total is what the documents come to without
 * their header charges, within a cent a document, which the report says beside the journals.
 */
final class ItemSalesReport
{
    use BuildsBlocks;

    private const FIGURES = ['quantity', 'discount', 'tax', 'total'];

    private ReportDocuments $documents;
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null, ?ReportDocuments $documents = null)
    {
        $this->db        = $db ?? Database::connect();
        $this->documents = $documents ?? new ReportDocuments($this->db);
    }

    /**
     * @param list<StatusFilter> $statuses
     */
    public function build(ReportScope $scope, ItemGroup $group, bool $details, ?string $from, string $to, string $today, ?DocumentKind $kind = null, array $statuses = [], ?int $customerId = null, ?int $employeeId = null, ?int $itemId = null, string $category = '', ?int $locationId = null, bool $allDates = false): ReportTable
    {
        $period = $group->period();
        $from ??= $allDates ? null : $period?->defaultFrom($to);

        $documents = array_values(array_filter(
            $this->documents->read($scope, $from, $to, $kind === null ? [] : [$kind], $customerId, $employeeId, true),
            static fn (ReportDocument $document): bool => StatusFilter::admits($statuses, $document, $today),
        ));

        $items    = $this->items($documents);
        $decimals = totals_decimals();
        $rows     = [];

        foreach ($documents as $document) {
            foreach ($document->lines as $saleLine) {
                $item = $items[$saleLine->itemId] ?? ['name' => '', 'number' => '', 'category' => ''];

                if ($itemId !== null && $saleLine->itemId !== $itemId) {
                    continue;
                }

                if ($category !== '' && $item['category'] !== $category) {
                    continue;
                }

                if ($locationId !== null && (! $saleLine->hasStock || $saleLine->locationId !== $locationId)) {
                    continue;
                }

                $line   = ReportLine::of($saleLine, $document->kind->sign(), $decimals);
                $rows[] = [
                    'document'    => $document,
                    'item_id'     => (string) $saleLine->itemId,
                    'item'        => $item['name'],
                    'item_number' => $item['number'],
                    'category'    => $item['category'] === '' ? lang('Accounting_operational_reports.undefined') : $item['category'],
                    'date'        => $document->date,
                    'user'        => $document->employeeName !== '' ? $document->employeeName : $this->nobody(),
                    'number'      => $document->number,
                    'customer'    => $document->customerName !== '' ? $document->customerName : $this->walkIn(),
                    'unit_price'  => $line->unitPrice,
                    'quantity'    => $line->quantity,
                    'discount'    => $line->discount,
                    'tax'         => $line->tax,
                    'total'       => $line->total,
                    'price_x_qty' => bcmul($line->unitPrice, $line->quantity, 8),
                ];
            }
        }

        $groups  = $this->grouped($rows, $group, $from, $to);
        $blocks  = [];
        $summary = [];

        foreach ($groups as $key => $members) {
            $cells    = array_map(fn (array $row): array => $this->cells($row), $members);
            $subtotal = $this->sums($members);
            $label    = $this->label($group, $key, $members);

            if ($details) {
                $blocks[] = new ReportBlock($this->heading($label['text'], $label['kind']), $cells, $subtotal);
            } else {
                $summary[] = [...$label['cells'], 'unit_price' => $this->average($members), ...$subtotal];
            }
        }

        $filtered = $itemId !== null || $category !== '' || $locationId !== null;
        $total    = $this->sums($rows);

        return new ReportTable(
            scope: $scope,
            from: $from,
            to: $to,
            columns: $this->columns($group, $details, $scope),
            blocks: $details ? $blocks : [new ReportBlock([], $summary)],
            total: $total,
            ties: $filtered ? [] : [$this->tie($documents, $total['total'])],
            notes: ['Accounting_operational_reports.note_items'],
            documents: count(array_unique(array_map(static fn (array $row): int => $row['document']->id, $rows))),
        );
    }

    /**
     * What the lines come to beside what the journals of their documents hold without the header
     * charges, which the lines do not carry. A document may round a cent apart from its lines.
     *
     * @param list<ReportDocument> $documents
     */
    private function tie(array $documents, string $lines): TieCheck
    {
        $ledger = Amounts::sum(array_map(static fn (ReportDocument $document): string => Amounts::sub($document->ledgerTotal, $document->headerCharges), $documents));

        return new TieCheck('Accounting_operational_reports.tie_items_total', $lines, $ledger, bcmul('0.01', (string) max(1, count($documents)), SaleAmounts::SCALE));
    }

    /**
     * @param list<ReportDocument> $documents
     *
     * @return array<int, array{name: string, number: string, category: string}>
     */
    private function items(array $documents): array
    {
        $ids = [];

        foreach ($documents as $document) {
            foreach ($document->lines as $line) {
                $ids[$line->itemId] = $line->itemId;
            }
        }

        $items = [];

        foreach (array_chunk(array_values($ids), 400) as $chunk) {
            foreach ($this->db->table('items')->select('item_id, name, item_number, category')->whereIn('item_id', $chunk)->get()->getResult() as $item) {
                $items[(int) $item->item_id] = ['name' => (string) $item->name, 'number' => (string) ($item->item_number ?? ''), 'category' => (string) ($item->category ?? '')];
            }
        }

        return $items;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function grouped(array $rows, ItemGroup $group, ?string $from, string $to): array
    {
        $period = $group->period();
        $groups = [];

        if ($period !== null) {
            $start = $from ?? ($rows === [] ? null : min(array_map(static fn (array $row): string => (string) $row['date'], $rows)));

            foreach ($start === null ? [] : $period->buckets($start, $to) as $bucket) {
                $groups[$bucket->key] = [];
            }

            foreach ($rows as $row) {
                $groups[$period->bucketOf((string) $row['date'])->key][] = $row;
            }

            ksort($groups);

            return $groups;
        }

        foreach ($rows as $row) {
            $key = match ($group) {
                ItemGroup::Item     => (string) $row['item_id'],
                ItemGroup::Category => (string) $row['category'],
                ItemGroup::Customer => (string) ($row['document']->customerId ?? 0),
                default             => (string) ($row['document']->employeeId ?? 0),
            };

            $groups[$key][] = $row;
        }

        uasort($groups, fn (array $a, array $b): int => [mb_strtolower($this->nameOf($group, $a[0])), $this->nameOf($group, $a[0])] <=> [mb_strtolower($this->nameOf($group, $b[0])), $this->nameOf($group, $b[0])]);

        return $groups;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function nameOf(ItemGroup $group, array $row): string
    {
        return (string) match ($group) {
            ItemGroup::Item     => $row['item'],
            ItemGroup::Category => $row['category'],
            ItemGroup::Customer => $row['customer'],
            default             => $row['user'],
        };
    }

    /**
     * @param list<array<string, mixed>> $members
     *
     * @return array{text: string, kind: string, cells: array<string, string>}
     */
    private function label(ItemGroup $group, string $key, array $members): array
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

        $name = $this->nameOf($group, $members[0]);

        return ['text' => $name, 'kind' => 'text', 'cells' => [$group->value => $name]];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, string>
     */
    private function cells(array $row): array
    {
        return array_map('strval', array_diff_key($row, ['document' => 1, 'item_id' => 1, 'price_x_qty' => 1]));
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, string>
     */
    private function sums(array $rows): array
    {
        $sums = [];

        foreach (self::FIGURES as $key) {
            $sums[$key] = Amounts::sum(array_map(static fn (array $row): string => (string) $row[$key], $rows));
        }

        return $sums;
    }

    /**
     * The prices of the lines weighted by their units, a return taking units away at its own price.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function average(array $rows): string
    {
        $units = Amounts::sum(array_map(static fn (array $row): string => (string) $row['quantity'], $rows));

        if (Amounts::isZero($units)) {
            return Amounts::ZERO;
        }

        $money = '0';

        foreach ($rows as $row) {
            $money = bcadd($money, (string) $row['price_x_qty'], 8);
        }

        return bcadd(bcdiv($money, $units, 8), '0.00005', 4);
    }

    /**
     * @return list<ReportColumn>
     */
    private function columns(ItemGroup $group, bool $details, ReportScope $scope): array
    {
        $figures = [
            new ReportColumn('quantity', 'Accounting_operational_reports.col_quantity', ColumnKind::Quantity),
            new ReportColumn('discount', 'Accounting_operational_reports.col_discount', ColumnKind::Amount),
            new ReportColumn('tax', 'Accounting_operational_reports.col_tax', ColumnKind::Amount),
            new ReportColumn('total', 'Accounting_operational_reports.col_total', ColumnKind::Amount),
        ];
        $price = new ReportColumn('unit_price', 'Accounting_operational_reports.col_unit_price', ColumnKind::Amount);

        if (! $details) {
            return [...match ($group) {
                ItemGroup::Item     => [new ReportColumn('item', 'Accounting_operational_reports.col_item')],
                ItemGroup::Category => [new ReportColumn('category', 'Accounting_operational_reports.col_category')],
                ItemGroup::Customer => [new ReportColumn('customer', 'Accounting_operational_reports.col_customer')],
                ItemGroup::User     => [new ReportColumn('user', 'Accounting_operational_reports.col_user')],
                ItemGroup::Day      => [new ReportColumn('period', 'Accounting_operational_reports.col_date', ColumnKind::Date)],
                ItemGroup::Week     => [new ReportColumn('period_from', 'Accounting_operational_reports.col_week_start', ColumnKind::Date), new ReportColumn('period_to', 'Accounting_operational_reports.col_week_end', ColumnKind::Date)],
                ItemGroup::Month    => [new ReportColumn('period', 'Accounting_operational_reports.col_month')],
                ItemGroup::Year     => [new ReportColumn('period', 'Accounting_operational_reports.col_year')],
            }, $price, ...$figures];
        }

        return [
            ...($group === ItemGroup::Item ? [] : [new ReportColumn('item', 'Accounting_operational_reports.col_item')]),
            new ReportColumn('item_number', 'Accounting_operational_reports.col_item_number'),
            ...(in_array($group, [ItemGroup::Day, ItemGroup::Week, ItemGroup::Month, ItemGroup::Year], true) ? [] : [new ReportColumn('date', 'Accounting_operational_reports.col_date', ColumnKind::Date)]),
            ...($group === ItemGroup::User ? [] : [new ReportColumn('user', 'Accounting_operational_reports.col_user')]),
            new ReportColumn('number', 'Accounting_operational_reports.col_number'),
            ...($group === ItemGroup::Customer ? [] : [new ReportColumn('customer', 'Accounting_operational_reports.col_customer')]),
            $price,
            ...$figures,
        ];
    }
}
