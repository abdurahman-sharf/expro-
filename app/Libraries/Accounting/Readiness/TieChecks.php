<?php

namespace App\Libraries\Accounting\Readiness;

use App\Libraries\Accounting\CustomerLedger;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\InterBranchBalances;
use App\Libraries\Accounting\InterBranchPosition;
use App\Libraries\Accounting\JournalStatus;
use App\Libraries\Accounting\ReceivingPostingBuilder;
use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Accounting\StockLedger;
use App\Libraries\Accounting\SupplierLedger;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Accounting\TreasuryTransfers;
use App\Libraries\Organization\CompanyContext;

/**
 * What must add up: the account of every customer and supplier against their invoices, credit and
 * aging, the account of every treasury against what documents put on it, the account of every
 * stock location against its valued movements, and what the branches are owed against what they
 * owe. A difference is listed and makes the check fail: a manual journal on one of these accounts
 * shows up here too, until somebody has looked at it.
 */
final class TieChecks extends CheckGroup
{
    /**
     * The aging is asked for as of a day after every document: the check compares totals, and a document dated after today must still be counted in a bucket.
     */
    private const AS_OF = '9999-12-31';

    public function run(): array
    {
        $ledger = ['accounting_accounts.entity_type', 'accounting_journals', 'accounting_journal_lines'];

        return [
            $this->check('customer_ties', 'TIE-CUSTOMERS', 'Customer balances against invoices, credit and aging', [...$ledger, 'customer_credit_applications', 'customer_advances', 'customer_credit_refunds', 'customer_credit_refund_sources', 'sales.return_of_sale_id'], fn (): CheckResult => $this->customers()),
            $this->check('supplier_ties', 'TIE-SUPPLIERS', 'Supplier balances against purchases, credit and aging', [...$ledger, 'supplier_credit_applications', 'supplier_advances', 'supplier_credit_refunds', 'supplier_credit_refund_sources', 'receivings_payments', 'receivings.receiving_type'], fn (): CheckResult => $this->suppliers()),
            $this->check('treasury_ties', 'TIE-TREASURIES', 'Treasury balances against their accounts', [...$ledger, 'treasuries'], fn (): CheckResult => $this->treasuries()),
            $this->check('stock_ties', 'TIE-STOCK', 'Stock locations against their valued movements', [...$ledger, 'accounting_stock_entries', 'stock_locations', 'item_quantities'], fn (): CheckResult => $this->stock()),
            $this->check('interbranch_unattributed', 'WP7-PRE-DEPLOY-QUERY', 'Journals on the accounts between branches that no transfer accounts for', ['accounting_journals', 'accounting_journal_lines', 'accounting_accounts.system_key'], fn (): CheckResult => $this->unattributed()),
            $this->check('interbranch_ties', 'TIE-BRANCHES', 'What the branches are owed against what they owe', [...$ledger, 'branches', 'accounting_interbranch_legs', 'treasury_transfers.to_branch_id', 'receivings.to_branch_id'], fn (): CheckResult => $this->branches()),
        ];
    }

    private function customers(): CheckResult
    {
        $ledger = new CustomerLedger($this->db);
        $bad    = [];
        $ids    = $this->partyIds(EntityAccountService::CUSTOMER);

        foreach ($ids as $customerId) {
            $position = $ledger->position($customerId);
            $aged     = $this->agedTotal($position->aging(self::AS_OF));

            if ($position->agrees() && bccomp($aged, $position->open(), SaleAmounts::SCALE) === 0) {
                continue;
            }

            $bad[] = sprintf(
                'customer #%d (%s): the account holds %s, invoices less credit say %s, unexplained %s; aging adds up to %s against %s open',
                $customerId,
                $ledger->accountName($customerId),
                $position->accountBalance,
                $position->ledgerBalance(),
                $position->unclassified(),
                $aged,
                $position->open(),
            );
        }

        return $this->partyResult('customer', count($ids), $bad);
    }

    private function suppliers(): CheckResult
    {
        $ledger = new SupplierLedger($this->db);
        $bad    = [];
        $ids    = $this->partyIds(EntityAccountService::SUPPLIER);

        foreach ($ids as $supplierId) {
            $position = $ledger->position($supplierId);
            $aged     = $this->agedTotal($position->aging(self::AS_OF));

            if ($position->agrees() && bccomp($aged, $position->open(), SaleAmounts::SCALE) === 0) {
                continue;
            }

            $bad[] = sprintf(
                'supplier #%d (%s): the account holds %s, purchases less credit say %s, unexplained %s; aging adds up to %s against %s open',
                $supplierId,
                $ledger->accountName($supplierId),
                $position->accountBalance,
                $position->ledgerBalance(),
                $position->unclassified(),
                $aged,
                $position->open(),
            );
        }

        return $this->partyResult('supplier', count($ids), $bad);
    }

    /**
     * @param list<string> $bad
     */
    private function partyResult(string $kind, int $checked, array $bad): CheckResult
    {
        if ($bad === []) {
            return CheckResult::pass("Every {$kind} account with movements ties to its documents, its credit and its aging ({$checked} checked).", [], ['checked' => $checked, 'differences' => 0]);
        }

        return CheckResult::fail(count($bad) . " {$kind} account(s) do not tie to their documents, credit and aging ({$checked} checked).", $bad, ['checked' => $checked, 'differences' => count($bad)]);
    }

    /**
     * @return list<int>
     */
    private function partyIds(string $kind): array
    {
        $rows = $this->db->table('accounting_accounts a')
            ->select('a.entity_id')
            ->distinct()
            ->join('accounting_journal_lines l', 'l.account_id = a.account_id')
            ->where('a.company_id', CompanyContext::id())
            ->where('a.entity_type', $kind)
            ->orderBy('a.entity_id', 'ASC')
            ->get()
            ->getResultArray();

        return array_map('intval', array_column($rows, 'entity_id'));
    }

    /**
     * @param array<string, numeric-string> $buckets
     */
    private function agedTotal(array $buckets): string
    {
        $total = '0';

        foreach ($buckets as $amount) {
            $total = bcadd($total, $amount, SaleAmounts::SCALE);
        }

        return $total;
    }

    private function treasuries(): CheckResult
    {
        $service = new TreasuryService($this->db);
        $bad     = [];
        $checked = 0;

        foreach ($service->all() as $treasury) {
            $checked++;
            $position = $service->position((int) $treasury->treasury_id);

            if ($position->accountId === null) {
                $bad[] = "treasury {$treasury->treasury_id} ({$treasury->name}) has no account";
            } elseif (! $position->agrees()) {
                $bad[] = "treasury {$treasury->treasury_id} ({$treasury->name}): its account holds {$position->balance} and {$position->manual} of it was put there by hand";
            }
        }

        if ($bad === []) {
            return CheckResult::pass("Every treasury has an account and its balance is what documents put on it ({$checked} checked).", [], ['checked' => $checked, 'differences' => 0]);
        }

        return CheckResult::fail(count($bad) . " treasury account(s) do not tie to their balance ({$checked} checked).", $bad, ['checked' => $checked, 'differences' => count($bad)]);
    }

    private function stock(): CheckResult
    {
        $ledger = new StockLedger($this->db);
        $names  = [];

        foreach ($this->db->table('stock_locations')->select('location_id, location_name')->get()->getResult() as $location) {
            $names[(int) $location->location_id] = (string) $location->location_name;
        }

        $bad = [];

        foreach ($ledger->reconcile(array_keys($names)) as $row) {
            if (bccomp($row['unattributed'], '0', StockLedger::VALUE_SCALE) !== 0) {
                $bad[] = sprintf('location %d (%s): its account holds %s, its movements add up to %s, difference %s', $row['location_id'], $names[$row['location_id']], $row['account_balance'], $row['movements_value'], $row['unattributed']);
            }
        }

        foreach ($ledger->mismatchedJournals() as $row) {
            $bad[] = sprintf('journal #%d puts %s on the account of location %d while its stock movements add up to %s', $row['journal_id'], $row['journal'], $row['location_id'], $row['movements']);
        }

        $data = ['locations' => count($names), 'differences' => count($bad)];

        if ($bad === []) {
            return CheckResult::pass('Every stock location ties to its valued movements, and every journal agrees with its movements (' . count($names) . ' location(s)).', [], $data);
        }

        return CheckResult::fail(count($bad) . ' stock difference(s): the account of a location and the value of its movements, or a journal and its movements, do not agree.', $bad, $data);
    }

    private function unattributed(): CheckResult
    {
        $transfers = [TreasuryTransfers::SOURCE, TreasuryTransfers::SOURCE_RECEIPT, ReceivingPostingBuilder::SOURCE_STOCK_TRANSFER, ReceivingPostingBuilder::SOURCE_TRANSFER_RECEIPT];

        $builder = $this->db->table('accounting_journals j')
            ->select('j.branch_id, j.source_type, COUNT(DISTINCT j.journal_id) AS journals', false)
            ->join('accounting_journal_lines l', 'l.journal_id = j.journal_id')
            ->join('accounting_accounts a', 'a.account_id = l.account_id')
            ->where('j.company_id', CompanyContext::id())
            ->whereIn('a.system_key', ['branches_receivable', 'branches_payable'])
            ->whereIn('j.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
            ->whereNotIn('j.source_type', $transfers);

        if ($this->schema->hasTable('accounting_interbranch_legs')) {
            $builder->where('NOT EXISTS (SELECT 1 FROM ' . $this->db->prefixTable('accounting_interbranch_legs') . ' g WHERE g.journal_id = COALESCE(j.reverses_journal_id, j.journal_id))', null, false);
        }

        $rows = $builder->groupBy('j.branch_id, j.source_type')->orderBy('j.branch_id', 'ASC')->orderBy('j.source_type', 'ASC')->get()->getResult();

        if ($rows === []) {
            return CheckResult::pass('Every journal on the accounts between branches comes from a transfer, a settlement or a payment across branches.');
        }

        $total = array_sum(array_map(static fn (object $row): int => (int) $row->journals, $rows));

        return CheckResult::review(
            "{$total} journal(s) on the accounts between branches come from no transfer: they show as unattributed in the balances between branches and are never rewritten.",
            array_map(static fn (object $row): string => "branch {$row->branch_id}, source {$row->source_type}: {$row->journals} journal(s)", $rows),
            ['journals' => $total, 'groups' => count($rows)],
        );
    }

    private function branches(): CheckResult
    {
        $difference = (new InterBranchPosition($this->db))->difference();
        $report     = (new InterBranchBalances($this->db))->report();
        $findings   = [];

        if (bccomp($difference, '0', SaleAmounts::SCALE) !== 0) {
            $findings[] = "the accounts between branches offset to {$difference} across the company instead of nothing";
        }

        foreach ($report->rows as $pair) {
            if (! $pair->agrees) {
                $findings[] = sprintf('branch %s against branch %s: it is owed %s and owes %s, and the other branch records something else in its own journals', $pair->code, $pair->counterpartCode, $pair->receivable, $pair->payable);
            }
        }

        $data = ['difference' => $difference, 'pairs' => count($report->rows), 'unattributed_branches' => count($report->unattributed)];

        if ($findings === []) {
            return CheckResult::pass('The accounts between branches offset to nothing across the company and every pair of branches agrees (' . count($report->rows) . ' pair(s)).', [], $data);
        }

        return CheckResult::fail(count($findings) . ' difference(s) between what the branches are owed and what they owe.', $findings, $data);
    }
}
