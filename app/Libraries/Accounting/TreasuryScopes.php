<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use App\Libraries\Roles\RoleTables;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Who may put money into a treasury and who may take money out of it. Each treasury has a scope for
 * deposits and a scope for withdrawals, and a scope is everybody, a list of employees, the employees
 * who hold one of a list of roles, or the employees who work in a list of branches. The permission
 * to manage treasuries stays apart from
 * both: it creates, changes and deactivates treasuries, and it does not give a deposit or a
 * withdrawal. The books act without an employee, and that is always allowed.
 */
final class TreasuryScopes
{
    use RunsInTransaction;

    public const DEPOSIT     = 'deposit';
    public const WITHDRAW    = 'withdraw';
    public const ALL         = 'all';
    public const STAFF       = 'staff';
    public const ROLES       = 'roles';
    public const BRANCHES    = 'branches';
    public const MODES       = [self::ALL, self::STAFF, self::ROLES, self::BRANCHES];
    private const DIRECTIONS = [self::DEPOSIT, self::WITHDRAW];
    private const SCALE      = 4;

    private BaseConnection $db;
    private BranchGuard $branches;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->branches = $branches ?? new BranchGuard($this->db);
        $this->audit    = new AuditLog($this->db);
    }

    public function allows(int $treasuryId, string $direction, ?int $employeeId): bool
    {
        if ($employeeId === null) {
            return true;
        }

        $scope = $this->scopeOf($treasuryId, $direction);

        return match ($scope['mode']) {
            self::ALL      => $scope['exists'],
            self::STAFF    => in_array($employeeId, $scope['members'], true),
            self::ROLES    => $this->holdsARole($scope['members'], $employeeId),
            self::BRANCHES => $this->worksIn($scope['members'], $employeeId),
            default        => false,
        };
    }

    /**
     * @throws AccountingException
     */
    public function assertAllows(int $treasuryId, string $direction, ?int $employeeId): void
    {
        if ($this->allows($treasuryId, $direction, $employeeId)) {
            return;
        }

        $name = (string) ($this->db->table('treasuries')->select('name')->where(['company_id' => CompanyContext::id(), 'treasury_id' => $treasuryId])->get()->getRow()->name ?? $treasuryId);

        throw new AccountingException(
            $direction === self::DEPOSIT ? AccountingError::TreasuryDepositDenied : AccountingError::TreasuryWithdrawDenied,
            "The employee may not {$direction} treasury {$treasuryId}.",
            ['treasury_id' => $treasuryId, 'name' => $name],
        );
    }

    /**
     * A payment that names no treasury goes to the account its way of payment is routed to. When
     * that account belongs to a treasury, whether the main one or any other, the scope of that
     * treasury governs the payment, or leaving the treasury empty would pass the scope by.
     *
     * @throws AccountingException
     */
    public function assertRoutedAllows(PaymentType $type, string $direction, ?int $employeeId): void
    {
        if ($employeeId === null) {
            return;
        }

        $owner = $this->ownerOfRoutedType($type);

        if ($owner !== null) {
            $this->assertAllows($owner, $direction, $employeeId);
        }
    }

    /**
     * The treasury that owns the account a way of payment is routed to now, or null when the way
     * of payment goes to an account no treasury owns.
     */
    public function ownerOfRoutedType(PaymentType $type): ?int
    {
        try {
            $account = (new RoutingResolver($this->db))->accountForPayment($type);
        } catch (PostingException) {
            return null;
        }

        return $account === null ? null : $this->ownerOfAccount($account);
    }

    /**
     * Taking a journal back is the opposite movement of what it did: a journal that put money
     * into a treasury is undone by a withdrawal from it, and one that took money out by a deposit.
     * The treasuries are read from the lines of the journal, whatever stamped the payment or the
     * routing of the day it was posted.
     *
     * @throws AccountingException
     */
    public function assertMayReverse(int $journalId, ?int $employeeId): void
    {
        if ($employeeId === null) {
            return;
        }

        $delta = [];

        foreach ($this->netOfJournal($journalId) as $treasuryId => $net) {
            $delta[$treasuryId] = bcsub('0', $net, self::SCALE);
        }

        $this->assertMoves($delta, $employeeId);
    }

    /**
     * Replacing the journal of a payment by another moves the difference. What a treasury holds
     * more of afterwards is a deposit into it, what it holds less of is a withdrawal from it, and
     * a payment that stops using a treasury, or is paid in less, takes money out of it.
     *
     * @throws AccountingException
     */
    public function assertMayReplace(int $journalId, ?JournalDraft $replacement, ?int $employeeId): void
    {
        if ($employeeId === null) {
            return;
        }

        $delta = [];

        foreach ($this->netOfJournal($journalId) as $treasuryId => $net) {
            $delta[$treasuryId] = bcsub('0', $net, self::SCALE);
        }

        foreach ($this->netOfLines($replacement?->lines ?? []) as $treasuryId => $net) {
            $delta[$treasuryId] = bcadd($delta[$treasuryId] ?? '0', $net, self::SCALE);
        }

        $this->assertMoves($delta, $employeeId);
    }

    /**
     * Replacing the journals of a payment that is posted in two branches by others moves the
     * difference of all of them, as replacing one journal does.
     *
     * @param list<int>          $journalIds
     * @param list<JournalDraft> $replacements
     *
     * @throws AccountingException
     */
    public function assertMayReplaceAll(array $journalIds, array $replacements, ?int $employeeId): void
    {
        if ($employeeId === null) {
            return;
        }

        $delta = [];

        foreach ($journalIds as $journalId) {
            foreach ($this->netOfJournal($journalId) as $treasuryId => $net) {
                $delta[$treasuryId] = bcsub($delta[$treasuryId] ?? '0', $net, self::SCALE);
            }
        }

        foreach ($replacements as $replacement) {
            foreach ($this->netOfLines($replacement->lines) as $treasuryId => $net) {
                $delta[$treasuryId] = bcadd($delta[$treasuryId] ?? '0', $net, self::SCALE);
            }
        }

        $this->assertMoves($delta, $employeeId);
    }

    /**
     * A journal written by hand, or an opening balance, moves money when it debits or credits the
     * account of a treasury just as a payment does: what it debits is a deposit into the treasury
     * and what it credits is a withdrawal from it. Posting a draft is what moves the money, so
     * saving one asks nothing.
     *
     * @param list<JournalLineDraft> $lines
     *
     * @throws AccountingException
     */
    public function assertMayPost(array $lines, ?int $employeeId): void
    {
        if ($employeeId === null) {
            return;
        }

        $this->assertMoves($this->netOfLines($lines), $employeeId);
    }

    /**
     * The treasury an account is the account of, or null for an account no treasury owns.
     */
    public function ownerOfAccount(int $accountId): ?int
    {
        $row = $this->db->table('accounting_accounts a')
            ->select('t.treasury_id')
            ->join('treasuries t', 't.treasury_id = a.entity_id AND t.company_id = a.company_id')
            ->where('a.company_id', CompanyContext::id())
            ->where('a.account_id', $accountId)
            ->where('a.entity_type', EntityAccountService::TREASURY)
            ->get()
            ->getRow();

        return $row === null ? null : (int) $row->treasury_id;
    }

    /**
     * @return array{mode: string, members: list<int>, exists: bool}
     */
    public function scopeOf(int $treasuryId, string $direction): array
    {
        $column = $this->column($direction);
        $row    = $this->db->table('treasuries')->select($column)->where(['company_id' => CompanyContext::id(), 'treasury_id' => $treasuryId])->get()->getRow();

        if ($row === null) {
            return ['mode' => self::ALL, 'members' => [], 'exists' => false];
        }

        $mode = in_array($row->{$column}, self::MODES, true) ? (string) $row->{$column} : self::STAFF;

        return ['mode' => $mode, 'members' => $mode === self::ALL ? [] : $this->membersOf($treasuryId, $direction), 'exists' => true];
    }

    /**
     * What the treasuries given let the employee do in the direction, so a screen offers only what
     * a refusal will not follow.
     *
     * @param list<object> $treasuries
     *
     * @return list<object>
     */
    public function usable(array $treasuries, string $direction, ?int $employeeId): array
    {
        return array_values(array_filter($treasuries, fn (object $treasury): bool => $this->allows((int) $treasury->treasury_id, $direction, $employeeId)));
    }

    /**
     * The scopes of an input that names them, checked, direction by direction. A direction the
     * input does not name keeps the scope it has.
     *
     * @param array<string, mixed> $input deposit_scope, deposit_members, withdraw_scope and withdraw_members.
     *
     * @return array<string, array{mode: string, members: list<int>}>
     *
     * @throws AccountingException
     */
    public function validated(array $input): array
    {
        $scopes = [];

        foreach (self::DIRECTIONS as $direction) {
            if (array_key_exists($direction . '_scope', $input)) {
                $scopes[$direction] = $this->normalized($direction, $input[$direction . '_scope'], $input[$direction . '_members'] ?? []);
            }
        }

        return $scopes;
    }

    /**
     * @param array<string, array{mode: string, members: list<int>}> $scopes As validated() returns them.
     *
     * @throws AccountingException
     */
    public function apply(int $treasuryId, array $scopes, ?int $employeeId, ?int $branchId = null): void
    {
        $this->transactional(function () use ($treasuryId, $scopes, $employeeId, $branchId): void {
            $before = [];
            $after  = [];

            foreach ($scopes as $direction => $wanted) {
                $current = $this->scopeOf($treasuryId, $direction);

                if ($current['mode'] === $wanted['mode'] && $current['members'] === $wanted['members']) {
                    continue;
                }

                $before[$direction] = ['mode' => $current['mode'], 'members' => $current['members']];
                $after[$direction]  = $wanted;

                $this->db->table('treasuries')->where('treasury_id', $treasuryId)->update([$this->column($direction) => $wanted['mode']]);
                $this->db->table('treasury_scope_members')->where(['treasury_id' => $treasuryId, 'direction' => $direction])->delete();

                foreach ($wanted['members'] as $memberId) {
                    $this->db->table('treasury_scope_members')->insert(['treasury_id' => $treasuryId, 'direction' => $direction, 'member_id' => $memberId]);
                }
            }

            if ($after !== []) {
                $this->audit->write('treasury', $treasuryId, 'scope_changed', $employeeId, JournalOrigin::User, ['before' => $before, 'after' => $after], $branchId);
            }
        });
    }

    /**
     * What lines move into each treasury they touch: debits less credits, by treasury.
     *
     * @param iterable<JournalLineDraft> $lines
     *
     * @return array<int, string>
     */
    private function netOfLines(iterable $lines): array
    {
        $net = [];

        foreach ($lines as $line) {
            $owner = $this->ownerOfAccount($line->accountId);

            if ($owner !== null) {
                $net[$owner] = bcadd($net[$owner] ?? '0', bcsub($line->debit === '' ? '0' : $line->debit, $line->credit === '' ? '0' : $line->credit, self::SCALE), self::SCALE);
            }
        }

        return $net;
    }

    /**
     * Asks the deposit scope of each treasury that is to hold more and the withdrawal scope of
     * each that is to hold less.
     *
     * @param array<int, string> $delta
     *
     * @throws AccountingException
     */
    private function assertMoves(array $delta, int $employeeId): void
    {
        ksort($delta);

        foreach ($delta as $treasuryId => $amount) {
            $sign = bccomp($amount, '0', self::SCALE);

            if ($sign !== 0) {
                $this->assertAllows($treasuryId, $sign > 0 ? self::DEPOSIT : self::WITHDRAW, $employeeId);
            }
        }
    }

    /**
     * What a journal moved into each treasury it touches: debits less credits, by treasury.
     *
     * @return array<int, string>
     */
    private function netOfJournal(int $journalId): array
    {
        $rows = $this->db->table('accounting_journal_lines l')
            ->select('t.treasury_id, SUM(l.debit) AS debit, SUM(l.credit) AS credit', false)
            ->join('accounting_accounts a', 'a.account_id = l.account_id')
            ->join('treasuries t', 't.treasury_id = a.entity_id AND t.company_id = a.company_id')
            ->where('l.journal_id', $journalId)
            ->where('a.entity_type', EntityAccountService::TREASURY)
            ->groupBy('t.treasury_id')
            ->orderBy('t.treasury_id', 'ASC')
            ->get()
            ->getResult();

        $net = [];

        foreach ($rows as $row) {
            $net[(int) $row->treasury_id] = bcsub((string) $row->debit, (string) $row->credit, self::SCALE);
        }

        return $net;
    }

    /**
     * Whether the employee holds one of the roles, which an inactive role does not count for.
     *
     * @param list<int> $roleIds
     */
    private function holdsARole(array $roleIds, int $employeeId): bool
    {
        if ($roleIds === [] || ! RoleTables::exist($this->db, 'employee_roles', 'roles')) {
            return false;
        }

        return $this->db->table('employee_roles er')
            ->join('roles r', 'r.role_id = er.role_id AND r.active = 1')
            ->join('employees e', 'e.person_id = er.person_id AND e.deleted = 0')
            ->where('er.person_id', $employeeId)
            ->whereIn('er.role_id', $roleIds)
            ->countAllResults() > 0;
    }

    /**
     * @param list<int> $branchIds
     */
    private function worksIn(array $branchIds, int $employeeId): bool
    {
        foreach ($branchIds as $branchId) {
            if ($this->branches->canAccess($branchId, $employeeId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<int>
     */
    private function membersOf(int $treasuryId, string $direction): array
    {
        $rows = $this->db->table('treasury_scope_members')->select('member_id')->where(['treasury_id' => $treasuryId, 'direction' => $direction])->orderBy('member_id', 'ASC')->get()->getResultArray();

        return array_map('intval', array_column($rows, 'member_id'));
    }

    /**
     * @return array{mode: string, members: list<int>}
     *
     * @throws AccountingException
     */
    private function normalized(string $direction, mixed $mode, mixed $members): array
    {
        if (! is_string($mode) || ! in_array($mode, self::MODES, true)) {
            throw new AccountingException(AccountingError::TreasuryScopeInvalid, 'A scope is everybody, employees, roles or branches.', ['scope_name' => $direction]);
        }

        if ($mode === self::ALL) {
            return ['mode' => self::ALL, 'members' => []];
        }

        $ids = [];

        foreach (is_array($members) ? $members : [] as $member) {
            if (is_int($member) || (is_string($member) && ctype_digit($member))) {
                $ids[(int) $member] = (int) $member;
            } else {
                throw new AccountingException(AccountingError::TreasuryScopeInvalid, 'A member of a scope is an employee, a role or a branch.', ['scope_name' => $direction]);
            }
        }

        $ids = array_values($ids);

        sort($ids);

        if ($ids === [] || ! $this->allExist($mode, $ids)) {
            throw new AccountingException(AccountingError::TreasuryScopeInvalid, 'A scope of employees, roles or branches names at least one that exists.', ['scope_name' => $direction]);
        }

        return ['mode' => $mode, 'members' => $ids];
    }

    /**
     * @param list<int> $ids
     */
    private function allExist(string $mode, array $ids): bool
    {
        if ($mode === self::STAFF) {
            return $this->db->table('employees')->whereIn('person_id', $ids)->where('deleted', 0)->countAllResults() === count($ids);
        }

        if ($mode === self::ROLES) {
            return RoleTables::exist($this->db, 'roles') && $this->db->table('roles')->whereIn('role_id', $ids)->countAllResults() === count($ids);
        }

        return $this->db->table('branches')->whereIn('branch_id', $ids)->where('company_id', CompanyContext::id())->countAllResults() === count($ids);
    }

    private function column(string $direction): string
    {
        return $direction === self::WITHDRAW ? 'withdraw_scope' : 'deposit_scope';
    }
}
