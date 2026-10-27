<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * One change at a time to what is owed to a supplier. Settling credit reads the ledger of the
 * supplier and writes from it, so two changes that run side by side could spend the same credit
 * twice. The lock is taken before the transaction starts, so the transaction reads what the one
 * before it left, and it is let go after the transaction ends. When the database does not keep the
 * lock on the connection that makes the change, the change is refused: it would run unprotected.
 */
final class SupplierLock
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null, private readonly int $waitSeconds = 15)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     *
     * @throws AccountingException When another change to the supplier does not finish in time.
     */
    public function run(?int $supplierId, callable $work): mixed
    {
        if ($supplierId === null || $supplierId <= 0) {
            return $work();
        }

        $name  = $this->nameOf($supplierId);
        $lock  = new NamedLock($this->db, $this->waitSeconds);
        $taken = $lock->take($name);

        if ($taken !== LockOutcome::Taken) {
            throw new AccountingException(
                AccountingError::SupplierBusy,
                $taken === LockOutcome::Busy ? 'Another change to this supplier is still running.' : 'The lock of this supplier is not held by the connection that makes the change, so it is refused.',
                ['supplier_id' => $supplierId, 'lock' => $taken->value],
            );
        }

        try {
            return $work();
        } finally {
            $lock->release($name);
        }
    }

    /**
     * The name the lock of a supplier goes by in the database.
     */
    public function nameOf(int $supplierId): string
    {
        return 'ospos_supplier_' . substr(sha1($this->db->getDatabase()), 0, 10) . '_' . $supplierId;
    }
}
