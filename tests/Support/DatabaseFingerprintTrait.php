<?php

namespace Tests\Support;

use Config\Database;

/**
 * A fingerprint of every table of the test database, to prove that a run changed no row: the row
 * count of each table and its checksum, so that an update or a swap shows as well as an insert.
 */
trait DatabaseFingerprintTrait
{
    /**
     * @return array<string, array{rows: int, checksum: string|null}>
     */
    protected function databaseFingerprint(): array
    {
        $db     = Database::connect();
        $tables = array_map(
            static fn (array $row): string => (string) array_values($row)[0],
            $db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->getResultArray(),
        );
        $print = [];

        foreach (array_chunk($tables, 40) as $chunk) {
            foreach ($db->query('CHECKSUM TABLE `' . implode('`, `', $chunk) . '`')->getResultArray() as $row) {
                $table         = substr((string) $row['Table'], (int) strrpos((string) $row['Table'], '.') + 1);
                $print[$table] = ['rows' => (int) $db->query('SELECT COUNT(*) AS found FROM `' . $table . '`')->getRow()->found, 'checksum' => $row['Checksum'] === null ? null : (string) $row['Checksum']];
            }
        }

        ksort($print);

        return $print;
    }

    /**
     * @param array<string, array{rows: int, checksum: string|null}> $before
     */
    protected function assertDatabaseUnchanged(array $before, string $message = ''): void
    {
        $after   = $this->databaseFingerprint();
        $changed = [];

        foreach ($after as $table => $print) {
            if (($before[$table] ?? null) !== $print) {
                $changed[] = $table . ' (' . ($before[$table]['rows'] ?? 'new') . ' rows, now ' . $print['rows'] . ')';
            }
        }

        $this->assertSame([], $changed, $message === '' ? 'The run changed these tables.' : $message);
    }
}
