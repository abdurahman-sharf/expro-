<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Accounting\SupplierLedger;

/**
 * For each purchase and each return of a period what it comes to, what was paid against it and what
 * is left to pay. What a supplier holds as credit is not taken off what is left: it is shown apart,
 * so that what is left less the credit is what the account of the suppliers holds.
 */
final class PurchasePaymentsReport implements FamilyReport
{
    use BuildsReports;

    public function __construct(
        private readonly PurchaseDocuments $documents = new PurchaseDocuments(),
        private readonly SupplierLedger $ledger = new SupplierLedger(),
    ) {
    }

    public function build(ReportInput $input): ReportTable
    {
        $period  = $input->period();
        $filters = ['supplier_id' => $input->id('supplier_id'), 'employee_id' => $input->id('employee_id')];
        $docs    = $this->documents->read($input->scope, $period, $input->viewerId, $filters);
        $byGroup = [];

        foreach ($docs as $document) {
            $byGroup[$document->supplierId ?? 0]['name']        = $document->supplierName === '' ? $this->text('no_supplier') : $document->supplierName;
            $byGroup[$document->supplierId ?? 0]['documents'][] = $document;
        }

        uasort($byGroup, static fn (array $first, array $second): int => mb_strtolower($first['name']) <=> mb_strtolower($second['name']));

        $blocks = [];
        $all    = [];

        foreach ($byGroup as $set) {
            $rows = [];

            foreach ($set['documents'] as $document) {
                $rows[] = new ReportRow([
                    'date'      => $document->date,
                    'kind'      => $this->text($document->isReturn() ? 'kind_return' : 'kind_invoice'),
                    'supplier'  => $set['name'],
                    'number'    => $document->number,
                    'net'       => $document->signed($document->total),
                    'paid'      => $document->settled,
                    'remaining' => $document->open(),
                ]);
            }

            $all      = [...$all, ...$rows];
            $blocks[] = new ReportBlock($set['name'], $rows, $this->totalRow($rows, ['net', 'paid', 'remaining'], 'number', $this->text('total')));
        }

        $grand = $this->totalRow($all, ['net', 'paid', 'remaining'], 'number', $this->text('grand_total'));
        $ties  = [];
        $notes = [$this->text('note_remaining_without_credit'), $this->text('note_net_purchases')];

        if ($input->scope->isCompany() && $input->viewerId === null && $period->from === null && $period->to >= $input->today && $filters['supplier_id'] === 0 && $filters['employee_id'] === 0) {
            $open    = '0';
            $credit  = '0';
            $account = '0';

            foreach (array_unique(array_filter(array_map(static fn (PurchaseDocument $document): ?int => $document->supplierId, $docs))) as $supplierId) {
                $position = $this->ledger->position((int) $supplierId);
                $open     = bcadd($open, $position->open(), SaleAmounts::SCALE);
                $credit   = bcadd($credit, $position->credit(), SaleAmounts::SCALE);
                $account  = bcadd($account, $position->accountBalance, SaleAmounts::SCALE);
            }

            $ties[]  = new TieCheck($this->text('tie_remaining_account'), bcsub($open, $credit, SaleAmounts::SCALE), $account);
            $notes[] = $this->text('credit_held', to_currency_no_money($credit));
        } else {
            $notes[] = $this->text('note_remaining_not_compared');
        }

        return new ReportTable($this->text('purchase_payments_title'), [
            $this->column('date', ColumnKind::Date), $this->column('kind'), $this->column('supplier'), $this->column('number'),
            $this->column('net', ColumnKind::Amount), $this->column('paid', ColumnKind::Amount), $this->column('remaining', ColumnKind::Amount),
        ], $blocks, $grand, $ties, $notes);
    }
}
