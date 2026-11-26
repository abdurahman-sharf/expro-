<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The routing row of what a purchase return gives back above or below what the goods are worth in the
 * stock, for a company that already has a chart. It starts on the account the difference went to until
 * now, the cost of sales, so nothing moves until somebody points the row elsewhere. Only the routing row
 * is added: no account, no journal and no balance is touched. Safe to run again.
 */
class PurchasePriceVarianceRouting extends Migration
{
    private const ROUTING_KEY = 'purchases.price_variance';
    private const SYSTEM_KEY  = 'cogs';

    public function up(): void
    {
        if (! $this->hasTable('accounting_routing') || $this->db->table('accounting_accounts')->countAllResults() === 0) {
            return;
        }

        if ($this->db->table('accounting_routing')->where('routing_key', self::ROUTING_KEY)->countAllResults() > 0) {
            return;
        }

        $current = $this->db->table('accounting_routing')->where('routing_key', 'sales.cogs')->get()->getRow();
        $account = $current !== null && $current->account_id !== null
            ? (int) $current->account_id
            : $this->db->table('accounting_accounts')->where('system_key', self::SYSTEM_KEY)->get()->getRow()?->account_id;

        $this->db->table('accounting_routing')->insert(['routing_key' => self::ROUTING_KEY, 'routing_type' => 'auto', 'account_id' => $account === null ? null : (int) $account]);
    }

    public function down(): void
    {
        if ($this->hasTable('accounting_routing')) {
            $this->db->table('accounting_routing')->where('routing_key', self::ROUTING_KEY)->delete();
        }
    }

    private function hasTable(string $table): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$this->db->prefixTable($table)],
        )->getRow() !== null;
    }
}
