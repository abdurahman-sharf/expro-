<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Runs a unit of work atomically. Inside a caller's transaction it joins that
 * transaction and leaves the rollback to the caller.
 *
 * @property BaseConnection $db
 */
trait RunsInTransaction
{
    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     *
     * @throws AccountingException|PostingException
     */
    private function transactional(callable $work): mixed
    {
        $ownsTransaction = $this->db->transDepth === 0;

        if ($ownsTransaction) {
            $this->db->resetTransStatus();
        }

        $this->db->transStart();

        try {
            $result = $work();
        } catch (Throwable $exception) {
            $ownsTransaction ? $this->db->transRollback() : $this->db->transComplete();

            if ($ownsTransaction) {
                $this->db->resetTransStatus();
            }

            throw $exception;
        }

        $this->db->transComplete();

        if (! $this->db->transStatus()) {
            if ($ownsTransaction) {
                $this->db->resetTransStatus();
            }

            throw new AccountingException(AccountingError::WriteFailed, 'The change could not be saved.');
        }

        return $result;
    }
}
