<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * The document layer of electronic invoicing, a candidate that sends nothing anywhere: the counter of the
 * company with the hash of the last document it issued, the issued documents (stored once, with their XML and
 * its hash, never changed), the append-only record of what a transport did with a document, and the
 * identification and national address of a customer. The screens are registered as a module with the
 * permission to manage them, a sensitive one, and nothing is granted. Only tables, a module and permissions
 * are added: no journal, no document, no number and no balance is touched. Safe to run again, and down()
 * refuses once a document has been issued.
 */
class EInvoicing extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
    private const MODULE        = 'accounting_einvoicing';
    private const PERMISSION    = 'acc_einvoicing_manage';
    private const SORT          = 135;
    private const IDENTIFIERS   = "'CRN', 'MOM', 'MLS', 'SAG', '700', 'TIN', 'NAT', 'IQA', 'GCC', 'PAS', 'OTH'";

    public function up(): void
    {
        $this->createTables();
        $this->seedCounters();
        $this->registerModule();
    }

    public function down(): void
    {
        if ($this->hasTable('einvoice_documents') && $this->db->table('einvoice_documents')->countAllResults() > 0) {
            throw new RuntimeException('Documents have been issued; taking the migration down would drop them, and an issued document is never deleted.');
        }

        $this->db->table('grants')->whereIn('permission_id', [self::MODULE, self::PERMISSION])->delete();
        $this->db->table('permissions')->whereIn('permission_id', [self::MODULE, self::PERMISSION])->delete();
        $this->db->table('modules')->where('module_id', self::MODULE)->delete();

        foreach (['einvoice_transport_log', 'einvoice_documents', 'einvoice_counters', 'customer_einvoice_parties'] as $table) {
            $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable($table) . '`');
        }
    }

    private function createTables(): void
    {
        $companies = $this->db->prefixTable('companies');
        $branches  = $this->db->prefixTable('branches');
        $customers = $this->db->prefixTable('customers');
        $counters  = $this->db->prefixTable('einvoice_counters');
        $documents = $this->db->prefixTable('einvoice_documents');
        $log       = $this->db->prefixTable('einvoice_transport_log');
        $parties   = $this->db->prefixTable('customer_einvoice_parties');
        $options   = self::TABLE_OPTIONS;
        $codes     = self::IDENTIFIERS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$counters}` (
                `company_id` INT UNSIGNED NOT NULL,
                `last_icv` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `last_hash` VARCHAR(64) NOT NULL DEFAULT '',
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`company_id`),
                CONSTRAINT `{$counters}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$documents}` (
                `document_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `branch_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `source_type` VARCHAR(20) NOT NULL,
                `source_id` BIGINT UNSIGNED NOT NULL,
                `doc_kind` VARCHAR(12) NOT NULL,
                `subtype` VARCHAR(10) NOT NULL,
                `document_number` VARCHAR(127) NOT NULL,
                `uuid` CHAR(36) NOT NULL,
                `icv` BIGINT UNSIGNED NOT NULL,
                `previous_hash` VARCHAR(64) NOT NULL,
                `invoice_hash` VARCHAR(64) NOT NULL,
                `issue_date` DATE NOT NULL,
                `issue_time` TIME NOT NULL,
                `currency` CHAR(3) NOT NULL DEFAULT 'SAR',
                `tax_exclusive` DECIMAL(15,2) NOT NULL,
                `tax_amount` DECIMAL(15,2) NOT NULL,
                `tax_inclusive` DECIMAL(15,2) NOT NULL,
                `original_document_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                `reason` VARCHAR(1000) NOT NULL DEFAULT '',
                `xml` LONGTEXT NOT NULL,
                `qr_text` TEXT NULL,
                `signed` TINYINT(1) NOT NULL DEFAULT 0,
                `profile` VARCHAR(40) NOT NULL DEFAULT 'candidate',
                `created_by` INT(10) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`document_id`),
                UNIQUE KEY `{$documents}_source` (`company_id`, `source_type`, `source_id`),
                UNIQUE KEY `{$documents}_icv` (`company_id`, `icv`),
                UNIQUE KEY `{$documents}_uuid` (`uuid`),
                KEY `{$documents}_branch` (`company_id`, `branch_id`, `issue_date`),
                KEY `{$documents}_original` (`original_document_id`),
                CONSTRAINT `{$documents}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$documents}_fk_branch` FOREIGN KEY (`branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$documents}_fk_original` FOREIGN KEY (`original_document_id`) REFERENCES `{$documents}` (`document_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$documents}_source_kind` CHECK (`source_type` IN ('sale', 'sales_debit_note')),
                CONSTRAINT `{$documents}_kind` CHECK (`doc_kind` IN ('invoice', 'credit_note', 'debit_note')),
                CONSTRAINT `{$documents}_subtype` CHECK (`subtype` IN ('standard', 'simplified')),
                CONSTRAINT `{$documents}_counter` CHECK (`icv` > 0),
                CONSTRAINT `{$documents}_signed` CHECK (`signed` IN (0, 1)),
                CONSTRAINT `{$documents}_note_original` CHECK (`doc_kind` = 'invoice' OR `original_document_id` IS NOT NULL)
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$log}` (
                `log_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `document_id` BIGINT UNSIGNED NOT NULL,
                `transport` VARCHAR(30) NOT NULL,
                `status` VARCHAR(20) NOT NULL,
                `note` VARCHAR(500) NOT NULL DEFAULT '',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`log_id`),
                KEY `{$log}_document` (`document_id`, `log_id`),
                CONSTRAINT `{$log}_fk_document` FOREIGN KEY (`document_id`) REFERENCES `{$documents}` (`document_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$log}_status` CHECK (`status` IN ('not_submitted', 'reported', 'cleared', 'rejected'))
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$parties}` (
                `person_id` INT(10) NOT NULL,
                `identification_type` VARCHAR(3) NULL DEFAULT NULL,
                `identification_number` VARCHAR(60) NOT NULL DEFAULT '',
                `street` VARCHAR(255) NOT NULL DEFAULT '',
                `additional_street` VARCHAR(127) NOT NULL DEFAULT '',
                `building_number` VARCHAR(10) NOT NULL DEFAULT '',
                `additional_number` VARCHAR(10) NOT NULL DEFAULT '',
                `district` VARCHAR(127) NOT NULL DEFAULT '',
                `city` VARCHAR(127) NOT NULL DEFAULT '',
                `province` VARCHAR(127) NOT NULL DEFAULT '',
                `postal_code` VARCHAR(10) NOT NULL DEFAULT '',
                `country_code` CHAR(2) NOT NULL DEFAULT 'SA',
                `updated_by` INT(10) NULL,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`person_id`),
                CONSTRAINT `{$parties}_fk_customer` FOREIGN KEY (`person_id`) REFERENCES `{$customers}` (`person_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$parties}_identification` CHECK (`identification_type` IS NULL OR `identification_type` IN ({$codes}))
            ) {$options}",
        );
    }

    private function seedCounters(): void
    {
        $counters  = $this->db->prefixTable('einvoice_counters');
        $companies = $this->db->prefixTable('companies');

        $this->db->query("INSERT IGNORE INTO `{$counters}` (`company_id`) SELECT `company_id` FROM `{$companies}`");
    }

    private function registerModule(): void
    {
        $this->db->table('modules')->ignore(true)->insert([
            'module_id'     => self::MODULE,
            'name_lang_key' => 'module_' . self::MODULE,
            'desc_lang_key' => 'module_' . self::MODULE . '_desc',
            'sort'          => self::SORT,
        ]);

        $this->db->table('permissions')->ignore(true)->insertBatch([
            ['permission_id' => self::MODULE, 'module_id' => self::MODULE],
            ['permission_id' => self::PERMISSION, 'module_id' => self::MODULE],
        ]);
    }

    private function hasTable(string $table): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->db->prefixTable($table)],
        )->getRow() !== null;
    }
}
