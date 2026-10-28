<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Which branch owes which, between every two branches. The accounts between branches are one for
 * what a branch is owed and one for what it owes, shared by all of them, so what a journal puts on
 * them says nothing of the other branch. The other branch of a journal is the one recorded with it
 * (a settlement, a payment into a treasury of another branch) or, for the transfers of money and
 * of stock that were posted without it, the one their own document names. A reversal belongs to
 * the pair of the journal it takes back. What neither can say stays apart as unattributed, and no
 * journal is changed to attribute it.
 */
final class InterBranchBalances
{
    private const ZERO = '0.0000';

    private BaseConnection $db;
    private InterBranchPosition $position;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->position = new InterBranchPosition($this->db);
    }

    /**
     * The balance of every ordered pair of branches in which the first branch is one the reader
     * may see. The company-wide difference is given only to a reader of every branch.
     *
     * @param list<int>|null $branchIds The branches the reader may see, null for every branch.
     * @param string|null    $asOf      The last day counted, none for everything in the books.
     */
    public function report(?array $branchIds = null, ?string $asOf = null): PairwiseReport
    {
        $matrix = $this->matrix($asOf);
        $codes  = $this->codes();
        $rows   = [];

        foreach ($matrix['pairs'] as $branchId => $counterparts) {
            if ($branchIds !== null && ! in_array($branchId, $branchIds, true)) {
                continue;
            }

            foreach ($counterparts as $counterpartId => $pair) {
                $rows[] = $this->row($branchId, $counterpartId, $pair, $matrix['pairs'][$counterpartId][$branchId] ?? null, $codes);
            }
        }

        usort($rows, static fn (PairBalance $a, PairBalance $b): int => [$a->branchId, $a->counterpartId] <=> [$b->branchId, $b->counterpartId]);

        $unattributed = [];

        foreach ($matrix['unattributed'] as $branchId => $amounts) {
            if (($branchIds === null || in_array($branchId, $branchIds, true)) && (bccomp($amounts['receivable'], '0', SaleAmounts::SCALE) !== 0 || bccomp($amounts['payable'], '0', SaleAmounts::SCALE) !== 0)) {
                $unattributed[] = (object) ['branch_id' => $branchId, 'code' => $codes[$branchId] ?? (string) $branchId, 'receivable' => $amounts['receivable'], 'payable' => $amounts['payable']];
            }
        }

        usort($unattributed, static fn (object $a, object $b): int => $a->branch_id <=> $b->branch_id);

        return new PairwiseReport($rows, $unattributed, $branchIds === null ? $this->position->difference($asOf) : null, $asOf);
    }

    /**
     * What two branches say of each other, each from its own journals, as of a day.
     *
     * @return array{first: array{receivable: string, payable: string}, second: array{receivable: string, payable: string}}
     */
    public function between(int $firstBranchId, int $secondBranchId, ?string $asOf = null): array
    {
        $pairs = $this->matrix($asOf, [$firstBranchId, $secondBranchId])['pairs'];

        return [
            'first'  => $this->amounts($pairs[$firstBranchId][$secondBranchId] ?? null),
            'second' => $this->amounts($pairs[$secondBranchId][$firstBranchId] ?? null),
        ];
    }

    /**
     * @param array{receivable: string, payable: string, bases: array<string, true>}|null $pair
     *
     * @return array{receivable: string, payable: string}
     */
    private function amounts(?array $pair): array
    {
        return ['receivable' => $pair['receivable'] ?? self::ZERO, 'payable' => $pair['payable'] ?? self::ZERO];
    }

    /**
     * @param array{receivable: string, payable: string, bases: array<string, true>}      $pair
     * @param array{receivable: string, payable: string, bases: array<string, true>}|null $other
     * @param array<int, string>                                                          $codes
     */
    private function row(int $branchId, int $counterpartId, array $pair, ?array $other, array $codes): PairBalance
    {
        $owed        = bcsub($pair['receivable'], $pair['payable'], SaleAmounts::SCALE);
        $otherAmount = $this->amounts($other);
        $bases       = array_keys($pair['bases']);

        return new PairBalance(
            branchId: $branchId,
            code: $codes[$branchId] ?? (string) $branchId,
            counterpartId: $counterpartId,
            counterpartCode: $codes[$counterpartId] ?? (string) $counterpartId,
            receivable: $pair['receivable'],
            payable: $pair['payable'],
            owed: $owed,
            basis: count($bases) === 1 ? $bases[0] : PairBalance::MIXED,
            agrees: PairBalance::agree($pair['receivable'], $pair['payable'], $otherAmount['receivable'], $otherAmount['payable']),
        );
    }

    /**
     * What each branch holds on the accounts between branches against each other branch, and what
     * it holds against none, added up by the database so that what comes back is a row for each
     * pair and not a row for each journal.
     *
     * The other branch of a journal is the one recorded with the journal it belongs to (itself, or
     * for a reversal the one it takes back) or, when none is recorded, the one the transfer of
     * money or of stock it belongs to names, which is a derived pair.
     *
     * @param list<int>|null $branchIds Only the journals of these branches, none for every branch.
     *
     * @return array{pairs: array<int, array<int, array{receivable: string, payable: string, bases: array<string, true>}>>, unattributed: array<int, array{receivable: string, payable: string}>}
     */
    private function matrix(?string $asOf, ?array $branchIds = null): array
    {
        [$receivable, $payable] = $this->position->accounts();
        $all                    = array_values(array_unique([...$receivable, ...$payable]));

        if ($all === []) {
            return ['pairs' => [], 'unattributed' => []];
        }

        $pairs        = [];
        $unattributed = [];

        foreach ($this->db->query($this->matrixQuery($receivable, $payable, $all, $asOf, $branchIds))->getResult() as $row) {
            $branchId = (int) $row->branch_id;
            $owed     = bcadd((string) $row->receivable, '0', SaleAmounts::SCALE);
            $owing    = bcadd((string) $row->payable, '0', SaleAmounts::SCALE);

            if ($row->recorded === null && $row->derived === null) {
                $unattributed[$branchId] ??= ['receivable' => self::ZERO, 'payable' => self::ZERO];
                $unattributed[$branchId]['receivable'] = bcadd($unattributed[$branchId]['receivable'], $owed, SaleAmounts::SCALE);
                $unattributed[$branchId]['payable']    = bcadd($unattributed[$branchId]['payable'], $owing, SaleAmounts::SCALE);

                continue;
            }

            $counterpartId = (int) ($row->recorded ?? $row->derived);
            $basis         = $row->recorded === null ? PairBalance::DERIVED : PairBalance::RECORDED;

            $pairs[$branchId][$counterpartId] ??= ['receivable' => self::ZERO, 'payable' => self::ZERO, 'bases' => []];
            $pairs[$branchId][$counterpartId]['receivable']    = bcadd($pairs[$branchId][$counterpartId]['receivable'], $owed, SaleAmounts::SCALE);
            $pairs[$branchId][$counterpartId]['payable']       = bcadd($pairs[$branchId][$counterpartId]['payable'], $owing, SaleAmounts::SCALE);
            $pairs[$branchId][$counterpartId]['bases'][$basis] = true;
        }

        foreach ($pairs as $branchId => $others) {
            foreach ($others as $counterpartId => $pair) {
                $pairs[$counterpartId][$branchId] ??= ['receivable' => self::ZERO, 'payable' => self::ZERO, 'bases' => $pair['bases']];
            }
        }

        ksort($pairs);

        foreach ($pairs as $branchId => $others) {
            ksort($pairs[$branchId]);
        }

        return ['pairs' => $pairs, 'unattributed' => $unattributed];
    }

    /**
     * One row for each branch and for each other branch named by the record of the journals or by
     * the document they belong to, with what the journals put on the account of what is owed to the
     * branch (debits less credits) and on the account of what the branch owes (credits less debits).
     *
     * @param list<int>      $receivable
     * @param list<int>      $payable
     * @param list<int>      $all
     * @param list<int>|null $branchIds
     */
    private function matrixQuery(array $receivable, array $payable, array $all, ?string $asOf, ?array $branchIds): string
    {
        $in      = static fn (array $ids): string => $ids === [] ? '0' : implode(',', array_map('intval', $ids));
        $table   = fn (string $name): string => $this->db->prefixTable($name);
        $quote   = fn (string $value): string => $this->db->escape($value);
        $sources = fn (string ...$names): string => implode(', ', array_map(fn (string $name): string => $this->db->escape($name), $names));
        $where   = [
            'j.company_id = ' . (int) CompanyContext::id(),
            'j.status IN (' . $quote(JournalStatus::Posted->value) . ', ' . $quote(JournalStatus::Reversed->value) . ')',
            'l.account_id IN (' . $in($all) . ')',
        ];

        if ($asOf !== null) {
            $where[] = 'j.journal_date <= ' . $quote($asOf);
        }

        if ($branchIds !== null) {
            $where[] = 'j.branch_id IN (' . $in($branchIds) . ')';
        }

        return 'SELECT j.branch_id,'
            . ' (SELECT MAX(g.counterpart_branch_id) FROM ' . $table('accounting_interbranch_legs') . ' g WHERE g.company_id = j.company_id AND g.journal_id = o.journal_id) AS recorded,'
            . ' CASE o.source_type'
            . ' WHEN ' . $quote(TreasuryTransfers::SOURCE) . ' THEN tt.to_branch_id'
            . ' WHEN ' . $quote(TreasuryTransfers::SOURCE_RECEIPT) . ' THEN IF(tt.to_branch_id IS NULL, NULL, tt.branch_id)'
            . ' WHEN ' . $quote(ReceivingPostingBuilder::SOURCE_STOCK_TRANSFER) . ' THEN rc.to_branch_id'
            . ' WHEN ' . $quote(ReceivingPostingBuilder::SOURCE_TRANSFER_RECEIPT) . ' THEN IF(rc.to_branch_id IS NULL, NULL, rc.branch_id)'
            . ' END AS derived,'
            . ' SUM(CASE WHEN l.account_id IN (' . $in($receivable) . ') THEN l.debit - l.credit ELSE 0 END) AS receivable,'
            . ' SUM(CASE WHEN l.account_id IN (' . $in($payable) . ') THEN l.credit - l.debit ELSE 0 END) AS payable'
            . ' FROM ' . $table('accounting_journal_lines') . ' l'
            . ' JOIN ' . $table('accounting_journals') . ' j ON j.journal_id = l.journal_id'
            . ' LEFT JOIN ' . $table('accounting_journals') . ' o ON o.journal_id = COALESCE(j.reverses_journal_id, j.journal_id)'
            . ' LEFT JOIN ' . $table('treasury_transfers') . ' tt ON o.source_type IN (' . $sources(TreasuryTransfers::SOURCE, TreasuryTransfers::SOURCE_RECEIPT) . ') AND tt.transfer_id = o.source_id'
            . ' LEFT JOIN ' . $table('receivings') . ' rc ON o.source_type IN (' . $sources(ReceivingPostingBuilder::SOURCE_STOCK_TRANSFER, ReceivingPostingBuilder::SOURCE_TRANSFER_RECEIPT) . ') AND rc.receiving_id = o.source_id'
            . ' WHERE ' . implode(' AND ', $where)
            . ' GROUP BY j.branch_id, recorded, derived';
    }

    /**
     * @return array<int, string>
     */
    private function codes(): array
    {
        $codes = [];

        foreach ($this->db->table('branches')->select('branch_id, code')->where('company_id', CompanyContext::id())->get()->getResult() as $branch) {
            $codes[(int) $branch->branch_id] = (string) $branch->code;
        }

        return $codes;
    }
}
