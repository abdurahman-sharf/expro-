<?php

namespace App\Libraries\Integrations;

use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The notifications an employee sees: the open ones of the rules that are on, for the branches the employee
 * works in. A notification that belongs to no branch is for those who work in every branch. Whether an
 * employee has read one is kept for that employee alone.
 */
final class Notifications
{
    private BaseConnection $db;
    private BranchGuard $branches;

    public function __construct(?BaseConnection $db = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->branches = $branches ?? new BranchGuard($this->db);
    }

    /**
     * @return array{total: int, unread: int, rows: list<object>} Newest first, each with its kind, its figures, its branch and whether this employee has read it.
     */
    public function visible(int $personId, bool $unreadOnly, int $limit, int $offset): array
    {
        $total  = $this->scoped($personId, $unreadOnly)->countAllResults();
        $unread = $this->unreadCount($personId);
        $reads  = $this->db->prefixTable('notification_reads');
        $rows   = $this->scoped($personId, $unreadOnly)
            ->select("n.notification_id, n.subject_key, n.branch_id, n.params, n.created_at, r.kind, r.name AS rule_name, b.code AS branch_code, EXISTS (SELECT 1 FROM {$reads} x WHERE x.notification_id = n.notification_id AND x.person_id = " . (int) $personId . ') AS is_read', false)
            ->join('branches b', 'b.branch_id = n.branch_id', 'left')
            ->orderBy('n.notification_id', 'DESC')
            ->limit(max(1, $limit), max(0, $offset))
            ->get()
            ->getResult();

        return ['total' => $total, 'unread' => $unread, 'rows' => $rows];
    }

    public function unreadCount(int $personId): int
    {
        return $this->scoped($personId, true)->countAllResults();
    }

    /**
     * @throws IntegrationException
     */
    public function markRead(int $notificationId, int $personId): void
    {
        if ($this->scoped($personId)->where('n.notification_id', $notificationId)->countAllResults() === 0) {
            throw new IntegrationException(IntegrationError::NotificationNotFound, "The notification {$notificationId} is not one this employee sees.", ['id' => $notificationId]);
        }

        $this->db->table('notification_reads')->ignore(true)->insert(['notification_id' => $notificationId, 'person_id' => $personId]);
    }

    public function markAllRead(int $personId): int
    {
        $marked = 0;

        foreach ($this->scoped($personId, true)->select('n.notification_id')->get()->getResult() as $row) {
            $this->db->table('notification_reads')->ignore(true)->insert(['notification_id' => (int) $row->notification_id, 'person_id' => $personId]);
            $marked += $this->db->affectedRows() === 1 ? 1 : 0;
        }

        return $marked;
    }

    public static function message(object $row): string
    {
        $kind   = NotificationRuleKind::tryFrom((string) $row->kind);
        $params = json_decode((string) $row->params, true);
        $params = is_array($params) ? $params : [];

        if ($kind === null) {
            return (string) $row->rule_name;
        }

        if ($kind === NotificationRuleKind::InvoiceOverdue) {
            $document           = (string) ($params['document'] ?? '');
            $params['document'] = $document !== '' ? lang('Integrations.document_invoice_numbered', [$document]) : lang('Integrations.document_' . (string) ($params['kind'] ?? 'invoice'));
        }

        return lang($kind->noticeKey(), array_map('strval', $params));
    }

    /**
     * The builder is made after the branches of the employee are known, because every query that runs
     * forgets the table aliases a builder was started with.
     */
    private function scoped(int $personId, bool $unreadOnly = false): BaseBuilder
    {
        $allowed = $this->branches->allowedBranchIds($personId);
        $builder = $this->db->table('notifications n')
            ->join('notification_rules r', 'r.rule_id = n.rule_id')
            ->where(['n.company_id' => CompanyContext::id(), 'r.active' => 1])
            ->where('n.resolved_at IS NULL', null, false);

        if ($allowed !== null) {
            $allowed === [] ? $builder->where('1 = 0', null, false) : $builder->whereIn('n.branch_id', $allowed);
        }

        if ($unreadOnly) {
            $builder->where('NOT EXISTS (SELECT 1 FROM ' . $this->db->prefixTable('notification_reads') . ' x WHERE x.notification_id = n.notification_id AND x.person_id = ' . (int) $personId . ')', null, false);
        }

        return $builder;
    }
}
