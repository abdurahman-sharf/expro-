<?php

namespace Tests\Support;

use CodeIgniter\Events\Events;

trait LockOrderTrait
{
    /**
     * What an action locks and in which order: 'journal' for the row of a journal read FOR UPDATE and
     * 'period' for a period lock, each kind named when it first follows the other.
     *
     * @return list<string>
     */
    protected function lockOrderOf(callable $action): array
    {
        $order    = [];
        $listener = static function ($query) use (&$order): void {
            $sql  = (string) $query->getQuery();
            $kind = match (true) {
                preg_match('/accounting_journals WHERE journal_id = \d+ FOR UPDATE/', $sql) === 1                                        => 'journal',
                preg_match('/accounting_period_locks WHERE company_id = \d+ AND branch_id = \d+ (?:LOCK IN SHARE MODE|FOR UPDATE)/', $sql) === 1 => 'period',
                default                                                                                                                  => null,
            };

            if ($kind !== null && end($order) !== $kind) {
                $order[] = $kind;
            }
        };

        Events::on('DBQuery', $listener);

        try {
            $action();
        } finally {
            Events::removeListener('DBQuery', $listener);
        }

        return $order;
    }
}
