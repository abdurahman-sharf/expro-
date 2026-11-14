<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\SaleAmounts;

/**
 * Every purchase and every return of a period with its value, its discounts, its tax and its total,
 * supplier by supplier. A return is shown with all its figures negative, and the discounts are shown
 * negative, so that the value, the discounts and the tax add up to the total.
 */
final class SupplierPurchasesReport implements FamilyReport
{
    use BuildsReports;

    private const KEYS = ['value', 'discount', 'tax', 'total'];

    public function __construct(private readonly PurchaseDocuments $documents = new PurchaseDocuments())
    {
    }

    public function build(ReportInput $input): ReportTable
    {
        $period = $input->period();
        $filter = ['supplier_id' => $input->id('supplier_id'), 'employee_id' => $input->id('employee_id')];
        $docs   = $this->documents->read($input->scope, $period, $input->viewerId, $filter);

        $sets = [];

        foreach ($docs as $document) {
            $key = $document->supplierId ?? 0;
            $sets[$key] ??= ['name' => $document->supplierName === '' ? $this->text('no_supplier') : $document->supplierName, 'documents' => []];
            $sets[$key]['documents'][] = $document;
        }

        uasort($sets, static fn (array $first, array $second): int => mb_strtolower($first['name']) <=> mb_strtolower($second['name']));

        $blocks = [];
        $all    = [];

        foreach ($sets as $set) {
            $rows = [];

            foreach ($set['documents'] as $document) {
                $rows[] = new ReportRow([
                    'date'     => $document->date,
                    'kind'     => $this->text($document->isReturn() ? 'kind_return' : 'kind_invoice'),
                    'supplier' => $set['name'],
                    'number'   => $document->number,
                    'value'    => $document->signed($document->value),
                    'discount' => bcsub('0', $document->signed($document->discount), SaleAmounts::SCALE),
                    'tax'      => $document->signed($document->tax),
                    'total'    => $document->signed($document->total),
                ]);
            }

            $all      = [...$all, ...$rows];
            $blocks[] = new ReportBlock($set['name'], $rows, $this->totalRow($rows, self::KEYS, 'number', $this->text('total')));
        }

        $columns = [
            $this->column('date', ColumnKind::Date), $this->column('kind'), $this->column('supplier'), $this->column('number'),
            $this->column('value', ColumnKind::Amount), $this->column('discount', ColumnKind::Amount), $this->column('tax', ColumnKind::Amount), $this->column('total', ColumnKind::Amount),
        ];

        $notes = [$this->text('note_net_purchases'), $this->text('note_rounding')];

        $this->noteIfAny($notes, PurchaseDocuments::unposted($docs) > 0, 'note_unposted', PurchaseDocuments::unposted($docs));

        return new ReportTable(
            $this->text('supplier_purchases_title'),
            $columns,
            $blocks,
            $this->totalRow($all, self::KEYS, 'number', $this->text('grand_total')),
            ForeignAmounts::guard([new TieCheck($this->text('tie_net_purchases'), PurchaseDocuments::net($docs), $this->documents->booked($input->scope, $period, $input->viewerId, $filter))], ForeignAmounts::documentsOf($docs)),
            $notes,
        );
    }
}
