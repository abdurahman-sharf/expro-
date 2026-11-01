<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\SaleAmounts;

/**
 * What was bought of each item: the lines of the purchases and the returns of a period, a return
 * as a line with a negative quantity and value, grouped by item, supplier, employee or period. The
 * value of a line has its tax in it, and the lines of a document add up to its total, so the report
 * adds up to the net purchases of the others.
 */
final class ProductPurchasesReport implements FamilyReport
{
    use BuildsReports;

    public const GROUPS = ['product', 'supplier', 'user', 'day', 'week', 'month', 'year'];

    public function __construct(private readonly PurchaseDocuments $documents = new PurchaseDocuments())
    {
    }

    public function build(ReportInput $input): ReportTable
    {
        $group    = $input->choice('group', self::GROUPS, 'product');
        $view     = $input->choice('view', ['summary', 'detail'], 'summary');
        $unit     = PeriodUnit::tryFrom($group);
        $period   = $input->period($unit?->defaultFrom($input->today));
        $filter   = ['supplier_id' => $input->id('supplier_id'), 'employee_id' => $input->id('employee_id')];
        $all      = $this->documents->read($input->scope, $period, $input->viewerId, $filter);
        $category = $input->text('category');
        $itemId   = $input->id('item_id');

        $entries = [];

        foreach ($all as $document) {
            foreach ($document->lines as $line) {
                if (($category !== '' && $line->category !== $category) || ($itemId > 0 && $line->itemId !== $itemId)) {
                    continue;
                }

                $entries[] = [$document, $line];
            }
        }

        $sets = [];

        if ($unit !== null && $entries !== []) {
            foreach ($unit->buckets($period->from ?? $entries[0][0]->date, $period->to) as $start) {
                $sets[$start] = ['label' => $unit->label($start), 'entries' => []];
            }
        }

        foreach ($entries as [$document, $line]) {
            [$key, $label] = match (true) {
                $unit !== null     => [$unit->start($document->date), $unit->label($unit->start($document->date))],
                $group === 'product' => ['i' . $line->itemId, $line->itemName],
                $group === 'supplier' => ['s' . ($document->supplierId ?? 0), $document->supplierName === '' ? $this->text('no_supplier') : $document->supplierName],
                default            => ['u' . ($document->employeeId ?? 0), $document->employeeName === '' ? $this->text('no_user') : $document->employeeName],
            };

            $sets[$key] ??= ['label' => $label, 'entries' => []];
            $sets[$key]['entries'][] = [$document, $line];
        }

        if ($unit === null) {
            uasort($sets, static fn (array $first, array $second): int => mb_strtolower($first['label']) <=> mb_strtolower($second['label']));
        } else {
            ksort($sets);
        }

        $table = $view === 'detail' ? $this->detail($sets) : $this->summary($sets, $group);

        $filtered = $category !== '' || $itemId > 0;
        $notes    = [$this->text('note_line_value'), $this->text('note_net_purchases')];

        $this->noteIfAny($notes, $filtered, 'note_item_filter');

        return new ReportTable(
            $this->text('product_purchases_title'),
            $table['columns'],
            $table['blocks'],
            $table['grand'],
            $filtered ? [] : [new TieCheck($this->text('tie_net_purchases'), $this->sumOf($entries), PurchaseDocuments::net($all))],
            $notes,
        );
    }

    /**
     * @param list<array{0: PurchaseDocument, 1: PurchaseLine}> $entries
     *
     * @return numeric-string
     */
    private function sumOf(array $entries): string
    {
        $total = '0';

        foreach ($entries as [, $line]) {
            $total = bcadd($total, $line->total, SaleAmounts::SCALE);
        }

        return bcadd($total, '0', SaleAmounts::SCALE);
    }

    /**
     * @param array<string, array{label: string, entries: list<array{0: PurchaseDocument, 1: PurchaseLine}>}> $sets
     *
     * @return array{columns: list<ReportColumn>, blocks: list<ReportBlock>, grand: ReportRow}
     */
    private function summary(array $sets, string $group): array
    {
        $rows = [];

        foreach ($sets as $set) {
            $quantity = '0';
            $total    = '0';

            foreach ($set['entries'] as [, $line]) {
                $quantity = bcadd($quantity, $line->quantity, 3);
                $total    = bcadd($total, $line->total, SaleAmounts::SCALE);
            }

            $rows[] = new ReportRow(['group' => $set['label'], 'quantity' => $this->quantity($quantity), 'total' => $this->amount($total)]);
        }

        return [
            'columns' => [$this->column('group', ColumnKind::Text, 'group_' . $group), $this->column('quantity', ColumnKind::Quantity), $this->column('total', ColumnKind::Amount)],
            'blocks'  => [new ReportBlock(null, $rows)],
            'grand'   => $this->totalRow($rows, ['quantity', 'total'], 'group', $this->text('total')),
        ];
    }

    /**
     * @param array<string, array{label: string, entries: list<array{0: PurchaseDocument, 1: PurchaseLine}>}> $sets
     *
     * @return array{columns: list<ReportColumn>, blocks: list<ReportBlock>, grand: ReportRow}
     */
    private function detail(array $sets): array
    {
        $blocks = [];
        $all    = [];

        foreach ($sets as $set) {
            if ($set['entries'] === []) {
                continue;
            }

            $rows = [];

            foreach ($set['entries'] as [$document, $line]) {
                $rows[] = new ReportRow([
                    'number'      => $document->number,
                    'item'        => $line->itemName,
                    'item_number' => $line->itemNumber ?? '',
                    'date'        => $document->date,
                    'kind'        => $this->text($document->isReturn() ? 'kind_return' : 'kind_invoice'),
                    'user'        => $document->employeeName,
                    'supplier'    => $document->supplierName,
                    'unit_price'  => $line->unitPrice,
                    'tax'         => $line->tax,
                    'quantity'    => $line->quantity,
                    'total'       => $line->total,
                ]);
            }

            $all      = [...$all, ...$rows];
            $blocks[] = new ReportBlock($set['label'], $rows, $this->totalRow($rows, ['tax', 'quantity', 'total'], 'number', $this->text('total')));
        }

        return [
            'columns' => [
                $this->column('number'), $this->column('item'), $this->column('item_number'), $this->column('date', ColumnKind::Date), $this->column('kind'), $this->column('user'), $this->column('supplier'),
                $this->column('unit_price', ColumnKind::Amount), $this->column('tax', ColumnKind::Amount), $this->column('quantity', ColumnKind::Quantity), $this->column('total', ColumnKind::Amount),
            ],
            'blocks'  => $blocks,
            'grand'   => $this->totalRow($all, ['tax', 'quantity', 'total'], 'number', $this->text('grand_total')),
        ];
    }
}
