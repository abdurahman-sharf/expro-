<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\OSPOS;

/**
 * The cash a drawer should hold when it is counted: what it was opened with and what was moved
 * into it, plus the cash payments that went into the treasuries of its branch in the time between
 * opening and closing, less the cash that was paid out of them for expenses in the same time. A
 * payment goes into the branch of the sale unless it carries the branch of another treasury, and
 * an expense is paid by the branch of its treasury in the same way, so a payment across branches
 * is counted by the drawer that holds the money. A payment counts as cash by what its name stands
 * for in the language in use or in English, and a payment that is not completed is not cash that
 * came in. An expense that belongs to no branch is one of the default branch. A receipt voucher
 * received in cash is cash that came in, counted for the days it is dated, as it carries no time. A payment
 * of a sale in a foreign currency counts for what the books moved into the treasury, which is its amount
 * at the rate of the payment rounded to the decimals of the base currency.
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

        return bcsub($expected, $this->cashExpenses($branchId, $openDate, $closeDate, $byDay, $branchId === $default), SaleAmounts::SCALE);
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
