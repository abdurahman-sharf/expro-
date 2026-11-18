<?php

namespace Tests\Support;

use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Database\MySQLi\Connection;

/**
 * A connection whose statements on one table, or on the list of the tables of the server (the
 * information schema and SHOW TABLES, in any case), fail. It answers false, as a connection without
 * debugging does and as any connection does inside a transaction, or throws, as one with debugging
 * does, and passes every other statement to the database.
 */
final class FailingTableConnection extends Connection
{
    public ?string $failingTable          = null;
    public bool $failingInformationSchema = false;
    public bool $throws                   = false;

    public function query(string $sql, $binds = null, bool $setEscapeFlags = true, string $queryClass = '')
    {
        if ($this->refuses($sql)) {
            if ($this->throws) {
                throw new DatabaseException('simulated failure');
            }

            return false;
        }

        return parent::query($sql, $binds, $setEscapeFlags, $queryClass);
    }

    private function refuses(string $sql): bool
    {
        if ($this->failingInformationSchema && (stripos($sql, 'information_schema') !== false || stripos(ltrim($sql), 'SHOW TABLES') === 0)) {
            return true;
        }

        return $this->failingTable !== null && str_contains($sql, '`' . $this->DBPrefix . $this->failingTable . '`');
    }
}
