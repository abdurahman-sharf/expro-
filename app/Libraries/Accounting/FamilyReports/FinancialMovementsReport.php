<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\CreditRefunds;
use App\Libraries\Accounting\CrossBranchPayments;
use App\Libraries\Accounting\CustomerAdvances;
use App\Libraries\Accounting\ExpensePostingBuilder;
use App\Libraries\Accounting\JournalStatus;
use App\Libraries\Accounting\ReceivingPostingBuilder;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\RevenuePostingBuilder;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Accounting\SalePostingBuilder;
use App\Libraries\Accounting\SupplierAdvances;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/**
 * What came into the treasuries and the banks and what went out of them by the documents of trade,
 * one movement after the other with the balance after each: the payments of customers and the receipt
 * vouchers in, the payments to suppliers and the expenses out, and what a supplier gave back in. The
 * manual journals and the transfers between treasuries are not here. The balance at the start is what
 * the same documents moved before the first day.
 */
final class FinancialMovementsReport implements FamilyReport
{
    use BuildsReports;

    private BaseConnection $db;

    public function __construct(
        ?BaseConnection $db = null,
        private readonly ControlAccounts $accounts = new ControlAccounts(),
        private readonly SupplierPaymentRows $payments = new SupplierPaymentRows(),
    ) {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return list<string>
     */
    public static function sources(): array
    {
        $sources = [
            SalePostingBuilder::SOURCE_PAYMENT,
            CustomerAdvances::SOURCE,
            CreditRefunds::CUSTOMER_SOURCE,
            ReceivingPostingBuilder::SOURCE_PAYMENT,
            ReceivingPostingBuilder::SOURCE_SUPPLIER_PAYMENT,
            SupplierAdvances::SOURCE,
            CreditRefunds::SUPPLIER_SOURCE,
            ExpensePostingBuilder::SOURCE,
            RevenuePostingBuilder::SOURCE,
        ];

        foreach ($sources as $source) {
            $sources = [...$sources, ...CrossBranchPayments::companionsOf($source)];
        }

        return $sources;
    }

    public function build(ReportInput $input): ReportTable
    {
        $period   = $input->period((new DateTimeImmutable($input->today))->modify('first day of this month')->format('Y-m-d'));
        $accounts = $this->accounts->tree(RoutingKey::Treasuries, RoutingKey::Banks);
        $opening  = $period->from === null ? '0.0000' : $this->accounts->movement($accounts, $input->scope, null, (new DateTimeImmutable($period->from))->modify('-1 day')->format('Y-m-d'), self::sources());
        $moves    = $this->moves($input, $period, $accounts);

        $balance = $opening;
        $rows    = [new ReportRow(['description' => $this->text('opening_balance'), 'balance' => $this->amount($opening)])];
        $income  = '0';
        $outgo   = '0';

        foreach ($moves as $move) {
            $balance = bcadd($balance, $move->amount, SaleAmounts::SCALE);
            $income  = bccomp($move->amount, '0', SaleAmounts::SCALE) > 0 ? bcadd($income, $move->amount, SaleAmounts::SCALE) : $income;
            $outgo   = bccomp($move->amount, '0', SaleAmounts::SCALE) < 0 ? bcadd($outgo, $move->amount, SaleAmounts::SCALE) : $outgo;
            $rows[]  = new ReportRow(['date' => (string) $move->journal_date, 'description' => (string) $move->description, 'source' => JournalSources::label((string) $move->source_type), 'amount' => $move->amount, 'balance' => bcadd($balance, '0', SaleAmounts::SCALE)]);
        }

        $rows[] = new ReportRow(['description' => $this->text('total_income'), 'amount' => $this->amount($income)], null, true);
        $rows[] = new ReportRow(['description' => $this->text('total_expenses'), 'amount' => $this->amount($outgo)], null, true);
        $rows[] = new ReportRow(['description' => $this->text('closing_balance'), 'balance' => $this->amount($balance)], null, true);

        $ties  = [new TieCheck($this->text('tie_closing_ledger'), bcadd($balance, '0', SaleAmounts::SCALE), $this->accounts->movement($accounts, $input->scope, null, $period->to, self::sources()))];
        $notes = [$this->text('note_financial_sources'), $this->text('note_financial_opening')];

        if ($input->scope->isCompany() && ! $input->readsOwnRecordsOnly()) {
            $supplierRows = $this->payments->read($input->scope, $period, null);
            $paid         = '0';

            foreach ($supplierRows as $row) {
                $paid = bcadd($paid, $row->amount, SaleAmounts::SCALE);
            }

            $out = '0';

            foreach ($moves as $move) {
                if (in_array($move->source_type, SupplierPaymentRows::cashSources(), true)) {
                    $out = bcadd($out, $move->amount, SaleAmounts::SCALE);
                }
            }

            $ties = [...$ties, ...ForeignAmounts::guard([new TieCheck($this->text('tie_supplier_payments'), bcsub('0', $out, SaleAmounts::SCALE), bcadd($paid, '0', SaleAmounts::SCALE))], ForeignAmounts::paymentsOf($supplierRows))];
        }

        $columns = [$this->column('date', ColumnKind::Date), $this->column('description'), $this->column('source'), $this->column('amount', ColumnKind::Amount), $this->column('balance', ColumnKind::Amount, 'col_balance_after')];

        return new ReportTable($this->text('financial_movements_title'), $columns, [new ReportBlock(null, $rows)], null, $ties, $notes);
    }

    /**
     * @param list<int> $accounts
     *
     * @return list<object>
     */
    private function moves(ReportInput $input, ReportPeriod $period, array $accounts): array
    {
        if ($accounts === [] || $input->scope->isEmpty()) {
            return [];
        }

        $builder = $this->db->table('accounting_journal_lines l')
            ->select('j.journal_id, j.journal_date, j.source_type, j.description, SUM(l.debit - l.credit) AS amount', false)
            ->join('accounting_journals j', 'j.journal_id = l.journal_id')
            ->where('j.company_id', CompanyContext::id())
            ->where('j.status', JournalStatus::Posted->value)
            ->whereIn('j.source_type', self::sources())
            ->whereIn('j.branch_id', $input->scope->ids)
            ->whereIn('l.account_id', $accounts)
            ->where('j.journal_date <=', $period->to)
            ->groupBy('j.journal_id, j.journal_date, j.source_type, j.description')
            ->orderBy('j.journal_date', 'ASC')
            ->orderBy('j.journal_id', 'ASC');

        if ($period->from !== null) {
            $builder->where('j.journal_date >=', $period->from);
        }

        $moves = [];

        foreach ($builder->get()->getResult() as $move) {
            $move->amount = bcadd((string) $move->amount, '0', SaleAmounts::SCALE);

            if (bccomp($move->amount, '0', SaleAmounts::SCALE) !== 0) {
                $moves[] = $move;
            }
        }

        return $moves;
    }
}
