<?php

namespace Tests\Support;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\MySQLi\Connection;

/**
 * A connection that behaves as one behind a pooler: the statements that mention the text given
 * are sent to a different connection to the same database, as a proxy that spreads the
 * statements of one client over several server connections would send them.
 */
final class PooledConnection extends Connection
{
    private ?BaseConnection $elsewhere = null;
    private string $mention            = '';

    public function sendStatementsMentioning(string $text, BaseConnection $elsewhere): void
    {
        $this->mention   = $text;
        $this->elsewhere = $elsewhere;
    }

    public function query(string $sql, $binds = null, bool $setEscapeFlags = true, string $queryClass = '')
    {
        if ($this->elsewhere !== null && str_contains($sql, $this->mention)) {
            return $this->elsewhere->query($sql, $binds, $setEscapeFlags, $queryClass);
        }

        return parent::query($sql, $binds, $setEscapeFlags, $queryClass);
    }
}
