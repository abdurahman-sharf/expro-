<?php

namespace Tests\Support;

use Config\Database;

/**
 * Many taxed sales of one day, written straight into the tables, for the tests that check a report
 * still works when its working tables are larger than the memory table the server allows.
 */
trait BulkSalesFixtureTrait
{
    protected function insertTaxedSales(int $employeeId, int $itemId, int $locationId, string $day, int $sales, int $linesPerSale): void
    {
        $db    = Database::connect();
        $heads = [];
        $lines = [];
        $taxes = [];

        for ($index = 0; $index < $sales; $index++) {
            $heads[] = ['sale_time' => $day . ' 12:00:00', 'employee_id' => $employeeId, 'comment' => '', 'sale_status' => COMPLETED, 'sale_type' => SALE_TYPE_POS, 'branch_id' => 1];
        }

        $db->table('sales')->insertBatch($heads, null, 800);

        $firstId = (int) $db->table('sales')->selectMin('sale_id', 'first')->like('sale_time', $day, 'after')->get()->getRow()->first;

        for ($offset = 0; $offset < $sales; $offset++) {
            for ($line = 1; $line <= $linesPerSale; $line++) {
                $lines[] = ['sale_id' => $firstId + $offset, 'item_id' => $itemId, 'line' => $line, 'description' => '', 'serialnumber' => '', 'quantity_purchased' => '1', 'item_cost_price' => '1', 'item_unit_price' => '2', 'discount' => '0', 'discount_type' => PERCENT, 'item_location' => $locationId, 'print_option' => 0];
                $taxes[] = ['sale_id' => $firstId + $offset, 'item_id' => $itemId, 'line' => $line, 'name' => 'VAT', 'percent' => '15', 'tax_type' => 1, 'rounding_code' => 0, 'cascade_sequence' => 0, 'item_tax_amount' => '0.3'];
            }
        }

        $db->table('sales_items')->insertBatch($lines, null, 2000);
        $db->table('sales_items_taxes')->insertBatch($taxes, null, 2000);
    }

    protected function capTheMemoryTablesOfTheServer(int $bytes): void
    {
        Database::connect()->query('SET SESSION max_heap_table_size = ' . $bytes);
    }

    protected function releaseTheMemoryTablesOfTheServer(): void
    {
        Database::connect()->query('SET SESSION max_heap_table_size = ' . (16 * 1048576));
    }
}
