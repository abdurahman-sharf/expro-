<?php

namespace Tests\Support;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\OSPOS;

/**
 * A document that a second connection has written and not committed yet, as a register saving a sale or a
 * purchase leaves it for a moment, while a report is built on the connection of the test. The reader waits
 * one second for a lock instead of fifty, so a read that would queue behind the writer fails at once.
 */
trait UncommittedWriterTrait
{
    protected const UNCOMMITTED_DAY = '2004-06-15';

    protected function whileASaleIsBeingSaved(int $employeeId, int $itemId, ?int $customerId, callable $read): mixed
    {
        $writer = Database::connect((new Database())->tests, false);

        $writer->transStart();
        $writer->table('sales')->insert(['sale_time' => self::UNCOMMITTED_DAY . ' 12:00:00', 'customer_id' => $customerId, 'employee_id' => $employeeId, 'comment' => '', 'sale_status' => COMPLETED, 'sale_type' => SALE_TYPE_POS, 'branch_id' => 1]);

        $saleId = (int) $writer->insertID();

        $writer->table('sales_items')->insert(['sale_id' => $saleId, 'item_id' => $itemId, 'line' => 1, 'quantity_purchased' => '1', 'item_cost_price' => '1', 'item_unit_price' => '10', 'item_location' => 1]);
        $writer->table('sales_items_taxes')->insert(['sale_id' => $saleId, 'item_id' => $itemId, 'line' => 1, 'name' => 'VAT', 'percent' => '15', 'tax_type' => 1, 'item_tax_amount' => '1.5']);
        $writer->table('sales_taxes')->insert(['sale_id' => $saleId, 'tax_type' => 1, 'tax_group' => 'VAT 15', 'sale_tax_basis' => '10', 'sale_tax_amount' => '1.5', 'name' => 'VAT', 'tax_rate' => '15']);
        $writer->table('sales_payments')->insert(['sale_id' => $saleId, 'payment_type' => 'Cash', 'payment_amount' => '11.5', 'cash_refund' => '0', 'employee_id' => $employeeId, 'reference_code' => '']);

        return $this->readingWithShortLockWait($writer, $read);
    }

    protected function whileAPurchaseIsBeingSaved(int $employeeId, int $itemId, callable $read): mixed
    {
        $writer = Database::connect((new Database())->tests, false);

        $writer->transStart();
        $writer->table('receivings')->insert(['receiving_time' => self::UNCOMMITTED_DAY . ' 12:00:00', 'supplier_id' => null, 'employee_id' => $employeeId, 'comment' => '', 'reference' => 'LOCK-1', 'payment_type' => null]);

        $receivingId = (int) $writer->insertID();

        $writer->table('receivings_items')->insert(['receiving_id' => $receivingId, 'item_id' => $itemId, 'line' => 1, 'quantity_purchased' => '1', 'item_cost_price' => '1', 'item_unit_price' => '10', 'item_location' => 1]);

        return $this->readingWithShortLockWait($writer, $read);
    }

    /**
     * The settings are loaded before the read, as they are when a request reaches a report: a report that meets them
     * unloaded reads them with a query of its own in the middle of building its own, which makes the query builder forget
     * the aliases of the one in the making.
     */
    private function readingWithShortLockWait(BaseConnection $writer, callable $read): mixed
    {
        config(OSPOS::class);

        $reader = Database::connect();

        $reader->query('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            return $read();
        } finally {
            $writer->transRollback();
            $writer->close();
            $reader->query('SET SESSION innodb_lock_wait_timeout = 50');
        }
    }
}
