<?php

namespace Tests\Support\Ledger;

use App\Libraries\Accounting\ClosedPeriodService;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\ReceivingLineEdit;

/**
 * What accountants and clerks do to documents and books after the fact: manual journals and their reversal,
 * closing and reopening a period, editing the lines of a purchase, confirming a delivery, restoring a cancelled
 * sale.
 */
trait LedgerBooksOperations
{
    /** @var array<int, string> */
    private array $journalBook = [];

    private function resetBooks(): void
    {
        $this->journalBook = [];
    }

    private function operationManualJournal(LedgerRandom $random): string
    {
        $accounts = $this->db->query(
            "SELECT account_id FROM {$this->db->prefixTable('accounting_accounts')} WHERE is_group = 0 AND active = 1 AND entity_type IS NULL AND account_type IN ('expense', 'revenue', 'equity', 'liability') AND (system_key IS NULL OR system_key NOT IN ('tax_output', 'tax_input', 'payables', 'receivables', 'counter_customers', 'cogs', 'opening_balance', 'retained_earnings', 'branches_receivable', 'branches_payable'))",
        )->getResultArray();
        $pool     = array_map(static fn (array $row): int => (int) $row['account_id'], $accounts);
        $debit    = $random->pick($pool);
        $credit   = $random->pick($pool);
        $amount   = $random->money(500000);
        $date     = date('Y-m-d', strtotime('-' . $random->int(0, 40) . ' days'));
        $branchId = $random->pick($this->world->branches);
        $split    = $random->chance(30);

        $lines = [['account_id' => $debit, 'debit' => $amount, 'credit' => '']];

        if ($split) {
            $first   = bcmul($amount, '0.3333', 2);
            $lines[] = ['account_id' => $credit, 'debit' => '', 'credit' => $first];
            $lines[] = ['account_id' => $random->pick($pool), 'debit' => '', 'credit' => bcsub($amount, $first, 2)];
        } else {
            $lines[] = ['account_id' => $credit, 'debit' => '', 'credit' => $amount];
        }

        $this->note(sprintf('manual journal branch=%d %s debit %d credit %s on %s', $branchId, $amount, $debit, $split ? 'split' : (string) $credit, $date));

        $journalId = (new JournalService())->postNew(['date' => $date, 'description' => 'ledger manual', 'branch_id' => $branchId, 'lines' => $lines], $this->world->boss);

        $this->journalBook[$journalId] = 'posted';

        return sprintf('manual journal %d branch=%d %s debit %d credit %s on %s', $journalId, $branchId, $amount, $debit, $split ? 'split' : (string) $credit, $date);
    }

    private function operationJournalReverse(LedgerRandom $random): string
    {
        $candidates = array_keys(array_filter($this->journalBook, static fn (string $status): bool => $status === 'posted'));

        if ($candidates === []) {
            return $this->operationManualJournal($random);
        }

        $journalId = $random->pick($candidates);
        $date      = date('Y-m-d');

        $this->note(sprintf('reverse manual journal %d on %s', $journalId, $date));

        $reversal = (new JournalService())->reverse($journalId, $date, 'ledger', $this->world->boss);

        $this->journalBook[$journalId] = 'reversed';

        return sprintf('reverse manual journal %d -> %d on %s', $journalId, $reversal, $date);
    }

    private function operationPeriodClose(LedgerRandom $random): string
    {
        $service  = new ClosedPeriodService();
        $branchId = $random->chance(60) ? null : $random->pick($this->world->branches);
        $date     = date('Y-m-d', strtotime('-' . $random->int(20, 80) . ' days'));

        $this->note(sprintf('close period of %s through %s', $branchId === null ? 'the company' : 'branch ' . $branchId, $date));

        $service->close($branchId, $date, 'ledger', $this->world->boss);

        return sprintf('close period of %s through %s', $branchId === null ? 'the company' : 'branch ' . $branchId, $date);
    }

    private function operationPeriodReopen(LedgerRandom $random): string
    {
        $service  = new ClosedPeriodService();
        $branchId = $random->chance(60) ? null : $random->pick($this->world->branches);

        $this->note(sprintf('reopen the period of %s', $branchId === null ? 'the company' : 'branch ' . $branchId));

        $service->reopen($branchId, 'ledger', $this->world->boss);

        return sprintf('reopen the period of %s', $branchId === null ? 'the company' : 'branch ' . $branchId);
    }

    private function operationPurchaseEdit(LedgerRandom $random): string
    {
        $receivingId = $this->pickPurchase($random, ['receive']);

        if ($receivingId === null) {
            return $this->operationPurchase($random);
        }

        $stored = $this->db->table('receivings_items')->where('receiving_id', $receivingId)->orderBy('line')->get()->getResultArray();
        $edits  = [];
        $text   = [];

        foreach ($stored as $row) {
            $kind = $random->weighted(['keep' => 30, 'quantity' => 25, 'price' => 25, 'both' => 10, 'remove' => 10]);

            if ($kind === 'keep') {
                continue;
            }

            if ($kind === 'remove' && count($stored) > 1) {
                $edits[] = ReceivingLineEdit::removing((int) $row['item_id'], (int) $row['line']);
                $text[]  = 'remove i' . $row['item_id'];

                continue;
            }

            $fields = [];

            if (in_array($kind, ['quantity', 'both', 'remove'], true)) {
                $fields['quantity'] = $random->quantity();
            }

            if (in_array($kind, ['price', 'both'], true)) {
                $fields['price'] = $random->price();
            }

            $edit = ReceivingLineEdit::changing((int) $row['item_id'], (int) $row['line'], $fields);

            if ($edit !== null) {
                $edits[] = $edit;
                $text[]  = 'i' . $row['item_id'] . ' ' . json_encode($fields);
            }
        }

        if ($edits === []) {
            return 'edit of purchase ' . $receivingId . ' skipped: nothing changed';
        }

        $this->note(sprintf('edit lines of purchase %d: %s', $receivingId, implode('; ', $text)));

        if (! $this->purchaseModel->updateLines($receivingId, $edits, $this->world->boss)) {
            $this->refusePurchase('edit');
        }

        return sprintf('edit lines of purchase %d: %s', $receivingId, implode('; ', $text));
    }
}
