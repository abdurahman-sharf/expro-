<?php

namespace Tests\Support;

use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Database\MySQLi\Connection;

/**
 * A connection that cannot open a read-only transaction. It refuses the statement the way a
 * connection without debugging does, by answering false, or the way one with debugging does, by
 * throwing, and passes every other statement to the database.
 */
final class ReadOnlyRefusingConnection extends Connection
{
    public bool $throws = false;

    public function query(string $sql, $binds = null, bool $setEscapeFlags = true, string $queryClass = '')
    {
        if (str_starts_with($sql, 'SET TRANSACTION') && str_contains($sql, 'READ ONLY')) {
            if ($this->throws) {
                throw new DatabaseException('read only transactions are not available');
            }

            return false;
        }

        return parent::query($sql, $binds, $setEscapeFlags, $queryClass);
    }
}
