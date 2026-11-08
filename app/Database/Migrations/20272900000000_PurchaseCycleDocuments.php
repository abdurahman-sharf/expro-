<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The purchase cycle documents: the purchase request, the quotation request, the supplier quotation and
 * the purchase order, each in a table of its own with its lines, and for the order the quantity every
 * receiving took from every line, so that an order is received once and no more than it holds. None of
 * them posts or moves anything. Four modules and their permissions are registered and nothing is granted.
 * Additive only: new tables, no existing table is altered. Safe to run again.
 */
class PurchaseCycleDocuments extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';

    /**
     * Per document: the table, the id column, the supplier column kind ('none', 'optional' or 'required'),
     * the table and column it was made from, and the table of its lines.
     */
    private const DOCUMENTS = [
        'purchase_requests'   => ['id' => 'request_id', 'lines' => 'purchase_request_lines', 'supplier' => 'none', 'from' => null],
        'quotation_requests'  => ['id' => 'rfq_id', 'lines' => 'quotation_request_lines', 'supplier' => 'optional', 'from' => ['purchase_requests', 'request_id', 'source_request_id']],
        'supplier_quotations' => ['id' => 'quotation_id', 'lines' => 'supplier_quotation_lines', 'supplier' => 'required', 'from' => ['quotation_requests', 'rfq_id', 'source_rfq_id']],
        'purchase_orders'     => ['id' => 'order_id', 'lines' => 'purchase_order_lines', 'supplier' => 'required', 'from' => ['supplier_quotations', 'quotation_id', 'source_quotation_id']],
    ];

    private const MODULES = [
        'purchase_requests'   => [150, ['purchase_requests', 'pr_add', 'pr_edit', 'pr_delete', 'pr_view_all', 'pr_convert']],
        'quotation_requests'  => [151, ['quotation_requests', 'qr_add', 'qr_edit', 'qr_delete', 'qr_view_all', 'qr_convert']],
        'supplier_quotations' => [152, ['supplier_quotations', 'sq_add', 'sq_edit', 'sq_delete', 'sq_view_all', 'sq_convert']],
        'purchase_orders'     => [153, ['purchase_orders', 'po_add', 'po_edit', 'po_delete', 'po_view_all', 'po_convert']],
    ];

    public function up(): void
    {
        foreach (self::DOCUMENTS as $table => $document) {
            $this->createDocument($table, $document);
        }

        $this->createReceivings();

        foreach (self::MODULES as $moduleId => [$sort, $permissions]) {
            $this->db->table('modules')->ignore(true)->insert([
                'module_id'     => $moduleId,
                'name_lang_key' => 'module_' . $moduleId,
                'desc_lang_key' => 'module_' . $moduleId . '_desc',
                'sort'          => $sort,
            ]);

            $this->db->table('permissions')->ignore(true)->insertBatch(array_map(static fn (string $permission): array => ['permission_id' => $permission, 'module_id' => $moduleId], $permissions));
        }
    }

    public function down(): void
    {
        $all = [];

        foreach (self::MODULES as [$sort, $permissions]) {
            array_push($all, ...$permissions);
        }

        $this->db->table('permissions')->whereIn('permission_id', $all)->delete();
        $this->db->table('modules')->whereIn('module_id', array_keys(self::MODULES))->delete();

        $tables = ['purchase_order_receiving_lines', 'purchase_order_receivings'];

        foreach (array_reverse(self::DOCUMENTS, true) as $table => $document) {
            $tables[] = $document['lines'];
            $tables[] = $table;
        }

        foreach ($tables as $table) {
            $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable($table) . '`');
        }
    }

    /**
     * @param array{id: string, lines: string, supplier: string, from: array{0: string, 1: string, 2: string}|null} $document
     */
    private function createDocument(string $table, array $document): void
    {
        $name      = $this->db->prefixTable($table);
        $lines     = $this->db->prefixTable($document['lines']);
        $companies = $this->db->prefixTable('companies');
        $branches  = $this->db->prefixTable('branches');
        $stock     = $this->db->prefixTable('stock_locations');
        $suppliers = $this->db->prefixTable('suppliers');
        $items     = $this->db->prefixTable('items');
        $options   = self::TABLE_OPTIONS;
        $id        = $document['id'];

        $supplierColumn = match ($document['supplier']) {
            'optional' => '`supplier_id` INT(10) NULL,',
            'required' => '`supplier_id` INT(10) NOT NULL,',
            default    => '',
        };
        $supplierKey = $document['supplier'] === 'none' ? '' : "KEY `{$name}_supplier` (`supplier_id`, `status`), CONSTRAINT `{$name}_fk_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `{$suppliers}` (`person_id`) ON DELETE RESTRICT,";

        $fromColumn = '';
        $fromKey    = '';

        if ($document['from'] !== null) {
            [$fromTable, $fromId, $fromName] = $document['from'];
            $parent                          = $this->db->prefixTable($fromTable);
            $fromColumn                      = "`{$fromName}` INT UNSIGNED NULL,";
            $fromKey                         = "KEY `{$name}_source` (`{$fromName}`), CONSTRAINT `{$name}_fk_source` FOREIGN KEY (`{$fromName}`) REFERENCES `{$parent}` (`{$fromId}`) ON DELETE RESTRICT,";
        }

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$name}` (
                `{$id}` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `branch_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `location_id` INT(11) NOT NULL,
                {$supplierColumn}
                {$fromColumn}
                `document_date` DATE NOT NULL,
                `due_date` DATE NULL,
                `status` VARCHAR(10) NOT NULL DEFAULT 'open',
                `note` VARCHAR(500) NOT NULL DEFAULT '',
                `created_by` INT(10) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_by` INT(10) NULL,
                `updated_at` DATETIME NULL,
                `cancelled_by` INT(10) NULL,
                `cancelled_at` DATETIME NULL,
                `cancel_reason` VARCHAR(255) NOT NULL DEFAULT '',
                PRIMARY KEY (`{$id}`),
                KEY `{$name}_branch` (`company_id`, `branch_id`, `document_date`),
                {$supplierKey}
                {$fromKey}
                CONSTRAINT `{$name}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$name}_fk_branch` FOREIGN KEY (`branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$name}_fk_location` FOREIGN KEY (`location_id`) REFERENCES `{$stock}` (`location_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$name}_status` CHECK (`status` IN ('open', 'sent', 'approved', 'cancelled'))
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$lines}` (
                `line_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `{$id}` INT UNSIGNED NOT NULL,
                `line_no` SMALLINT UNSIGNED NOT NULL,
                `item_id` INT(10) NOT NULL,
                `description` VARCHAR(255) NOT NULL DEFAULT '',
                `quantity` DECIMAL(15,3) NOT NULL,
                `unit_price` DECIMAL(15,2) NOT NULL DEFAULT 0,
                PRIMARY KEY (`line_id`),
                UNIQUE KEY `{$lines}_line` (`{$id}`, `line_no`),
                KEY `{$lines}_item` (`item_id`),
                CONSTRAINT `{$lines}_fk_document` FOREIGN KEY (`{$id}`) REFERENCES `{$name}` (`{$id}`) ON DELETE RESTRICT,
                CONSTRAINT `{$lines}_fk_item` FOREIGN KEY (`item_id`) REFERENCES `{$items}` (`item_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$lines}_figures` CHECK (`quantity` > 0 AND `unit_price` >= 0)
            ) {$options}",
        );
    }

    private function createReceivings(): void
    {
        $links     = $this->db->prefixTable('purchase_order_receivings');
        $taken     = $this->db->prefixTable('purchase_order_receiving_lines');
        $orders    = $this->db->prefixTable('purchase_orders');
        $lines     = $this->db->prefixTable('purchase_order_lines');
        $receiving = $this->db->prefixTable('receivings');
        $options   = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$links}` (
                `receiving_id` INT(10) NOT NULL,
                `order_id` INT UNSIGNED NOT NULL,
                `linked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`receiving_id`),
                KEY `{$links}_order` (`order_id`),
                CONSTRAINT `{$links}_fk_receiving` FOREIGN KEY (`receiving_id`) REFERENCES `{$receiving}` (`receiving_id`) ON DELETE CASCADE,
                CONSTRAINT `{$links}_fk_order` FOREIGN KEY (`order_id`) REFERENCES `{$orders}` (`order_id`) ON DELETE RESTRICT
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$taken}` (
                `receiving_id` INT(10) NOT NULL,
                `order_line_id` INT UNSIGNED NOT NULL,
                `quantity` DECIMAL(15,3) NOT NULL,
                PRIMARY KEY (`receiving_id`, `order_line_id`),
                KEY `{$taken}_line` (`order_line_id`),
                CONSTRAINT `{$taken}_fk_link` FOREIGN KEY (`receiving_id`) REFERENCES `{$links}` (`receiving_id`) ON DELETE CASCADE,
                CONSTRAINT `{$taken}_fk_line` FOREIGN KEY (`order_line_id`) REFERENCES `{$lines}` (`line_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$taken}_quantity` CHECK (`quantity` > 0)
            ) {$options}",
        );
    }
}
