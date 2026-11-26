<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Brings the chart of a company that already has one in line with the rule that the code of an account
 * starts with the code of its parent, for the accounts the default chart opened against it: the four
 * accounts under the creditors group take codes that start with the code of that group, and every
 * account under them follows, keeping what comes after the old code. The account of opening balances
 * moves from the current liabilities to the equity, where it is held until it is closed out.
 *
 * Only the code, and for the opening balances the parent and the type, of an account changes. The id of
 * every account stays, so no journal, no line, no routing row and no balance is touched. The code an
 * account had is kept in legacy_code, and down() puts every one of them back. An account whose new code
 * is taken is left as it is and noted in the log. Safe to run again.
 */
class ChartCodeHierarchy extends Migration
{
    private const TABLE = 'accounting_accounts';

    /**
     * The accounts the default chart opened under a group whose code theirs does not start with, by the
     * code they were given, with the code of that group.
     */
    private const MISPLACED = ['2211' => '211', '2212' => '211', '2213' => '211', '2214' => '211'];

    private const OPENING_KEY = 'opening_balance';

    public function up(): void
    {
        if (! $this->hasTable()) {
            return;
        }

        if (! $this->hasColumn()) {
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable(self::TABLE) . '` ADD COLUMN `legacy_code` VARCHAR(32) NULL DEFAULT NULL AFTER `code`');
        }

        foreach ($this->companies() as $companyId) {
            foreach (self::MISPLACED as $oldCode => $parentCode) {
                $this->bringUnderParent($companyId, (string) $oldCode, $parentCode);
            }

            $this->moveOpeningBalancesToEquity($companyId);
        }
    }

    public function down(): void
    {
        if (! $this->hasTable() || ! $this->hasColumn()) {
            return;
        }

        foreach ($this->companies() as $companyId) {
            $opening = $this->db->table(self::TABLE)->where(['company_id' => $companyId, 'system_key' => self::OPENING_KEY, 'account_type' => 'equity'])->where('legacy_code IS NOT NULL')->get()->getRow();

            if ($opening !== null) {
                $parent = $this->liabilityGroupOf($companyId, (string) $opening->legacy_code);

                if ($parent !== null) {
                    $this->db->table(self::TABLE)->where('account_id', $opening->account_id)->update(['parent_id' => $parent, 'account_type' => 'liability']);
                }
            }

            $recoded = $this->db->table(self::TABLE)->select('account_id, code, legacy_code')->where('company_id', $companyId)->where('legacy_code IS NOT NULL')->get()->getResult();
            $free    = [];

            foreach ($recoded as $account) {
                $taken = $this->db->table(self::TABLE)->where(['company_id' => $companyId, 'code' => $account->legacy_code])->whereNotIn('account_id', array_map(static fn (object $row): int => (int) $row->account_id, $recoded))->countAllResults() > 0;

                if ($taken) {
                    log_message('notice', "ChartCodeHierarchy left account {$account->account_id} at {$account->code}: its earlier code {$account->legacy_code} is taken.");

                    continue;
                }

                $free[] = $account;
            }

            foreach ($free as $account) {
                $this->db->table(self::TABLE)->where('account_id', $account->account_id)->update(['code' => 'x' . $account->account_id]);
            }

            foreach ($free as $account) {
                $this->db->table(self::TABLE)->where('account_id', $account->account_id)->update(['code' => $account->legacy_code]);
            }
        }

        $this->db->query('ALTER TABLE `' . $this->db->prefixTable(self::TABLE) . '` DROP COLUMN `legacy_code`');
    }

    /**
     * Gives the account the first free code that starts with the code of its parent and keeps its last
     * digit, then moves every account under it along.
     */
    private function bringUnderParent(int $companyId, string $oldCode, string $parentCode): void
    {
        $account = $this->db->table(self::TABLE . ' a')
            ->select('a.account_id, a.code')
            ->join(self::TABLE . ' p', 'p.account_id = a.parent_id')
            ->where(['a.company_id' => $companyId, 'a.code' => $oldCode, 'p.code' => $parentCode])
            ->get()
            ->getRow();

        if ($account === null) {
            return;
        }

        $newCode = $parentCode . substr($oldCode, -1);
        $subtree = $this->subtree((int) $account->account_id);
        $moves   = [(int) $account->account_id => $newCode];

        foreach ($subtree as $descendant) {
            if (str_starts_with((string) $descendant->code, $oldCode)) {
                $moves[(int) $descendant->account_id] = $newCode . substr((string) $descendant->code, strlen($oldCode));
            }
        }

        $taken = $this->db->table(self::TABLE)->where('company_id', $companyId)->whereIn('code', array_values($moves))->whereNotIn('account_id', array_keys($moves))->countAllResults() > 0;

        if ($taken || count(array_unique($moves)) !== count($moves)) {
            log_message('notice', "ChartCodeHierarchy left account {$oldCode} and the accounts under it as they are: a code they would take is used already.");

            return;
        }

        foreach ($moves as $accountId => $code) {
            $this->db->query(
                'UPDATE `' . $this->db->prefixTable(self::TABLE) . '` SET legacy_code = COALESCE(legacy_code, code), code = ? WHERE account_id = ?',
                [$code, $accountId],
            );
        }
    }

    private function moveOpeningBalancesToEquity(int $companyId): void
    {
        $opening = $this->db->table(self::TABLE)->where(['company_id' => $companyId, 'system_key' => self::OPENING_KEY, 'account_type' => 'liability'])->get()->getRow();
        $equity  = $this->db->table(self::TABLE)->where(['company_id' => $companyId, 'account_type' => 'equity', 'is_group' => 1])->where('parent_id IS NULL')->orderBy('code', 'ASC')->get()->getRow();

        if ($opening === null || $equity === null) {
            return;
        }

        foreach (range(3, 9) as $digit) {
            $code = $equity->code . $digit;

            if ($this->db->table(self::TABLE)->where(['company_id' => $companyId, 'code' => $code])->countAllResults() > 0) {
                continue;
            }

            $this->db->query(
                'UPDATE `' . $this->db->prefixTable(self::TABLE) . "` SET legacy_code = COALESCE(legacy_code, code), code = ?, parent_id = ?, account_type = 'equity' WHERE account_id = ?",
                [$code, (int) $equity->account_id, (int) $opening->account_id],
            );

            return;
        }

        log_message('notice', 'ChartCodeHierarchy found no free code for the opening balances under the equity, so it left them among the liabilities.');
    }

    /**
     * The liability group the code belonged under: the one whose code is the longest start of it.
     */
    private function liabilityGroupOf(int $companyId, string $code): ?int
    {
        for ($length = strlen($code) - 1; $length >= 1; $length--) {
            $group = $this->db->table(self::TABLE)->select('account_id')->where(['company_id' => $companyId, 'code' => substr($code, 0, $length), 'account_type' => 'liability', 'is_group' => 1])->get()->getRow();

            if ($group !== null) {
                return (int) $group->account_id;
            }
        }

        return null;
    }

    /**
     * @return list<object> Every account under the account, at any depth.
     */
    private function subtree(int $accountId): array
    {
        $found = [];
        $level = [$accountId];

        while ($level !== []) {
            $children = $this->db->table(self::TABLE)->select('account_id, code')->whereIn('parent_id', $level)->get()->getResult();
            $level    = array_map(static fn (object $row): int => (int) $row->account_id, $children);
            $found    = [...$found, ...$children];
        }

        return $found;
    }

    /**
     * @return list<int>
     */
    private function companies(): array
    {
        return array_map('intval', array_column($this->db->table(self::TABLE)->select('company_id')->distinct()->get()->getResultArray(), 'company_id'));
    }

    private function hasTable(): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->db->prefixTable(self::TABLE)],
        )->getRow() !== null;
    }

    private function hasColumn(): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable(self::TABLE), 'legacy_code'],
        )->getRow() !== null;
    }
}
