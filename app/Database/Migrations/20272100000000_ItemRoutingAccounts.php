<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * What the per-item routing types need: the account an item names for a routing row that takes the
 * accounts of products (the revenue of its sales and the returns of them), kept by item and routing key
 * in a table of its own so that the table of the items is not touched. An item that names nothing reads
 * as it always did, and a routing row keeps its type, so nothing changes until somebody sets a row to
 * per item and an item to an account. Only a table is added: no journal, no document and no balance is
 * touched. Safe to run again.
 */
class ItemRoutingAccounts extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';

    public function up(): void
    {
        $table    = $this->db->prefixTable('item_routing_accounts');
        $items    = $this->db->prefixTable('items');
        $accounts = $this->db->prefixTable('accounting_accounts');
        $options  = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `item_id` INT(10) NOT NULL,
                `routing_key` VARCHAR(64) NOT NULL,
                `account_id` INT UNSIGNED NOT NULL,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`item_id`, `routing_key`),
                KEY `{$table}_account` (`account_id`),
                CONSTRAINT `{$table}_fk_item` FOREIGN KEY (`item_id`) REFERENCES `{$items}` (`item_id`) ON DELETE CASCADE,
                CONSTRAINT `{$table}_fk_account` FOREIGN KEY (`account_id`) REFERENCES `{$accounts}` (`account_id`) ON DELETE RESTRICT
            ) {$options}",
        );
    }

    public function down(): void
    {
        $table = $this->db->prefixTable('item_routing_accounts');

        if ($this->hasTable($table) && $this->db->table('item_routing_accounts')->countAllResults() > 0) {
            throw new RuntimeException('Items name accounts for their routing rows; taking the migration down would drop them.');
        }

        $this->db->query("DROP TABLE IF EXISTS `{$table}`");
    }

    private function hasTable(string $table): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table],
        )->getRow() !== null;
    }
}
