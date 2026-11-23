<?php

namespace Tests\Support;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

trait TransactionStateTrait
{
    /**
     * Closes whatever transaction a failed call left open, so that one test that fails does not fail
     * every test after it on the shared connection, and then asserts that there was none.
     */
    protected function assertNoTransactionIsOpen(): void
    {
        $db     = Database::connect();
        $depth  = $db->transDepth;
        $status = $db->transStatus();

        $this->closeOpenTransaction();

        $this->assertSame(0, $depth, 'The call left its transaction open.');
        $this->assertTrue($status, 'The call left the transaction status of the connection failed.');
    }

    protected function closeOpenTransaction(): void
    {
        $db = Database::connect();

        while ($db->transDepth > 0) {
            $db->transRollback();
        }

        $db->resetTransStatus();
    }

    /**
     * A second connection to the test database, which sees only what was committed. It gives up on a
     * lock after a second, and with debugging off it behaves as production does: a failed query
     * returns false.
     */
    protected function anotherConnection(): BaseConnection
    {
        $config            = (new Database())->tests;
        $config['DBDebug'] = false;

        $connection = Database::connect($config, false);
        $connection->query('SET SESSION innodb_lock_wait_timeout = 1');

        return $connection;
    }
}
