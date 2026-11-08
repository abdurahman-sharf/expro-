<?php

namespace App\Libraries\Accounting\Ai;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\LockedRead;
use App\Libraries\Accounting\RunsInTransaction;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\RawSql;
use Config\Database;
use Generator;

/**
 * What the scan found, for people to read and to decide: open, dismissed or confirmed, with who decided, when
 * and why. Reading is limited to the branches the reader works in, and a decision changes the finding and nothing
 * else in the books.
 */
final class FindingService
{
    use RunsInTransaction;

    public const OPEN       = 'open';
    public const DISMISSED  = 'dismissed';
    public const CONFIRMED  = 'confirmed';
    public const STATUSES   = [self::OPEN, self::DISMISSED, self::CONFIRMED];
    public const SEVERITIES = [Finding::LOW, Finding::MEDIUM, Finding::HIGH];
    public const PAGE_MAX   = 200;
    public const EXPORT_MAX = 20000;
    private const CHUNK     = 500;

    private BaseConnection $db;
    private AuditLog $audit;
    private AccountingGuard $guard;
    private BranchGuard $branches;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->audit    = new AuditLog($this->db);
        $this->guard    = $guard ?? new AccountingGuard();
        $this->branches = $branches ?? new BranchGuard($this->db);
    }

    /**
     * @param array{status?: string, rule?: string, severity?: string, from?: string, to?: string} $filters
     *
     * @return array{total: int, rows: list<object>}
     *
     * @throws AccountingException
     */
    public function search(array $filters, ?int $employeeId, int $limit = 25, int $offset = 0): array
    {
        $this->guard->require(AccountingGuard::AI_USE, $employeeId);

        $builder = $this->filtered($filters, $employeeId);
        $total   = $builder->countAllResults(false);
        $rows    = $builder->orderBy('f.finding_id', 'DESC')->limit(max(1, min($limit, self::PAGE_MAX)), max(0, $offset))->get()->getResult();

        return ['total' => $total, 'rows' => array_map([$this, 'prepared'], $rows)];
    }

    /**
     * Every finding the filters leave, newest first, up to the limit of a file.
     *
     * @param array<string, string> $filters
     *
     * @return Generator<int, object>
     *
     * @throws AccountingException
     */
    public function stream(array $filters, ?int $employeeId): Generator
    {
        $this->guard->require(AccountingGuard::AI_USE, $employeeId);

        $last = PHP_INT_MAX;
        $sent = 0;

        while ($sent < self::EXPORT_MAX) {
            $page = $this->filtered($filters, $employeeId)->where('f.finding_id <', $last)->orderBy('f.finding_id', 'DESC')->limit(min(self::CHUNK, self::EXPORT_MAX - $sent))->get()->getResult();

            if ($page === []) {
                return;
            }

            foreach ($page as $row) {
                $last = (int) $row->finding_id;
                $sent++;

                yield $this->prepared($row);
            }
        }
    }

    /**
     * @throws AccountingException
     */
    public function find(int $findingId, ?int $employeeId): object
    {
        $this->guard->require(AccountingGuard::AI_USE, $employeeId);

        $row = $this->filtered([], $employeeId)->where('f.finding_id', $findingId)->get()->getRow();

        if ($row === null) {
            throw new AccountingException(AccountingError::AiFindingNotFound, "Finding {$findingId} does not exist.");
        }

        return $this->prepared($row);
    }

    /**
     * @return array<string, int> The findings of each status the employee may see.
     *
     * @throws AccountingException
     */
    public function counts(?int $employeeId): array
    {
        $this->guard->require(AccountingGuard::AI_USE, $employeeId);

        $counts = array_fill_keys(self::STATUSES, 0);

        foreach ($this->filtered([], $employeeId, false)->select('f.status, COUNT(*) AS total')->groupBy('f.status')->get()->getResult() as $row) {
            $counts[(string) $row->status] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * @return list<string> Every rule that has a finding, for the filter.
     */
    public function rules(): array
    {
        return array_column($this->db->table('accounting_ai_findings')->distinct()->select('rule_code')->orderBy('rule_code', 'ASC')->get()->getResultArray(), 'rule_code');
    }

    /**
     * Confirms or dismisses an open finding, with the reason, once.
     *
     * @throws AccountingException
     */
    public function decide(int $findingId, string $decision, string $reason, ?int $employeeId): void
    {
        $this->guard->require(AccountingGuard::AI_USE, $employeeId);

        if ($employeeId === null) {
            throw new AccountingException(AccountingError::AiApproverRequired, 'A finding is decided by a person.');
        }

        if (! in_array($decision, [self::DISMISSED, self::CONFIRMED], true)) {
            throw new AccountingException(AccountingError::AiDecisionInvalid, 'A finding is confirmed or dismissed.');
        }

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > ProposalService::REASON_MAX) {
            throw new AccountingException(AccountingError::ReasonRequired, 'A reason of 1 to 255 characters is required.');
        }

        $this->find($findingId, $employeeId);

        $this->transactional(function () use ($findingId, $decision, $reason, $employeeId): void {
            $table = $this->db->prefixTable('accounting_ai_findings');
            $row   = LockedRead::row($this->db, "SELECT finding_id, status, rule_code, branch_id FROM {$table} WHERE finding_id = ? FOR UPDATE", [$findingId]);

            if ($row === null) {
                throw new AccountingException(AccountingError::AiFindingNotFound, "Finding {$findingId} does not exist.");
            }

            if ($row->status !== self::OPEN) {
                throw new AccountingException(AccountingError::AiFindingDecided, 'The finding was decided already.', ['status' => $row->status]);
            }

            $this->db->table('accounting_ai_findings')->where('finding_id', $findingId)->update([
                'status'          => $decision,
                'decided_by'      => $employeeId,
                'decided_at'      => new RawSql('NOW()'),
                'decision_reason' => $reason,
            ]);

            $this->audit->write('ai_finding', $findingId, 'finding_' . $decision, $employeeId, JournalOrigin::User, ['rule' => $row->rule_code, 'reason' => $reason], $row->branch_id === null ? null : (int) $row->branch_id);
        });
    }

    /**
     * @param array<string, string> $filters
     */
    private function filtered(array $filters, ?int $employeeId, bool $named = true): BaseBuilder
    {
        $allowed = $this->branches->allowedBranchIds($employeeId);
        $builder = $this->db->table('accounting_ai_findings f');

        if ($named) {
            $builder->select("f.*, br.code AS branch_code, TRIM(CONCAT(p.first_name, ' ', p.last_name)) AS decider_name", false)
                ->join('branches br', 'br.branch_id = f.branch_id', 'left')
                ->join('people p', 'p.person_id = f.decided_by', 'left');
        }

        if ($allowed !== null) {
            $allowed === [] ? $builder->groupStart()->where('f.branch_id IS NULL', null, false)->groupEnd() : $builder->groupStart()->whereIn('f.branch_id', $allowed)->orWhere('f.branch_id IS NULL', null, false)->groupEnd();
        }

        $status = (string) ($filters['status'] ?? '');

        if (in_array($status, self::STATUSES, true)) {
            $builder->where('f.status', $status);
        }

        $severity = (string) ($filters['severity'] ?? '');

        if (in_array($severity, self::SEVERITIES, true)) {
            $builder->where('f.severity', $severity);
        }

        $rule = (string) ($filters['rule'] ?? '');

        if ($rule !== '') {
            $builder->where('f.rule_code', $rule);
        }

        foreach (['from' => 'f.occurred_on >=', 'to' => 'f.occurred_on <='] as $key => $column) {
            $date = (string) ($filters[$key] ?? '');

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
                $builder->where($column, $date);
            }
        }

        return $builder;
    }

    private function prepared(object $row): object
    {
        $evidence = json_decode((string) $row->evidence, true);

        $row->evidence_rows = is_array($evidence) ? $evidence : [];
        $row->message       = ProposalText::fill('finding', self::templateOf((string) $row->rule_code), ProposalText::argsOf($row->template_args), (string) $row->rule_code);

        return $row;
    }

    public static function templateOf(string $ruleCode): string
    {
        return strtolower(str_replace('-', '_', $ruleCode));
    }
}
