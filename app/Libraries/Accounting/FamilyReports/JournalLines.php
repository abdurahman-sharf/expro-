<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\CostCenterService;
use App\Libraries\Accounting\JournalStatus;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Generator;
use LogicException;

/**
 * The lines of the journals that are in the books of the branches of a scope over a period, a reversed
 * journal and its reversal both, cut down to a source, an account and the accounts under it. A reversal
 * counts as the source of the journal it reverses. A reader who may not read everyone's journals reads
 * their own.
 */
final class JournalLines
{
    public const CHUNK            = 2000;
    private const JOURNAL_COLUMNS = 'j.journal_id, j.journal_date, j.series, j.number, j.description, j.source_type, j.entry_type, j.status, j.branch_id, br.code AS branch_code, j.total_debit';
    private const LINE_COLUMNS    = 'j.journal_id, j.journal_date, j.series, j.number, j.description AS journal_description, j.source_type, j.entry_type, j.status, j.branch_id, l.line_id, l.line_no, l.description AS line_description, a.code AS account_code, a.name AS account_name, l.account_id, l.cost_center_id, l.debit, l.credit';

    private BaseConnection $db;

    /**
     * @var array<string, array{0: list<int>, 1: list<int>}>
     */
    private array $resolved = [];

    public function __construct(?BaseConnection $db = null, private readonly int $chunk = self::CHUNK)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return list<object>
     */
    public function read(ReportScope $scope, ReportPeriod $period, ?string $source = null, ?int $accountId = null, ?int $owner = null, bool $withoutCostCenter = false, ?int $costCenterId = null): array
    {
        $this->resolved = [];
        $builder        = $this->base($scope, $period, $source, $accountId, $owner, $withoutCostCenter, $costCenterId);

        if ($builder === null) {
            return [];
        }

        return $builder
            ->select(self::LINE_COLUMNS)
            ->orderBy('j.journal_date', 'ASC')
            ->orderBy('j.journal_id', 'ASC')
            ->orderBy('l.line_no', 'ASC')
            ->get()
            ->getResult();
    }

    public function count(ReportScope $scope, ReportPeriod $period, ?string $source = null, ?int $accountId = null, ?int $owner = null, bool $withoutCostCenter = false, ?int $costCenterId = null): int
    {
        return array_sum($this->days($scope, $period, $source, $accountId, $owner, $withoutCostCenter, $costCenterId));
    }

    /**
     * How many lines each day of the period holds, for the days that hold any.
     *
     * @return array<string, int>
     */
    public function days(ReportScope $scope, ReportPeriod $period, ?string $source = null, ?int $accountId = null, ?int $owner = null, bool $withoutCostCenter = false, ?int $costCenterId = null): array
    {
        $this->resolved = [];
        $builder        = $this->base($scope, $period, $source, $accountId, $owner, $withoutCostCenter, $costCenterId, true);
        $days           = [];

        if ($builder === null) {
            return $days;
        }

        foreach ($builder->select('j.journal_date AS day, COUNT(*) AS found', false)->groupBy('j.journal_date')->orderBy('j.journal_date', 'ASC')->get()->getResult() as $row) {
            $days[(string) $row->day] = (int) $row->found;
        }

        return $days;
    }

    /**
     * The same lines in the same order, a few thousand at a time, so that a period of any size costs the
     * memory of one chunk. Each chunk is the lines of whole days, and a day of more lines than a chunk is read
     * by journal. With $byAccount each chunk holds the lines of one account, or of several whole accounts, and
     * the accounts come in the order of their codes, as they are listed by a report that groups by account.
     *
     * @param array<string, int>|null $days What days() gave for the same lines, when it was asked already.
     *
     * @return Generator<int, array{0: ?string, 1: list<object>}> The code of the account the chunk is of when it is of one, and the lines.
     */
    public function stream(ReportScope $scope, ReportPeriod $period, ?string $source = null, ?int $accountId = null, ?int $owner = null, bool $withoutCostCenter = false, ?int $costCenterId = null, bool $byAccount = false, ?array $days = null): Generator
    {
        $filters        = [$scope, $period, $source, $accountId, $owner, $withoutCostCenter, $costCenterId];
        $this->resolved = [];

        if ($scope->isEmpty()) {
            return;
        }

        if (! $byAccount) {
            $days ??= $this->days(...$filters);

            foreach ($this->chunks($filters, null, $days) as $lines) {
                yield [null, $lines];
            }

            return;
        }

        $counts = [];

        foreach ($this->narrowed($filters, null)->select('a.code AS account_code, COUNT(*) AS found', false)->groupBy('a.code')->get()->getResult() as $row) {
            $counts[(string) $row->account_code] = (int) $row->found;
        }

        $order = array_fill_keys(array_keys($counts), true);

        ksort($order);

        $batch = [];
        $held  = 0;

        foreach (array_keys($order) as $code) {
            $code  = (string) $code;
            $found = $counts[$code];

            if ($found > $this->chunk) {
                yield from $this->byAccount($filters, $batch);

                $batch = [];
                $held  = 0;

                foreach ($this->chunks($filters, [$code], $found) as $lines) {
                    yield [$code, $lines];
                }

                continue;
            }

            if ($held + $found > $this->chunk && $batch !== []) {
                yield from $this->byAccount($filters, $batch);

                $batch = [];
                $held  = 0;
            }

            $batch[] = $code;
            $held += $found;
        }

        yield from $this->byAccount($filters, $batch);
    }

    /**
     * @return list<object>
     */
    public function journals(ReportScope $scope, ReportPeriod $period, ?string $source = null, ?int $owner = null, string $search = ''): array
    {
        $builder = $this->base($scope, $period, $source, null, $owner, false, null);

        if ($builder === null) {
            return [];
        }

        if ($search !== '') {
            $builder->like('j.description', $search);
        }

        return $builder
            ->select('j.journal_id, j.journal_date, j.series, j.number, j.description, j.source_type, j.entry_type, j.status, j.branch_id, br.code AS branch_code, j.total_debit')
            ->join('branches br', 'br.branch_id = j.branch_id', 'left')
            ->groupBy('j.journal_id, j.journal_date, j.series, j.number, j.description, j.source_type, j.entry_type, j.status, j.branch_id, br.code, j.total_debit')
            ->orderBy('j.journal_date', 'ASC')
            ->orderBy('j.journal_id', 'ASC')
            ->get()
            ->getResult();
    }

    /**
     * How many journals each day of the period holds, for the days that hold any, cut down as journals() is.
     *
     * @return array<string, int>
     */
    public function journalDays(ReportScope $scope, ReportPeriod $period, ?string $source = null, ?int $owner = null, string $search = ''): array
    {
        $builder = $this->journalBase($scope, $period, $source, $owner, $search);
        $days    = [];

        if ($builder === null) {
            return $days;
        }

        foreach ($builder->select('j.journal_date AS day, COUNT(*) AS found', false)->groupBy('j.journal_date')->orderBy('j.journal_date', 'ASC')->get()->getResult() as $row) {
            $days[(string) $row->day] = (int) $row->found;
        }

        return $days;
    }

    /**
     * The journals journals() gives, in the same order, a few thousand at a time: the journals of whole days,
     * and a day of more journals than a chunk by number.
     *
     * @param array<string, int>|null $days What journalDays() gave for the same journals, when it was asked already.
     *
     * @return Generator<int, list<object>>
     */
    public function journalChunks(ReportScope $scope, ReportPeriod $period, ?string $source = null, ?int $owner = null, string $search = '', ?array $days = null): Generator
    {
        $days ??= $this->journalDays($scope, $period, $source, $owner, $search);
        $first = null;
        $last  = null;
        $held  = 0;
        $read  = fn (string $from, string $to): array => $this->journalRows($scope, ReportPeriod::of($from, $to), $source, $owner, $search);

        foreach ($days as $day => $count) {
            $day = (string) $day;

            if ($count > $this->chunk) {
                if ($first !== null) {
                    yield $read($first, $last);
                }

                $first = null;
                $held  = 0;
                $after = 0;

                while (true) {
                    $journals = $this->journalBase($scope, ReportPeriod::of($day, $day), $source, $owner, $search)
                        ->select(self::JOURNAL_COLUMNS)
                        ->where('j.journal_id >', $after)
                        ->orderBy('j.journal_id', 'ASC')
                        ->limit($this->chunk)
                        ->get()
                        ->getResult();

                    if ($journals === []) {
                        break;
                    }

                    yield $journals;

                    $after = (int) end($journals)->journal_id;
                }

                continue;
            }

            if ($held + $count > $this->chunk && $first !== null) {
                yield $read($first, $last);

                $first = null;
                $held  = 0;
            }

            $first ??= $day;
            $last = $day;
            $held += $count;
        }

        if ($first !== null) {
            yield $read($first, $last);
        }
    }

    /**
     * @return list<object>
     */
    private function journalRows(ReportScope $scope, ReportPeriod $period, ?string $source, ?int $owner, string $search): array
    {
        return $this->journalBase($scope, $period, $source, $owner, $search)
            ?->select(self::JOURNAL_COLUMNS)
            ->orderBy('j.journal_date', 'ASC')
            ->orderBy('j.journal_id', 'ASC')
            ->get()
            ->getResult() ?? [];
    }

    private function journalBase(ReportScope $scope, ReportPeriod $period, ?string $source, ?int $owner, string $search): ?BaseBuilder
    {
        if ($scope->isEmpty()) {
            return null;
        }

        $lines = $this->db->prefixTable('accounting_journal_lines');

        $builder = $this->db->table('accounting_journals j')
            ->join('accounting_journals o', 'o.journal_id = j.reverses_journal_id', 'left')
            ->join('branches br', 'br.branch_id = j.branch_id', 'left')
            ->where('j.company_id', CompanyContext::id())
            ->whereIn('j.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
            ->whereIn('j.branch_id', $scope->ids)
            ->where('j.journal_date <=', $period->to)
            ->where("EXISTS (SELECT 1 FROM {$lines} jl WHERE jl.journal_id = j.journal_id)", null, false);

        if ($period->from !== null) {
            $builder->where('j.journal_date >=', $period->from);
        }

        if ($source !== null && $source !== '') {
            $builder->groupStart()->where('j.source_type', $source)->orWhere('o.source_type', $source)->groupEnd();
        }

        if ($owner !== null) {
            $builder->where('j.created_by', $owner);
        }

        if ($search !== '') {
            $builder->like('j.description', $search);
        }

        return $builder;
    }

    /**
     * @param list<mixed>  $filters
     * @param list<string> $codes
     *
     * @return Generator<int, array{0: ?string, 1: list<object>}>
     */
    private function byAccount(array $filters, array $codes): Generator
    {
        if ($codes === []) {
            return;
        }

        $perCode = array_fill_keys($codes, []);

        foreach ($this->fetch($filters, $codes) as $line) {
            $perCode[(string) $line->account_code][] = $line;
        }

        foreach ($perCode as $code => $lines) {
            yield [(string) $code, $lines];
        }
    }

    /**
     * @param list<mixed>            $filters
     * @param list<string>|null      $codes
     * @param array<string, int>|int $days    How many lines each day holds, or how many lines there are when the days are not known and are asked for if the lines are more than a chunk.
     *
     * @return Generator<int, list<object>>
     */
    private function chunks(array $filters, ?array $codes, array|int $days): Generator
    {
        $found = is_array($days) ? array_sum($days) : $days;

        if ($found <= $this->chunk) {
            yield $this->fetch($filters, $codes);

            return;
        }

        if (! is_array($days)) {
            $days = [];

            foreach ($this->narrowed($filters, $codes)->select('j.journal_date AS day, COUNT(*) AS found', false)->groupBy('j.journal_date')->orderBy('j.journal_date', 'ASC')->get()->getResult() as $row) {
                $days[(string) $row->day] = (int) $row->found;
            }
        }

        $first = null;
        $last  = null;
        $held  = 0;

        foreach ($days as $day => $count) {
            $day = (string) $day;

            if ($count > $this->chunk) {
                if ($first !== null) {
                    yield $this->fetch($this->between($filters, $first, $last), $codes);
                }

                $first = null;
                $held  = 0;

                yield from $this->byJournal($this->between($filters, $day, $day), $codes);

                continue;
            }

            if ($held + $count > $this->chunk && $first !== null) {
                yield $this->fetch($this->between($filters, $first, $last), $codes);

                $first = null;
                $held  = 0;
            }

            $first ??= $day;
            $last = $day;
            $held += $count;
        }

        if ($first !== null) {
            yield $this->fetch($this->between($filters, $first, $last), $codes);
        }
    }

    /**
     * @param list<mixed> $filters
     *
     * @return Generator<int, list<object>>
     */
    private function byJournal(array $filters, ?array $codes): Generator
    {
        $journalId = 0;
        $lineNo    = 0;

        while (true) {
            $lines = $this->narrowed($filters, $codes)
                ->select(self::LINE_COLUMNS)
                ->where('(j.journal_id > ' . $journalId . ' OR (j.journal_id = ' . $journalId . ' AND l.line_no > ' . $lineNo . '))', null, false)
                ->orderBy('j.journal_id', 'ASC')
                ->orderBy('l.line_no', 'ASC')
                ->limit($this->chunk)
                ->get()
                ->getResult();

            if ($lines === []) {
                return;
            }

            yield $lines;

            $journalId = (int) end($lines)->journal_id;
            $lineNo    = (int) end($lines)->line_no;
        }
    }

    /**
     * @param list<mixed>       $filters
     * @param list<string>|null $codes
     *
     * @return list<object>
     */
    private function fetch(array $filters, ?array $codes): array
    {
        return $this->narrowed($filters, $codes)
            ->select(self::LINE_COLUMNS)
            ->orderBy('j.journal_date', 'ASC')
            ->orderBy('j.journal_id', 'ASC')
            ->orderBy('l.line_no', 'ASC')
            ->get()
            ->getResult();
    }

    /**
     * @param list<mixed>       $filters
     * @param list<string>|null $codes
     */
    private function narrowed(array $filters, ?array $codes): BaseBuilder
    {
        $builder = $this->base(...$filters) ?? throw new LogicException('A scope without branches has no lines to read.');

        if ($codes !== null) {
            $builder->whereIn('a.code', $codes);
        }

        return $builder;
    }

    /**
     * @param list<mixed> $filters
     *
     * @return list<mixed>
     */
    private function between(array $filters, string $from, string $to): array
    {
        $filters[1] = ReportPeriod::of($from, $to);

        return $filters;
    }

    private function base(ReportScope $scope, ReportPeriod $period, ?string $source, ?int $accountId, ?int $owner, bool $withoutCostCenter, ?int $costCenterId, bool $light = false): ?BaseBuilder
    {
        if ($scope->isEmpty()) {
            return null;
        }

        $memo = $accountId . ':' . ($withoutCostCenter ? 'none' : (string) $costCenterId);

        $this->resolved[$memo] ??= [
            $accountId !== null && $accountId > 0 ? (new ControlAccounts($this->db))->under($accountId) : [],
            ! $withoutCostCenter && $costCenterId !== null && $costCenterId > 0 ? (new CostCenterService($this->db))->descendantsOf($costCenterId) : [],
        ];

        [$accounts, $centers] = $this->resolved[$memo];

        $builder = $this->db->table('accounting_journal_lines l')->join('accounting_journals j', 'j.journal_id = l.journal_id');

        if (! $light || ($source !== null && $source !== '')) {
            $builder->join('accounting_journals o', 'o.journal_id = j.reverses_journal_id', 'left');
        }

        if (! $light) {
            $builder->join('accounting_accounts a', 'a.account_id = l.account_id');
        }

        $builder
            ->where('j.company_id', CompanyContext::id())
            ->whereIn('j.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
            ->whereIn('j.branch_id', $scope->ids)
            ->where('j.journal_date <=', $period->to);

        if ($period->from !== null) {
            $builder->where('j.journal_date >=', $period->from);
        }

        if ($source !== null && $source !== '') {
            $builder->groupStart()->where('j.source_type', $source)->orWhere('o.source_type', $source)->groupEnd();
        }

        if ($accounts !== []) {
            $builder->whereIn('l.account_id', $accounts);
        }

        if ($owner !== null) {
            $builder->where('j.created_by', $owner);
        }

        $distributions = $this->db->prefixTable('accounting_journal_line_distributions');

        if ($withoutCostCenter) {
            $builder->where('l.cost_center_id IS NULL', null, false)->where("NOT EXISTS (SELECT 1 FROM {$distributions} dd WHERE dd.line_id = l.line_id)", null, false);
        } elseif ($centers !== []) {
            $ids = implode(', ', $centers);

            $builder->where("(l.cost_center_id IN ({$ids}) OR EXISTS (SELECT 1 FROM {$distributions} dd WHERE dd.line_id = l.line_id AND dd.cost_center_id IN ({$ids})))", null, false);
        }

        return $builder;
    }
}
