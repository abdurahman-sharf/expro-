<?php

namespace App\Libraries\Integrations;

use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\RunsInTransaction;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The rules that make in-app notifications: a kind of event, a name and, for the kinds that count days, the
 * number of days. A rule that has made a notification is switched off and never deleted, so what was said
 * stays explained. Every change is audited. Nothing here sends anything anywhere.
 */
final class NotificationRules
{
    use RunsInTransaction;

    public const MAX_NAME = 100;

    private BaseConnection $db;
    private AuditLog $audit;
    private IntegrationAccess $access;

    public function __construct(?BaseConnection $db = null, ?IntegrationAccess $access = null)
    {
        $this->db     = $db ?? Database::connect();
        $this->audit  = new AuditLog($this->db);
        $this->access = $access ?? new IntegrationAccess();
    }

    /**
     * @return list<NotificationRule>
     */
    public function all(bool $activeOnly = false): array
    {
        $builder = $this->db->table('notification_rules')->where('company_id', CompanyContext::id())->orderBy('rule_id', 'ASC');

        if ($activeOnly) {
            $builder->where('active', 1);
        }

        return array_map($this->made(...), $builder->get()->getResult());
    }

    public function find(int $ruleId): ?NotificationRule
    {
        $row = $this->db->table('notification_rules')->where(['company_id' => CompanyContext::id(), 'rule_id' => $ruleId])->get()->getRow();

        return $row === null ? null : $this->made($row);
    }

    /**
     * @throws IntegrationException
     */
    public function create(string $kind, string $name, mixed $threshold, ?int $employeeId = null): int
    {
        $this->access->requireManage($employeeId);

        $kind      = NotificationRuleKind::tryFrom($kind) ?? throw new IntegrationException(IntegrationError::RuleKind, "{$kind} is not a kind of rule.", ['name' => $kind]);
        $name      = $this->name($name, null);
        $threshold = $this->threshold($kind, $threshold);

        $this->db->table('notification_rules')->insert(['company_id' => CompanyContext::id(), 'kind' => $kind->value, 'name' => $name, 'threshold' => $threshold, 'active' => 1, 'created_by' => $employeeId]);

        $ruleId = (int) $this->db->insertID();

        $this->audit->write('notification_rule', $ruleId, 'notification_rule_created', $employeeId, JournalOrigin::User, ['kind' => $kind->value, 'name' => $name, 'threshold' => $threshold]);

        return $ruleId;
    }

    /**
     * @throws IntegrationException
     */
    public function update(int $ruleId, string $name, mixed $threshold, ?int $employeeId = null): void
    {
        $this->access->requireManage($employeeId);

        $rule      = $this->find($ruleId) ?? throw new IntegrationException(IntegrationError::RuleNotFound, "The rule {$ruleId} does not exist.", ['id' => $ruleId]);
        $name      = $this->name($name, $ruleId);
        $threshold = $this->threshold($rule->kind, $threshold);

        if ($name === $rule->name && $threshold === $rule->threshold) {
            return;
        }

        $this->db->table('notification_rules')->where('rule_id', $ruleId)->update(['name' => $name, 'threshold' => $threshold]);
        $this->audit->write('notification_rule', $ruleId, 'notification_rule_updated', $employeeId, JournalOrigin::User, ['before' => ['name' => $rule->name, 'threshold' => $rule->threshold], 'after' => ['name' => $name, 'threshold' => $threshold]]);
    }

    /**
     * @throws IntegrationException
     */
    public function setActive(int $ruleId, bool $active, ?int $employeeId = null): void
    {
        $this->access->requireManage($employeeId);

        $rule = $this->find($ruleId) ?? throw new IntegrationException(IntegrationError::RuleNotFound, "The rule {$ruleId} does not exist.", ['id' => $ruleId]);

        if ($rule->active === $active) {
            return;
        }

        $this->db->table('notification_rules')->where('rule_id', $ruleId)->update(['active' => $active ? 1 : 0]);
        $this->audit->write('notification_rule', $ruleId, $active ? 'notification_rule_activated' : 'notification_rule_deactivated', $employeeId, JournalOrigin::User, ['name' => $rule->name]);
    }

    /**
     * @throws IntegrationException
     */
    public function delete(int $ruleId, ?int $employeeId = null): void
    {
        $this->access->requireManage($employeeId);

        $this->transactional(function () use ($ruleId, $employeeId): void {
            $rule = $this->find($ruleId) ?? throw new IntegrationException(IntegrationError::RuleNotFound, "The rule {$ruleId} does not exist.", ['id' => $ruleId]);

            if ($this->db->table('notifications')->where('rule_id', $ruleId)->countAllResults() > 0) {
                throw new IntegrationException(IntegrationError::RuleInUse, "The rule {$ruleId} has made notifications, so it is switched off and kept.", ['id' => $ruleId]);
            }

            $this->db->table('notification_rules')->where('rule_id', $ruleId)->delete();
            $this->audit->write('notification_rule', $ruleId, 'notification_rule_deleted', $employeeId, JournalOrigin::User, ['kind' => $rule->kind->value, 'name' => $rule->name, 'threshold' => $rule->threshold]);
        });
    }

    private function made(object $row): NotificationRule
    {
        return new NotificationRule((int) $row->rule_id, NotificationRuleKind::from((string) $row->kind), (string) $row->name, $row->threshold === null ? null : (int) $row->threshold, (int) $row->active === 1);
    }

    /**
     * @throws IntegrationException
     */
    private function name(string $value, ?int $ruleId): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        if ($name === '' || mb_strlen($name) > self::MAX_NAME || preg_match('/\p{C}/u', $name) === 1) {
            throw new IntegrationException(IntegrationError::RuleName, 'A rule needs a short plain name.', ['max' => self::MAX_NAME]);
        }

        $builder = $this->db->table('notification_rules')->where(['company_id' => CompanyContext::id(), 'name' => $name]);

        if ($ruleId !== null) {
            $builder->where('rule_id !=', $ruleId);
        }

        if ($builder->countAllResults() > 0) {
            throw new IntegrationException(IntegrationError::RuleNameTaken, "A rule is called {$name} already.", ['name' => $name]);
        }

        return $name;
    }

    /**
     * @throws IntegrationException
     */
    private function threshold(NotificationRuleKind $kind, mixed $value): ?int
    {
        if (! $kind->takesThreshold()) {
            return null;
        }

        $days = filter_var($value, FILTER_VALIDATE_INT);

        if ($days === false || $days < $kind->lowest() || $days > $kind->highest()) {
            throw new IntegrationException(IntegrationError::RuleThreshold, 'The days are a whole number from ' . $kind->lowest() . ' to ' . $kind->highest() . '.', ['min' => $kind->lowest(), 'max' => $kind->highest()]);
        }

        return $days;
    }
}
