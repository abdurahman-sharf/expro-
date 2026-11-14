<?php

namespace App\Libraries\Database;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;

/**
 * Runs the statement that builds a temporary table from a SELECT (a report, a list, the figures of a
 * customer) in a way that takes no locks on the rows it reads. Under REPEATABLE READ such a statement
 * reads with shared locks: it waits for every document being saved and sits on the rows the next save needs,
 * and the two end in a deadlock that costs the register a sale. Under READ COMMITTED the same statement reads
 * a snapshot without locks.
 *
 * The level is set for the next transaction only, which is the statement itself: the server drops the setting
 * as soon as that statement ends, whether it succeeded or failed, so no later statement of the connection can
 * inherit it. It cannot be set inside a transaction; there the statement runs at the level of the
 * transaction, and a refusal to set it never stops the report from being built.
 */
final class ReadCommittedStatement
{
    public static function run(BaseConnection $db, string $sql): void
    {
        if ($db->transDepth === 0) {
            try {
                $db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            } catch (DatabaseException) {
            }
        }

        $db->query($sql);
    }
}
