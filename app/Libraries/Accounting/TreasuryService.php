<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The treasuries and bank accounts: each is an entity with an account of its own under the main
 * treasury account, so what it holds is what its account says. The main treasury is the account
 * every payment already went to, and takes its place without a journal changing. A treasury that
 * holds money is not deactivated, and one that has been used is not deleted.
 */
final class TreasuryService
{
    use RunsInTransaction;

    public const CASH          = 'cash';
    public const BANK          = 'bank';
    private const KINDS        = [self::CASH, self::BANK];
    private const NAME_MAX     = 255;
    private const MAIN_NAME    = 'الخزينة الأساسية';
    private const DESCRIPTION  = 500;
    private const HAND_WRITTEN = 'manual';

    private BaseConnection $db;
    private EntityAccountService $accounts;
    private AccountingGuard $guard;
    private BranchGuard $branches;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->accounts = new EntityAccountService($this->db);
        $this->guard    = $guard ?? new AccountingGuard();
        $this->branches = $branches ?? new BranchGuard($this->db);
        $this->audit    = new AuditLog($this->db);
    }

    /**
     * The main treasury, taken from the account every payment already went to the first time it
     * is asked for.
     *
     * @throws AccountingException|PostingException
     */
    public function ensureMain(?int $employeeId = null): int
    {
        $main = $this->mainRow();

        if ($main !== null) {
            return (int) $main->treasury_id;
        }

        $account = (new RoutingResolver($this->db))->accountFor(RoutingKey::CashDefault);

        if ($account === null) {
            throw new AccountingException(AccountingError::EntityMainAccountInvalid, 'There is no main treasury account to start from.', ['kind' => EntityAccountService::TREASURY]);
        }

        return $this->transactional(function () use ($account, $employeeId): int {
            LockedRead::row($this->db, 'SELECT account_id FROM ' . $this->db->prefixTable('accounting_accounts') . ' WHERE account_id = ? FOR UPDATE', [$account]);

            $existing = $this->mainRow(true);

            if ($existing !== null) {
                return (int) $existing->treasury_id;
            }

            $accountName = (string) $this->db->table('accounting_accounts')->select('name')->where('account_id', $account)->get()->getRow()->name;
            $name        = $this->freeName($accountName === '' ? self::MAIN_NAME : $accountName);

            $this->db->table('treasuries')->insert([
                'company_id' => CompanyContext::id(),
                'branch_id'  => $this->branches->companyDefaultBranchId(),
                'name'       => $name,
                'kind'       => self::CASH,
                'is_main'    => 1,
                'created_by' => $employeeId,
            ]);

            $treasuryId = (int) $this->db->insertID();

            $this->db->table('accounting_accounts')->where('account_id', $account)->update(['entity_type' => EntityAccountService::TREASURY, 'entity_id' => $treasuryId]);
            $this->audit->write('treasury', $treasuryId, 'created', $employeeId, JournalOrigin::System, ['name' => $name, 'main' => true, 'account_id' => $account]);

            return $treasuryId;
        });
    }

    /**
     * @param array{name?: mixed, kind?: mixed, description?: mixed, branch_id?: mixed} $input
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function create(array $input, ?int $employeeId = null): int
    {
        $this->guard->require(AccountingGuard::TREASURIES_MANAGE, $employeeId);

        $name        = $this->name($input['name'] ?? '');
        $kind        = $this->kind($input['kind'] ?? self::CASH);
        $description = $this->description($input['description'] ?? '');
        $branchId    = (int) ($input['branch_id'] ?? 0) > 0 ? (int) $input['branch_id'] : $this->branches->defaultBranchId($employeeId);

        $this->branches->requireBranch($branchId, $employeeId);
        $this->assertBranchExists($branchId);
        $this->ensureMain($employeeId);

        return $this->transactional(function () use ($name, $kind, $description, $branchId, $employeeId): int {
            $this->assertNameFree($name);

            $this->db->table('treasuries')->insert([
                'company_id'  => CompanyContext::id(),
                'branch_id'   => $branchId,
                'name'        => $name,
                'kind'        => $kind,
                'description' => $description,
                'created_by'  => $employeeId,
            ]);

            $treasuryId = (int) $this->db->insertID();

            $this->accounts->ensure(EntityAccountService::TREASURY, $treasuryId, $name, $employeeId);
            $this->audit->write('treasury', $treasuryId, 'created', $employeeId, JournalOrigin::User, ['name' => $name, 'kind' => $kind], $branchId);

            return $treasuryId;
        });
    }

    /**
     * @param array{name?: mixed, kind?: mixed, description?: mixed} $input
     *
     * @throws AccountingException|BranchException
     */
    public function update(int $treasuryId, array $input, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::TREASURIES_MANAGE, $employeeId);

        $name        = $this->name($input['name'] ?? '');
        $kind        = $this->kind($input['kind'] ?? self::CASH);
        $description = $this->description($input['description'] ?? '');

        $this->transactional(function () use ($treasuryId, $name, $kind, $description, $employeeId): void {
            $treasury = $this->lock($treasuryId);

            $this->branches->requireBranch((int) $treasury->branch_id, $employeeId);
            $this->assertNameFree($name, $treasuryId);

            if ((int) $treasury->is_main === 1 && $kind !== self::CASH) {
                throw new AccountingException(AccountingError::TreasuryIsMain, 'The main treasury is a cash treasury.', ['treasury_id' => $treasuryId]);
            }

            $this->db->table('treasuries')->where('treasury_id', $treasuryId)->update(['name' => $name, 'kind' => $kind, 'description' => $description]);
            $this->accounts->rename(EntityAccountService::TREASURY, $treasuryId, $name, $employeeId);
            $this->audit->write('treasury', $treasuryId, 'updated', $employeeId, JournalOrigin::User, [
                'before' => ['name' => $treasury->name, 'kind' => $treasury->kind, 'description' => $treasury->description],
                'after'  => ['name' => $name, 'kind' => $kind, 'description' => $description],
            ], (int) $treasury->branch_id);
        });
    }

    /**
     * @throws AccountingException|BranchException
     */
    public function deactivate(int $treasuryId, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::TREASURIES_MANAGE, $employeeId);

        $this->transactional(function () use ($treasuryId, $employeeId): void {
            $treasury = $this->lock($treasuryId);

            $this->branches->requireBranch((int) $treasury->branch_id, $employeeId);

            if ((int) $treasury->is_main === 1) {
                throw new AccountingException(AccountingError::TreasuryIsMain, 'The main treasury cannot be deactivated.', ['treasury_id' => $treasuryId]);
            }

            if ((int) $treasury->active === 0) {
                throw new AccountingException(AccountingError::AlreadyInactive, 'The treasury is already deactivated.', ['treasury_id' => $treasuryId]);
            }

            $balance = $this->position($treasuryId)->balance;

            if (bccomp($balance, '0', SaleAmounts::SCALE) !== 0) {
                throw new AccountingException(AccountingError::TreasuryHasBalance, 'A treasury that holds money cannot be deactivated.', ['treasury_id' => $treasuryId, 'difference' => $this->display($balance)]);
            }

            $this->db->table('treasuries')->where('treasury_id', $treasuryId)->update(['active' => 0]);
            $this->db->table('employees')->where('default_treasury_id', $treasuryId)->update(['default_treasury_id' => null]);
            $this->audit->write('treasury', $treasuryId, 'deactivated', $employeeId, JournalOrigin::User, [], (int) $treasury->branch_id);
        });
    }

    /**
     * @throws AccountingException|BranchException
     */
    public function activate(int $treasuryId, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::TREASURIES_MANAGE, $employeeId);

        $this->transactional(function () use ($treasuryId, $employeeId): void {
            $treasury = $this->lock($treasuryId);

            $this->branches->requireBranch((int) $treasury->branch_id, $employeeId);

            if ((int) $treasury->active === 1) {
                throw new AccountingException(AccountingError::AlreadyActive, 'The treasury is already active.', ['treasury_id' => $treasuryId]);
            }

            $this->db->table('treasuries')->where('treasury_id', $treasuryId)->update(['active' => 1]);
            $this->audit->write('treasury', $treasuryId, 'activated', $employeeId, JournalOrigin::User, [], (int) $treasury->branch_id);
        });
    }

    /**
     * Only a treasury nothing has happened in: no payment, no transfer and no line on its account.
     *
     * @throws AccountingException|BranchException
     */
    public function delete(int $treasuryId, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::TREASURIES_MANAGE, $employeeId);

        $this->transactional(function () use ($treasuryId, $employeeId): void {
            $treasury = $this->lock($treasuryId);

            $this->branches->requireBranch((int) $treasury->branch_id, $employeeId);

            if ((int) $treasury->is_main === 1) {
                throw new AccountingException(AccountingError::TreasuryIsMain, 'The main treasury cannot be deleted.', ['treasury_id' => $treasuryId]);
            }

            if ($this->isUsed($treasuryId)) {
                throw new AccountingException(AccountingError::TreasuryInUse, 'The treasury has been used.', ['treasury_id' => $treasuryId]);
            }

            $this->db->table('treasuries')->where('treasury_id', $treasuryId)->delete();
            $this->accounts->retire(EntityAccountService::TREASURY, $treasuryId, false, $employeeId);
            $this->audit->write('treasury', $treasuryId, 'deleted', $employeeId, JournalOrigin::User, ['name' => $treasury->name], (int) $treasury->branch_id);
        });
    }

    /**
     * The treasury an employee works from, or none. It has to be one of their branches.
     *
     * @throws AccountingException|BranchException
     */
    public function setDefaultFor(int $personId, ?int $treasuryId, ?int $actorId = null): void
    {
        $this->guard->require(AccountingGuard::TREASURIES_MANAGE, $actorId);

        $this->transactional(function () use ($personId, $treasuryId, $actorId): void {
            if ($this->db->table('employees')->where('person_id', $personId)->where('deleted', 0)->countAllResults() === 0) {
                throw new BranchException(BranchError::EmployeeNotFound, "Employee {$personId} does not exist.", ['person_id' => $personId]);
            }

            $branchId = null;

            if ($treasuryId !== null) {
                $treasury = $this->lock($treasuryId);
                $branchId = (int) $treasury->branch_id;

                if ((int) $treasury->active === 0) {
                    throw new AccountingException(AccountingError::TreasuryInactive, 'The treasury is deactivated.', ['treasury_id' => $treasuryId]);
                }

                $this->branches->requireBranch($branchId, $actorId);

                if (! $this->branches->canAccess($branchId, $personId)) {
                    throw new BranchException(BranchError::NotAllowed, "The branch {$branchId} is outside the employee's scope.", ['branch_id' => $branchId]);
                }
            }

            $this->db->table('employees')->where('person_id', $personId)->update(['default_treasury_id' => $treasuryId]);
            $this->audit->write('employee', $personId, 'default_treasury_set', $actorId, JournalOrigin::User, ['treasury_id' => $treasuryId], $branchId);
        });
    }

    /**
     * The treasury a new payment is written into, or none, which sends it to the account of its
     * type as before: the treasury the employee works from, when it is active and belongs to the
     * branch of the document.
     */
    public function stampFor(?int $employeeId, int $branchId): ?int
    {
        if ($employeeId === null) {
            return null;
        }

        $row = $this->db->table('employees e')
            ->select('t.treasury_id')
            ->join('treasuries t', 't.treasury_id = e.default_treasury_id')
            ->where('e.person_id', $employeeId)
            ->where('t.company_id', CompanyContext::id())
            ->where('t.active', 1)
            ->where('t.branch_id', $branchId)
            ->get()
            ->getRow();

        return $row === null ? null : (int) $row->treasury_id;
    }

    /**
     * Whether a document of a branch may name this treasury for a payment: it exists and belongs
     * to the branch.
     *
     * @throws AccountingException
     */
    public function assertForBranch(int $treasuryId, int $branchId): void
    {
        $treasury = $this->find($treasuryId);

        if ($treasury === null || (int) $treasury->branch_id !== $branchId) {
            throw new AccountingException(AccountingError::TreasuryNotFound, "Treasury {$treasuryId} is not one of branch {$branchId}.", ['treasury_id' => $treasuryId]);
        }
    }

    public function defaultFor(int $personId): ?int
    {
        $row = $this->db->table('employees')->select('default_treasury_id')->where('person_id', $personId)->get()->getRow();

        return $row === null || $row->default_treasury_id === null ? null : (int) $row->default_treasury_id;
    }

    public function find(int $treasuryId): ?object
    {
        return $this->db->table('treasuries t')
            ->select('t.*, b.code AS branch_code')
            ->join('branches b', 'b.branch_id = t.branch_id')
            ->where('t.company_id', CompanyContext::id())
            ->where('t.treasury_id', $treasuryId)
            ->get()
            ->getRow();
    }

    /**
     * The treasuries, the main one first, then by name.
     *
     * @param list<int>|null $branchIds The branches the reader may see, null for every branch.
     *
     * @return list<object>
     */
    public function all(?array $branchIds = null, bool $withInactive = true): array
    {
        if ($branchIds === []) {
            return [];
        }

        $builder = $this->db->table('treasuries t')
            ->select('t.*, b.code AS branch_code')
            ->join('branches b', 'b.branch_id = t.branch_id')
            ->where('t.company_id', CompanyContext::id())
            ->orderBy('t.is_main', 'DESC')
            ->orderBy('t.name', 'ASC');

        if ($branchIds !== null) {
            $builder->whereIn('t.branch_id', $branchIds);
        }

        if (! $withInactive) {
            $builder->where('t.active', 1);
        }

        return $builder->get()->getResult();
    }

    /**
     * The employees, with the treasury each works from.
     *
     * @return list<object>
     */
    public function employees(): array
    {
        return $this->db->table('employees e')
            ->select("e.person_id, e.default_treasury_id, TRIM(CONCAT(p.first_name, ' ', p.last_name)) AS employee_name", false)
            ->join('people p', 'p.person_id = e.person_id')
            ->where('e.deleted', 0)
            ->orderBy('employee_name', 'ASC')
            ->orderBy('e.person_id', 'ASC')
            ->get()
            ->getResult();
    }

    public function accountOf(int $treasuryId): ?int
    {
        return $this->accounts->accountFor(EntityAccountService::TREASURY, $treasuryId);
    }

    public function position(int $treasuryId): TreasuryPosition
    {
        $accountId = $this->accountOf($treasuryId);

        if ($accountId === null) {
            return new TreasuryPosition($treasuryId, null, '0.0000', '0.0000');
        }

        $journals = $this->db->prefixTable('accounting_journals');
        $lines    = $this->db->prefixTable('accounting_journal_lines');
        $statuses = [JournalStatus::Posted->value, JournalStatus::Reversed->value];

        $row = $this->db->query(
            "SELECT COALESCE(SUM(l.debit), 0) - COALESCE(SUM(l.credit), 0) AS balance,
                    COALESCE(SUM(CASE WHEN COALESCE(o.source_type, j.source_type) = ? THEN l.debit - l.credit ELSE 0 END), 0) AS manual
             FROM {$lines} l
             JOIN {$journals} j ON j.journal_id = l.journal_id
             LEFT JOIN {$journals} o ON o.journal_id = j.reverses_journal_id
             WHERE j.status IN (?, ?) AND l.account_id = ?",
            [self::HAND_WRITTEN, ...$statuses, $accountId],
        )->getRow();

        return new TreasuryPosition($treasuryId, $accountId, bcadd((string) $row->balance, '0', SaleAmounts::SCALE), bcadd((string) $row->manual, '0', SaleAmounts::SCALE));
    }

    /**
     * What went into and out of the treasury, oldest first, with the balance after each movement,
     * so that whoever finds the balance below zero can see which movement took it there.
     *
     * @return array{rows: list<array<string, mixed>>, crossings: list<array<string, mixed>>, lowest: string}
     */
    public function movements(int $treasuryId): array
    {
        $accountId = $this->accountOf($treasuryId);
        $empty     = ['rows' => [], 'crossings' => [], 'lowest' => '0.0000'];

        if ($accountId === null) {
            return $empty;
        }

        $journals = $this->db->prefixTable('accounting_journals');
        $lines    = $this->db->prefixTable('accounting_journal_lines');
        $statuses = [JournalStatus::Posted->value, JournalStatus::Reversed->value];

        $result = $this->db->query(
            "SELECT j.journal_id, j.number, j.journal_date, j.source_type, j.source_id, j.reverses_journal_id,
                    COALESCE(NULLIF(l.description, ''), j.description) AS description, l.debit, l.credit
             FROM {$lines} l
             JOIN {$journals} j ON j.journal_id = l.journal_id
             WHERE j.status IN (?, ?) AND l.account_id = ?
             ORDER BY j.journal_date ASC, j.journal_id ASC, l.line_no ASC",
            [...$statuses, $accountId],
        );

        $rows      = [];
        $crossings = [];
        $balance   = '0.0000';
        $lowest    = '0.0000';

        while (($row = $result->getUnbufferedRow('array')) !== null) {
            $before  = $balance;
            $balance = bcsub(bcadd($balance, (string) $row['debit'], SaleAmounts::SCALE), (string) $row['credit'], SaleAmounts::SCALE);

            $row['balance'] = $balance;
            $row['crossed'] = bccomp($before, '0', SaleAmounts::SCALE) >= 0 && bccomp($balance, '0', SaleAmounts::SCALE) < 0;

            if (bccomp($balance, $lowest, SaleAmounts::SCALE) < 0) {
                $lowest = $balance;
            }

            if ($row['crossed']) {
                $crossings[] = $row;
            }

            $rows[] = $row;
        }

        return ['rows' => $rows, 'crossings' => $crossings, 'lowest' => $lowest];
    }

    private function mainRow(bool $lock = false): ?object
    {
        $table = $this->db->prefixTable('treasuries');

        return LockedRead::row(
            $this->db,
            "SELECT * FROM {$table} WHERE company_id = ? AND is_main = 1" . ($lock ? ' FOR UPDATE' : ''),
            [CompanyContext::id()],
        );
    }

    /**
     * @throws AccountingException
     */
    private function lock(int $treasuryId): object
    {
        $table = $this->db->prefixTable('treasuries');
        $row   = LockedRead::row($this->db, "SELECT * FROM {$table} WHERE company_id = ? AND treasury_id = ? FOR UPDATE", [CompanyContext::id(), $treasuryId]);

        if ($row === null) {
            throw new AccountingException(AccountingError::TreasuryNotFound, "Treasury {$treasuryId} does not exist.", ['treasury_id' => $treasuryId]);
        }

        return $row;
    }

    private function isUsed(int $treasuryId): bool
    {
        foreach (['sales_payments', 'receivings_payments', 'receivings'] as $table) {
            if ($this->db->table($table)->where('treasury_id', $treasuryId)->countAllResults() > 0) {
                return true;
            }
        }

        if ($this->db->table('treasury_transfers')->groupStart()->where('from_treasury_id', $treasuryId)->orWhere('to_treasury_id', $treasuryId)->groupEnd()->countAllResults() > 0) {
            return true;
        }

        $accountId = $this->accountOf($treasuryId);

        return $accountId !== null && $this->db->table('accounting_journal_lines')->where('account_id', $accountId)->countAllResults() > 0;
    }

    /**
     * @throws AccountingException
     */
    private function assertNameFree(string $name, ?int $exceptId = null): void
    {
        $builder = $this->db->table('treasuries')->where('company_id', CompanyContext::id())->where('name', $name);

        if ($exceptId !== null) {
            $builder->where('treasury_id !=', $exceptId);
        }

        if ($builder->countAllResults() > 0) {
            throw new AccountingException(AccountingError::TreasuryNameTaken, "The name {$name} is taken.", ['name' => $name]);
        }
    }

    private function freeName(string $name): string
    {
        $taken = $this->db->table('treasuries')->where('company_id', CompanyContext::id())->where('name', $name)->countAllResults() > 0;

        return $taken ? $name . ' (' . self::MAIN_NAME . ')' : $name;
    }

    /**
     * @throws AccountingException
     */
    private function name(mixed $value): string
    {
        $name = is_string($value) ? trim($value) : '';

        if ($name === '' || mb_strlen($name) > self::NAME_MAX - 24) {
            throw new AccountingException(AccountingError::TreasuryInvalidName, 'A treasury needs a name.', ['max' => self::NAME_MAX - 24]);
        }

        return $name;
    }

    /**
     * @throws AccountingException
     */
    private function kind(mixed $value): string
    {
        if (! is_string($value) || ! in_array($value, self::KINDS, true)) {
            throw new AccountingException(AccountingError::TreasuryInvalidKind, 'A treasury is a cash treasury or a bank account.');
        }

        return $value;
    }

    private function description(mixed $value): string
    {
        return mb_substr(is_string($value) ? trim($value) : '', 0, self::DESCRIPTION);
    }

    /**
     * @throws BranchException
     */
    private function assertBranchExists(int $branchId): void
    {
        if ($this->db->table('branches')->where('company_id', CompanyContext::id())->where('branch_id', $branchId)->where('active', 1)->countAllResults() === 0) {
            throw new BranchException(BranchError::NotFound, "Branch {$branchId} does not exist or is not active.", ['branch_id' => $branchId]);
        }
    }

    private function display(string $amount): string
    {
        return to_currency_no_money($amount);
    }
}
