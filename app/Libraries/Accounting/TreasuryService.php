<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The treasuries and bank accounts: each is an entity with an account of its own, so what it holds
 * is what its account says: a cash treasury under the main treasury account and a bank account
 * under the main account of the banks, which takes the name of the bank and the number of the
 * account. The main treasury is the account every payment already went to, and takes its place
 * without a journal changing. A treasury that holds money is not deactivated, and one that has
 * been used is not deleted.
 */
final class TreasuryService
{
    use RunsInTransaction;

    public const CASH          = 'cash';
    public const BANK          = 'bank';
    private const KINDS        = [self::CASH, self::BANK];
    private const NAME_MAX     = 255;
    private const BANK_MAX     = 120;
    private const NUMBER_MIN   = 4;
    private const NUMBER_MAX   = 34;
    private const MAIN_NAME    = 'الخزينة الأساسية';
    private const DESCRIPTION  = 500;
    private const HAND_WRITTEN = 'manual';

    private BaseConnection $db;
    private EntityAccountService $accounts;
    private AccountingGuard $guard;
    private BranchGuard $branches;
    private TreasuryScopes $scopes;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->accounts = new EntityAccountService($this->db);
        $this->guard    = $guard ?? new AccountingGuard();
        $this->branches = $branches ?? new BranchGuard($this->db);
        $this->scopes   = new TreasuryScopes($this->db, $this->branches);
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
     * @param array{name?: mixed, kind?: mixed, description?: mixed, branch_id?: mixed, bank_name?: mixed, account_number?: mixed, deposit_scope?: mixed, deposit_members?: mixed, withdraw_scope?: mixed, withdraw_members?: mixed} $input
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function create(array $input, ?int $employeeId = null): int
    {
        $this->guard->require(AccountingGuard::TREASURIES_MANAGE, $employeeId);

        $name        = $this->name($input['name'] ?? '');
        $kind        = $this->kind($input['kind'] ?? self::CASH);
        $bank        = $this->bankDetails($kind, $input);
        $scopes      = $this->scopes->validated($input);
        $description = $this->description($input['description'] ?? '');
        $branchId    = (int) ($input['branch_id'] ?? 0) > 0 ? (int) $input['branch_id'] : $this->branches->defaultBranchId($employeeId);

        $this->branches->requireBranch($branchId, $employeeId);
        $this->assertBranchExists($branchId);
        $this->ensureMain($employeeId);

        return $this->transactional(function () use ($name, $kind, $bank, $scopes, $description, $branchId, $employeeId): int {
            $this->assertNameFree($name);
            $this->assertBankAccountFree($bank);

            $this->db->table('treasuries')->insert([
                'company_id'     => CompanyContext::id(),
                'branch_id'      => $branchId,
                'name'           => $name,
                'kind'           => $kind,
                'bank_name'      => $bank['bank_name'],
                'account_number' => $bank['account_number'],
                'description'    => $description,
                'created_by'     => $employeeId,
            ]);

            $treasuryId = (int) $this->db->insertID();

            $this->accounts->ensure(EntityAccountService::TREASURY, $treasuryId, $name, $employeeId, $this->parentKey($kind));
            $this->scopes->apply($treasuryId, $scopes, $employeeId, $branchId);
            $this->audit->write('treasury', $treasuryId, 'created', $employeeId, JournalOrigin::User, ['name' => $name, 'kind' => $kind] + $bank, $branchId);

            return $treasuryId;
        });
    }

    /**
     * A treasury that changes between cash and bank takes its account to the main account of the
     * other: only its place in the chart changes, so its balance and its entries stay as they are.
     *
     * @param array{name?: mixed, kind?: mixed, description?: mixed, bank_name?: mixed, account_number?: mixed, deposit_scope?: mixed, deposit_members?: mixed, withdraw_scope?: mixed, withdraw_members?: mixed} $input
     *
     * @throws AccountingException|BranchException
     */
    public function update(int $treasuryId, array $input, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::TREASURIES_MANAGE, $employeeId);

        $name        = $this->name($input['name'] ?? '');
        $kind        = $this->kind($input['kind'] ?? self::CASH);
        $bank        = $this->bankDetails($kind, $input);
        $scopes      = $this->scopes->validated($input);
        $description = $this->description($input['description'] ?? '');

        $this->transactional(function () use ($treasuryId, $name, $kind, $bank, $scopes, $description, $employeeId): void {
            $treasury = $this->lock($treasuryId);

            $this->branches->requireBranch((int) $treasury->branch_id, $employeeId);
            $this->assertNameFree($name, $treasuryId);
            $this->assertBankAccountFree($bank, $treasuryId);

            if ((int) $treasury->is_main === 1 && $kind !== self::CASH) {
                throw new AccountingException(AccountingError::TreasuryIsMain, 'The main treasury is a cash treasury.', ['treasury_id' => $treasuryId]);
            }

            $this->db->table('treasuries')->where('treasury_id', $treasuryId)->update(['name' => $name, 'kind' => $kind, 'description' => $description] + $bank);

            if ($treasury->kind !== $kind) {
                $this->accounts->relocate(EntityAccountService::TREASURY, $treasuryId, $this->parentKey($kind) ?? RoutingKey::Treasuries, $employeeId);
            }

            $this->accounts->rename(EntityAccountService::TREASURY, $treasuryId, $name, $employeeId);
            $this->scopes->apply($treasuryId, $scopes, $employeeId, (int) $treasury->branch_id);
            $this->audit->write('treasury', $treasuryId, 'updated', $employeeId, JournalOrigin::User, [
                'before' => ['name' => $treasury->name, 'kind' => $treasury->kind, 'description' => $treasury->description, 'bank_name' => $treasury->bank_name, 'account_number' => $treasury->account_number],
                'after'  => ['name' => $name, 'kind' => $kind, 'description' => $description] + $bank,
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
     * type as before: the treasury the employee works from, when it is active, belongs to the
     * branch of the document and, for a direction given, lets the employee deposit or withdraw.
     */
    public function stampFor(?int $employeeId, int $branchId, ?string $direction = null): ?int
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

        if ($row === null || ($direction !== null && ! $this->scopes->allows((int) $row->treasury_id, $direction, $employeeId))) {
            return null;
        }

        return (int) $row->treasury_id;
    }

    /**
     * The treasury a new payment goes into or out of, with the scope of the treasury enforced: the
     * one it names, which must be of the branch and open to the employee in the direction, or else
     * the treasury the employee works from when that is open to them, or else none, which sends it
     * to the account of its way of payment and so to the main treasury, whose scope then governs.
     *
     * @throws AccountingException
     */
    public function ofPayment(?int $named, PaymentType $type, string $direction, ?int $employeeId, int $branchId): ?int
    {
        if ($named !== null && $named > 0) {
            $this->assertForBranch($named, $branchId);
            $this->scopes->assertAllows($named, $direction, $employeeId);

            return $named;
        }

        $stamped = $this->stampFor($employeeId, $branchId, $direction);

        if ($stamped === null) {
            $this->scopes->assertRoutedAllows($type, $direction, $employeeId);
        }

        return $stamped;
    }

    /**
     * Where a new payment goes, with the branch of the treasury when that is not the branch of the
     * document: the treasury it names, which may be one of another branch for an employee who works
     * in both, and open to the employee in the direction, or else what ofPayment() chooses, which is
     * always of the branch of the document.
     *
     * @return array{?int, ?int} The treasury and the branch it belongs to when that is another.
     *
     * @throws AccountingException
     */
    public function placeOfPayment(?int $named, PaymentType $type, string $direction, ?int $employeeId, int $branchId): array
    {
        if ($named !== null && $named > 0) {
            $other = $this->otherBranchOf($named, $branchId, $employeeId);

            $this->scopes->assertAllows($named, $direction, $employeeId);

            return [$named, $other];
        }

        return [$this->ofPayment(null, $type, $direction, $employeeId, $branchId), null];
    }

    /**
     * The branch of a treasury a document of a branch names for a payment, or null when it is the
     * branch of the document. A treasury of another branch is for an employee who works in both
     * branches, as moving money between branches is, and it must be open unless the payment is
     * already in it. A treasury the employee may not use that way is as good as not there, and so is
     * every treasury of another branch while the books are off. A payment that is already in it is
     * known to the employee, and is told that both branches are needed.
     *
     * @throws AccountingException
     */
    public function otherBranchOf(int $treasuryId, int $branchId, ?int $employeeId, bool $alreadyUsed = false): ?int
    {
        $treasury = $this->find($treasuryId);

        if ($treasury === null) {
            throw new AccountingException(AccountingError::TreasuryNotFound, "Treasury {$treasuryId} does not exist.", ['treasury_id' => $treasuryId]);
        }

        $treasuryBranch = (int) $treasury->branch_id;

        if ($treasuryBranch === $branchId) {
            return null;
        }

        if (! (new AccountingSettings($this->db))->isEnabled()) {
            throw new AccountingException(AccountingError::TreasuryNotFound, "Treasury {$treasuryId} is not one of branch {$branchId}.", ['treasury_id' => $treasuryId]);
        }

        foreach ([$treasuryBranch, $branchId] as $needed) {
            if (! $this->branches->canAccess($needed, $employeeId)) {
                throw $alreadyUsed
                    ? new AccountingException(AccountingError::InterBranchBranchesDenied, "The branch {$needed} is outside the employee's scope.", ['branch_id' => $needed, 'name' => (string) ($this->db->table('branches')->select('code')->where('branch_id', $needed)->get()->getRow()->code ?? $needed)])
                    : new AccountingException(AccountingError::TreasuryNotFound, "Treasury {$treasuryId} is not one of branch {$branchId}.", ['treasury_id' => $treasuryId]);
            }
        }

        if (! $alreadyUsed && (int) $treasury->active === 0) {
            throw new AccountingException(AccountingError::TreasuryInactive, 'A payment cannot go into a deactivated treasury of another branch.', ['treasury_id' => $treasuryId]);
        }

        return $treasuryBranch;
    }

    /**
     * Whether an employee may change a payment that was written before: only a change of the
     * treasury it lands on or of its amount is a new deposit or withdrawal. A payment with no
     * treasury lands on the treasury that owns the account its way of payment is routed to, so a
     * change of the way of payment can move it too. Taking a payment off a treasury is the opposite
     * of putting it there, so it needs the opposite scope of the treasury it leaves.
     *
     * @throws AccountingException
     */
    public function assertMayChangePayment(?int $newTreasuryId, ?int $oldTreasuryId, bool $amountChanged, PaymentType $type, string $direction, ?int $employeeId, ?PaymentType $oldType = null): void
    {
        $newTreasuryId = $newTreasuryId !== null && $newTreasuryId > 0 ? $newTreasuryId : null;
        $newOwner      = $newTreasuryId ?? $this->scopes->ownerOfRoutedType($type);
        $oldOwner      = $oldTreasuryId ?? $this->scopes->ownerOfRoutedType($oldType ?? $type);

        if (! $amountChanged && $newOwner === $oldOwner) {
            return;
        }

        if ($newOwner !== null) {
            $this->scopes->assertAllows($newOwner, $direction, $employeeId);
        }

        if ($oldOwner !== null && $oldOwner !== $newOwner) {
            $this->scopes->assertAllows($oldOwner, $direction === TreasuryScopes::DEPOSIT ? TreasuryScopes::WITHDRAW : TreasuryScopes::DEPOSIT, $employeeId);
        }
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
        foreach (['sales_payments', 'receivings_payments', 'receivings', 'expenses', 'cash_up'] as $table) {
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
     * Whether a bank account still lacks the name of the bank or the number of the account, as the
     * ones opened before both were required do. Editing one asks for both.
     */
    public static function lacksBankDetails(object $treasury): bool
    {
        return $treasury->kind === self::BANK && (trim((string) ($treasury->bank_name ?? '')) === '' || trim((string) ($treasury->account_number ?? '')) === '');
    }

    private function parentKey(string $kind): ?RoutingKey
    {
        return $kind === self::BANK ? RoutingKey::Banks : null;
    }

    /**
     * The name of the bank and the number of the account of a bank account, which a cash treasury
     * does not have.
     *
     * @param array<string, mixed> $input
     *
     * @return array{bank_name: ?string, account_number: ?string}
     *
     * @throws AccountingException
     */
    private function bankDetails(string $kind, array $input): array
    {
        if ($kind !== self::BANK) {
            return ['bank_name' => null, 'account_number' => null];
        }

        $bankName = is_string($input['bank_name'] ?? null) ? trim($input['bank_name']) : '';

        if ($bankName === '' || mb_strlen($bankName) > self::BANK_MAX) {
            throw new AccountingException(AccountingError::TreasuryBankNameInvalid, 'A bank account needs the name of its bank.', ['max' => self::BANK_MAX]);
        }

        $number = strtoupper((string) preg_replace('/[\s-]+/', '', is_string($input['account_number'] ?? null) ? $input['account_number'] : ''));

        if (preg_match('/^[A-Z0-9]{' . self::NUMBER_MIN . ',' . self::NUMBER_MAX . '}$/', $number) !== 1) {
            throw new AccountingException(AccountingError::TreasuryAccountNumberInvalid, 'A bank account needs its account number.', ['min' => self::NUMBER_MIN, 'max' => self::NUMBER_MAX]);
        }

        return ['bank_name' => $bankName, 'account_number' => $number];
    }

    /**
     * @param array{bank_name: ?string, account_number: ?string} $bank
     *
     * @throws AccountingException
     */
    private function assertBankAccountFree(array $bank, ?int $exceptId = null): void
    {
        if ($bank['account_number'] === null) {
            return;
        }

        $builder = $this->db->table('treasuries')->where('company_id', CompanyContext::id())->where('bank_name', $bank['bank_name'])->where('account_number', $bank['account_number']);

        if ($exceptId !== null) {
            $builder->where('treasury_id !=', $exceptId);
        }

        if ($builder->countAllResults() > 0) {
            throw new AccountingException(AccountingError::TreasuryBankAccountTaken, 'This bank account is already one of the treasuries.', ['bank_name' => $bank['bank_name'], 'account_number' => $bank['account_number']]);
        }
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
