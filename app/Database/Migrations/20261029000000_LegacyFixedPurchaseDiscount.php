<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A fixed discount on a purchase line used to be an amount for the whole line and is now an amount
 * for every unit. A line saved before that keeps its old meaning: it is marked as a fixed discount
 * for the line, so what it shows, what it totals and what it posts do not change. A line of one unit
 * means the same in both and is left alone. Running it again changes nothing, and what it marks is
 * what the marking would match again, so a document saved afterwards is never touched.
 */
class LegacyFixedPurchaseDiscount extends Migration
{
    public function up(): void
    {
        $this->db->query(
            'UPDATE `' . $this->db->prefixTable('receivings_items') . '` SET `discount_type` = ' . FIXED_LINE
            . ' WHERE `discount_type` = ' . FIXED . ' AND `discount` > 0 AND `quantity_purchased` * `receiving_quantity` <> 1',
        );
    }

    public function down(): void
    {
        $this->db->query(
            'UPDATE `' . $this->db->prefixTable('receivings_items') . '` SET `discount_type` = ' . FIXED . ' WHERE `discount_type` = ' . FIXED_LINE,
        );
    }
}
