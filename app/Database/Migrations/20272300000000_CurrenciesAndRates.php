<?php

namespace App\Database\Migrations;

use App\Libraries\Accounting\CurrencyCatalog;
use CodeIgniter\Database\Migration;

/**
 * The currencies the books know, with the decimals each is kept in, and the rate of each to the base
 * currency by effective date, as the screens of the currencies need them. The catalog is put in
 * switched off, so that nothing is offered on a document until someone turns it on, and the base
 * currency of the books is put in switched on when it is already set. The screens are registered as
 * a module and the permission to change currencies and rates as a permission, and nothing is
 * granted. Only tables, rows of those tables, a module and permissions are added: no journal, no
 * document and no balance is touched. Safe to run again.
 */
class CurrenciesAndRates extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
    private const MODULE_ID     = 'accounting_currencies';
    private const PERMISSION    = 'acc_currencies_manage';
    private const SORT          = 132;

    public function up(): void
    {
        $currencies = $this->db->prefixTable('accounting_currencies');
        $rates      = $this->db->prefixTable('accounting_currency_rates');
        $options    = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$currencies}` (
                `currency_code` CHAR(3) NOT NULL,
                `name_ar` VARCHAR(100) NOT NULL DEFAULT '',
                `name_en` VARCHAR(100) NOT NULL DEFAULT '',
                `decimals` TINYINT UNSIGNED NOT NULL DEFAULT 2,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`currency_code`),
                CONSTRAINT `{$currencies}_decimals` CHECK (`decimals` IN (0, 2, 3))
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$rates}` (
                `rate_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `currency_code` CHAR(3) NOT NULL,
                `rate` DECIMAL(18,8) NOT NULL,
                `effective_date` DATE NOT NULL,
                `note` VARCHAR(255) NULL,
                `entered_by` INT(10) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`rate_id`),
                UNIQUE KEY `{$rates}_day` (`currency_code`, `effective_date`),
                CONSTRAINT `{$rates}_fk_currency` FOREIGN KEY (`currency_code`) REFERENCES `{$currencies}` (`currency_code`) ON DELETE RESTRICT,
                CONSTRAINT `{$rates}_positive` CHECK (`rate` > 0)
            ) {$options}",
        );

        $this->seedCatalog();
        $this->registerModule();
    }

    public function down(): void
    {
        $this->db->table('grants')->whereIn('permission_id', [self::MODULE_ID, self::PERMISSION])->delete();
        $this->db->table('permissions')->whereIn('permission_id', [self::MODULE_ID, self::PERMISSION])->delete();
        $this->db->table('modules')->where('module_id', self::MODULE_ID)->delete();

        foreach (['accounting_currency_rates', 'accounting_currencies'] as $table) {
            $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable($table) . '`');
        }
    }

    private function seedCatalog(): void
    {
        $base = (string) ($this->db->table('app_config')->select('value')->where('key', 'accounting_base_currency')->get()->getRow()->value ?? '');
        $rows = [];

        foreach (CurrencyCatalog::CURRENCIES as $code => [$english, $arabic, $decimals]) {
            $rows[] = ['currency_code' => $code, 'name_ar' => $arabic, 'name_en' => $english, 'decimals' => $decimals, 'active' => $code === $base ? 1 : 0];
        }

        $this->db->table('accounting_currencies')->ignore(true)->insertBatch($rows);

        if ($base !== '' && ! isset(CurrencyCatalog::CURRENCIES[$base]) && preg_match('/^[A-Z]{3}$/', $base) === 1) {
            $this->db->table('accounting_currencies')->ignore(true)->insert(['currency_code' => $base, 'name_ar' => $base, 'name_en' => $base, 'decimals' => 2, 'active' => 1]);
        }
    }

    private function registerModule(): void
    {
        $this->db->table('modules')->ignore(true)->insert([
            'module_id'     => self::MODULE_ID,
            'name_lang_key' => 'module_' . self::MODULE_ID,
            'desc_lang_key' => 'module_' . self::MODULE_ID . '_desc',
            'sort'          => self::SORT,
        ]);

        $this->db->table('permissions')->ignore(true)->insertBatch([
            ['permission_id' => self::MODULE_ID, 'module_id' => self::MODULE_ID],
            ['permission_id' => self::PERMISSION, 'module_id' => self::MODULE_ID],
        ]);
    }
}
