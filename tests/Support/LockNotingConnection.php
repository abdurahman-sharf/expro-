<?php

namespace Tests\Support;

use CodeIgniter\Database\MySQLi\Connection;

/**
 * A connection that notes, the first time a transaction on it is rolled back, whether it still holds
 * the named lock set on lockName: it tells the order in which the work behind a lock is ended and the
 * lock is released.
 */
final class LockNotingConnection extends Connection
{
    public ?string $lockName     = null;
    public ?bool $heldAtRollback = null;

    public function transRollback(): bool
    {
        if ($this->lockName !== null) {
            $this->heldAtRollback ??= (bool) $this->query('SELECT IS_USED_LOCK(?) = CONNECTION_ID() AS held', [$this->lockName])->getRow()->held;
        }

        return parent::transRollback();
    }
}
