<?php

namespace App\Libraries\Roles;

use CodeIgniter\Database\BaseConnection;

/**
 * Whether the tables of roles exist yet, so code that is deployed before the migration has run
 * behaves as it did. It asks the server and not the list of tables the connection remembers, which
 * is stale for a table created after the list was read.
 */
final class RoleTables
{
    public static function exist(BaseConnection $db, string ...$names): bool
    {
        foreach ($names as $name) {
            $found = $db->query(
                'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$db->prefixTable($name)],
            )->getRow();

            if ($found === null) {
                return false;
            }
        }

        return true;
    }
}
