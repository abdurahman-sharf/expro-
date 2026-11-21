<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * What the second phase of electronic invoicing stands on, with no address of ZATCA in it (T9, §4.6 and §4.7):
 *
 * - the units that issue documents, one for the company to begin with, and the counter of each unit in each
 *   environment, the default unit taking over the counter of the company as it stands, to the figure;
 * - the certificates of each unit, whose secrets and keys are kept only encrypted;
 * - every attempt to send a document, kept as it was answered, and the cleared file of a tax invoice;
 * - the queue of the documents to be sent, a table of its own so that an issued document is never written to
 *   again: a claim and a lease so that two workers never send one at once. A document of the first phase is not
 *   queued. Each document names the unit that issued it, written when it is issued.
 *
 * Safe to run again; down() refuses once a document was queued or a certificate stored.
 */
class EInvoiceIntegration extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';

    public function up(): void
    {
        $options = self::TABLE_OPTIONS;
        $units   = $this->db->prefixTable('einvoice_units');
        $unitIcv = $this->db->prefixTable('einvoice_unit_counters');
        $creds   = $this->db->prefixTable('einvoice_credentials');
        $subs    = $this->db->prefixTable('einvoice_submissions');
        $cleared = $this->db->prefixTable('einvoice_cleared_documents');

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$units}` (
                `unit_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL,
                `branch_id` INT UNSIGNED NULL DEFAULT NULL,
                `name` VARCHAR(100) NOT NULL,
                `egs_serial` VARCHAR(127) NOT NULL DEFAULT '',
                `status` VARCHAR(16) NOT NULL DEFAULT 'draft',
                `is_default` TINYINT(1) NOT NULL DEFAULT 0,
                `created_by` INT(10) NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (`unit_id`),
                KEY `{$units}_company` (`company_id`, `branch_id`, `status`)
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$unitIcv}` (
                `unit_id` INT UNSIGNED NOT NULL,
                `environment` VARCHAR(16) NOT NULL,
                `last_icv` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `last_hash` VARCHAR(100) NOT NULL DEFAULT '',
                PRIMARY KEY (`unit_id`, `environment`)
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$creds}` (
                `credential_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `unit_id` INT UNSIGNED NOT NULL,
                `environment` VARCHAR(16) NOT NULL,
                `kind` VARCHAR(16) NOT NULL,
                `certificate` TEXT NULL,
                `secret_sealed` TEXT NULL,
                `token_sealed` TEXT NULL,
                `private_key_sealed` TEXT NULL,
                `request_id` VARCHAR(64) NOT NULL DEFAULT '',
                `valid_from` DATETIME NULL DEFAULT NULL,
                `valid_to` DATETIME NULL DEFAULT NULL,
                `status` VARCHAR(16) NOT NULL DEFAULT 'active',
                `created_by` INT(10) NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`credential_id`),
                KEY `{$creds}_unit` (`unit_id`, `environment`, `kind`, `status`)
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$subs}` (
                `submission_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `document_id` BIGINT UNSIGNED NOT NULL,
                `environment` VARCHAR(16) NOT NULL,
                `kind` VARCHAR(16) NOT NULL,
                `attempt_no` INT UNSIGNED NOT NULL,
                `claim_token` CHAR(32) NOT NULL,
                `http_status` SMALLINT UNSIGNED NULL DEFAULT NULL,
                `outcome` VARCHAR(24) NOT NULL,
                `warnings` TEXT NULL,
                `errors` TEXT NULL,
                `request_hash` CHAR(64) NOT NULL,
                `started_at` DATETIME NOT NULL,
                `finished_at` DATETIME NOT NULL,
                PRIMARY KEY (`submission_id`),
                KEY `{$subs}_document` (`document_id`, `attempt_no`)
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$cleared}` (
                `document_id` BIGINT UNSIGNED NOT NULL,
                `xml` LONGTEXT NOT NULL,
                `qr_text` TEXT NULL,
                `cleared_at` DATETIME NOT NULL,
                PRIMARY KEY (`document_id`)
            ) {$options}",
        );

        $outbox = $this->db->prefixTable('einvoice_outbox');

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$outbox}` (
                `document_id` BIGINT UNSIGNED NOT NULL,
                `company_id` INT UNSIGNED NOT NULL,
                `environment` VARCHAR(16) NOT NULL,
                `status` VARCHAR(24) NOT NULL,
                `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
                `next_attempt_at` DATETIME NULL DEFAULT NULL,
                `last_error_code` VARCHAR(255) NULL DEFAULT NULL,
                `claim_token` CHAR(32) NULL DEFAULT NULL,
                `lease_until` DATETIME NULL DEFAULT NULL,
                `queued_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`document_id`),
                KEY `{$outbox}_due` (`company_id`, `environment`, `status`, `next_attempt_at`)
            ) {$options}",
        );

        $documents = $this->db->prefixTable('einvoice_documents');

        if (! $this->hasColumn('einvoice_documents', 'unit_id')) {
            $this->db->query("ALTER TABLE `{$documents}` ADD COLUMN `unit_id` INT UNSIGNED NULL DEFAULT NULL");
        }

        $this->defaultUnits();
    }

    public function down(): void
    {
        if ($this->hasTable('einvoice_outbox') && $this->db->table('einvoice_outbox')->countAllResults() > 0) {
            throw new RuntimeException('Documents were queued to be sent; taking the migration down would lose where each of them stands.');
        }

        if ($this->hasTable('einvoice_credentials') && $this->db->table('einvoice_credentials')->countAllResults() > 0) {
            throw new RuntimeException('Certificates are stored; taking the migration down would lose them.');
        }

        if ($this->hasColumn('einvoice_documents', 'unit_id')) {
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable('einvoice_documents') . '` DROP COLUMN `unit_id`');
        }

        foreach (['einvoice_outbox', 'einvoice_cleared_documents', 'einvoice_submissions', 'einvoice_credentials', 'einvoice_unit_counters', 'einvoice_units'] as $table) {
            $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable($table) . '`');
        }
    }

    /**
     * One unit for each company that has none, which takes over the counter of the company as it stands: the chain
     * goes on, it does not start again (§4.6, the default).
     */
    private function defaultUnits(): void
    {
        $counters = $this->hasTable('einvoice_counters') ? $this->db->table('einvoice_counters')->get()->getResult() : [];

        foreach ($this->db->table('companies')->select('company_id')->get()->getResult() as $company) {
            $companyId = (int) $company->company_id;

            if ($this->db->table('einvoice_units')->where(['company_id' => $companyId, 'is_default' => 1])->countAllResults() > 0) {
                continue;
            }

            $this->db->table('einvoice_units')->insert(['company_id' => $companyId, 'name' => 'Main unit', 'status' => 'draft', 'is_default' => 1]);

            $unitId  = (int) $this->db->insertID();
            $counter = array_values(array_filter($counters, static fn (object $row): bool => (int) $row->company_id === $companyId))[0] ?? null;

            $this->db->table('einvoice_unit_counters')->insert([
                'unit_id'     => $unitId,
                'environment' => 'production',
                'last_icv'    => $counter === null ? 0 : (int) $counter->last_icv,
                'last_hash'   => $counter === null ? '' : (string) $counter->last_hash,
            ]);
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable($table), $column],
        )->getRow() !== null;
    }

    private function hasTable(string $table): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->db->prefixTable($table)],
        )->getRow() !== null;
    }
}
