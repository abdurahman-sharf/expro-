<?php

namespace App\Libraries\Roles;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Database\ResultInterface;

/**
 * Whether the migration that creates the tables of roles has run yet, so code that is deployed
 * before it behaves as it did. It reads the record the migration leaves in the table of migrations,
 * which every request reads anyway, and not the tables: the list of tables of the server leaves out
 * a table the database user may not read, and a table taken for missing is a restriction that is
 * never applied. A record that cannot be read is refused and never taken for a migration that has
 * not run.
 */
final class RoleTables
{
    /**
     * The version of RolesAndRecordScope, the one migration that creates the tables of roles.
     */
    public const MIGRATION = '20271800000000';

    /**
     * @throws RoleTablesUnavailableException
     */
    public static function migrated(BaseConnection $db): bool
    {
        try {
            $result = $db->table('migrations')->select('1', false)->where('version', self::MIGRATION)->limit(1)->get();
        } catch (DatabaseException $exception) {
            throw new RoleTablesUnavailableException('The record of the migration of the roles could not be read.', $exception);
        }

        if (! $result instanceof ResultInterface) {
            throw new RoleTablesUnavailableException('The record of the migration of the roles could not be read.');
        }

        return $result->getRowArray() !== null;
    }
}
