<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use App\Models\Appconfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/**
 * The fiscal years of the company. They follow one another with no gap and no overlap, so
 * every date belongs to exactly one year. Changing them concerns the whole company: it needs
 * the permission and every branch, and it takes the company lock, so it waits for postings in
 * progress and holds new ones back while it writes.
 */
final class FiscalYearService
{
    use RunsInTransaction;

    public const MAX_DAYS           = 400;
    public const AUTO_CREATE_YEARS  = 2;
    public const THROUGH_MAX        = 100;
    public const NOTICE_DAYS_BEFORE = 60;
    private const WEEK_YEAR_DAYS    = 364;
    private const NAME_MAX          = 100;
    private const DESCRIPTION_MAX   = 255;
    private const AUTO_KEY          = 'accounting_auto_fiscal_year';

    private BaseConnection $db;
    private AuditLog $audit;
    private AccountingGuard $guard;
    private BranchGuard $branches;
    private PeriodLocks $locks;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->audit    = new AuditLog($this->db);
        $this->guard    = $guard ?? new AccountingGuard();
        $this->branches = $branches ?? new BranchGuard($this->db);
        $this->locks    = new PeriodLocks($this->db);
    }

    /**
     * Every year with the number of posted journals inside it and where it stands today.
     *
     * @return list<object>
     */
    public function all(): array
    {
        $years    = $this->db->prefixTable('accounting_fiscal_years');
        $journals = $this->db->prefixTable('accounting_journals');
        $today    = date('Y-m-d');

        $rows = $this->db->query(
            "SELECT y.*,
                (SELECT COUNT(*) FROM {$journals} j
                 WHERE j.company_id = y.company_id AND j.journal_date BETWEEN y.start_date AND y.end_date AND j.status IN (?, ?)) AS journal_count
             FROM {$years} y
             WHERE y.company_id = ?
             ORDER BY y.start_date ASC",
            [JournalStatus::Posted->value, JournalStatus::Reversed->value, CompanyContext::id()],
        )->getResult();

        foreach ($rows as $row) {
            $row->state = $row->end_date < $today ? 'past' : ($row->start_date > $today ? 'future' : 'current');
        }

        return $rows;
    }

    public function find(int $fiscalYearId): ?object
    {
        return $this->db->table('accounting_fiscal_years')->where('fiscal_year_id', $fiscalYearId)->where('company_id', CompanyContext::id())->get()->getRow();
    }

    public function covering(string $date): ?object
    {
        return $this->db->table('accounting_fiscal_years')
            ->where('company_id', CompanyContext::id())
            ->where('start_date <=', $date)
            ->where('end_date >=', $date)
            ->get()
            ->getRow();
    }

    /**
     * The values a new year starts with: it follows the last one, or begins on the accounting
     * start date, or on the first day of this year.
     *
     * @return array{name: string, start_date: string, end_date: string}
     */
    public function suggest(): array
    {
        $last = $this->last();

        if ($last !== null) {
            $start = $this->day($last->end_date, 1);
        } else {
            $configured = (string) model(Appconfig::class)->get_value('accounting_start_date');
            $start      = $this->isDate($configured) ? $configured : date('Y') . '-01-01';
        }

        $end = $this->yearEndFor($start, $last);

        return ['name' => $this->nameFor($start, $end), 'start_date' => $start, 'end_date' => $end];
    }

    /**
     * Days left until the last year ends, when that is within the notice period and no year
     * follows it. Null when there is nothing to warn about.
     */
    public function daysUntilLastYearEnds(): ?int
    {
        $last = $this->last();

        if ($last === null) {
            return null;
        }

        $days = (int) (new DateTimeImmutable(date('Y-m-d')))->diff(new DateTimeImmutable($last->end_date))->format('%r%a');

        return $days <= self::NOTICE_DAYS_BEFORE ? $days : null;
    }

    public function autoCreateEnabled(): bool
    {
        return model(Appconfig::class)->get_value(self::AUTO_KEY, '1') === '1';
    }

    /**
     * @param array{name?: string, start_date?: string, end_date?: string, description?: string} $input
     *
     * @throws AccountingException|BranchException
     */
    public function create(array $input, ?int $employeeId = null): int
    {
        $this->requireManage($employeeId);

        $fields = $this->normalize($input);

        return $this->transactional(function () use ($fields, $employeeId): int {
            $this->locks->holdCompany();
            $this->assertFits($fields['start_date'], $fields['end_date'], null);
            $this->assertNameFree($fields['name'], null);

            $this->db->table('accounting_fiscal_years')->insert($fields + ['company_id' => CompanyContext::id(), 'created_by' => $employeeId]);

            $fiscalYearId = (int) $this->db->insertID();

            $this->audit->write('fiscal_year', $fiscalYearId, 'created', $employeeId, JournalOrigin::User, [
                'name'  => $fields['name'],
                'start' => $fields['start_date'],
                'end'   => $fields['end_date'],
            ]);

            return $fiscalYearId;
        });
    }

    /**
     * Creates the first fiscal year, the calendar year that holds the start date of the books, when the company has
     * none. It is part of turning accounting on, and the person who does that has been shown to hold the permission
     * for it, so no other permission is asked here; the audit names that person. A year that covers the date already
     * is returned as it is, so a repeated call changes nothing.
     *
     * @throws AccountingException
     */
    public function createFirst(string $startDate, ?int $employeeId = null): int
    {
        if (! $this->isDate($startDate)) {
            throw new AccountingException(AccountingError::InvalidDate, 'The start date must be a valid Y-m-d date.');
        }

        return $this->transactional(function () use ($startDate, $employeeId): int {
            $this->locks->holdCompany();

            $covering = $this->covering($startDate);

            if ($covering !== null) {
                return (int) $covering->fiscal_year_id;
            }

            if ($this->last() !== null) {
                throw new AccountingException(AccountingError::StartDateOutsideFiscalYear, 'The start date must fall in a fiscal year.', ['date' => $startDate]);
            }

            $calendarYear = substr($startDate, 0, 4);
            $start        = "{$calendarYear}-01-01";
            $end          = "{$calendarYear}-12-31";
            $name         = $this->nameFor($start, $end);

            $this->db->table('accounting_fiscal_years')->insert([
                'company_id'  => CompanyContext::id(),
                'name'        => $name,
                'start_date'  => $start,
                'end_date'    => $end,
                'description' => null,
                'status'      => 'open',
                'created_by'  => $employeeId,
            ]);

            $fiscalYearId = (int) $this->db->insertID();

            $this->audit->write('fiscal_year', $fiscalYearId, 'created', $employeeId, $employeeId === null ? JournalOrigin::System : JournalOrigin::User, ['name' => $name, 'start' => $start, 'end' => $end]);

            return $fiscalYearId;
        });
    }

    /**
     * The name and description can always change. The dates change only at the edges of the
     * calendar, only while no journal and no closing lies in the part given up.
     *
     * @param array{name?: string, start_date?: string, end_date?: string, description?: string} $input
     *
     * @throws AccountingException|BranchException
     */
    public function update(int $fiscalYearId, array $input, ?int $employeeId = null): void
    {
        $this->requireManage($employeeId);

        $this->transactional(function () use ($fiscalYearId, $input, $employeeId): void {
            $this->locks->holdCompany();

            $year    = $this->requireYear($fiscalYearId);
            $fields  = $this->normalize($input + (array) $year);
            $changes = [];

            foreach ($fields as $key => $value) {
                if ((string) ($year->{$key} ?? '') !== (string) ($value ?? '')) {
                    $changes[$key] = $value;
                }
            }

            if ($changes === []) {
                return;
            }

            if (isset($changes['name'])) {
                $this->assertNameFree($changes['name'], $fiscalYearId);
            }

            if (isset($changes['start_date']) || isset($changes['end_date'])) {
                if ((new ClosedOutYears($this->db))->hasActiveIn($fiscalYearId)) {
                    throw new AccountingException(AccountingError::YearEndClosedOut, 'The year is closed out. Take its closing back before its dates change.', ['name' => $year->name, 'date' => $year->end_date]);
                }

                $this->assertDatesCanChange($year, $fields['start_date'], $fields['end_date']);
            }

            $this->db->table('accounting_fiscal_years')->where('fiscal_year_id', $fiscalYearId)->update($changes);

            $this->audit->write('fiscal_year', $fiscalYearId, 'updated', $employeeId, JournalOrigin::User, [
                'before' => array_intersect_key((array) $year, $changes),
                'after'  => $changes,
            ]);
        });
    }

    /**
     * Only the first or the last year can go, so the rest stay in sequence, and only while no
     * journal of any state and no closing lies inside it.
     *
     * @throws AccountingException|BranchException
     */
    public function delete(int $fiscalYearId, ?int $employeeId = null): void
    {
        $this->requireManage($employeeId);

        $this->transactional(function () use ($fiscalYearId, $employeeId): void {
            $this->locks->holdCompany();

            $year  = $this->requireYear($fiscalYearId);
            $first = $this->first();
            $last  = $this->last();

            if ((int) $year->fiscal_year_id !== (int) $first->fiscal_year_id && (int) $year->fiscal_year_id !== (int) $last->fiscal_year_id) {
                throw new AccountingException(AccountingError::FiscalYearBoundary, 'Only the first or the last fiscal year can be deleted.');
            }

            $this->assertRangeEmpty($year->start_date, $year->end_date);

            $this->db->table('accounting_fiscal_years')->where('fiscal_year_id', $fiscalYearId)->delete();

            $this->audit->write('fiscal_year', $fiscalYearId, 'deleted', $employeeId, JournalOrigin::User, ['name' => $year->name, 'start' => $year->start_date, 'end' => $year->end_date]);
        });
    }

    /**
     * Creates the years a date needs when it lies just past the last one and creating them
     * automatically is on. The system does this, so no permission is asked. Two requests
     * creating the same year are harmless: the second one finds the first one's row.
     */
    public function ensureCovering(string $date): bool
    {
        if ($this->covering($date) !== null) {
            return true;
        }

        $last = $this->last();

        if ($last === null || $date <= $last->end_date || ! $this->autoCreateEnabled()) {
            return false;
        }

        for ($created = 0; $created < self::AUTO_CREATE_YEARS && $last->end_date < $date; $created++) {
            $start = $this->day($last->end_date, 1);
            $end   = $this->yearEndFor($start, $last);
            $name  = $this->nameFor($start, $end);

            $this->db->query(
                'INSERT IGNORE INTO ' . $this->db->prefixTable('accounting_fiscal_years') . ' (company_id, name, start_date, end_date, description, status)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [CompanyContext::id(), $name, $start, $end, null, 'open'],
            );

            if ($this->db->affectedRows() === 1) {
                $this->audit->write('fiscal_year', (int) $this->db->insertID(), 'created', null, JournalOrigin::System, ['name' => $name, 'start' => $start, 'end' => $end, 'automatic' => true]);
            }

            $last = $this->last();
        }

        return $this->covering($date) !== null;
    }

    /**
     * The years that follow the last one until one covers the date, as createThrough makes them, with
     * nothing written. None when a year covers the date already or when there is no year to follow.
     *
     * @return list<array{name: string, start: string, end: string}>
     *
     * @throws AccountingException
     */
    public function missingThrough(string $date): array
    {
        if (! $this->isDate($date)) {
            throw new AccountingException(AccountingError::InvalidDate, 'The date must be a valid Y-m-d date.');
        }

        $years    = [];
        $previous = $this->last();

        while ($previous !== null && $previous->end_date < $date && count($years) < self::THROUGH_MAX) {
            $start    = $this->day($previous->end_date, 1);
            $end      = $this->yearEndFor($start, $previous);
            $years[]  = ['name' => $this->nameFor($start, $end), 'start' => $start, 'end' => $end];
            $previous = (object) ['start_date' => $start, 'end_date' => $end];
        }

        return $years;
    }

    /**
     * Creates the years that follow the last one until one covers the date, whatever the setting for creating
     * years by themselves says: it is how the books are given the years their documents fall in. A person
     * needs the permission and every branch, and the system, which turns accounting on, needs neither.
     *
     * @return list<array{name: string, start: string, end: string}> The years created, none when nothing was missing.
     *
     * @throws AccountingException|BranchException
     */
    public function createThrough(string $date, ?int $employeeId = null): array
    {
        if ($employeeId !== null) {
            $this->requireManage($employeeId);
        }

        return $this->transactional(function () use ($date, $employeeId): array {
            $this->locks->holdCompany();

            $created = [];

            foreach ($this->missingThrough($date) as $year) {
                $this->db->query(
                    'INSERT IGNORE INTO ' . $this->db->prefixTable('accounting_fiscal_years') . ' (company_id, name, start_date, end_date, description, status, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [CompanyContext::id(), $year['name'], $year['start'], $year['end'], null, 'open', $employeeId],
                );

                if ($this->db->affectedRows() === 1) {
                    $this->audit->write('fiscal_year', (int) $this->db->insertID(), 'created', $employeeId, $employeeId === null ? JournalOrigin::System : JournalOrigin::User, ['name' => $year['name'], 'start' => $year['start'], 'end' => $year['end'], 'automatic' => $employeeId === null]);

                    $created[] = $year;
                }
            }

            return $created;
        });
    }

    private function requireManage(?int $employeeId): void
    {
        $this->guard->require(AccountingGuard::PERIODS_MANAGE, $employeeId);
        $this->branches->requireCompanyScope($employeeId);
    }

    private function requireYear(int $fiscalYearId): object
    {
        $year = $this->find($fiscalYearId);

        if ($year === null) {
            throw new AccountingException(AccountingError::FiscalYearNotFound, "Fiscal year {$fiscalYearId} does not exist.");
        }

        return $year;
    }

    private function first(): ?object
    {
        return $this->db->table('accounting_fiscal_years')->where('company_id', CompanyContext::id())->orderBy('start_date', 'ASC')->limit(1)->get()->getRow();
    }

    private function last(): ?object
    {
        return $this->db->table('accounting_fiscal_years')->where('company_id', CompanyContext::id())->orderBy('end_date', 'DESC')->limit(1)->get()->getRow();
    }

    /**
     * A new year follows the last one or precedes the first one. Anything else overlaps a year
     * or leaves a gap.
     */
    private function assertFits(string $start, string $end, ?int $exceptId): void
    {
        $builder = $this->db->table('accounting_fiscal_years')->where('company_id', CompanyContext::id());

        if ($exceptId !== null) {
            $builder->where('fiscal_year_id !=', $exceptId);
        }

        $years = $builder->orderBy('start_date', 'ASC')->get()->getResult();

        if ($years === []) {
            return;
        }

        foreach ($years as $year) {
            if ($start <= $year->end_date && $end >= $year->start_date) {
                throw new AccountingException(AccountingError::FiscalYearOverlap, "The dates overlap the fiscal year {$year->name}.", ['name' => $year->name]);
            }
        }

        $first = $years[0];
        $last  = $years[count($years) - 1];

        if ($start !== $this->day($last->end_date, 1) && $end !== $this->day($first->start_date, -1)) {
            throw new AccountingException(AccountingError::FiscalYearGap, 'A fiscal year must start the day after the last one ends.', ['date' => $this->day($last->end_date, 1)]);
        }
    }

    private function assertNameFree(string $name, ?int $exceptId): void
    {
        $builder = $this->db->table('accounting_fiscal_years')->where('company_id', CompanyContext::id())->where('name', $name);

        if ($exceptId !== null) {
            $builder->where('fiscal_year_id !=', $exceptId);
        }

        if ($builder->countAllResults() > 0) {
            throw new AccountingException(AccountingError::FiscalYearNameTaken, 'The name is already used.', ['name' => $name]);
        }
    }

    private function assertDatesCanChange(object $year, string $start, string $end): void
    {
        $first   = $this->first();
        $last    = $this->last();
        $isFirst = (int) $year->fiscal_year_id === (int) $first->fiscal_year_id;
        $isLast  = (int) $year->fiscal_year_id === (int) $last->fiscal_year_id;

        if (($start !== $year->start_date && ! $isFirst) || ($end !== $year->end_date && ! $isLast)) {
            throw new AccountingException(AccountingError::FiscalYearBoundary, 'Only the start of the first year and the end of the last year can change.');
        }

        $this->assertFits($start, $end, (int) $year->fiscal_year_id);

        if ($start > $year->start_date) {
            $this->assertRangeEmpty($year->start_date, $this->day($start, -1));
        }

        if ($end < $year->end_date) {
            $this->assertRangeEmpty($this->day($end, 1), $year->end_date);
        }
    }

    /**
     * No journal of any state and no closing may lie in the range.
     */
    private function assertRangeEmpty(string $from, string $to): void
    {
        $journals = $this->db->table('accounting_journals')
            ->where('company_id', CompanyContext::id())
            ->where('journal_date >=', $from)
            ->where('journal_date <=', $to)
            ->countAllResults();

        if ($journals > 0) {
            throw new AccountingException(AccountingError::FiscalYearHasJournals, 'Journals lie in that part of the year.', ['count' => $journals]);
        }

        $closings = $this->db->table('accounting_closed_periods')
            ->where('company_id', CompanyContext::id())
            ->where('active', 1)
            ->where('date_from <=', $to)
            ->where('date_to >=', $from)
            ->countAllResults();

        if ($closings > 0) {
            throw new AccountingException(AccountingError::FiscalYearClosed, 'A closed period lies in that part of the year.');
        }
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{name: string, start_date: string, end_date: string, description: ?string}
     */
    private function normalize(array $input): array
    {
        $name  = trim((string) ($input['name'] ?? ''));
        $start = (string) ($input['start_date'] ?? '');
        $end   = (string) ($input['end_date'] ?? '');

        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            throw new AccountingException(AccountingError::FiscalYearInvalidName, 'The name must be 1 to 100 characters.');
        }

        if (! $this->isDate($start) || ! $this->isDate($end)) {
            throw new AccountingException(AccountingError::InvalidDate, 'The dates must be valid Y-m-d dates.');
        }

        if ($end < $start) {
            throw new AccountingException(AccountingError::FiscalYearRange, 'The end must not be before the start.');
        }

        $days = (int) (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->format('%a') + 1;

        if ($days > self::MAX_DAYS) {
            throw new AccountingException(AccountingError::FiscalYearTooLong, 'A fiscal year cannot be longer than 400 days.', ['max' => self::MAX_DAYS]);
        }

        $description = trim((string) ($input['description'] ?? ''));

        return [
            'name'        => $name,
            'start_date'  => $start,
            'end_date'    => $end,
            'description' => $description === '' ? null : mb_substr($description, 0, self::DESCRIPTION_MAX),
        ];
    }

    /**
     * The end of the year that begins on a date: 31 December for a year that begins on 1 January,
     * 364 days after a year of 52 weeks, else the day before the same date a year later.
     */
    private function yearEndFor(string $start, ?object $previous): string
    {
        if (substr($start, 5) === '01-01') {
            return substr($start, 0, 4) . '-12-31';
        }

        if ($previous !== null) {
            $days = (int) (new DateTimeImmutable($previous->start_date))->diff(new DateTimeImmutable($previous->end_date))->format('%a');

            if ($days === self::WEEK_YEAR_DAYS - 1) {
                return $this->day($start, $days);
            }
        }

        return $this->day((new DateTimeImmutable($start))->modify('+1 year')->format('Y-m-d'), -1);
    }

    private function nameFor(string $start, string $end): string
    {
        $from = substr($start, 0, 4);
        $to   = substr($end, 0, 4);

        return $from === $to ? $from : "{$from}/{$to}";
    }

    private function day(string $date, int $offset): string
    {
        return (new DateTimeImmutable($date))->modify(($offset >= 0 ? '+' : '') . $offset . ' day')->format('Y-m-d');
    }

    private function isDate(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }
}
