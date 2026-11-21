<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * The VAT classes of the company (S, Z, E and O with the reason a supply carries no tax), their rates by the date
 * they take effect, the class of each item, and the class a line of a sale was sold under. The classes the
 * standard names are seeded with the standard rate's history (5% from 2018 and 15% from July 2020), and no item is
 * classified, so nothing is charged differently until the owner classifies an item and the Saudi mode is on.
 * Safe to run again; down() refuses once a line was sold under a class, since its documents need it.
 */
class VatClassification extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
    private const CLASSES       = [
        ['S', 'Standard rate', 'S', null, null, [['5.000', '2018-01-01', '2020-06-30'], ['15.000', '2020-07-01', null]]],
        ['Z-EXPORT-GOODS', 'Export of goods', 'Z', 'VATEX-SA-32', 'Export of goods', [['0.000', '2018-01-01', null]]],
        ['Z-EXPORT-SERVICES', 'Export of services', 'Z', 'VATEX-SA-33', 'Export of services', [['0.000', '2018-01-01', null]]],
        ['Z-MEDICINES', 'Medicines and medical equipment', 'Z', 'VATEX-SA-35', 'Medicines and medical equipment', [['0.000', '2018-01-01', null]]],
        ['Z-EDUCATION', 'Private education to a citizen', 'Z', 'VATEX-SA-EDU', 'Private education to citizen', [['0.000', '2018-01-01', null]]],
        ['Z-HEALTHCARE', 'Private healthcare to a citizen', 'Z', 'VATEX-SA-HEA', 'Private healthcare to citizen', [['0.000', '2018-01-01', null]]],
        ['E-FINANCIAL', 'Financial services', 'E', 'VATEX-SA-29', 'Financial services mentioned in Article 29 of the VAT Regulations', [['0.000', '2018-01-01', null]]],
        ['E-REAL-ESTATE', 'Real estate', 'E', 'VATEX-SA-30', 'Real estate transactions mentioned in Article 30 of the VAT Regulations', [['0.000', '2018-01-01', null]]],
        ['O-OUT-OF-SCOPE', 'Out of scope', 'O', 'VATEX-SA-OOS', 'Not subject to VAT', [['0.000', '2018-01-01', null]]],
    ];

    public function up(): void
    {
        $classes   = $this->db->prefixTable('vat_classes');
        $rates     = $this->db->prefixTable('vat_class_rates');
        $itemClass = $this->db->prefixTable('item_vat_classes');
        $lines     = $this->db->prefixTable('sales_items_vat');
        $companies = $this->db->prefixTable('companies');
        $items     = $this->db->prefixTable('items');
        $options   = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$classes}` (
                `class_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `code` VARCHAR(30) NOT NULL,
                `name` VARCHAR(100) NOT NULL,
                `category` CHAR(1) NOT NULL,
                `exemption_code` VARCHAR(20) NULL DEFAULT NULL,
                `exemption_text` VARCHAR(1000) NULL DEFAULT NULL,
                `tax_name` VARCHAR(255) NOT NULL,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `updated_by` INT(10) NULL DEFAULT NULL,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`class_id`),
                UNIQUE KEY `{$classes}_code` (`company_id`, `code`),
                CONSTRAINT `{$classes}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$rates}` (
                `rate_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `class_id` INT UNSIGNED NOT NULL,
                `percent` DECIMAL(6,3) NOT NULL,
                `valid_from` DATE NOT NULL,
                `valid_to` DATE NULL DEFAULT NULL,
                `created_by` INT(10) NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`rate_id`),
                UNIQUE KEY `{$rates}_start` (`class_id`, `valid_from`),
                CONSTRAINT `{$rates}_fk_class` FOREIGN KEY (`class_id`) REFERENCES `{$classes}` (`class_id`) ON DELETE RESTRICT
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$itemClass}` (
                `item_id` INT(10) NOT NULL,
                `class_id` INT UNSIGNED NOT NULL,
                `updated_by` INT(10) NULL DEFAULT NULL,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`item_id`),
                KEY `{$itemClass}_class` (`class_id`),
                CONSTRAINT `{$itemClass}_fk_item` FOREIGN KEY (`item_id`) REFERENCES `{$items}` (`item_id`) ON DELETE CASCADE,
                CONSTRAINT `{$itemClass}_fk_class` FOREIGN KEY (`class_id`) REFERENCES `{$classes}` (`class_id`) ON DELETE RESTRICT
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$lines}` (
                `sale_id` INT(10) NOT NULL,
                `item_id` INT(10) NOT NULL,
                `line` INT(3) NOT NULL,
                `class_id` INT UNSIGNED NULL DEFAULT NULL,
                `category` CHAR(1) NOT NULL,
                `percent` DECIMAL(6,3) NOT NULL,
                `exemption_code` VARCHAR(20) NULL DEFAULT NULL,
                `exemption_text` VARCHAR(1000) NULL DEFAULT NULL,
                PRIMARY KEY (`sale_id`, `item_id`, `line`),
                KEY `{$lines}_class` (`class_id`)
            ) {$options}",
        );

        $this->seed();
    }

    public function down(): void
    {
        if ($this->hasTable('sales_items_vat') && $this->db->table('sales_items_vat')->countAllResults() > 0) {
            throw new RuntimeException('Lines were sold under a VAT class; taking the migration down would drop what their documents need.');
        }

        foreach (['sales_items_vat', 'item_vat_classes', 'vat_class_rates', 'vat_classes'] as $table) {
            $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable($table) . '`');
        }
    }

    private function seed(): void
    {
        $setting = $this->db->table('app_config')->select('value')->where('key', 'default_tax_1_name')->get()->getRow();
        $taxName = trim((string) ($setting->value ?? '')) === '' ? 'VAT' : trim((string) $setting->value);

        foreach ($this->db->table('companies')->select('company_id')->get()->getResult() as $company) {
            foreach (self::CLASSES as [$code, $name, $category, $exemptionCode, $exemptionText, $rates]) {
                $known = $this->db->table('vat_classes')->where(['company_id' => (int) $company->company_id, 'code' => $code])->get()->getRow();

                if ($known !== null) {
                    continue;
                }

                $this->db->table('vat_classes')->insert([
                    'company_id'     => (int) $company->company_id,
                    'code'           => $code,
                    'name'           => $name,
                    'category'       => $category,
                    'exemption_code' => $exemptionCode,
                    'exemption_text' => $exemptionText,
                    'tax_name'       => $taxName,
                ]);

                $classId = (int) $this->db->insertID();

                foreach ($rates as [$percent, $from, $to]) {
                    $this->db->table('vat_class_rates')->insert(['class_id' => $classId, 'percent' => $percent, 'valid_from' => $from, 'valid_to' => $to]);
                }
            }
        }
    }

    private function hasTable(string $table): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->db->prefixTable($table)],
        )->getRow() !== null;
    }
}
