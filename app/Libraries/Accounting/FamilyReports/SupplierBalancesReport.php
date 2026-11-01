<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\BalanceRow;
use App\Libraries\Accounting\PartyReportQuery;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Accounting\SupplierBalanceReport;
use DateTimeImmutable;

/**
 * The balance of each supplier over a period and what moved it, from the report of the supplier
 * balances of the payables screen. A return and a debit note are one column here, taken off the
 * purchases, so the net purchases are the same as in every report of purchases. The balances are set
 * beside what the accounts of the suppliers hold on the last day, and the balances before beside
 * what they held the day before the first.
 */
final class SupplierBalancesReport implements FamilyReport
{
    use BuildsReports;

    public function __construct(
        private readonly SupplierBalanceReport $balances = new SupplierBalanceReport(),
        private readonly ControlAccounts $accounts = new ControlAccounts(),
    ) {
    }

    public function build(ReportInput $input): ReportTable
    {
        $query = PartyReportQuery::forBalances($input->query, $input->today);

        if ($query->refusal !== null) {
            throw $query->refusal;
        }

        $table = $input->scope->isEmpty() ? null : $this->balances->build($query->period, ! $query->showEmpty, $input->scope->ids);
        $lines = [];

        foreach ($table->rows ?? [] as $row) {
            $returns = bcsub('0', bcadd($row->settlements, $row->returns, SaleAmounts::SCALE), SaleAmounts::SCALE);

            $lines[] = new ReportRow([
                'code'      => (string) $row->partyId,
                'account'   => (string) $row->accountId,
                'name'      => $row->name,
                'before'    => $row->before,
                'purchases' => $row->sales,
                'returns'   => $returns,
                'net'       => bcsub($row->sales, $returns, SaleAmounts::SCALE),
                'payments'  => $row->payments,
                'other'     => $row->other,
                'balance'   => $row->balance,
            ]);
        }

        $keys    = ['before', 'purchases', 'returns', 'net', 'payments', 'other', 'balance'];
        $control = $this->accounts->tree(RoutingKey::Suppliers);
        $ties    = [new TieCheck($this->text('tie_suppliers_account'), $this->sum($lines, 'balance'), bcsub('0', $this->accounts->movement($control, $input->scope, null, $query->period->to), SaleAmounts::SCALE))];

        if ($query->period->from !== null) {
            $before = (new DateTimeImmutable($query->period->from))->modify('-1 day')->format('Y-m-d');
            $ties[] = new TieCheck($this->text('tie_suppliers_before'), $this->sum($lines, 'before'), bcsub('0', $this->accounts->movement($control, $input->scope, null, $before), SaleAmounts::SCALE));
        }

        $columns = [
            $this->column('code', ColumnKind::Plain), $this->column('account', ColumnKind::Plain), $this->column('name'),
            $this->column('before', ColumnKind::Amount), $this->column('purchases', ColumnKind::Amount), $this->column('returns', ColumnKind::Amount), $this->column('net', ColumnKind::Amount),
            $this->column('payments', ColumnKind::Amount), $this->column('other', ColumnKind::Amount), $this->column('balance', ColumnKind::Amount),
        ];

        return new ReportTable($this->text('supplier_balances_title'), $columns, [new ReportBlock(null, $lines)], $this->totalRow($lines, $keys, 'name', $this->text('total')), $ties, [$this->text('note_balances_engine'), $this->text('note_net_purchases')]);
    }

    /**
     * @param list<ReportRow> $rows
     *
     * @return numeric-string
     */
    private function sum(array $rows, string $key): string
    {
        return $this->sums($rows, [$key])[$key];
    }
}
