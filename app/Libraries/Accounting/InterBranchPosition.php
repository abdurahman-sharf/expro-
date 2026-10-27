<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * What each branch is owed by the other branches and what it owes them, from the two accounts
 * the transfers between branches meet in. Taken together the branches owe what they are owed, so
 * the two accounts offset to nothing across the company: a difference means a journal of one of
 * the accounts has no counterpart in the other branch. The accounts are those the routing names,
 * the system accounts, and any account a transfer was ever posted to, so changing the routing
 * later does not hide what was posted before.
 */
final class InterBranchPosition
{
    private BaseConnection $db;
    private RoutingResolver $resolver;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->resolver = new RoutingResolver($this->db);
    }

    /**
     * Every branch with its debit balance on the receivable account and its credit balance on the
     * payable account, as positive amounts, reversals included.
     *
     * @param list<int>|null $branchIds Null for every branch.
     *
     * @return list<object{branch_id: int, code: string, receivable: string, payable: string}>
     */
    public function byBranch(?array $branchIds = null): array
    {
        if ($branchIds === []) {
            return [];
        }

        [$receivable, $payable] = $this->accounts();

        $builder = $this->db->table('branches b')
            ->select('b.branch_id, b.code', false)
            ->where('b.company_id', CompanyContext::id())
            ->orderBy('b.branch_id', 'ASC');

        if ($branchIds !== null) {
            $builder->whereIn('b.branch_id', $branchIds);
        }

        $rows = [];

        foreach ($builder->get()->getResult() as $branch) {
            $rows[] = (object) [
                'branch_id'  => (int) $branch->branch_id,
                'code'       => (string) $branch->code,
                'receivable' => $this->net($receivable, (int) $branch->branch_id),
                'payable'    => bcsub('0', $this->net($payable, (int) $branch->branch_id), SaleAmounts::SCALE),
            ];
        }

        return $rows;
    }

    /**
     * What the branches are owed less what they owe, across the company: nothing, unless a
     * journal of the one account has no counterpart in the other.
     *
     * @param string|null $asOf The last day counted, none for everything in the books.
     */
    public function difference(?string $asOf = null): string
    {
        [$receivable, $payable] = $this->accounts();
        $owed                   = $this->net($receivable, null, $asOf);
        $owing                  = bcsub('0', $this->net($payable, null, $asOf), SaleAmounts::SCALE);

        return bcsub($owed, $owing, SaleAmounts::SCALE);
    }

    /**
     * @return array{list<int>, list<int>} The accounts of what is owed to a branch and of what it owes.
     */
    public function accounts(): array
    {
        return [
            $this->accountsOf(RoutingKey::InterBranchReceivable, [TreasuryTransfers::SOURCE, ReceivingPostingBuilder::SOURCE_STOCK_TRANSFER], InterBranchLegs::RECEIVABLE),
            $this->accountsOf(RoutingKey::InterBranchPayable, [TreasuryTransfers::SOURCE_RECEIPT, ReceivingPostingBuilder::SOURCE_TRANSFER_RECEIPT], InterBranchLegs::PAYABLE),
        ];
    }

    /**
     * @param list<string> $legSources The sources of the journals that put a transfer on the account.
     * @param string       $side       The side a settlement or a payment recorded the account on.
     *
     * @return list<int>
     */
    private function accountsOf(RoutingKey $key, array $legSources, string $side): array
    {
        $ids = [];

        try {
            $routed = $this->resolver->accountFor($key);
        } catch (PostingException) {
            $routed = null;
        }

        if ($routed !== null) {
            $ids[] = $routed;
        }

        $system = $this->db->table('accounting_accounts')->select('account_id')->where('company_id', CompanyContext::id())->where('system_key', (string) $key->defaultSystemKey())->get()->getRow();

        if ($system !== null) {
            $ids[] = (int) $system->account_id;
        }

        $used = $this->db->table('accounting_journal_lines l')
            ->select('l.account_id')
            ->distinct()
            ->join('accounting_journals j', 'j.journal_id = l.journal_id')
            ->join('accounting_accounts a', 'a.account_id = l.account_id')
            ->where('j.company_id', CompanyContext::id())
            ->whereIn('j.source_type', $legSources)
            ->where('a.entity_type IS NULL', null, false)
            ->get()
            ->getResultArray();

        foreach ($used as $row) {
            $ids[] = (int) $row['account_id'];
        }

        $recorded = $this->db->table('accounting_interbranch_legs')->select('account_id')->distinct()->where('company_id', CompanyContext::id())->where('side', $side)->get()->getResultArray();

        foreach ($recorded as $row) {
            $ids[] = (int) $row['account_id'];
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param list<int> $accountIds
     */
    private function net(array $accountIds, ?int $branchId, ?string $asOf = null): string
    {
        if ($accountIds === []) {
            return '0.0000';
        }

        $builder = $this->db->table('accounting_journal_lines l')
            ->select('COALESCE(SUM(l.debit), 0) - COALESCE(SUM(l.credit), 0) AS balance', false)
            ->join('accounting_journals j', 'j.journal_id = l.journal_id')
            ->where('j.company_id', CompanyContext::id())
            ->whereIn('l.account_id', $accountIds)
            ->whereIn('j.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value]);

        if ($branchId !== null) {
            $builder->where('j.branch_id', $branchId);
        }

        if ($asOf !== null) {
            $builder->where('j.journal_date <=', $asOf);
        }

        return bcadd((string) $builder->get()->getRow()->balance, '0', SaleAmounts::SCALE);
    }
}
