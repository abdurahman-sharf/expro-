<?php

namespace App\Libraries\Accounting\Ai;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use Throwable;

/**
 * Runs the rules over the books and keeps what they find. The scan reads the books and writes only the findings
 * and its own line in the audit log: it never posts and never blocks. A finding is kept once, whatever the number of
 * times the scan runs, because its key is made from the rule and the records it is about; one a person has
 * already decided stays as decided.
 */
final class AnomalyScanner
{
    private const DUPLICATE = 1062;

    private BaseConnection $db;
    private AccountingGuard $guard;
    private BranchGuard $branches;
    private AiSettings $settings;
    private AuditLog $audit;

    /** @var list<AnomalyRule> */
    private array $rules;

    /**
     * @param list<AnomalyRule>|null $rules
     */
    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null, ?array $rules = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->guard    = $guard ?? new AccountingGuard();
        $this->branches = $branches ?? new BranchGuard($this->db);
        $this->settings = new AiSettings($this->db, $this->guard);
        $this->audit    = new AuditLog($this->db);
        $this->rules    = $rules ?? self::defaults();
    }

    /**
     * @return list<AnomalyRule>
     */
    public static function defaults(): array
    {
        return [
            new Rules\OffHoursRule(),
            new Rules\RoundNumberRule(),
            new Rules\DuplicateRule(),
            new Rules\BalanceJumpRule(),
            new Rules\ControlAccountRule(),
            new Rules\ReversalChainRule(),
            new Rules\BackdatedRule(),
        ];
    }

    /**
     * @throws AccountingException
     */
    public function scan(?int $employeeId = null, ?DateTimeImmutable $today = null, ?string $since = null): ScanReport
    {
        $this->settings->assertEnabled();
        $this->guard->require(AccountingGuard::AI_USE, $employeeId);

        $today = $today ?? new DateTimeImmutable('today');
        $since = $since ?? $today->modify('-' . $this->settings->int('lookback_days') . ' days')->format('Y-m-d');

        $context = new AnomalyContext($this->db, $this->settings, $today, $since, $this->branches->allowedBranchIds($employeeId));
        $created = [];
        $existed = [];

        foreach ($this->rules as $rule) {
            $created[$rule->code()] = 0;
            $existed[$rule->code()] = 0;

            foreach ($rule->detect($context) as $finding) {
                $this->keep($finding) ? $created[$rule->code()]++ : $existed[$rule->code()]++;
            }
        }

        $this->audit->write('ai_scan', null, 'scan_run', $employeeId, JournalOrigin::Ai, [
            'since'    => $since,
            'created'  => array_sum($created),
            'existing' => array_sum($existed),
            'by_rule'  => $created,
        ]);

        return new ScanReport($since, $created, $existed);
    }

    /**
     * @return bool Whether the finding was new.
     */
    private function keep(Finding $finding): bool
    {
        $digest = $finding->digest();

        if ($this->db->table('accounting_ai_findings')->where('finding_key', $digest)->countAllResults() > 0) {
            return false;
        }

        try {
            $inserted = $this->db->table('accounting_ai_findings')->insert([
                'rule_code'     => $finding->rule,
                'severity'      => $finding->severity,
                'finding_key'   => $digest,
                'branch_id'     => $finding->branchId,
                'subject_type'  => $finding->subjectType,
                'subject_id'    => $finding->subjectId,
                'occurred_on'   => $finding->occurredOn,
                'template_args' => json_encode($finding->templateArgs, JSON_UNESCAPED_UNICODE),
                'evidence'      => json_encode($finding->evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable $failure) {
            if ($failure->getCode() === self::DUPLICATE || str_contains($failure->getMessage(), 'Duplicate entry')) {
                return false;
            }

            throw $failure;
        }

        if ($inserted === false) {
            if (($this->db->error()['code'] ?? 0) === self::DUPLICATE) {
                return false;
            }

            throw new AccountingException(AccountingError::WriteFailed, 'The finding could not be saved.');
        }

        return true;
    }
}
