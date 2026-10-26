<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The cost an item carried when a purchase was saved, to six decimals like the cost of the item it
 * is copied from, so the cost and the profit of a purchase in the reports are not rounded to two.
 * Additive only: every cost that is there keeps its value, and running it again changes nothing.
 */
class ReceivingCostPrecision extends Migration
{
    private const TABLE  = 'receivings_items';
    private const COLUMN = 'item_cost_price';

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
        $current = $this->db->query(
            'SELECT NUMERIC_SCALE AS scale FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable(self::TABLE), self::COLUMN],
        )->getRow();

        if ($current !== null && (int) $current->scale !== $scale) {
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable(self::TABLE) . '` MODIFY `' . self::COLUMN . "` DECIMAL(15,{$scale}) NOT NULL");
        }
    }
}
