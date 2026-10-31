<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A setting that holds a list, such as the lines of the cash flow report, does not fit in the
 * five hundred characters a setting was given. The column of the value becomes text. No setting is
 * changed or removed, and it is safe to run again. It is not narrowed back, because a value that
 * outgrew five hundred characters would be cut.
 */
class AppConfigValueText extends Migration
{
    public function up(): void
    {
        $table = $this->db->prefixTable('app_config');

        $column = $this->db->query(
            'SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, 'value'],
        )->getRow();

        if ($column !== null && strtolower((string) $column->DATA_TYPE) !== 'text') {
            $this->db->query("ALTER TABLE `{$table}` MODIFY COLUMN `value` TEXT NOT NULL");
        }
    }

    public function down(): void
    {
    }
}
