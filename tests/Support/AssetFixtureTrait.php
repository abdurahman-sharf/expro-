<?php

namespace Tests\Support;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Assets\AssetCategories;
use App\Libraries\Assets\AssetRegister;
use App\Libraries\Assets\DepreciationRun;
use CodeIgniter\Events\Events;

/**
 * What a test needs to enter assets and depreciate them through the real services: an employee who
 * changes the register and one who runs the depreciation, a treasury of the main branch and the
 * services. A class that uses it also uses AccountingFixtureTrait.
 */
trait AssetFixtureTrait
{
    protected AssetRegister $register;
    protected DepreciationRun $depreciation;
    protected AssetCategories $categories;
    protected int $assetClerk;
    protected int $assetRunner;
    protected int $assetTreasury;

    protected function clearAssets(): void
    {
        $db = $this->accountingDb();
        $this->releaseOpenTransactions();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach (['asset_usage', 'asset_operations', 'asset_depreciations', 'asset_depreciation_runs', 'asset_units', 'assets', 'asset_categories'] as $table) {
            $db->table($table)->truncate();
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
        $db->table('document_numbers')->where('doc_type', 'fixed_asset')->delete();
        $db->table('document_sequences')->where('doc_type', 'fixed_asset')->delete();
    }

    protected function startAssets(): void
    {
        $this->setConfigValue('accounting_base_currency', 'SAR');

        $this->register     = new AssetRegister();
        $this->depreciation = new DepreciationRun();
        $this->categories   = new AssetCategories();
        $this->assetClerk   = $this->employeeWithGrants([AccountingGuard::ASSETS_MANAGE, AccountingGuard::TREASURIES_MANAGE]);
        $this->assetRunner  = $this->employeeWithGrants([AccountingGuard::ASSETS_DEPRECIATE]);

        $this->assetTreasury = (new TreasuryService())->create(['name' => 'Asset cash ' . uniqid(), 'kind' => 'cash', 'branch_id' => 1], $this->assetClerk);
    }

    /**
     * The form of an asset bought for 12,000 from the treasury, depreciated by 1,000 a month from the month after it
     * went into service, and what a test changes of it.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    protected function assetInput(array $overrides = []): array
    {
        return [
            'name'              => 'Office desk',
            'code'              => '',
            'branch_id'         => 1,
            'purchase_date'     => '2026-01-01',
            'in_service_date'   => '2026-01-01',
            'cost'              => '12000',
            'salvage'           => '0',
            'method'            => 'fixed',
            'period_length'     => '1',
            'period_interval'   => 'month',
            'amount_per_period' => '1000',
            'funding'           => 'treasury',
            'treasury_id'       => $this->assetTreasury,
            ...$overrides,
        ];
    }

    protected function enterAsset(array $overrides = [], ?int $employeeId = null): int
    {
        return $this->register->create($this->assetInput($overrides), $employeeId ?? $this->assetClerk);
    }

    /**
     * A depreciation run holds the lock of a branch before it reads the assets of the branch, so whatever
     * changes an asset takes the shared lock of the branch before it locks or writes the asset.
     */
    protected function assertTakesTheBranchLockBeforeTheAsset(callable $action): void
    {
        $assets  = preg_quote($this->accountingDb()->prefixTable('assets'), '/');
        $lockAt  = null;
        $assetAt = null;
        $index   = 0;

        $listener = static function ($query) use (&$lockAt, &$assetAt, &$index, $assets): void {
            $sql = (string) $query->getQuery();

            if ($lockAt === null && preg_match('/accounting_period_locks WHERE company_id = \d+ AND branch_id = [1-9]\d* LOCK IN SHARE MODE/', $sql) === 1) {
                $lockAt = $index;
            }

            if ($assetAt === null && preg_match('/(INSERT INTO `?' . $assets . '`? |FROM ' . $assets . ' WHERE .*FOR UPDATE)/', $sql) === 1) {
                $assetAt = $index;
            }

            $index++;
        };

        Events::on('DBQuery', $listener);

        try {
            $action();
        } finally {
            Events::removeListener('DBQuery', $listener);
        }

        $this->assertNotNull($lockAt, 'the shared lock of the branch is taken');
        $this->assertNotNull($assetAt, 'the asset is locked or written');
        $this->assertLessThan($assetAt, $lockAt, 'and it is taken first');
    }

    /**
     * @return array{debit: string, credit: string}
     */
    protected function accountTotals(int $accountId, ?int $branchId = null): array
    {
        $journals = $this->accountingDb()->prefixTable('accounting_journals');
        $lines    = $this->accountingDb()->prefixTable('accounting_journal_lines');

        $row = $this->accountingDb()->query(
            "SELECT COALESCE(SUM(l.debit), 0) AS debit, COALESCE(SUM(l.credit), 0) AS credit FROM {$lines} l JOIN {$journals} j ON j.journal_id = l.journal_id WHERE l.account_id = ? AND j.status IN ('posted', 'reversed')" . ($branchId === null ? '' : ' AND j.branch_id = ?'),
            $branchId === null ? [$accountId] : [$accountId, $branchId],
        )->getRow();

        return ['debit' => bcadd((string) $row->debit, '0', 4), 'credit' => bcadd((string) $row->credit, '0', 4)];
    }

    protected function accountNet(int $accountId, ?int $branchId = null): string
    {
        $totals = $this->accountTotals($accountId, $branchId);

        return bcsub($totals['debit'], $totals['credit'], 4);
    }

    /**
     * @return list<object> The lines of the journal, by line number.
     */
    protected function journalLines(int $journalId): array
    {
        return $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $journalId)->orderBy('line_no', 'ASC')->get()->getResult();
    }

    protected function journalOf(string $source, int $sourceId, int $revision = 1): ?object
    {
        return $this->accountingDb()->table('accounting_journals')->where(['source_type' => $source, 'source_id' => $sourceId, 'revision' => $revision])->get()->getRow();
    }

    protected function treasuryAccount(int $treasuryId): int
    {
        return (int) $this->accountingDb()->table('accounting_accounts')->where(['entity_type' => 'treasury', 'entity_id' => $treasuryId])->get()->getRow()->account_id;
    }
}
