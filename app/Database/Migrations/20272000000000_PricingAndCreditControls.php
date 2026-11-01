<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * What price lists and the controls on prices and on credit need: the price lists with the price of
 * an item in each, the list a customer is assigned and the list a sale was priced from, the credit
 * limit and the credit days of a customer, and the minimum price and the highest discount of an
 * item. Every column that is added may be empty or holds the value that changes nothing (no list, no
 * limit, no minimum, no ceiling), so an existing item, customer or sale reads as it did. The screen
 * of the price lists is registered as a module, and the two permissions that let an employee sell
 * under the controls are registered too; nothing is granted. Only tables, columns, a module and
 * permissions are added: no journal, no document and no balance is touched. Safe to run again.
 */
class PricingAndCreditControls extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
    private const MODULE        = 'price_lists';
    private const PERMISSIONS   = [
        'sales_price_override'  => 'sales',
        'sales_credit_override' => 'sales',
    ];

    public function up(): void
    {
        $this->createTables();
        $this->addColumns();
        $this->registerModule();
    }

    public function down(): void
    {
        $this->refuseWhileEntered();

        $permissionIds = [self::MODULE, ...array_keys(self::PERMISSIONS)];

        $this->db->table('grants')->whereIn('permission_id', $permissionIds)->delete();
        $this->db->table('permissions')->whereIn('permission_id', $permissionIds)->delete();
        $this->db->table('modules')->where('module_id', self::MODULE)->delete();

        foreach ([['customers', 'customers_fk_price_list'], ['sales', 'sales_fk_price_list']] as [$table, $constraint]) {
            $table = $this->db->prefixTable($table);
            $name  = $this->db->prefixTable($constraint);

            if ($this->hasForeignKey($table, $name)) {
                $this->db->query("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$name}`");
            }
        }

        foreach ([['customers', 'price_list_id'], ['customers', 'credit_limit'], ['customers', 'credit_period_days'], ['sales', 'price_list_id'], ['items', 'minimum_price'], ['items', 'max_discount_percent']] as [$table, $column]) {
            if ($this->hasColumn($table, $column)) {
                $this->db->query('ALTER TABLE `' . $this->db->prefixTable($table) . "` DROP COLUMN `{$column}`");
            }
        }

        foreach (['price_list_items', 'price_lists'] as $table) {
            $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable($table) . '`');
        }
    }

    private function refuseWhileEntered(): void
    {
        $entered = [
            'price_lists'          => $this->hasTable('price_lists') && $this->db->table('price_lists')->countAllResults() > 0,
            'customers.price_list' => $this->hasColumn('customers', 'price_list_id') && $this->db->table('customers')->where('price_list_id IS NOT NULL')->countAllResults() > 0,
            'customers.credit'     => $this->hasColumn('customers', 'credit_limit') && $this->db->table('customers')->groupStart()->where('credit_limit !=', 0)->orWhere('credit_period_days !=', 0)->groupEnd()->countAllResults() > 0,
            'sales.price_list'     => $this->hasColumn('sales', 'price_list_id') && $this->db->table('sales')->where('price_list_id IS NOT NULL')->countAllResults() > 0,
            'items.controls'       => $this->hasColumn('items', 'minimum_price') && $this->db->table('items')->groupStart()->where('minimum_price !=', 0)->orWhere('max_discount_percent IS NOT NULL')->groupEnd()->countAllResults() > 0,
        ];

        $names = array_keys(array_filter($entered));

        if ($names !== []) {
            throw new RuntimeException('The pricing and credit controls hold data (' . implode(', ', $names) . '); taking the migration down would drop it.');
        }
    }

    private function createTables(): void
    {
        $lists   = $this->db->prefixTable('price_lists');
        $prices  = $this->db->prefixTable('price_list_items');
        $items   = $this->db->prefixTable('items');
        $options = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$lists}` (
                `price_list_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(100) NOT NULL,
                `status` TINYINT(1) NOT NULL DEFAULT 1,
                `created_by` INT(10) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`price_list_id`),
                UNIQUE KEY `{$lists}_name` (`name`),
                CONSTRAINT `{$lists}_status` CHECK (`status` IN (0, 1))
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$prices}` (
                `price_list_id` INT UNSIGNED NOT NULL,
                `item_id` INT(10) NOT NULL,
                `price` DECIMAL(15,4) NOT NULL,
                PRIMARY KEY (`price_list_id`, `item_id`),
                KEY `{$prices}_item` (`item_id`),
                CONSTRAINT `{$prices}_fk_list` FOREIGN KEY (`price_list_id`) REFERENCES `{$lists}` (`price_list_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$prices}_fk_item` FOREIGN KEY (`item_id`) REFERENCES `{$items}` (`item_id`) ON DELETE CASCADE,
                CONSTRAINT `{$prices}_price` CHECK (`price` >= 0)
            ) {$options}",
        );
    }

    private function addColumns(): void
    {
        $customers   = $this->db->prefixTable('customers');
        $sales       = $this->db->prefixTable('sales');
        $items       = $this->db->prefixTable('items');
        $lists       = $this->db->prefixTable('price_lists');
        $customerKey = $this->db->prefixTable('customers_fk_price_list');
        $saleKey     = $this->db->prefixTable('sales_fk_price_list');

        if (! $this->hasColumn('customers', 'price_list_id')) {
            $this->db->query("ALTER TABLE `{$customers}` ADD COLUMN `price_list_id` INT UNSIGNED NULL DEFAULT NULL");
        }

        if (! $this->hasColumn('customers', 'credit_limit')) {
            $this->db->query("ALTER TABLE `{$customers}` ADD COLUMN `credit_limit` DECIMAL(15,4) NOT NULL DEFAULT 0");
        }

        if (! $this->hasColumn('customers', 'credit_period_days')) {
            $this->db->query("ALTER TABLE `{$customers}` ADD COLUMN `credit_period_days` SMALLINT UNSIGNED NOT NULL DEFAULT 0");
        }

        if (! $this->hasForeignKey($customers, $customerKey)) {
            $this->db->query("ALTER TABLE `{$customers}` ADD CONSTRAINT `{$customerKey}` FOREIGN KEY (`price_list_id`) REFERENCES `{$lists}` (`price_list_id`) ON DELETE RESTRICT");
        }

        if (! $this->hasColumn('sales', 'price_list_id')) {
            $this->db->query("ALTER TABLE `{$sales}` ADD COLUMN `price_list_id` INT UNSIGNED NULL DEFAULT NULL");
        }

        if (! $this->hasForeignKey($sales, $saleKey)) {
            $this->db->query("ALTER TABLE `{$sales}` ADD CONSTRAINT `{$saleKey}` FOREIGN KEY (`price_list_id`) REFERENCES `{$lists}` (`price_list_id`) ON DELETE RESTRICT");
        }

        if (! $this->hasColumn('items', 'minimum_price')) {
            $this->db->query("ALTER TABLE `{$items}` ADD COLUMN `minimum_price` DECIMAL(15,4) NOT NULL DEFAULT 0");
        }

        if (! $this->hasColumn('items', 'max_discount_percent')) {
            $this->db->query("ALTER TABLE `{$items}` ADD COLUMN `max_discount_percent` DECIMAL(5,2) NULL DEFAULT NULL");
        }
    }

    private function registerModule(): void
    {
        $this->db->table('modules')->ignore(true)->insert([
            'module_id'     => self::MODULE,
            'name_lang_key' => 'module_' . self::MODULE,
            'desc_lang_key' => 'module_' . self::MODULE . '_desc',
            'sort'          => 132,
        ]);

        $permissions = [['permission_id' => self::MODULE, 'module_id' => self::MODULE]];

        foreach (self::PERMISSIONS as $permissionId => $moduleId) {
            $permissions[] = ['permission_id' => $permissionId, 'module_id' => $moduleId];
        }

        $this->db->table('permissions')->ignore(true)->insertBatch($permissions);
    }

    private function hasTable(string $table): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->db->prefixTable($table)],
        )->getRow() !== null;
    }

    private function hasForeignKey(string $table, string $constraint): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$table, $constraint, 'FOREIGN KEY'],
        )->getRow() !== null;
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable($table), $column],
        )->getRow() !== null;
    }
}
