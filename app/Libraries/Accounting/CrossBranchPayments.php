<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * A payment on a document of one branch that goes into, or comes out of, a treasury of another. A
 * journal belongs to one branch, so the payment is two: the journal of the document, which settles
 * the customer, the supplier or the expense against the account between branches where the
 * treasury would have been, and the journal of the treasury, which puts the money in or takes it
 * out against the other account between branches. Both are balanced in their own branch, they are
 * written in the order of the branch numbers, and they are changed and taken back together or not
 * at all. A payment that carries no branch of a treasury is posted as one journal, as it always was.
 */
final class CrossBranchPayments
{
    private const COMPANIONS = [
        SalePostingBuilder::SOURCE_PAYMENT               => 'sale_payment_branch',
        ReceivingPostingBuilder::SOURCE_PAYMENT          => 'purchase_payment_branch',
        ReceivingPostingBuilder::SOURCE_SUPPLIER_PAYMENT => 'supplier_payment_branch',
        ExpensePostingBuilder::SOURCE                    => 'expense_branch',
    ];

    private BaseConnection $db;
    private BranchGuard $branches;
    private PostingService $posting;
    private RoutingResolver $resolver;
    private InterBranchLegs $legs;
    private TreasuryScopes $scopes;

    public function __construct(?BaseConnection $db = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->branches = $branches ?? new BranchGuard($this->db);
        $this->posting  = new PostingService($this->db);
        $this->resolver = new RoutingResolver($this->db);
        $this->legs     = new InterBranchLegs($this->db);
        $this->scopes   = new TreasuryScopes($this->db, $this->branches);
    }

    /**
     * The journals of a payment: the draft as it is when the payment carries no branch of a
     * treasury, and otherwise the journal of its own branch and the one of the treasury's, the
     * lower branch first.
     *
     * @return list<JournalDraft>
     *
     * @throws PostingException
     */
    public function drafts(JournalDraft $draft, ?int $treasuryId, ?int $treasuryBranchId): array
    {
        if ($treasuryId === null || $treasuryBranchId === null || $treasuryBranchId === $draft->branchId) {
            return [$draft];
        }

        $companion = self::COMPANIONS[$draft->sourceType] ?? null;
        $treasury  = $this->resolver->accountFor(RoutingKey::Treasuries, $treasuryId);

        if ($companion === null || $treasury === null) {
            throw new PostingException(PostingError::CrossBranchPaymentInvalid, 'The payment cannot be posted into a treasury of another branch.', ['treasury_id' => $treasuryId]);
        }

        $moved = array_values(array_filter($draft->lines, static fn (JournalLineDraft $line): bool => $line->accountId === $treasury));

        if ($moved === []) {
            return [$draft];
        }

        if (count($moved) !== 1) {
            throw new PostingException(PostingError::CrossBranchPaymentInvalid, 'A payment into a treasury of another branch moves the treasury once.', ['treasury_id' => $treasuryId]);
        }

        [$receivable, $payable] = $this->accountsBetweenBranches();

        $line   = $moved[0];
        $debit  = bccomp($line->debit, '0', SaleAmounts::SCALE) > 0;
        $amount = $debit ? $line->debit : $line->credit;

        $own = new JournalDraft(
            date: $draft->date,
            lines: array_map(
                static fn (JournalLineDraft $each): JournalLineDraft => $each === $line
                    ? ($debit ? new JournalLineDraft($receivable, debit: $amount) : new JournalLineDraft($payable, credit: $amount))
                    : $each,
                $draft->lines,
            ),
            sourceType: $draft->sourceType,
            sourceId: $draft->sourceId,
            revision: $draft->revision,
            origin: $draft->origin,
            employeeId: $draft->employeeId,
            description: $draft->description,
            currencyCode: $draft->currencyCode,
            exchangeRate: $draft->exchangeRate,
            branchId: $draft->branchId,
            entryType: $draft->entryType,
        );

        $other = new JournalDraft(
            date: $draft->date,
            lines: $debit
                ? [new JournalLineDraft($treasury, debit: $amount), new JournalLineDraft($payable, credit: $amount)]
                : [new JournalLineDraft($receivable, debit: $amount), new JournalLineDraft($treasury, credit: $amount)],
            sourceType: $companion,
            sourceId: $draft->sourceId,
            revision: $draft->revision,
            origin: $draft->origin,
            employeeId: $draft->employeeId,
            description: $draft->description . ' (' . $this->branchCode((int) $draft->branchId) . ')',
            currencyCode: $draft->currencyCode,
            exchangeRate: $draft->exchangeRate,
            branchId: $treasuryBranchId,
            entryType: $draft->entryType,
        );

        $pair = [(int) $draft->branchId => $own, $treasuryBranchId => $other];

        ksort($pair);

        return array_values($pair);
    }

    /**
     * The source of the journal the treasury's branch gets for the payments of a source, when the
     * treasury is of another branch than the document.
     *
     * @return list<string>
     */
    public static function companionsOf(string $source): array
    {
        return isset(self::COMPANIONS[$source]) ? [self::COMPANIONS[$source]] : [];
    }

    public function isPaired(object $journal): bool
    {
        return $this->legs->isPaired((int) $journal->journal_id);
    }

    /**
     * Brings the journals of a payment in line with the drafts: posts them when there is none,
     * leaves them when they hold what the drafts say, and otherwise takes the standing ones back,
     * together, and posts the drafts. The branches and the treasuries are asked before anything is
     * written.
     *
     * @param list<JournalDraft>      $drafts
     * @param callable(object): mixed $reverse Takes back a journal of the payment the way its document does, with the companions that go with it.
     *
     * @throws AccountingException|PostingException
     */
    public function sync(?object $active, array $drafts, ?int $employeeId, callable $reverse): JournalChange
    {
        if ($active === null) {
            if ($drafts === []) {
                return JournalChange::None;
            }

            $this->assertMayPost($drafts, $employeeId);
            $this->post($drafts);

            return JournalChange::Posted;
        }

        $standing = $this->standing($active);

        if ($drafts !== [] && $this->holds($standing, $drafts)) {
            return JournalChange::Unchanged;
        }

        $this->assertBranches($standing, $drafts, $employeeId);
        $this->scopes->assertMayReplaceAll(array_map(static fn (object $journal): int => (int) $journal->journal_id, $standing), $drafts, $employeeId);

        $reverse($active);

        if ($drafts === []) {
            return JournalChange::Reversed;
        }

        $this->post($drafts);

        return JournalChange::Replaced;
    }

    /**
     * Writes the journals in the order of the branch numbers and records the branch on the other
     * side of each, when there are two.
     *
     * @param list<JournalDraft> $drafts
     *
     * @throws PostingException
     */
    public function post(array $drafts): void
    {
        usort($drafts, static fn (JournalDraft $a, JournalDraft $b): int => (int) $a->branchId <=> (int) $b->branchId);

        $ids = [];

        foreach ($drafts as $draft) {
            $ids[(int) $draft->branchId] = $this->posting->post($draft)->journalId;
        }

        if (count($drafts) < 2) {
            return;
        }

        [$receivable, $payable] = $this->accountsBetweenBranches();

        foreach ($drafts as $draft) {
            $counterpart = array_values(array_diff(array_keys($ids), [(int) $draft->branchId]))[0];
            $document    = array_search($draft->sourceType, self::COMPANIONS, true);

            foreach ($draft->lines as $line) {
                $side = match ($line->accountId) {
                    $receivable => InterBranchLegs::RECEIVABLE,
                    $payable    => InterBranchLegs::PAYABLE,
                    default     => null,
                };

                if ($side !== null) {
                    $this->legs->record($ids[(int) $draft->branchId], $line->accountId, $side, $counterpart, $document === false ? $draft->sourceType : (string) $document, (int) $draft->sourceId, $draft->revision);
                }
            }
        }
    }

    /**
     * Whoever posts a payment into a treasury of another branch works in both branches and may
     * move the treasuries it touches.
     *
     * @param list<JournalDraft> $drafts
     *
     * @throws AccountingException|PostingException
     */
    public function assertMayPost(array $drafts, ?int $employeeId): void
    {
        $this->assertBranches([], $drafts, $employeeId);

        $lines = [];

        foreach ($drafts as $draft) {
            array_push($lines, ...$draft->lines);
        }

        $this->scopes->assertMayPost($lines, $employeeId);
    }

    /**
     * The journal that stands and the ones that stand with it.
     *
     * @return list<object>
     */
    private function standing(object $active): array
    {
        return [$active, ...$this->legs->partnersOf((int) $active->journal_id)];
    }

    /**
     * Whether the journals that stand are the drafts: the same number, in the same branches, on the
     * same date and with the same lines.
     *
     * @param list<object>       $standing
     * @param list<JournalDraft> $drafts
     */
    private function holds(array $standing, array $drafts): bool
    {
        if (count($standing) !== count($drafts)) {
            return false;
        }

        $stored = [];

        foreach ($standing as $journal) {
            $row = $this->db->table('accounting_journals')->select('journal_date, branch_id, source_type')->where('journal_id', $journal->journal_id)->get()->getRow();

            $stored[$row->branch_id . ':' . $row->source_type . ':' . $row->journal_date] = $this->linesOf((int) $journal->journal_id);
        }

        foreach ($drafts as $draft) {
            $key = $draft->branchId . ':' . $draft->sourceType . ':' . $draft->date;

            if (! isset($stored[$key])) {
                return false;
            }

            $wanted = array_map(
                static fn (JournalLineDraft $line): string => $line->accountId . ':' . bcadd($line->debit, '0', SaleAmounts::SCALE) . ':' . bcadd($line->credit, '0', SaleAmounts::SCALE),
                $draft->lines,
            );

            sort($wanted);

            if ($stored[$key] !== $wanted) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function linesOf(int $journalId): array
    {
        $lines = array_map(
            static fn (object $line): string => $line->account_id . ':' . bcadd($line->debit, '0', SaleAmounts::SCALE) . ':' . bcadd($line->credit, '0', SaleAmounts::SCALE),
            $this->db->table('accounting_journal_lines')->select('account_id, debit, credit')->where('journal_id', $journalId)->get()->getResult(),
        );

        sort($lines);

        return $lines;
    }

    /**
     * @param list<object>       $standing
     * @param list<JournalDraft> $drafts
     *
     * @throws AccountingException
     */
    private function assertBranches(array $standing, array $drafts, ?int $employeeId): void
    {
        $branchIds = [];

        foreach ($standing as $journal) {
            $branchIds[(int) $journal->branch_id] = true;
        }

        foreach ($drafts as $draft) {
            $branchIds[(int) $draft->branchId] = true;
        }

        foreach (array_keys($branchIds) as $branchId) {
            if (! $this->branches->canAccess($branchId, $employeeId)) {
                throw new AccountingException(AccountingError::InterBranchBranchesDenied, "The branch {$branchId} is outside the employee's scope.", ['branch_id' => $branchId, 'name' => $this->branchCode($branchId)]);
            }
        }
    }

    /**
     * @return array{int, int}
     *
     * @throws PostingException
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
            throw new PostingException(PostingError::InterBranchAccountsMissing, 'A payment into a treasury of another branch needs the two accounts between branches in the routing.');
        }

        return [$receivable, $payable];
    }

    private function branchCode(int $branchId): string
    {
        return (string) ($this->db->table('branches')->select('code')->where('branch_id', $branchId)->get()->getRow()->code ?? $branchId);
    }
}
