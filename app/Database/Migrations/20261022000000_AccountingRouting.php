<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds what the routing screen and the sales branch need: the branch of every sale, the routing
 * module with its permission, and one routing row for each payment type. Additive only, and safe
 * to run again.
 */
class AccountingRouting extends Migration
{
    private const MODULE_ID   = 'accounting_routing';
    private const MODULE_SORT = 125;
    private const PERMISSION  = 'acc_routing_manage';

    /**
     * Payment type => the system account its routing row starts with. Due and gift card
     * payments have no row of their own: they follow the customers and gift card accounts.
     */
    private const PAYMENT_DEFAULTS = [
        'cash'          => 'cash',
        'debit'         => 'cash',
        'credit'        => 'cash',
        'check'         => 'cash',
        'bank_transfer' => 'cash',
        'upi'           => 'cash',
        'wallet'        => 'cash',
        'rewards'       => 'sales_discount',
    ];

    public function up(): void
    {
        $this->addSalesBranch();
        $this->registerModule();
        $this->grantConfigHolders();
        $this->seedPaymentRouting();
    }

    public function down(): void
    {
        $this->db->table('accounting_routing')->like('routing_key', 'payment.', 'after')->delete();

        $this->db->table('grants')->whereIn('permission_id', [self::MODULE_ID, self::PERMISSION])->delete();
        $this->db->table('permissions')->whereIn('permission_id', [self::MODULE_ID, self::PERMISSION])->delete();
        $this->db->table('modules')->where('module_id', self::MODULE_ID)->delete();

        if ($this->hasForeignKey('sales', 'sales_fk_branch')) {
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable('sales') . '` DROP FOREIGN KEY `' . $this->db->prefixTable('sales_fk_branch') . '`');
        }

        if ($this->hasIndex('sales', 'sales_branch')) {
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable('sales') . '` DROP INDEX `' . $this->db->prefixTable('sales_branch') . '`');
        }

        if ($this->hasColumn('sales', 'branch_id')) {
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable('sales') . '` DROP COLUMN `branch_id`');
        }
    }

    /**
     * Existing sales belong to the branch of the stock location they sold from, and to the
     * default branch when they have no line to tell.
     */
    private function addSalesBranch(): void
    {
        $sales = $this->db->prefixTable('sales');

        if (! $this->hasColumn('sales', 'branch_id')) {
            $this->db->query("ALTER TABLE `{$sales}` ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1");

            $this->db->query(
                "UPDATE `{$sales}` s
                 JOIN (
                     SELECT si.sale_id, MIN(sl.branch_id) AS branch_id
                     FROM `" . $this->db->prefixTable('sales_items') . '` si
                     JOIN `' . $this->db->prefixTable('stock_locations') . '` sl ON sl.location_id = si.item_location
                     GROUP BY si.sale_id
                 ) located ON located.sale_id = s.sale_id
                 SET s.branch_id = located.branch_id
                 WHERE s.branch_id <> located.branch_id',
            );
        }

        if (! $this->hasIndex('sales', 'sales_branch')) {
            $this->db->query("ALTER TABLE `{$sales}` ADD INDEX `" . $this->db->prefixTable('sales_branch') . '` (`branch_id`, `sale_time`)');
        }

        if (! $this->hasForeignKey('sales', 'sales_fk_branch')) {
            $this->db->query(
                "ALTER TABLE `{$sales}` ADD CONSTRAINT `" . $this->db->prefixTable('sales_fk_branch') . '` FOREIGN KEY (`branch_id`) REFERENCES `'
                . $this->db->prefixTable('branches') . '` (`branch_id`) ON DELETE RESTRICT',
            );
        }
    }

    private function registerModule(): void
    {
        $this->db->table('modules')->ignore(true)->insert([
            'module_id'     => self::MODULE_ID,
            'name_lang_key' => 'module_' . self::MODULE_ID,
            'desc_lang_key' => 'module_' . self::MODULE_ID . '_desc',
            'sort'          => self::MODULE_SORT,
        ]);

        $this->db->table('permissions')->ignore(true)->insertBatch([
            ['permission_id' => self::MODULE_ID, 'module_id' => self::MODULE_ID],
            ['permission_id' => self::PERMISSION, 'module_id' => self::MODULE_ID],
        ]);
    }

    /**
     * Whoever can change the application settings can open the screen. Changing the routing is
     * sensitive and goes to nobody here: an administrator grants it from the employee's permissions.
     */
    private function grantConfigHolders(): void
    {
        $grants = [];

        foreach ($this->db->table('grants')->select('person_id, menu_group')->where('permission_id', 'config')->get()->getResult() as $holder) {
            $grants[] = ['permission_id' => self::MODULE_ID, 'person_id' => $holder->person_id, 'menu_group' => $holder->menu_group];
        }

        if ($grants !== []) {
            $this->db->table('grants')->ignore(true)->insertBatch($grants);
        }
    }

    /**
     * A company that has a chart gets a row for each payment type pointing at the account the
     * chart names for it. A company without one gets them when its chart is installed.
     */
    private function seedPaymentRouting(): void
    {
        $companies = $this->db->table('accounting_routing')->distinct()->select('company_id')->get()->getResultArray();

        foreach (array_column($companies, 'company_id') as $companyId) {
            $accounts = $this->db->table('accounting_accounts')
                ->select('account_id, system_key')
                ->where('company_id', $companyId)
                ->where('system_key IS NOT NULL')
                ->get()
                ->getResultArray();

            $accountIds = array_column($accounts, 'account_id', 'system_key');
            $rows       = [];

            foreach (self::PAYMENT_DEFAULTS as $type => $systemKey) {
                $rows[] = [
                    'company_id'  => $companyId,
                    'routing_key' => "payment.{$type}",
                    'account_id'  => $accountIds[$systemKey] ?? null,
                ];
            }

            $this->db->table('accounting_routing')->ignore(true)->insertBatch($rows);
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->db->query(
            'SELECT COUNT(*) AS found FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable($table), $column],
        )->getRow()->found > 0;
    }

    private function hasIndex(string $table, string $index): bool
    {
        return $this->db->query(
            'SELECT COUNT(*) AS found FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$this->db->prefixTable($table), $this->db->prefixTable($index)],
        )->getRow()->found > 0;
    }

    private function hasForeignKey(string $table, string $name): bool
    {
        return $this->db->query(
            'SELECT COUNT(*) AS found FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$this->db->prefixTable($table), $this->db->prefixTable($name), 'FOREIGN KEY'],
        )->getRow()->found > 0;
    }
}
