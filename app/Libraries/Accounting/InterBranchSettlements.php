<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/**
 * Clears what two branches owe each other, in one document and one transaction. Each branch gets a
 * journal of its own, balanced inside the branch: it takes off the accounts between branches what
 * the branch was owed by the other and what it owed the other, and what is left between the two
 * moves in cash from a treasury of the branch that owed it to a treasury of the branch that was
 * owed, or, when the two offset exactly, moves nothing. The journals are written and taken back
 * in the order of the branch numbers, the settlement is refused when the two branches do not say
 * the same of each other, and it is taken back by reversing both journals, never one.
 */
final class InterBranchSettlements
{
    use RunsInTransaction;

    public const SOURCE        = 'interbranch_settlement';
    public const SOURCE_SECOND = 'interbranch_settlement_second';
    private const NOTE_MAX     = 500;
    private const ZERO         = '0.0000';

    private BaseConnection $db;
    private AccountingGuard $guard;
    private BranchGuard $branches;
    private PostingService $posting;
    private JournalService $journals;
    private PeriodGuard $periods;
    private RoutingResolver $resolver;
    private InterBranchBalances $balances;
    private InterBranchLegs $legs;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->guard    = $guard ?? new AccountingGuard();
        $this->branches = $branches ?? new BranchGuard($this->db);
        $this->posting  = new PostingService($this->db);
        $this->journals = new JournalService($this->db);
        $this->periods  = new PeriodGuard($this->db);
        $this->resolver = new RoutingResolver($this->db);
        $this->balances = new InterBranchBalances($this->db);
        $this->legs     = new InterBranchLegs($this->db);
        $this->audit    = new AuditLog($this->db);
    }

    /**
     * What settling the two branches on the day would do, for whoever works in both.
     *
     * @throws AccountingException|BranchException
     */
    public function plan(int $branchId, int $otherBranchId, string $date, ?int $employeeId = null): SettlementPlan
    {
        [$first, $second] = $this->pair($branchId, $otherBranchId);

        $this->branches->requireBranch($first, $employeeId);
        $this->branches->requireBranch($second, $employeeId);

        return $this->planOf($first, $second, $this->date($date));
    }

    /**
     * Settles two branches as of the day. A cash settlement needs the treasury the money comes out
     * of, in the branch that owes, and the one it goes into, in the branch that is owed. Asking
     * again with the same request key returns the settlement the first request made.
     *
     * @return int The settlement.
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function settle(int $branchId, int $otherBranchId, string $date, ?int $fromTreasuryId = null, ?int $toTreasuryId = null, string $note = '', ?string $requestKey = null, ?int $employeeId = null): int
    {
        $this->guard->require(AccountingGuard::INTERBRANCH_SETTLE, $employeeId);

        [$first, $second] = $this->pair($branchId, $otherBranchId);

        $date = $this->date($date);
        $note = mb_substr(trim($note), 0, self::NOTE_MAX);
        $key  = $this->keyOf($requestKey);

        $this->branches->requireBranch($first, $employeeId);
        $this->branches->requireBranch($second, $employeeId);

        return $this->transactional(function () use ($first, $second, $date, $fromTreasuryId, $toTreasuryId, $note, $key, $employeeId): int {
            $this->lockPair($first, $second);

            if ($key !== null) {
                $existing = $this->db->table('interbranch_settlements')->select('settlement_id')->where(['company_id' => CompanyContext::id(), 'request_key' => $key])->get()->getRow();

                if ($existing !== null) {
                    return (int) $existing->settlement_id;
                }
            }

            $plan = $this->planOf($first, $second, $date);

            if ($plan->settledOn !== null) {
                throw new AccountingException(AccountingError::SettlementOlderThanSettled, 'The two branches were settled on a later day already, which cleared what this day would clear again.', ['date' => $plan->settledOn]);
            }

            if (! $plan->agrees) {
                throw new AccountingException(AccountingError::SettlementPairNotAgreed, 'The two branches do not say the same of each other, so they cannot be settled.', ['first' => $plan->firstCode, 'second' => $plan->secondCode]);
            }

            if ($plan->kind === null) {
                throw new AccountingException(AccountingError::SettlementNothingToSettle, 'Nothing is owed between the two branches on that day.');
            }

            $accounts = $plan->kind === SettlementPlan::CASH ? $this->cashAccounts($plan, (int) $fromTreasuryId, (int) $toTreasuryId, $employeeId) : [];

            $this->db->table('interbranch_settlements')->insert([
                'company_id'            => CompanyContext::id(),
                'branch_id'             => $first,
                'counterpart_branch_id' => $second,
                'kind'                  => $plan->kind,
                'settle_date'           => $date,
                'amount'                => $plan->amount,
                'payer_branch_id'       => $plan->payerBranchId,
                'from_treasury_id'      => $plan->kind === SettlementPlan::CASH ? (int) $fromTreasuryId : null,
                'to_treasury_id'        => $plan->kind === SettlementPlan::CASH ? (int) $toTreasuryId : null,
                'first_receivable'      => $plan->firstReceivable,
                'first_payable'         => $plan->firstPayable,
                'second_receivable'     => $plan->secondReceivable,
                'second_payable'        => $plan->secondPayable,
                'request_key'           => $key,
                'note'                  => $note,
                'created_by'            => $employeeId,
            ]);

            $settlementId           = (int) $this->db->insertID();
            [$receivable, $payable] = $this->accountsBetweenBranches();

            $drafts = [
                $first  => $this->draft($settlementId, $plan, $first, $second, $receivable, $payable, $accounts, $note, $employeeId),
                $second => $this->draft($settlementId, $plan, $second, $first, $receivable, $payable, $accounts, $note, $employeeId),
            ];

            // Written in the order of the branch ids, so that two documents that touch the same branches take the period
            // locks of the two branches in the same order.
            ksort($drafts);

            foreach ($drafts as $branch => $draft) {
                $journalId = $this->posting->post($draft)->journalId;

                $this->recordLegs($journalId, $draft, $branch === $first ? $second : $first, $receivable, $payable, $settlementId);
            }

            foreach ([$first, $second] as $branch) {
                $this->audit->write('interbranch_settlement', $settlementId, 'created', $employeeId, JournalOrigin::User, [
                    'with'   => $branch === $first ? $second : $first,
                    'kind'   => $plan->kind,
                    'amount' => $plan->amount,
                    'date'   => $date,
                ], $branch);
            }

            return $settlementId;
        });
    }

    /**
     * Takes a settlement back by reversing the journals of both branches together. Who may do it
     * works in both branches, may deposit into the treasury the money went out of and withdraw from
     * the one it went into.
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function cancel(int $settlementId, string $reason, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::INTERBRANCH_SETTLE, $employeeId);

        $reason = trim($reason);

        $this->transactional(function () use ($settlementId, $reason, $employeeId): void {
            $table      = $this->db->prefixTable('interbranch_settlements');
            $settlement = LockedRead::row($this->db, "SELECT * FROM {$table} WHERE company_id = ? AND settlement_id = ? FOR UPDATE", [CompanyContext::id(), $settlementId]);

            if ($settlement === null) {
                throw new AccountingException(AccountingError::SettlementNotFound, "Settlement {$settlementId} does not exist.", ['settlement_id' => $settlementId]);
            }

            foreach (self::branchesOf($settlement) as $involved) {
                $this->branches->requireBranch($involved, $employeeId);
            }

            if ($settlement->status === 'cancelled') {
                throw new AccountingException(AccountingError::SettlementAlreadyCancelled, 'The settlement is already cancelled.', ['settlement_id' => $settlementId]);
            }

            $this->lockPair((int) $settlement->branch_id, (int) $settlement->counterpart_branch_id);

            $journals = $this->db->table('accounting_journals')
                ->select('journal_id, journal_date, branch_id')
                ->where('source_id', $settlementId)
                ->whereIn('source_type', [self::SOURCE, self::SOURCE_SECOND])
                ->where('status', JournalStatus::Posted->value)
                ->orderBy('branch_id', 'ASC')
                ->get()
                ->getResult();
            $scopes = new TreasuryScopes($this->db, $this->branches);

            foreach ($journals as $journal) {
                $scopes->assertMayReverse((int) $journal->journal_id, $employeeId);
            }

            foreach ($journals as $journal) {
                $current = $this->db->table('accounting_journals')->select('status')->where('journal_id', $journal->journal_id)->get()->getRow();

                if ($current === null || $current->status !== JournalStatus::Posted->value) {
                    continue;
                }

                $cutoff = $this->periods->effectiveCutoff((int) $journal->branch_id, true);
                $date   = $cutoff !== null && $journal->journal_date <= $cutoff['date'] ? date('Y-m-d') : $journal->journal_date;

                $this->journals->reverseAutomatic((int) $journal->journal_id, $date, $reason, $employeeId);
            }

            $this->db->table('interbranch_settlements')->where('settlement_id', $settlementId)->update([
                'status'        => 'cancelled',
                'cancelled_by'  => $employeeId,
                'cancelled_at'  => date('Y-m-d H:i:s'),
                'cancel_reason' => $reason,
            ]);

            foreach (self::branchesOf($settlement) as $involved) {
                $this->audit->write('interbranch_settlement', $settlementId, 'cancelled', $employeeId, JournalOrigin::User, ['reason' => $reason], $involved);
            }
        });
    }

    public function find(int $settlementId): ?object
    {
        return $this->query()->where('st.settlement_id', $settlementId)->get()->getRow();
    }

    /**
     * The settlements a reader may see, the newest first: those of a pair that has a branch of theirs.
     *
     * @param list<int>|null $branchIds Null for every branch.
     *
     * @return array{total: int, rows: list<object>}
     */
    public function search(?array $branchIds = null, int $limit = 25, int $offset = 0): array
    {
        if ($branchIds === []) {
            return ['total' => 0, 'rows' => []];
        }

        $count = $this->db->table('interbranch_settlements st')->where('st.company_id', CompanyContext::id());

        if ($branchIds !== null) {
            $count->groupStart()->whereIn('st.branch_id', $branchIds)->orWhereIn('st.counterpart_branch_id', $branchIds)->groupEnd();
        }

        $total = $count->countAllResults();
        $rows  = $this->query();

        if ($branchIds !== null) {
            $rows->groupStart()->whereIn('st.branch_id', $branchIds)->orWhereIn('st.counterpart_branch_id', $branchIds)->groupEnd();
        }

        return ['total' => $total, 'rows' => $rows->orderBy('st.settlement_id', 'DESC')->limit($limit, $offset)->get()->getResult()];
    }

    /**
     * @return list<int>
     */
    public static function branchesOf(object $settlement): array
    {
        return [(int) $settlement->branch_id, (int) $settlement->counterpart_branch_id];
    }

    private function query(): BaseBuilder
    {
        return $this->db->table('interbranch_settlements st')
            ->select('st.*, b.code AS branch_code, cb.code AS counterpart_code, pb.code AS payer_code, f.name AS from_name, t.name AS to_name')
            ->join('branches b', 'b.branch_id = st.branch_id')
            ->join('branches cb', 'cb.branch_id = st.counterpart_branch_id')
            ->join('branches pb', 'pb.branch_id = st.payer_branch_id', 'left')
            ->join('treasuries f', 'f.treasury_id = st.from_treasury_id', 'left')
            ->join('treasuries t', 't.treasury_id = st.to_treasury_id', 'left')
            ->where('st.company_id', CompanyContext::id());
    }

    /**
     * @throws AccountingException|BranchException
     */
    private function planOf(int $first, int $second, string $date): SettlementPlan
    {
        $codes   = $this->codesOf($first, $second);
        $between = $this->balances->between($first, $second, $date);
        $one     = $between['first'];
        $two     = $between['second'];
        $agrees  = PairBalance::agree($one['receivable'], $one['payable'], $two['receivable'], $two['payable']);
        $net     = bcsub($one['receivable'], $one['payable'], SaleAmounts::SCALE);
        $empty   = bccomp($one['receivable'], '0', SaleAmounts::SCALE) === 0 && bccomp($one['payable'], '0', SaleAmounts::SCALE) === 0 && bccomp($two['receivable'], '0', SaleAmounts::SCALE) === 0 && bccomp($two['payable'], '0', SaleAmounts::SCALE) === 0;
        $cash    = bccomp($net, '0', SaleAmounts::SCALE) !== 0;
        $kind    = match (true) {
            $empty  => null,
            $cash   => SettlementPlan::CASH,
            default => SettlementPlan::NETTING,
        };
        $payer = ! $cash ? null : (bccomp($net, '0', SaleAmounts::SCALE) > 0 ? $second : $first);

        return new SettlementPlan(
            firstBranchId: $first,
            firstCode: $codes[$first],
            secondBranchId: $second,
            secondCode: $codes[$second],
            date: $date,
            firstReceivable: $one['receivable'],
            firstPayable: $one['payable'],
            secondReceivable: $two['receivable'],
            secondPayable: $two['payable'],
            agrees: $agrees,
            kind: $kind,
            amount: $cash ? SaleAmounts::absolute($net) : self::ZERO,
            payerBranchId: $payer,
            payeeBranchId: $payer === null ? null : ($payer === $first ? $second : $first),
            settledOn: $this->settledAfter($first, $second, $date),
        );
    }

    /**
     * A settlement counts only what is dated on or before its own day, so one made for an earlier
     * day than a settlement that stands cannot see it and would clear the same balances twice.
     */
    private function settledAfter(int $first, int $second, string $date): ?string
    {
        $latest = $this->db->table('interbranch_settlements')
            ->selectMax('settle_date', 'latest')
            ->where(['company_id' => CompanyContext::id(), 'branch_id' => $first, 'counterpart_branch_id' => $second, 'status !=' => 'cancelled', 'settle_date >' => $date])
            ->get()
            ->getRow();

        return $latest?->latest === null ? null : (string) $latest->latest;
    }

    /**
     * The accounts of the two treasuries of a cash settlement, once both are locked, open, in the
     * branches the money moves between and open to the employee in the direction it moves.
     *
     * @return array{from: int, to: int}
     *
     * @throws AccountingException
     */
    private function cashAccounts(SettlementPlan $plan, int $fromId, int $toId, ?int $employeeId): array
    {
        if ($fromId <= 0 || $toId <= 0) {
            throw new AccountingException(AccountingError::SettlementTreasuryRequired, 'A cash settlement needs the treasury the money comes out of and the one it goes into.');
        }

        if ($fromId === $toId) {
            throw new AccountingException(AccountingError::TransferSameTreasury, 'A settlement needs two different treasuries.');
        }

        $table = $this->db->prefixTable('treasuries');
        $rows  = LockedRead::rows($this->db, "SELECT * FROM {$table} WHERE company_id = ? AND treasury_id IN (?, ?) ORDER BY treasury_id ASC FOR UPDATE", [CompanyContext::id(), $fromId, $toId]);
        $byId  = [];

        foreach ($rows as $row) {
            $byId[(int) $row->treasury_id] = $row;
        }

        foreach ([$fromId => $plan->payerBranchId, $toId => $plan->payeeBranchId] as $id => $branchId) {
            if (! isset($byId[$id]) || (int) $byId[$id]->branch_id !== $branchId) {
                throw new AccountingException(AccountingError::TreasuryNotFound, "Treasury {$id} is not a treasury of the branch it must be.", ['treasury_id' => $id]);
            }

            if ((int) $byId[$id]->active === 0) {
                throw new AccountingException(AccountingError::TreasuryInactive, 'A settlement cannot touch a deactivated treasury.', ['treasury_id' => $id]);
            }
        }

        $scopes = new TreasuryScopes($this->db, $this->branches);

        $scopes->assertAllows($fromId, TreasuryScopes::WITHDRAW, $employeeId);
        $scopes->assertAllows($toId, TreasuryScopes::DEPOSIT, $employeeId);

        $entities = new EntityAccountService($this->db);
        $from     = $entities->accountFor(EntityAccountService::TREASURY, $fromId);
        $to       = $entities->accountFor(EntityAccountService::TREASURY, $toId);

        if ($from === null || $to === null) {
            throw new PostingException(PostingError::EntityAccountMissing, 'A treasury has no account of its own yet.', ['entity_id' => $from === null ? $fromId : $toId]);
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * @param array{from?: int, to?: int} $accounts
     */
    private function draft(int $settlementId, SettlementPlan $plan, int $branchId, int $otherId, int $receivable, int $payable, array $accounts, string $note, ?int $employeeId): JournalDraft
    {
        $isFirst = $branchId === $plan->firstBranchId;
        $owed    = $isFirst ? $plan->firstReceivable : $plan->secondReceivable;
        $owing   = $isFirst ? $plan->firstPayable : $plan->secondPayable;
        $lines   = [];

        if ($plan->kind === SettlementPlan::CASH && $branchId === $plan->payeeBranchId) {
            $lines[] = new JournalLineDraft((int) $accounts['to'], debit: $plan->amount);
        }

        if (bccomp($owing, '0', SaleAmounts::SCALE) > 0) {
            $lines[] = new JournalLineDraft($payable, debit: $owing);
        }

        if (bccomp($owed, '0', SaleAmounts::SCALE) > 0) {
            $lines[] = new JournalLineDraft($receivable, credit: $owed);
        }

        if ($plan->kind === SettlementPlan::CASH && $branchId === $plan->payerBranchId) {
            $lines[] = new JournalLineDraft((int) $accounts['from'], credit: $plan->amount);
        }

        $codes = $this->codesOf($branchId, $otherId);

        return new JournalDraft(
            date: $plan->date,
            lines: $lines,
            sourceType: $isFirst ? self::SOURCE : self::SOURCE_SECOND,
            sourceId: $settlementId,
            origin: JournalOrigin::System,
            employeeId: $employeeId,
            description: 'Settlement #' . $settlementId . ' with branch ' . $codes[$otherId] . ($note === '' ? '' : ': ' . $note),
            branchId: $branchId,
            entryType: JournalEntryType::General,
        );
    }

    private function recordLegs(int $journalId, JournalDraft $draft, int $counterpartId, int $receivable, int $payable, int $settlementId): void
    {
        foreach ($draft->lines as $line) {
            $side = match ($line->accountId) {
                $receivable => InterBranchLegs::RECEIVABLE,
                $payable    => InterBranchLegs::PAYABLE,
                default     => null,
            };

            if ($side !== null) {
                $this->legs->record($journalId, $line->accountId, $side, $counterpartId, self::SOURCE, $settlementId, 1);
            }
        }
    }

    /**
     * @return array{int, int}
     *
     * @throws AccountingException
     */
    private function accountsBetweenBranches(): array
    {
        try {
            $receivable = $this->resolver->accountFor(RoutingKey::InterBranchReceivable);
            $payable    = $this->resolver->accountFor(RoutingKey::InterBranchPayable);
        } catch (PostingException) {
            $receivable = $payable = null;
        }

        if ($receivable === null || $payable === null) {
            throw new AccountingException(AccountingError::InterBranchAccountsMissing, 'A settlement between branches needs the two accounts between branches in the routing.');
        }

        return [$receivable, $payable];
    }

    /**
     * Two settlements of the same pair wait for each other, so that the second finds what the
     * first left. The row is the one thing both take, in the transaction.
     */
    private function lockPair(int $first, int $second): void
    {
        $table = $this->db->prefixTable('interbranch_pair_locks');

        $this->db->query("INSERT IGNORE INTO {$table} (company_id, branch_id, counterpart_branch_id) VALUES (?, ?, ?)", [CompanyContext::id(), $first, $second]);
        LockedRead::row($this->db, "SELECT branch_id FROM {$table} WHERE company_id = ? AND branch_id = ? AND counterpart_branch_id = ? FOR UPDATE", [CompanyContext::id(), $first, $second]);
    }

    /**
     * The two branches of a pair in the order of their numbers.
     *
     * @return array{int, int}
     *
     * @throws AccountingException|BranchException
     */
    private function pair(int $branchId, int $otherBranchId): array
    {
        if ($branchId === $otherBranchId) {
            throw new AccountingException(AccountingError::SettlementSameBranch, 'A settlement is between two different branches.');
        }

        $pair = [min($branchId, $otherBranchId), max($branchId, $otherBranchId)];

        foreach ($pair as $id) {
            if ($this->db->table('branches')->where(['company_id' => CompanyContext::id(), 'branch_id' => $id])->countAllResults() === 0) {
                throw new BranchException(BranchError::NotFound, "The branch {$id} does not exist.", ['branch_id' => $id]);
            }
        }

        return $pair;
    }

    /**
     * @return array<int, string>
     */
    private function codesOf(int $first, int $second): array
    {
        $codes = [];

        foreach ($this->db->table('branches')->select('branch_id, code')->whereIn('branch_id', [$first, $second])->get()->getResult() as $branch) {
            $codes[(int) $branch->branch_id] = (string) $branch->code;
        }

        return $codes;
    }

    private function keyOf(?string $requestKey): ?string
    {
        $requestKey = $requestKey === null ? '' : trim($requestKey);

        return $requestKey === '' ? null : hash('sha256', $requestKey);
    }

    /**
     * @throws AccountingException
     */
    private function date(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        if ($date === false || $date->format('Y-m-d') !== trim($value)) {
            throw new AccountingException(AccountingError::InvalidDate, 'The date is not a valid date.');
        }

        return $date->format('Y-m-d');
    }
}
