<?php

namespace App\Libraries\Accounting\Readiness;

use CodeIgniter\Database\BaseConnection;

/**
 * Whether the tables and columns a check reads exist yet. A database that the migrations have not
 * reached has none of them, and a check that needs them is skipped instead of failing.
 */
final class SchemaProbe
{
    public function __construct(private BaseConnection $db)
    {
    }

    public function hasTable(string $table): bool
    {
        return $this->db->tableExists($this->db->prefixTable($table), false);
    }

    public function hasColumn(string $table, string $column): bool
    {
        return $this->hasTable($table)
            && $this->db->query('SHOW COLUMNS FROM `' . $this->db->prefixTable($table) . '` LIKE ?', [$column])->getNumRows() > 0;
    }

    /**
     * @param list<string> $needs A table, or a table and a column written as "table.column".
     *
     * @return list<string> What is not there.
     */
    public function missing(array $needs): array
    {
        $missing = [];

        foreach ($needs as $need) {
            [$table, $column] = array_pad(explode('.', $need, 2), 2, null);

            if ($column === null ? ! $this->hasTable($table) : ! $this->hasColumn($table, $column)) {
                $missing[] = $need;
            }
        }

        return $missing;
    }
}
