<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * A named lock of the database server around the migrations that a web request starts, so that two
 * requests cannot run them together. The lock belongs to the connection that took it and goes with
 * it if the request dies, and a request that finds it taken waits a few seconds for it, so that one
 * that comes right behind the request that is migrating learns it is finished, and then gives up.
 */
final class MigrationLock
{
    public const WAIT_SECONDS = 3;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null, private int $waitSeconds = self::WAIT_SECONDS)
    {
        $this->db = $db ?? Database::connect();
    }

    public static function name(string $database): string
    {
        return 'expro_migrate_' . md5($database);
    }

    /**
     * @param callable(): mixed $work
     *
     * @return bool False when another request holds the lock and the work did not run.
     */
    public function run(callable $work): bool
    {
        $name = self::name($this->db->getDatabase());

        if ((int) ($this->db->query('SELECT GET_LOCK(?, ?) AS taken', [$name, $this->waitSeconds])->getRow()->taken ?? 0) !== 1) {
            return false;
        }

        try {
            $work();
        } finally {
            $this->db->query('SELECT RELEASE_LOCK(?)', [$name]);
        }

        return true;
    }
}
