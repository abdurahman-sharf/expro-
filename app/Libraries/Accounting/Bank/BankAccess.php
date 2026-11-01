<?php

namespace App\Libraries\Accounting\Bank;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\LockedRead;
use App\Libraries\Accounting\TreasuryScopes;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Who may work on the statements of a bank account. It takes the permission to reconcile, and then a
 * bank account the employee may see: one of a branch they work in, which they may deposit into or
 * withdraw from by its scope. Anything else, a cash treasury, an account of another company and an id
 * that does not exist alike, is a bank account that is not found, so that guessing an id tells nothing.
 * The books act without an employee, and that is always allowed.
 */
final class BankAccess
{
    private BaseConnection $db;
    private AccountingGuard $guard;
    private BranchGuard $branches;
    private TreasuryScopes $scopes;
    private TreasuryService $treasuries;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db         = $db ?? Database::connect();
        $this->guard      = $guard ?? new AccountingGuard();
        $this->branches   = $branches ?? new BranchGuard($this->db);
        $this->scopes     = new TreasuryScopes($this->db, $this->branches);
        $this->treasuries = new TreasuryService($this->db, $this->guard, $this->branches);
    }

    /**
     * The bank account, after the permission and the scope are checked. With $lock the row of the
     * treasury is locked, which is what serialises every change of the statements of one account. The
     * lock comes before anything is read: the snapshot a transaction reads from starts at its first
     * plain read, so whatever is read after a wait for the lock is what its holder committed.
     *
     * @throws AccountingException
     */
    public function bankAccount(int $treasuryId, ?int $employeeId, bool $lock = false): object
    {
        if ($lock) {
            $table = $this->db->prefixTable('treasuries');

            LockedRead::row($this->db, "SELECT treasury_id FROM {$table} WHERE company_id = ? AND treasury_id = ? FOR UPDATE", [CompanyContext::id(), $treasuryId]);
        }

        $this->requirePermission($employeeId);

        $treasury = $this->treasuries->find($treasuryId);

        if ($treasury === null || ! $this->canSee($treasury, $employeeId)) {
            throw new AccountingException(AccountingError::BankAccountNotFound, "Bank account {$treasuryId} does not exist.", ['treasury_id' => $treasuryId]);
        }

        return $treasury;
    }

    /**
     * @throws AccountingException
     */
    public function requirePermission(?int $employeeId): void
    {
        $this->guard->require(AccountingGuard::BANK_RECONCILE, $employeeId);
    }

    public function canSee(object $treasury, ?int $employeeId): bool
    {
        if ($treasury->kind !== TreasuryService::BANK) {
            return false;
        }

        if ($employeeId === null) {
            return true;
        }

        return $this->branches->canAccess((int) $treasury->branch_id, $employeeId)
            && ($this->scopes->allows((int) $treasury->treasury_id, TreasuryScopes::DEPOSIT, $employeeId) || $this->scopes->allows((int) $treasury->treasury_id, TreasuryScopes::WITHDRAW, $employeeId));
    }

    /**
     * The bank accounts the employee may reconcile, by name.
     *
     * @return list<object>
     */
    public function bankAccounts(?int $employeeId): array
    {
        if (! $this->guard->has(AccountingGuard::BANK_RECONCILE, $employeeId)) {
            return [];
        }

        return array_values(array_filter(
            $this->treasuries->all($this->branches->allowedBranchIds($employeeId)),
            fn (object $treasury): bool => $this->canSee($treasury, $employeeId),
        ));
    }

    public function treasuries(): TreasuryService
    {
        return $this->treasuries;
    }
}
