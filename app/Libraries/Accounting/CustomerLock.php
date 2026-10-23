<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * One change at a time to what a customer owes. Settling credit reads the ledger of the customer
 * and writes from it, so two changes that run side by side could spend the same credit twice. The
 * lock is taken before the transaction starts, so the transaction reads what the one before it
 * left, and it is let go after the transaction ends.
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
            return $work();
        }

        $name = $this->nameOf($customerId);
        $got  = (int) ($this->db->query('SELECT GET_LOCK(?, ?) AS got', [$name, $this->waitSeconds])->getRow()->got ?? 0);

        if ($got !== 1) {
            throw new AccountingException(AccountingError::CustomerBusy, 'Another change to this customer is still running.', ['customer_id' => $customerId]);
        }

        try {
            return $work();
        } finally {
            $this->db->query('SELECT RELEASE_LOCK(?)', [$name]);
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
