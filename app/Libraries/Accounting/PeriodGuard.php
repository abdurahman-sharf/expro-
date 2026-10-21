<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseBuilder;
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
     * Takes the period locks and checks the date against what is committed right now. The
     * reads lock too, because a poster inside a longer transaction may hold a snapshot older
     * than a closing that has committed since.
     *
     * @throws PostingException
     */
    public function enforce(string $date, int $branchId): void
    {
        $this->locks->shareForPosting($branchId);
        $this->assert($date, $branchId, true);
    }

    /**
     * The latest closing that applies to a branch: the company's or the branch's own.
     *
     * @return array{date: string, branch_code: ?string}|null
     */
    public function effectiveCutoff(int $branchId, bool $latest = false): ?array
    {
        $builder = $this->db->table('accounting_closed_periods')
            ->select('date_to, branch_id')
            ->where('company_id', CompanyContext::id())
            ->where('active', 1)
            ->groupStart()
            ->where('branch_id IS NULL', null, false)
            ->orWhere('branch_id', $branchId)
            ->groupEnd()
            ->orderBy('date_to', 'DESC')
            ->limit(1);

        $row = $this->row($builder, $latest);

        if ($row === null) {
            return null;
        }

        return ['date' => $row->date_to, 'branch_code' => $row->branch_id === null ? null : $this->branchCode((int) $row->branch_id)];
    }

    private function assert(string $date, int $branchId, bool $latest = false): void
    {
        if (! $this->isDate($date)) {
            return;
        }

        $covering = $this->db->table('accounting_fiscal_years')
            ->select('fiscal_year_id')
            ->where('company_id', CompanyContext::id())
            ->where('start_date <=', $date)
            ->where('end_date >=', $date)
            ->limit(1);

        if ($this->row($covering, $latest) === null) {
            throw new PostingException(PostingError::OutsideFiscalYear, "The date {$date} is outside every fiscal year.", ['date' => $date]);
        }

        $cutoff = $this->effectiveCutoff($branchId, $latest);

        if ($cutoff !== null && $date <= $cutoff['date']) {
            throw new PostingException(PostingError::PeriodClosed, "The date {$date} is in a period closed until {$cutoff['date']}.", [
                'date'       => $date,
                'until'      => $cutoff['date'],
                'scope_name' => $cutoff['branch_code'] ?? $this->companyName(),
            ]);
        }
    }

    /**
     * One row, read from the transaction's snapshot or, with $latest, as last committed and
     * under a shared lock.
     *
     * @throws PostingException
     */
    private function row(BaseBuilder $builder, bool $latest): ?object
    {
        if (! $latest) {
            return $builder->get()->getRow();
        }

        $result = $this->db->query($builder->getCompiledSelect() . ' LOCK IN SHARE MODE');

        if ($result === false) {
            throw new PostingException(PostingError::WriteFailed, 'The period could not be checked.');
        }

        return $result->getRow();
    }

    private function branchCode(int $branchId): string
    {
        $branch = $this->db->table('branches')->select('code')->where('branch_id', $branchId)->get()->getRow();

        return $branch === null ? '' : (string) $branch->code;
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
