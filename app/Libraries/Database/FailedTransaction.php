<?php

namespace App\Libraries\Database;

use CodeIgniter\Database\BaseConnection;

/**
 * After a statement fails inside a transaction the connection keeps the status "failed" in its strict mode, and
 * every transaction the process starts later is rolled back at its end, with no error of the database, until the
 * process exits. A web request is one process and never notices. A command that goes through many documents does:
 * one deadlock and every document after it is refused. The one that began the transaction, and so has the answer
 * (it read the status when it ended the transaction), is the one to forget the failure, as long as it is not
 * working inside the transaction of someone else, who is still to read the status.
 */
final class FailedTransaction
{
    public static function forget(BaseConnection $db): void
    {
        if ($db->transDepth === 0) {
            $db->resetTransStatus();
        }
    }
}
