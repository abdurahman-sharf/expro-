<?php

namespace Tests\Support\Ledger;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Puts the test database back to the rows the migrations leave, so that every seed of the harness starts
 * from the same books. The rows of each table are read once, after the migrations, and a table that has
 * rows of other seeds in it is emptied and given them again.
 */
final class LedgerDatabase
{
    /** @var array<string, list<array<string, mixed>>> */
    private static array $pristine = [];

    /** @var array<string, list<string>> */
    private static array $storedColumns = [];

    private BaseConnection $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    public static function savedSnapshot(): ?string
    {
        $path = getenv('LEDGER_SNAPSHOT');

        return $path === false || $path === '' ? null : $path;
    }

    public function remember(): void
    {
        if (self::$pristine !== []) {
            return;
        }

        $saved = self::savedSnapshot();

        if ($saved !== null && is_file($saved)) {
            [self::$pristine, self::$storedColumns] = unserialize((string) file_get_contents($saved), ['allowed_classes' => false]);

            return;
        }

        foreach ($this->tables() as $table) {
            self::$storedColumns[$table] = $this->storedColumnsOf($table);
            $rows                        = $this->db->query('SELECT ' . $this->columnList(self::$storedColumns[$table]) . ' FROM `' . $table . '`')->getResultArray();
            self::$pristine[$table]      = $rows;
        }

        if ($saved !== null) {
            file_put_contents($saved, serialize([self::$pristine, self::$storedColumns]));
        }
    }

    public function restore(): void
    {
        $this->releaseTransactions();
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach (self::$pristine as $table => $rows) {
            if ($rows === []) {
                $found = $this->db->query('SELECT 1 AS found FROM `' . $table . '` LIMIT 1')->getRowArray();

                if ($found !== null) {
                    $this->db->query('TRUNCATE TABLE `' . $table . '`');
                }

                continue;
            }

            $this->db->query('TRUNCATE TABLE `' . $table . '`');

            foreach (array_chunk($rows, 200) as $chunk) {
                $this->db->table(substr($table, strlen($this->db->DBPrefix)))->insertBatch($chunk);
            }
        }

        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->db->resetDataCache();
    }

    public function releaseTransactions(): void
    {
        while ($this->db->transDepth > 0) {
            $this->db->transRollback();
        }

        $this->db->resetTransStatus();
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        return array_map(
            static fn (array $row): string => (string) array_values($row)[0],
            $this->db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->getResultArray(),
        );
    }

    /**
     * @return list<string>
     */
    private function storedColumnsOf(string $table): array
    {
        $columns = [];

        foreach ($this->db->query('SHOW COLUMNS FROM `' . $table . '`')->getResultArray() as $column) {
            if (! str_contains(strtoupper((string) $column['Extra']), 'GENERATED')) {
                $columns[] = (string) $column['Field'];
            }
        }

        return $columns;
    }

    /**
     * @param list<string> $columns
     */
    private function columnList(array $columns): string
    {
        return implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', $columns));
    }
}
