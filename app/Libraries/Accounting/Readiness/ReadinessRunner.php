<?php

namespace App\Libraries\Accounting\Readiness;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Throwable;

/**
 * Runs every check inside one read-only transaction, so that the database itself refuses a write
 * and all the checks see one snapshot of the books. A connection that cannot open a read-only
 * transaction says so in the result and still reads inside a transaction.
 */
final class ReadinessRunner
{
    private BaseConnection $db;
    private MigrationChecks $migrations;

    /**
     * @var list<CheckGroup>
     */
    private array $groups;

    /**
     * @param list<CheckGroup>|null $groups The checks to run, every check of the report when null.
     */
    public function __construct(?BaseConnection $db = null, ?array $groups = null)
    {
        $this->db         = $db ?? Database::connect();
        $this->migrations = new MigrationChecks($this->db);
        $this->groups     = $groups ?? [
            $this->migrations,
            new HistoricalDataChecks($this->db, $this->migrations),
            new LedgerChecks($this->db),
            new TieChecks($this->db),
            new SettingsChecks($this->db),
        ];
    }

    public function run(): ReadinessResult
    {
        $ownsTransaction = $this->db->transDepth === 0;
        $readOnly        = $ownsTransaction && $this->begin();
        $results         = [];

        try {
            foreach ($this->groups as $group) {
                array_push($results, ...$group->run());
            }

            $pending = $this->migrations->pending();
        } finally {
            if ($ownsTransaction) {
                $this->db->transRollback();
                $this->db->resetTransStatus();
            }
        }

        return new ReadinessResult($results, $pending, $readOnly, $this->db->getDatabase(), date('Y-m-d H:i:s'));
    }

    private function begin(): bool
    {
        try {
            $readOnly = $this->db->query('SET TRANSACTION READ ONLY') !== false;
        } catch (Throwable) {
            $readOnly = false;
        }

        $this->db->resetTransStatus();
        $this->db->transStart();

        return $readOnly;
    }
}
