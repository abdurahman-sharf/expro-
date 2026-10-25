<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The cost an item carries and the cost a sale line carries, to six decimals instead of two, so
 * that the average cost, which is worked out to more decimals than that, is not rounded before it
 * costs a sale: 3 units bought at 100 and 4 at 50 cost 71.428571 each, and the 7 of them cost 500
 * and not 500.01. Additive only: every cost that is there keeps its value, and running it again
 * changes nothing.
 */
class CostPrecision extends Migration
{
    private const COLUMNS = [
        'items'       => 'cost_price',
        'sales_items' => 'item_cost_price',
    ];

    public function up(): void
    {
        $this->changeScale(6);
    }

    public function down(): void
    {
        $this->changeScale(2);
    }

    private function changeScale(int $scale): void
    {
        foreach (self::COLUMNS as $table => $column) {
            $current = $this->db->query(
                'SELECT NUMERIC_SCALE AS scale FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$this->db->prefixTable($table), $column],
            )->getRow();

            if ($current !== null && (int) $current->scale !== $scale) {
                $this->db->query('ALTER TABLE `' . $this->db->prefixTable($table) . "` MODIFY `{$column}` DECIMAL(15,{$scale}) NOT NULL");
            }
        }
    }
}
