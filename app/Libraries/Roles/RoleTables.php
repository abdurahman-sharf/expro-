<?php

namespace App\Libraries\Roles;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Database\ResultInterface;
use Throwable;

/**
 * Whether the migration that creates the tables of roles has run yet, so code that is deployed
 * before it behaves as it did. It reads the record the migration leaves in the table of migrations,
 * which every request reads anyway, and not the tables: the list of tables of the server leaves out
 * a table the database user may not read, and a table taken for missing is a restriction that is
 * never applied. A record that cannot be read is refused and never taken for a migration that has
 * not run. The same goes for what the tables hold: a connection without debugging, or inside a
 * transaction, answers a failed query with false, and a read that failed is refused through read()
 * and never taken for a table that holds nothing; a count that failed is refused through count() and
 * never taken for none, since a role, an employee or a location taken for missing is refused for
 * the wrong reason.
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

    /**
     * @throws RoleTablesUnavailableException
     */
    public static function read(BaseBuilder $builder, string $what): ResultInterface
    {
        try {
            $result = $builder->get();
        } catch (DatabaseException $exception) {
            throw new RoleTablesUnavailableException("{$what} could not be read: {$exception->getMessage()}", $exception);
        }

        if (! $result instanceof ResultInterface) {
            throw new RoleTablesUnavailableException("{$what} could not be read: " . self::driverError($builder));
        }

        return $result;
    }

    /**
     * @throws RoleTablesUnavailableException
     */
    public static function count(BaseBuilder $builder, string $what): int
    {
        return (int) self::read($builder->select('COUNT(*) AS total', false), $what)->getRow()->total;
    }

    private static function driverError(BaseBuilder $builder): string
    {
        try {
            $error = $builder->db()->error();

            if (! empty($error['message'])) {
                return (string) $error['message'];
            }
        } catch (Throwable) {
            // A disconnected connection may not answer error(), and the failure is reported all the same.
        }

        return 'unknown error';
    }
}
