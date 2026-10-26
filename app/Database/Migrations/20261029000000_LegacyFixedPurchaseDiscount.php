<?php

namespace App\Database\Migrations;

use App\Libraries\Accounting\LegacyPurchaseDiscount;
use CodeIgniter\Database\Migration;

/**
 * A fixed discount on a purchase line used to be an amount for the whole line and is now an amount
 * for every unit. A line saved before that keeps its old meaning: it is marked as a fixed discount
 * for the line, so what it shows, what it totals and what it posts do not change. A line of one unit
 * means the same in both and is left alone.
 *
 * The migration marks only what it can show was saved the old way (see {@see LegacyPurchaseDiscount})
 * and stops, changing nothing, while a line is left that it cannot tell apart: the operator looks at
 * them with "php spark accounting:purchase_discount" and decides with its "resolve" action. Running
 * it again marks nothing it already marked, and a document saved afterwards is never touched.
 */
class LegacyFixedPurchaseDiscount extends Migration
{
    public function up(): void
    {
        $marked = (new LegacyPurchaseDiscount($this->db))->apply();

        log_message('notice', "LegacyFixedPurchaseDiscount marked {$marked} purchase line(s) as a fixed discount for the whole line.");
    }

    public function down(): void
    {
        $this->db->query(
            'UPDATE `' . $this->db->prefixTable('receivings_items') . '` SET `discount_type` = ' . FIXED . ' WHERE `discount_type` = ' . FIXED_LINE,
        );
    }
}
