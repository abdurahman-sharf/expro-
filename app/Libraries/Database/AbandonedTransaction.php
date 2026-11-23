<?php

namespace App\Libraries\Database;

use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * A transaction that the work which began it did not get to end, because the work threw. Nothing of it
 * is committed, but the connection stays inside it, so every later write of the request (the one of the
 * session at its end among them) joins it and is lost when the connection closes. It is ended down to the
 * depth the work began at, so the transaction of whoever called the work is left to them.
 */
final class AbandonedTransaction
{
    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public static function guard(BaseConnection $db, callable $work): mixed
    {
        $depth = $db->transDepth;

        try {
            return $work();
        } catch (Throwable $failure) {
            self::close($db, $depth);

            throw $failure;
        }
    }

    public static function close(BaseConnection $db, int $depth): void
    {
        try {
            while ($db->transDepth > $depth) {
                if (! $db->transRollback()) {
                    break;
                }
            }
        } catch (Throwable $failure) {
            log_message('error', 'A transaction that the work left open could not be rolled back: ' . $failure->getMessage());
        }

        FailedTransaction::forget($db);
    }
}
