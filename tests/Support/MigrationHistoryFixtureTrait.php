<?php

namespace Tests\Support;

use Config\Database;

/**
 * Takes a migration out of the history of the test database, so that it counts as pending, and puts
 * it back. A class that uses it calls restoreMigrations() when each test ends.
 */
trait MigrationHistoryFixtureTrait
{
    /** @var list<array<string, mixed>> */
    private array $forgottenMigrations = [];

    protected function forgetMigration(string $version): void
    {
        $db  = Database::connect();
        $row = $db->table('migrations')->where('version', $version)->get()->getRowArray();

        if ($row !== null) {
            $this->forgottenMigrations[] = $row;
            $db->table('migrations')->where('version', $version)->delete();
        }
    }

    protected function restoreMigrations(): void
    {
        foreach ($this->forgottenMigrations as $row) {
            Database::connect()->table('migrations')->insert($row);
        }

        $this->forgottenMigrations = [];
    }
}
