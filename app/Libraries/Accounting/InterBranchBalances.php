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
    private const ZERO  = '0.0000';
    private const CHUNK = 5000;

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
     * it holds against none. The journals are read in runs of a few thousand, so that what is held
     * in memory is a run and not the whole history of the two accounts.
     *
     * @param list<int>|null $branchIds Only the journals of these branches, none for every branch.
     *
     * @return array{pairs: array<int, array<int, array{receivable: string, payable: string, bases: array<string, true>}>>, unattributed: array<int, array{receivable: string, payable: string}>}
     */
    private function matrix(?string $asOf, ?array $branchIds = null): array
    {
        [$receivable, $payable] = $this->position->accounts();
        $pairs                  = [];
        $unattributed           = [];
        $after                  = 0;

        do {
            $ids      = $this->journalIdsOnTheAccounts([...$receivable, ...$payable], $asOf, $branchIds, $after);
            $journals = $ids === [] ? [] : $this->journalsOnTheAccounts($receivable, $payable, $ids);
            $after    = $ids === [] ? $after : $ids[array_key_last($ids)];

            if ($journals === []) {
                break;
            }

            $counterparts = $this->counterpartsOf($journals);

            foreach ($journals as $journal) {
                $branchId = (int) $journal->branch_id;
                $found    = $counterparts[$journal->reverses_journal_id === null ? (int) $journal->journal_id : (int) $journal->reverses_journal_id] ?? null;

                if ($found === null) {
                    $unattributed[$branchId] ??= ['receivable' => self::ZERO, 'payable' => self::ZERO];
                    $unattributed[$branchId]['receivable'] = bcadd($unattributed[$branchId]['receivable'], (string) $journal->receivable, SaleAmounts::SCALE);
                    $unattributed[$branchId]['payable']    = bcadd($unattributed[$branchId]['payable'], (string) $journal->payable, SaleAmounts::SCALE);

                    continue;
                }

                [$counterpartId, $basis] = $found;

                $pairs[$branchId][$counterpartId] ??= ['receivable' => self::ZERO, 'payable' => self::ZERO, 'bases' => []];
                $pairs[$branchId][$counterpartId]['receivable']    = bcadd($pairs[$branchId][$counterpartId]['receivable'], (string) $journal->receivable, SaleAmounts::SCALE);
                $pairs[$branchId][$counterpartId]['payable']       = bcadd($pairs[$branchId][$counterpartId]['payable'], (string) $journal->payable, SaleAmounts::SCALE);
                $pairs[$branchId][$counterpartId]['bases'][$basis] = true;
            }
        } while (count($ids) === self::CHUNK);

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
     * The next run of journals that touch the accounts, by number, after the last one read.
     *
     * @param list<int>      $accountIds
     * @param list<int>|null $branchIds
     *
     * @return list<int>
     */
    private function journalIdsOnTheAccounts(array $accountIds, ?string $asOf, ?array $branchIds, int $after): array
    {
        $accounts = implode(',', array_map('intval', array_unique($accountIds)));

        if ($accounts === '') {
            return [];
        }

        $builder = $this->db->table('accounting_journals j')
            ->select('j.journal_id')
            ->where('j.company_id', CompanyContext::id())
            ->where('j.journal_id >', $after)
            ->whereIn('j.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
            ->where('EXISTS (SELECT 1 FROM ' . $this->db->prefixTable('accounting_journal_lines') . " l WHERE l.journal_id = j.journal_id AND l.account_id IN ({$accounts}))", null, false)
            ->orderBy('j.journal_id', 'ASC')
            ->limit(self::CHUNK);

        if ($asOf !== null) {
            $builder->where('j.journal_date <=', $asOf);
        }

        if ($branchIds !== null) {
            $builder->whereIn('j.branch_id', $branchIds);
        }

        return array_map(static fn (object $row): int => (int) $row->journal_id, $builder->get()->getResult());
    }

    /**
     * What each of the journals given put on the accounts: what it debited of the account of what
     * is owed to the branch, and what it credited of the account of what the branch owes.
     *
     * @param list<int> $receivable
     * @param list<int> $payable
     * @param list<int> $journalIds
     *
     * @return list<object>
     */
    private function journalsOnTheAccounts(array $receivable, array $payable, array $journalIds): array
    {
        $all = array_values(array_unique([...$receivable, ...$payable]));

        $in      = static fn (array $ids): string => $ids === [] ? '0' : implode(',', array_map('intval', $ids));
        $builder = $this->db->table('accounting_journal_lines l')
            ->select('j.journal_id, j.branch_id, j.source_type, j.source_id, j.reverses_journal_id', false)
            ->select('COALESCE(SUM(CASE WHEN l.account_id IN (' . $in($receivable) . ') THEN l.debit - l.credit ELSE 0 END), 0) AS receivable', false)
            ->select('COALESCE(SUM(CASE WHEN l.account_id IN (' . $in($payable) . ') THEN l.credit - l.debit ELSE 0 END), 0) AS payable', false)
            ->join('accounting_journals j', 'j.journal_id = l.journal_id')
            ->whereIn('j.journal_id', $journalIds)
            ->whereIn('l.account_id', $all)
            ->groupBy('j.journal_id, j.branch_id, j.source_type, j.source_id, j.reverses_journal_id')
            ->orderBy('j.journal_id', 'ASC');

        return $builder->get()->getResult();
    }

    /**
     * The other branch of each journal, by the journal that decides it: the journal itself, or for
     * a reversal the one it takes back.
     *
     * @param list<object> $journals
     *
     * @return array<int, array{int, string}> The branch and how it is known, by journal id.
     */
    private function counterpartsOf(array $journals): array
    {
        $ids = [];

        foreach ($journals as $journal) {
            $ids[$journal->reverses_journal_id === null ? (int) $journal->journal_id : (int) $journal->reverses_journal_id] = true;
        }

        $ids   = array_keys($ids);
        $found = [];
        $legs  = $this->db->table('accounting_interbranch_legs')->select('journal_id, counterpart_branch_id')->where('company_id', CompanyContext::id())->whereIn('journal_id', $ids)->get()->getResult();

        foreach ($legs as $leg) {
            $found[(int) $leg->journal_id] = [(int) $leg->counterpart_branch_id, PairBalance::RECORDED];
        }

        $left = array_values(array_diff($ids, array_keys($found)));

        if ($left === []) {
            return $found;
        }

        $documents = $this->db->table('accounting_journals')->select('journal_id, source_type, source_id')->whereIn('journal_id', $left)->get()->getResult();
        $transfers = [];
        $stock     = [];

        foreach ($documents as $document) {
            if (in_array($document->source_type, [TreasuryTransfers::SOURCE, TreasuryTransfers::SOURCE_RECEIPT], true)) {
                $transfers[(int) $document->source_id] = true;
            } elseif (in_array($document->source_type, [ReceivingPostingBuilder::SOURCE_STOCK_TRANSFER, ReceivingPostingBuilder::SOURCE_TRANSFER_RECEIPT], true)) {
                $stock[(int) $document->source_id] = true;
            }
        }

        $transferBranches = $transfers === [] ? [] : $this->branchesOf('treasury_transfers', 'transfer_id', array_keys($transfers));
        $stockBranches    = $stock === [] ? [] : $this->branchesOf('receivings', 'receiving_id', array_keys($stock));

        foreach ($documents as $document) {
            $branches = match ($document->source_type) {
                TreasuryTransfers::SOURCE, TreasuryTransfers::SOURCE_RECEIPT => $transferBranches[(int) $document->source_id] ?? null,
                ReceivingPostingBuilder::SOURCE_STOCK_TRANSFER, ReceivingPostingBuilder::SOURCE_TRANSFER_RECEIPT => $stockBranches[(int) $document->source_id] ?? null,
                default => null,
            };

            if ($branches === null || $branches['to'] === null) {
                continue;
            }

            $isSending = in_array($document->source_type, [TreasuryTransfers::SOURCE, ReceivingPostingBuilder::SOURCE_STOCK_TRANSFER], true);

            $found[(int) $document->journal_id] = [$isSending ? $branches['to'] : $branches['from'], PairBalance::DERIVED];
        }

        return $found;
    }

    /**
     * The branch a document leaves and the branch it goes to.
     *
     * @param list<int> $ids
     *
     * @return array<int, array{from: int, to: ?int}>
     */
    private function branchesOf(string $table, string $key, array $ids): array
    {
        $branches = [];

        foreach ($this->db->table($table)->select($key . ', branch_id, to_branch_id')->whereIn($key, $ids)->get()->getResult() as $row) {
            $branches[(int) $row->{$key}] = ['from' => (int) $row->branch_id, 'to' => $row->to_branch_id === null ? null : (int) $row->to_branch_id];
        }

        return $branches;
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
