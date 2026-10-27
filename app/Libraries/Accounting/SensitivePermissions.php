<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Who holds the permissions that close and reopen periods, enter opening balances, turn accounting
 * on and off, change the routing, open treasuries and move money between them, settle what branches
 * owe each other, and enter supplier payments and opening balances, and the record of who handed
 * them out or took them back.
 */
final class SensitivePermissions
{
    private BaseConnection $db;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db    = $db ?? Database::connect();
        $this->audit = new AuditLog($this->db);
    }

    public static function isSensitive(string $permissionId): bool
    {
        return in_array($permissionId, AccountingGuard::SENSITIVE, true);
    }

    /**
     * The sensitive permissions an employee holds now.
     *
     * @return list<string>
     */
    public function heldBy(int $personId): array
    {
        $rows = $this->db->table('grants')->select('permission_id')->where('person_id', $personId)->whereIn('permission_id', AccountingGuard::SENSITIVE)->get()->getResultArray();

        return array_values(array_intersect(AccountingGuard::SENSITIVE, array_column($rows, 'permission_id')));
    }

    /**
     * Every active employee who holds at least one, with what they hold and where they may work.
     *
     * @return list<array{person_id: int, name: string, username: string, permissions: list<string>, all_branches: bool, branches: list<string>}>
     */
    public function holders(): array
    {
        $rows = $this->db->table('grants g')
            ->select("g.person_id, g.permission_id, e.username, TRIM(CONCAT(p.first_name, ' ', p.last_name)) AS name", false)
            ->join('employees e', 'e.person_id = g.person_id')
            ->join('people p', 'p.person_id = g.person_id')
            ->whereIn('g.permission_id', AccountingGuard::SENSITIVE)
            ->where('e.deleted', 0)
            ->orderBy('name', 'ASC')
            ->orderBy('g.person_id', 'ASC')
            ->get()
            ->getResult();

        $holders = [];

        foreach ($rows as $row) {
            $personId = (int) $row->person_id;

            $holders[$personId] ??= ['person_id' => $personId, 'name' => (string) $row->name, 'username' => (string) $row->username, 'permissions' => []];
            $holders[$personId]['permissions'][] = (string) $row->permission_id;
        }

        $guard  = new BranchGuard($this->db);
        $result = [];

        foreach ($holders as $personId => $holder) {
            $allowed = $guard->allowedBranchIds($personId);

            $result[] = [
                'person_id'    => $personId,
                'name'         => $holder['name'],
                'username'     => $holder['username'],
                'permissions'  => array_values(array_intersect(AccountingGuard::SENSITIVE, $holder['permissions'])),
                'all_branches' => $allowed === null,
                'branches'     => $allowed === null || $allowed === [] ? [] : $this->branchCodes($allowed),
            ];
        }

        return $result;
    }

    /**
     * Writes one entry for each sensitive permission that was given or taken away.
     *
     * @param list<string> $before
     * @param list<string> $after
     */
    public function recordChange(?int $actorId, int $targetId, array $before, array $after): void
    {
        foreach (array_diff($after, $before) as $permissionId) {
            $this->audit->write('employee_permission', $targetId, 'permission_granted', $actorId, JournalOrigin::User, ['permission' => $permissionId]);
        }

        foreach (array_diff($before, $after) as $permissionId) {
            $this->audit->write('employee_permission', $targetId, 'permission_revoked', $actorId, JournalOrigin::User, ['permission' => $permissionId]);
        }
    }

    /**
     * @param list<int> $branchIds
     *
     * @return list<string>
     */
    private function branchCodes(array $branchIds): array
    {
        $rows = $this->db->table('branches')->select('code')->whereIn('branch_id', $branchIds)->orderBy('code', 'ASC')->get()->getResultArray();

        return array_column($rows, 'code');
    }
}
