<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\OSPOS;

/**
 * The cash a drawer should hold when it is counted: what it was opened with and what was moved
 * into it, plus the cash that came into the treasuries of its branch in the time between opening
 * and closing, less the cash that went out of them in the same time.
 *
 * Cash comes in with the payments of sales (less the change given back), the receipt vouchers, the
 * advances customers paid, the credit suppliers paid back and the refunds of purchases returned for
 * cash. It goes out with the expenses, the cash purchases, the payments to suppliers, the advances
 * paid to suppliers and the credit paid back to customers. A payment goes into the branch of the sale
 * unless it carries the branch of another treasury, and an expense or a payment to a supplier is paid
 * by the branch of its treasury in the same way, so a payment across branches is counted by the drawer
 * that holds the money. A document counts as cash by what the name of its way of payment stands for
 * in the language in use or in English, and one that is not completed or was cancelled moves no cash.
 * An expense that belongs to no branch is one of the default branch. A purchase counts at the time it
 * was saved, as a sale does. A receipt voucher, an advance, a refund and a payment to a supplier
 * carry a day and no time, and are counted for the days they are dated. A payment of a sale or to
 * a supplier in a foreign currency counts for what the books moved through the treasury, which is
 * its amount at the rate of the payment rounded to the decimals of the base currency.
 */
final class CashupExpectation
{
    private const SALE_TYPES = [SALE_TYPE_POS, SALE_TYPE_INVOICE, SALE_TYPE_RETURN];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @param numeric-string $openAmount
     * @param numeric-string $transferAmount
     *
     * @return numeric-string
     */
    public function expected(int $branchId, string $openDate, string $closeDate, string $openAmount, string $transferAmount): string
    {
        $byDay    = empty(config(OSPOS::class)->settings['date_or_time_format']);
        $default  = (new BranchGuard($this->db))->companyDefaultBranchId();
        $expected = bcadd(bcadd($openAmount, $transferAmount, SaleAmounts::SCALE), $this->cashSales($branchId, $openDate, $closeDate, $byDay), SaleAmounts::SCALE);
        $expected = bcadd($expected, $this->cashVouchers($branchId, $openDate, $closeDate), SaleAmounts::SCALE);
        $expected = bcadd($expected, $this->cashDocuments('customer_advances', 'advance_date', $branchId, $openDate, $closeDate), SaleAmounts::SCALE);
        $expected = bcadd($expected, $this->cashDocuments('supplier_credit_refunds', 'refund_date', $branchId, $openDate, $closeDate), SaleAmounts::SCALE);
        $expected = bcsub($expected, $this->cashExpenses($branchId, $openDate, $closeDate, $byDay, $branchId === $default), SaleAmounts::SCALE);
        $expected = bcsub($expected, $this->cashDocuments('customer_credit_refunds', 'refund_date', $branchId, $openDate, $closeDate), SaleAmounts::SCALE);
        $expected = bcsub($expected, $this->cashDocuments('supplier_advances', 'advance_date', $branchId, $openDate, $closeDate, ['kind' => SupplierAdvances::PAYMENT]), SaleAmounts::SCALE);
        $expected = bcsub($expected, $this->cashPaidToSuppliers($branchId, $openDate, $closeDate), SaleAmounts::SCALE);

        return bcsub($expected, $this->cashPurchases($branchId, $openDate, $closeDate, $byDay), SaleAmounts::SCALE);
    }

    /**
     * @return numeric-string
     */
    private function cashSales(int $branchId, string $openDate, string $closeDate, bool $byDay): string
    {
        $rate     = 'COALESCE(p.currency_rate, s.currency_rate)';
        $net      = '(p.payment_amount - p.cash_refund)';
        $decimals = (new Currencies($this->db))->baseDecimals();
        $builder  = $this->db->table('sales_payments p')
            ->select("p.payment_type, SUM(CASE WHEN {$rate} IS NULL THEN {$net} ELSE ROUND({$net} * {$rate}, {$decimals}) END) AS amount", false)
            ->join('sales s', 's.sale_id = p.sale_id')
            ->where('s.sale_status', COMPLETED)
            ->whereIn('s.sale_type', self::SALE_TYPES)
            ->where('COALESCE(p.treasury_branch_id, s.branch_id) = ' . $this->db->escape($branchId), null, false)
            ->where('p.payment_status', PaymentStatus::Completed->value)
            ->groupBy('p.payment_type');

        $this->within($builder, 's.sale_time', $openDate, $closeDate, $byDay);

        return $this->cashTotal($builder->get()->getResult());
    }

    /**
     * @return numeric-string
     */
    private function cashVouchers(int $branchId, string $openDate, string $closeDate): string
    {
        $builder = $this->db->table('revenues r')
            ->select('r.payment_type, SUM(r.amount) AS amount', false)
            ->where('r.deleted', 0)
            ->where('COALESCE(r.treasury_branch_id, r.branch_id) = ' . $this->db->escape($branchId), null, false)
            ->groupBy('r.payment_type');

        $this->within($builder, 'r.date', $openDate, $closeDate, true);

        return $this->cashTotal($builder->get()->getResult());
    }

    /**
     * @return numeric-string
     */
    private function cashExpenses(int $branchId, string $openDate, string $closeDate, bool $byDay, bool $isDefault): string
    {
        $builder = $this->db->table('expenses e')
            ->select('e.payment_type, SUM(e.amount) AS amount', false)
            ->where('e.deleted', 0)
            ->groupBy('e.payment_type');

        $drawer = 'COALESCE(e.treasury_branch_id, e.branch_id) = ' . $this->db->escape($branchId);

        if ($isDefault) {
            $builder->groupStart()->where($drawer, null, false)->orWhere('e.branch_id IS NULL', null, false)->groupEnd();
        } else {
            $builder->where($drawer, null, false);
        }

        $this->within($builder, 'e.date', $openDate, $closeDate, $byDay);

        return $this->cashTotal($builder->get()->getResult());
    }

    /**
     * The cash of the documents of one kind that moved money through a treasury of the branch for a
     * customer or a supplier, on the days between opening and closing.
     *
     * @param array<string, string> $where Conditions on the columns of the document besides its branch and its status.
     *
     * @return numeric-string
     */
    private function cashDocuments(string $table, string $dateColumn, int $branchId, string $openDate, string $closeDate, array $where = []): string
    {
        $builder = $this->db->table($table . ' d')
            ->select('d.payment_type, SUM(d.amount) AS amount', false)
            ->where('d.branch_id', $branchId)
            ->where('d.status', 'completed')
            ->groupBy('d.payment_type');

        foreach ($where as $column => $value) {
            $builder->where('d.' . $column, $value);
        }

        $this->within($builder, 'd.' . $dateColumn, $openDate, $closeDate, true);

        return $this->cashTotal($builder->get()->getResult());
    }

    /**
     * What was paid to suppliers against purchases, less what suppliers gave back, as the drawer that
     * paid it holds it, on the days the payments are dated.
     *
     * @return numeric-string
     */
    private function cashPaidToSuppliers(int $branchId, string $openDate, string $closeDate): string
    {
        $rate     = 'COALESCE(p.currency_rate, r.currency_rate)';
        $decimals = (new Currencies($this->db))->baseDecimals();
        $builder  = $this->db->table('receivings_payments p')
            ->select("p.payment_type, SUM(CASE WHEN {$rate} IS NULL THEN p.payment_amount ELSE ROUND(p.payment_amount * {$rate}, {$decimals}) END) AS amount", false)
            ->join('receivings r', 'r.receiving_id = p.receiving_id')
            ->where('COALESCE(p.treasury_branch_id, r.branch_id) = ' . $this->db->escape($branchId), null, false)
            ->where('p.payment_status', PaymentStatus::Completed->value)
            ->groupBy('p.payment_type');

        $this->within($builder, 'COALESCE(p.payment_date, DATE(p.payment_time))', $openDate, $closeDate, true);

        return $this->cashTotal($builder->get()->getResult());
    }

    /**
     * What the purchases of the branch that were paid in cash at once took out of the drawer, less what
     * the returns that were paid back in cash put in, at the base value of each. A purchase carries its
     * total in its lines, which is why it is read as the document and not summed in the query.
     *
     * @return numeric-string
     */
    private function cashPurchases(int $branchId, string $openDate, string $closeDate, bool $byDay): string
    {
        $builder = $this->db->table('receivings r')
            ->select('r.receiving_id, r.payment_type')
            ->whereIn('r.receiving_type', [ReceivingType::Receive->value, ReceivingType::Return->value])
            ->where('r.supplier_id IS NOT NULL', null, false)
            ->where('r.payment_type IS NOT NULL', null, false)
            ->where('r.branch_id', $branchId);

        $this->within($builder, 'r.receiving_time', $openDate, $closeDate, $byDay);

        $rows = $builder->get()->getResult();

        if ($rows === []) {
            return '0.0000';
        }

        $loader   = new ReceivingLoader($this->db);
        $decimals = (new Currencies($this->db))->baseDecimals();
        $total    = '0';

        foreach ($rows as $row) {
            if (PaymentType::fromLabel((string) $row->payment_type) !== PaymentType::Cash) {
                continue;
            }

            $purchase = $loader->load((int) $row->receiving_id, false);

            if ($purchase === null) {
                continue;
            }

            $amount = ReceivingAmounts::totals($purchase->lines, $purchase->currency?->decimals ?? 2)['total'];
            $amount = $purchase->currency === null ? $amount : $purchase->currency->toBase($amount, $decimals);
            $total  = $purchase->type === ReceivingType::Return ? bcsub($total, $amount, SaleAmounts::SCALE) : bcadd($total, $amount, SaleAmounts::SCALE);
        }

        return bcadd($total, '0', SaleAmounts::SCALE);
    }

    /**
     * @param list<object> $rows A payment type and what it came to.
     *
     * @return numeric-string
     */
    private function cashTotal(array $rows): string
    {
        $total = '0';

        foreach ($rows as $row) {
            if (PaymentType::fromLabel((string) $row->payment_type) === PaymentType::Cash) {
                $total = bcadd($total, (string) $row->amount, SaleAmounts::SCALE);
            }
        }

        return bcadd($total, '0', SaleAmounts::SCALE);
    }

    /**
     * The days, or the days and times, between opening and closing, as the cash-up screen takes
     * them.
     */
    private function within(BaseBuilder $builder, string $column, string $openDate, string $closeDate, bool $byDay): void
    {
        if ($byDay) {
            $builder->where("DATE({$column}) BETWEEN " . $this->db->escape(substr($openDate, 0, 10)) . ' AND ' . $this->db->escape(substr($closeDate, 0, 10)), null, false);

            return;
        }

        $builder->where("{$column} BETWEEN " . $this->db->escape($openDate) . ' AND ' . $this->db->escape($closeDate), null, false);
    }
}
