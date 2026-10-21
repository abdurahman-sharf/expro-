<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/**
 * Whether a journal may be posted on a date: the date must fall in a fiscal year of the
 * company and after the closing of the company and of the journal's branch. The check runs
 * twice. Before the posting transaction it gives the early answer and creates the next fiscal
 * year when that is what the date needs. Inside the transaction it takes the period locks
 * first, so a closing that commits meanwhile is seen.
 */
final class PeriodGuard
{
    private BaseConnection $db;
    private PeriodLocks $locks;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db    = $db ?? Database::connect();
        $this->locks = new PeriodLocks($this->db);
    }

    public function prepare(string $date): void
    {
        if ($this->isDate($date)) {
            (new FiscalYearService($this->db))->ensureCovering($date);
        }
    }

    /**
     * @throws PostingException
     */
    public function check(string $date, int $branchId): void
    {
        $this->assert($date, $branchId);
    }

    /**
     * @throws PostingException
     */
    public function enforce(string $date, int $branchId): void
    {
        $this->locks->shareForPosting($branchId);
        $this->assert($date, $branchId);
    }

    /**
     * The latest closing that applies to a branch: the company's or the branch's own.
     *
     * @return array{date: string, branch_code: ?string}|null
     */
    public function effectiveCutoff(int $branchId): ?array
    {
        $row = $this->db->table('accounting_closed_periods p')
            ->select('p.date_to, p.branch_id, b.code AS branch_code')
            ->join('branches b', 'b.branch_id = p.branch_id', 'left')
            ->where('p.company_id', CompanyContext::id())
            ->where('p.active', 1)
            ->groupStart()
            ->where('p.branch_id IS NULL', null, false)
            ->orWhere('p.branch_id', $branchId)
            ->groupEnd()
            ->orderBy('p.date_to', 'DESC')
            ->limit(1)
            ->get()
            ->getRow();

        if ($row === null) {
            return null;
        }

        return ['date' => $row->date_to, 'branch_code' => $row->branch_id === null ? null : (string) $row->branch_code];
    }

    private function assert(string $date, int $branchId): void
    {
        if (! $this->isDate($date)) {
            return;
        }

        $covered = $this->db->table('accounting_fiscal_years')
            ->where('company_id', CompanyContext::id())
            ->where('start_date <=', $date)
            ->where('end_date >=', $date)
            ->countAllResults() > 0;

        if (! $covered) {
            throw new PostingException(PostingError::OutsideFiscalYear, "The date {$date} is outside every fiscal year.", ['date' => $date]);
        }

        $cutoff = $this->effectiveCutoff($branchId);

        if ($cutoff !== null && $date <= $cutoff['date']) {
            throw new PostingException(PostingError::PeriodClosed, "The date {$date} is in a period closed until {$cutoff['date']}.", [
                'date'       => $date,
                'until'      => $cutoff['date'],
                'scope_name' => $cutoff['branch_code'] ?? $this->companyName(),
            ]);
        }
    }

    private function companyName(): string
    {
        $company = $this->db->table('companies')->select('name')->where('company_id', CompanyContext::id())->get()->getRow();

        return $company === null ? '' : (string) $company->name;
    }

    private function isDate(string $value): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }
}
