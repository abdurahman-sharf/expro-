<?php

namespace Tests\Support;

use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Database\MySQLi\Connection;

/**
 * A connection whose statements on one table, or the probe of the tables, fail. It answers false,
 * as a connection without debugging does and as any connection does inside a transaction, or
 * throws, as one with debugging does, and passes every other statement to the database.
 */
final class FailingTableConnection extends Connection
{
    public ?string $failingTable = null;
    public bool $failingProbe    = false;
    public bool $throws          = false;

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
        if ($this->failingProbe && str_contains($sql, 'information_schema')) {
            return true;
        }

        return $this->failingTable !== null && str_contains($sql, '`' . $this->DBPrefix . $this->failingTable . '`');
    }
}
