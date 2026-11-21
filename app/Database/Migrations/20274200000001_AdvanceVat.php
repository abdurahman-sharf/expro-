<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * The VAT an advance of a customer carries under the Saudi rules, charged when it is received for the part of it
 * that no invoice takes at once, and what of it each invoice takes back when the advance pays it (R-04 §9.5). An
 * advance received before carries none. Safe to run again; down() refuses once an advance carries VAT.
 */
class AdvanceVat extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';

    public function up(): void
    {
        $vat     = $this->db->prefixTable('customer_advance_vat');
        $uses    = $this->db->prefixTable('customer_advance_vat_uses');
        $options = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$vat}` (
                `advance_id` INT UNSIGNED NOT NULL,
                `category` CHAR(1) NOT NULL DEFAULT 'S',
                `percent` DECIMAL(6,3) NOT NULL,
                `gross` DECIMAL(15,2) NOT NULL,
                `taxable` DECIMAL(15,2) NOT NULL,
                `vat` DECIMAL(15,2) NOT NULL,
                `calc_version` TINYINT UNSIGNED NOT NULL,
                `cancelled` TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (`advance_id`)
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$uses}` (
                `application_id` INT UNSIGNED NOT NULL,
                `advance_id` INT UNSIGNED NOT NULL,
                `sale_id` INT(10) NULL DEFAULT NULL,
                `gross` DECIMAL(15,2) NOT NULL,
                `taxable` DECIMAL(15,2) NOT NULL,
                `vat` DECIMAL(15,2) NOT NULL,
                `released` TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (`application_id`),
                KEY `{$uses}_advance` (`advance_id`, `released`),
                KEY `{$uses}_sale` (`sale_id`, `released`)
            ) {$options}",
        );
    }

    public function down(): void
    {
        if ($this->hasTable('customer_advance_vat') && $this->db->table('customer_advance_vat')->countAllResults() > 0) {
            throw new RuntimeException('Advances carry VAT under the Saudi rules; taking the migration down would lose what their journals and documents stand on.');
        }

        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable('customer_advance_vat_uses') . '`');
        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable('customer_advance_vat') . '`');
    }

    private function hasTable(string $table): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->db->prefixTable($table)],
        )->getRow() !== null;
    }
}
