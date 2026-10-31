<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * A return of stock with no sale to take it back from is refused, because the cost of what comes
 * back is the cost it was sold at and nothing says what that was. A manager who holds the permission
 * can let one through anyway, with a reason: the goods then come back at the cost of the day, the
 * journals of the return carry a marker that says so, and the audit log says who did it and why. The
 * override is a fact about a return, kept in a record of its own, so it cannot be added to a return
 * after it was posted.
 */
final class ReturnOverride
{
    public const AUDIT_ACTION = 'return_override';
    public const MARKER       = '[override: no invoice]';
    private const REASON_MAX  = 255;

    private BaseConnection $db;
    private AccountingGuard $guard;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null)
    {
        $this->db    = $db ?? Database::connect();
        $this->guard = $guard ?? new AccountingGuard();
        $this->audit = new AuditLog($this->db);
    }

    public function exists(int $saleId): bool
    {
        return $this->db->table('sale_return_overrides')->where('sale_id', $saleId)->countAllResults() > 0;
    }

    /**
     * The reason of an override that may be given: the employee holds the permission and the reason
     * is one to 255 characters.
     *
     * @throws AccountingException
     */
    public function reasonFor(?int $employeeId, string $reason): string
    {
        $this->guard->require(AccountingGuard::RETURN_OVERRIDE, $employeeId);

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX) {
            throw new AccountingException(AccountingError::ReasonRequired, 'A reason of 1 to 255 characters is required.');
        }

        return $reason;
    }

    /**
     * Writes down that a return was let through, in the transaction that saves it.
     */
    public function record(int $saleId, int $branchId, int $employeeId, string $reason): void
    {
        if ($this->exists($saleId)) {
            return;
        }

        $this->db->table('sale_return_overrides')->insert([
            'sale_id'     => $saleId,
            'branch_id'   => $branchId,
            'employee_id' => $employeeId,
            'reason'      => $reason,
        ]);

        $this->audit->write('sale', $saleId, self::AUDIT_ACTION, $employeeId, JournalOrigin::User, ['reason' => $reason], $branchId);
    }

    public static function describe(string $description, bool $overridden): string
    {
        return $overridden ? $description . ' ' . self::MARKER : $description;
    }
}
