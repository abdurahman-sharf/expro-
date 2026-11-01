<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\AgedLedger;
use App\Libraries\Accounting\AgedLedgerRow;
use App\Libraries\Accounting\AgedLedgerTable;
use App\Libraries\Accounting\AgingBuckets;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\PartyReportQuery;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\SaleAmounts;

/**
 * What is owed to each supplier on a day, in the six buckets of the aging of the ledger, from the
 * aging the receivables and payables screens use: the payments and debit notes go to the oldest
 * purchase first and what is left of each purchase is aged by its own day. The buckets less the
 * credit held are set beside what the accounts of the suppliers hold on that day in the branches read.
 */
final class SupplierAgedLedgerReport implements FamilyReport
{
    use BuildsReports;

    public function __construct(
        private readonly AgedLedger $aged = new AgedLedger(),
        private readonly ControlAccounts $accounts = new ControlAccounts(),
    ) {
    }

    public function build(ReportInput $input): ReportTable
    {
        $query = PartyReportQuery::forAging($input->query, $input->today);

        if ($query->refusal !== null) {
            throw $query->refusal;
        }

        $supplierId = $input->id('supplier_id');
        $rows       = $input->scope->isEmpty() ? [] : $this->aged->build(EntityAccountService::SUPPLIER, $query->day(), $query->days, $supplierId > 0 ? $supplierId : null, $input->scope->ids);
        $table      = new AgedLedgerTable($query->day(), $query->days, $rows);

        $ranges  = AgingBuckets::ranges($query->days);
        $columns = [$this->column('code', ColumnKind::Plain), $this->column('account', ColumnKind::Plain), $this->column('name'), new ReportColumn('b0', lang('Accounting_family_reports.age_today'), ColumnKind::Amount)];

        foreach (range(1, 5) as $index) {
            $columns[] = new ReportColumn('b' . $index, $ranges[$index] . ' ' . lang('Accounting_family_reports.age_days'), ColumnKind::Amount);
        }

        array_push($columns, $this->column('total', ColumnKind::Amount), $this->column('credit', ColumnKind::Amount), $this->column('balance', ColumnKind::Amount));

        $lines = array_map(function (AgedLedgerRow $row): ReportRow {
            $cells = ['code' => (string) $row->partyId, 'account' => (string) $row->accountId, 'name' => $row->name];

            foreach ($row->buckets as $index => $amount) {
                $cells['b' . $index] = $amount;
            }

            return new ReportRow([...$cells, 'total' => $row->total(), 'credit' => $row->credit, 'balance' => $row->balance()]);
        }, $rows);

        $keys  = ['b0', 'b1', 'b2', 'b3', 'b4', 'b5', 'total', 'credit', 'balance'];
        $ties  = [];
        $notes = [$this->text('note_aging_engine'), $this->text('note_aging_rows')];

        if ($supplierId === 0) {
            $book   = bcsub('0', $this->accounts->movement($this->accounts->tree(RoutingKey::Suppliers), $input->scope, null, $query->day()), SaleAmounts::SCALE);
            $ties[] = new TieCheck($this->text('tie_suppliers_account'), $table->balance(), $book);
        } else {
            $notes[] = $this->text('note_supplier_filter_not_compared');
        }

        return new ReportTable($this->text('supplier_aged_ledger_title'), $columns, [new ReportBlock(null, $lines)], $this->totalRow($lines, $keys, 'name', $this->text('total')), $ties, $notes);
    }
}
