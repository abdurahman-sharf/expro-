<?php

namespace Tests\Support;

use CodeIgniter\Database\BaseConnection;
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

        foreach ($this->tablesWithGeneratedColumns($db) as $table => $columns) {
            if (isset($print[$table])) {
                $print[$table]['checksum'] = $this->contentChecksum($db, $table, $columns);
            }
        }

        ksort($print);

        return $print;
    }

    /**
     * CHECKSUM TABLE of a table with a generated column is not stable for the same rows, so such a
     * table is fingerprinted by the content of its stored columns.
     *
     * @return array<string, list<string>> The stored columns of each table that has a generated one.
     */
    private function tablesWithGeneratedColumns(BaseConnection $db): array
    {
        $rows      = $db->query('SELECT TABLE_NAME AS t, COLUMN_NAME AS c, EXTRA AS e FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION')->getResultArray();
        $stored    = [];
        $generated = [];

        foreach ($rows as $row) {
            if (str_contains(strtoupper((string) $row['e']), 'GENERATED')) {
                $generated[(string) $row['t']] = true;

                continue;
            }

            $stored[(string) $row['t']][] = (string) $row['c'];
        }

        return array_intersect_key($stored, $generated);
    }

    /**
     * @param list<string> $columns
     */
    private function contentChecksum(BaseConnection $db, string $table, array $columns): string
    {
        $parts = array_map(static fn (string $column): string => "IFNULL(CAST(`{$column}` AS CHAR), '\\0')", $columns);
        $row   = $db->query('SELECT COALESCE(SUM(CRC32(CONCAT_WS(0x1f, ' . implode(', ', $parts) . '))), 0) AS c FROM `' . $table . '`')->getRow();

        return (string) $row->c;
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
