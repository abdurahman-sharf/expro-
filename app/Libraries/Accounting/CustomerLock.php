<?php

namespace App\Libraries\Accounting;

use App\Libraries\Database\AbandonedTransaction;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * One change at a time to what a customer owes. Settling credit reads the ledger of the customer
 * and writes from it, so two changes that run side by side could spend the same credit twice. The
 * lock is taken before the transaction starts, so the transaction reads what the one before it
 * left, and it is let go after the transaction ends. When the database does not keep the lock on
 * the connection that makes the change, the change is refused: it would run unprotected.
 */
final class CustomerLock
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
     * @throws AccountingException When another change to the customer does not finish in time.
     */
    public function run(?int $customerId, callable $work): mixed
    {
        if ($customerId === null || $customerId <= 0) {
            return AbandonedTransaction::guard($this->db, $work);
        }

        $name  = $this->nameOf($customerId);
        $lock  = new NamedLock($this->db, $this->waitSeconds);
        $taken = $lock->take($name);

        if ($taken !== LockOutcome::Taken) {
            throw new AccountingException(
                AccountingError::CustomerBusy,
                $taken === LockOutcome::Busy ? 'Another change to this customer is still running.' : 'The lock of this customer is not held by the connection that makes the change, so it is refused.',
                ['customer_id' => $customerId, 'lock' => $taken->value],
            );
        }

        try {
            return AbandonedTransaction::guard($this->db, $work);
        } finally {
            $lock->release($name);
        }
    }

    /**
     * The name the lock of a customer goes by in the database.
     */
    public function nameOf(int $customerId): string
    {
        return 'ospos_customer_' . substr(sha1($this->db->getDatabase()), 0, 10) . '_' . $customerId;
    }
}
