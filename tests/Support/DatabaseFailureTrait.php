<?php

namespace Tests\Support;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * A real error of the database on the write of one named row, made by a trigger that refuses an insert whose
 * column holds a marker: the statement fails inside the transaction of whatever saves the row, as a deadlock or a
 * lost connection would, and every other row is written as usual. The trigger is dropped by
 * stopFailingWrites(), which a test calls in a finally block.
 */
trait DatabaseFailureTrait
{
    protected const REFUSED_MARKER = 'refused-by-the-database';

    /**
     * @var list<string>
     */
    private array $failingTriggers = [];

    protected function failWritesTo(string $table, string $column, string $marker, ?BaseConnection $db = null): void
    {
        $db      ??= Database::connect();
        $trigger = 'fail_' . $table . '_' . count($this->failingTriggers);

        $db->query("DROP TRIGGER IF EXISTS {$trigger}");
        $db->query("CREATE TRIGGER {$trigger} BEFORE INSERT ON " . $db->prefixTable($table) . " FOR EACH ROW BEGIN IF NEW.`{$column}` = '{$marker}' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused for the test'; END IF; END");

        $this->failingTriggers[] = $trigger;
    }

    protected function stopFailingWrites(?BaseConnection $db = null): void
    {
        $db ??= Database::connect();

        foreach ($this->failingTriggers as $trigger) {
            $db->query("DROP TRIGGER IF EXISTS {$trigger}");
        }

        $this->failingTriggers = [];
    }
}
