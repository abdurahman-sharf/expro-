<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The tax identity of each company in one place: whether it is registered for VAT, its VAT number and the
 * Saudi mode. The number of the company is copied from the setting tax_id, which stays where it is and is read
 * as before; nobody is taken to be registered and the mode is off, so no installation behaves differently after
 * this runs. The permission that will manage the registration of the units with the authority is registered and
 * granted to nobody. Safe to run again; down() drops what up() added and leaves the number in the settings.
 */
class CompanyTaxProfile extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
    private const MODULE        = 'accounting_einvoicing';
    private const PERMISSION    = 'acc_einvoicing_onboard';

    public function up(): void
    {
        $profile   = $this->db->prefixTable('company_tax_profile');
        $companies = $this->db->prefixTable('companies');
        $options   = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$profile}` (
                `company_id` INT UNSIGNED NOT NULL,
                `vat_registered` TINYINT(1) NULL DEFAULT NULL,
                `vat_number` VARCHAR(64) NULL DEFAULT NULL,
                `ksa_mode` TINYINT(1) NOT NULL DEFAULT 0,
                `ksa_mode_effective_at` DATETIME NULL DEFAULT NULL,
                `ksa_mode_activated_at` DATETIME NULL DEFAULT NULL,
                `ksa_mode_activated_by` INT(10) NULL DEFAULT NULL,
                `updated_by` INT(10) NULL DEFAULT NULL,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`company_id`),
                CONSTRAINT `{$profile}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT
            ) {$options}",
        );

        $this->seedProfiles();
        $this->registerPermission();
    }

    public function down(): void
    {
        $this->db->table('grants')->where('permission_id', self::PERMISSION)->delete();
        $this->db->table('permissions')->where('permission_id', self::PERMISSION)->delete();
        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable('company_tax_profile') . '`');
    }

    private function seedProfiles(): void
    {
        $setting = $this->db->table('app_config')->select('value')->where('key', 'tax_id')->get()->getRow();
        $taxId   = trim((string) ($setting->value ?? ''));

        foreach ($this->db->table('companies')->select('company_id, tax_id')->get()->getResult() as $company) {
            $number = (int) $company->company_id === 1 ? $taxId : trim((string) ($company->tax_id ?? ''));

            $this->db->table('company_tax_profile')->ignore(true)->insert([
                'company_id' => (int) $company->company_id,
                'vat_number' => $number === '' ? null : $number,
            ]);
        }
    }

    private function registerPermission(): void
    {
        if ($this->db->table('modules')->where('module_id', self::MODULE)->countAllResults() === 0) {
            return;
        }

        $this->db->table('permissions')->ignore(true)->insert(['permission_id' => self::PERMISSION, 'module_id' => self::MODULE]);
    }
}
