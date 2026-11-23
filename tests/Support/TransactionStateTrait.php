<?php

namespace Tests\Support;

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

    protected function connectionThatNotesTheLockAtRollback(): LockNotingConnection
    {
        return new LockNotingConnection((new Database())->tests);
    }
}
