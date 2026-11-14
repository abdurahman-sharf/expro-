<?php

namespace Tests\Support;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * A test database comes with the account the installer writes, whose password everybody knows.
 * A test that expects a ready database changes it first, and puts the installer hash back after.
 */
trait InstallerPasswordFixtureTrait
{
    private ?string $installerAdminHash = null;

    protected function installerHash(): string
    {
        preg_match("/\\('admin', '(\\\$2y\\\$[^']+)'/", (string) file_get_contents(APPPATH . 'Database/Migrations/sqlscripts/initial_schema.sql'), $matches);

        return $matches[1];
    }

    protected function changeInstallerPassword(?BaseConnection $db = null): void
    {
        $db ??= Database::connect();

        $this->installerAdminHash ??= (string) $db->table('employees')->select('password')->where('person_id', 1)->get()->getRow()->password;

        $db->table('employees')->where('person_id', 1)->update(['password' => password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT), 'hash_version' => 2]);
    }

    protected function restoreInstallerPassword(?BaseConnection $db = null): void
    {
        if ($this->installerAdminHash === null) {
            return;
        }

        ($db ?? Database::connect())->table('employees')->where('person_id', 1)->update(['password' => $this->installerAdminHash, 'hash_version' => 2]);

        $this->installerAdminHash = null;
    }
}
