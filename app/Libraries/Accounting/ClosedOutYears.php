<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Which branches have closed which years in the books. A year a branch has closed out holds
 * still: the closing journal was made from its figures, so nothing that would change them may be
 * done until the closing is taken back.
 */
final class ClosedOutYears
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function activeOf(int $branchId, int $fiscalYearId): ?object
    {
        return $this->db->table('accounting_year_end_closings')
            ->where('company_id', CompanyContext::id())
            ->where('branch_id', $branchId)
            ->where('fiscal_year_id', $fiscalYearId)
            ->where('status', 'active')
            ->get()
            ->getRow();
    }

    public function hasActiveIn(int $fiscalYearId): bool
    {
        return $this->db->table('accounting_year_end_closings')
            ->where('company_id', CompanyContext::id())
            ->where('fiscal_year_id', $fiscalYearId)
            ->where('status', 'active')
            ->countAllResults() > 0;
    }

    /**
     * With $locked the closing is read under a shared lock, so the answer is what is committed now
     * and not what the snapshot of a caller already inside a transaction holds.
     */
    public function covering(int $branchId, string $date, bool $locked = false): ?object
    {
        $table   = $this->db->prefixTable('accounting_year_end_closings');
        $mode    = $locked ? ' LOCK IN SHARE MODE' : '';
        $closing = LockedRead::row($this->db, "SELECT * FROM {$table} WHERE company_id = ? AND branch_id = ? AND status = 'active' AND period_from <= ? AND period_to >= ?{$mode}", [CompanyContext::id(), $branchId, $date, $date]);

        if ($closing !== null) {
            $closing->year_name = (string) ($this->db->table('accounting_fiscal_years')->select('name')->where('fiscal_year_id', (int) $closing->fiscal_year_id)->get()->getRow()->name ?? '');
        }

        return $closing;
    }

    /**
     * The closings of years that a closing of periods up to the date would no longer cover: for the
     * company every branch's, for a branch only its own, which the company's closing may still cover.
     *
     * @return list<object>
     */
    public function leftOpenBy(?int $branchId, ?string $companyCutoff, ?string $branchCutoff = null): array
    {
        $builder = $this->db->table('accounting_year_end_closings c')
            ->select('c.closing_id, c.branch_id, c.fiscal_year_id, c.period_to, y.name AS year_name')
            ->join('accounting_fiscal_years y', 'y.fiscal_year_id = c.fiscal_year_id')
            ->where('c.company_id', CompanyContext::id())
            ->where('c.status', 'active');

        if ($branchId !== null) {
            $builder->where('c.branch_id', $branchId);
        }

        $cutoff = $branchId === null ? $companyCutoff : max($companyCutoff ?? '', $branchCutoff ?? '');

        if ($cutoff !== null && $cutoff !== '') {
            $builder->where('c.period_to >', $cutoff);
        }

        return $builder->orderBy('c.period_to', 'ASC')->orderBy('c.branch_id', 'ASC')->get()->getResult();
    }

    /**
     * The caller holds the period locks of the branch, and the closing is read under them.
     *
     * @throws AccountingException
     */
    public function assertNotClosedOut(int $branchId, string $date): void
    {
        $closing = $this->covering($branchId, $date, true);

        if ($closing !== null) {
            throw new AccountingException(AccountingError::YearEndClosedOut, "The year of {$date} is closed out for the branch. Take the closing back first.", ['name' => $closing->year_name, 'date' => $closing->period_to]);
        }
    }
}
