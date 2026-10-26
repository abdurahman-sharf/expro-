<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\RawSql;
use Config\Database;
use DateTimeImmutable;

final class JournalService
{
    use RunsInTransaction;

    private const MAX_LINES      = 500;
    private const AMOUNT_PATTERN = '/^\d{1,11}(\.\d{1,4})?$/';
    private const SORTABLE       = ['journal_date', 'number', 'created_at', 'total_debit', 'status'];

    private BaseConnection $db;
    private AuditLog $audit;
    private AccountingGuard $guard;
    private PostingService $posting;
    private JournalNumbering $numbering;
    private BranchGuard $branches;
    private PeriodGuard $periods;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?PostingService $posting = null, ?BranchGuard $branches = null)
    {
        $this->db        = $db ?? Database::connect();
        $this->audit     = new AuditLog($this->db);
        $this->guard     = $guard ?? new AccountingGuard();
        $this->posting   = $posting ?? new PostingService($this->db);
        $this->numbering = new JournalNumbering($this->db);
        $this->branches  = $branches ?? new BranchGuard($this->db);
        $this->periods   = new PeriodGuard($this->db);
    }

    /**
     * Creates a draft, or replaces the header and lines of an existing one. A draft may be
     * unbalanced and incomplete, but every line it keeps must be well formed.
     *
     * @param array{date?: string, description?: string, branch_id?: int|string, lines?: list<array<string, mixed>>} $input
     *
     * @throws AccountingException|BranchException
     */
    public function saveDraft(array $input, ?int $employeeId = null, ?int $journalId = null): int
    {
        $this->guard->require(AccountingGuard::JOURNALS_DRAFT, $employeeId);

        $stored = $journalId === null ? null : $this->db->table('accounting_journals')->select('branch_id')->where('journal_id', $journalId)->get()->getRow();

        if ($stored !== null && ! $this->branches->canAccess((int) $stored->branch_id, $employeeId)) {
            throw new AccountingException(AccountingError::JournalNotFound, "Journal {$journalId} does not exist.");
        }

        $branchId    = $this->resolveBranch($input['branch_id'] ?? $stored?->branch_id, $employeeId);
        $date        = $this->normalizeDate((string) ($input['date'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $lines       = $this->normalizeDraftLines($input['lines'] ?? []);
        $currency    = (new AccountingSettings($this->db))->baseCurrency();

        if ($currency === '') {
            throw new AccountingException(AccountingError::BaseCurrencyMissing, 'The base currency is not configured.');
        }

        return $this->transactional(function () use ($journalId, $branchId, $date, $description, $lines, $currency, $employeeId): int {
            $totals = $this->totals($lines);

            if ($journalId === null) {
                $this->db->table('accounting_journals')->insert([
                    'company_id'    => CompanyContext::id(),
                    'branch_id'     => $branchId,
                    'series'        => 'manual',
                    'number'        => null,
                    'journal_date'  => $date,
                    'description'   => $description === '' ? null : $description,
                    'status'        => JournalStatus::Draft->value,
                    'source_type'   => JournalDraft::SOURCE_MANUAL,
                    'origin'        => JournalOrigin::User->value,
                    'currency_code' => $currency,
                    'total_debit'   => $totals[0],
                    'total_credit'  => $totals[1],
                    'created_by'    => $employeeId,
                ]);

                $id     = (int) $this->db->insertID();
                $action = 'draft_created';
            } else {
                $this->lockDraft($journalId, $employeeId);

                $this->db->table('accounting_journals')->where('journal_id', $journalId)->update([
                    'branch_id'    => $branchId,
                    'journal_date' => $date,
                    'description'  => $description === '' ? null : $description,
                    'total_debit'  => $totals[0],
                    'total_credit' => $totals[1],
                ]);
                $this->db->table('accounting_journal_lines')->where('journal_id', $journalId)->delete();

                $id     = $journalId;
                $action = 'draft_updated';
            }

            $this->insertLines($id, $lines);
            $this->audit->write('journal', $id, $action, $employeeId, JournalOrigin::User, ['lines' => count($lines)], $branchId);

            return $id;
        });
    }

    /**
     * @throws AccountingException
     */
    public function deleteDraft(int $journalId, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::JOURNALS_DRAFT, $employeeId);

        $this->transactional(function () use ($journalId, $employeeId): void {
            $draft = $this->lockDraft($journalId, $employeeId);

            $this->db->table('accounting_journal_lines')->where('journal_id', $journalId)->delete();
            $this->db->table('accounting_journals')->where('journal_id', $journalId)->delete();

            $this->audit->write('journal', $journalId, 'draft_deleted', $employeeId, JournalOrigin::User, [], (int) $draft->branch_id);
        });
    }

    /**
     * Posts a stored draft, replacing its content first when new input is given. Nothing is
     * written when the journal does not pass the posting checks.
     *
     * @param array{date?: string, description?: string, branch_id?: int|string, lines?: list<array<string, mixed>>}|null $input
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function postDraft(int $journalId, ?int $employeeId = null, ?array $input = null): int
    {
        $this->guard->require(AccountingGuard::JOURNALS_POST, $employeeId);

        $stored = $this->find($journalId, $employeeId);

        if ($stored->status !== JournalStatus::Draft->value) {
            throw new AccountingException(AccountingError::NotADraft, 'Only a draft can be posted.');
        }

        if ($input !== null) {
            $this->guard->require(AccountingGuard::JOURNALS_DRAFT, $employeeId);

            $branchId    = $this->resolveBranch($input['branch_id'] ?? $stored->branch_id, $employeeId);
            $date        = $this->normalizeDate((string) ($input['date'] ?? ''));
            $description = trim((string) ($input['description'] ?? ''));
            $lines       = $this->normalizeDraftLines($input['lines'] ?? []);
        } else {
            $branchId    = (int) $stored->branch_id;
            $date        = $stored->journal_date;
            $description = (string) $stored->description;
            $lines       = array_map(static fn (object $line): array => [
                'account_id'     => (int) $line->account_id,
                'description'    => $line->description,
                'cost_center_id' => $line->cost_center_id === null ? null : (int) $line->cost_center_id,
                'debit'          => $line->debit,
                'credit'         => $line->credit,
            ], $stored->lines);
        }

        $this->periods->prepare($date);

        $prepared = $this->posting->validate($this->toPostingDraft($date, $description, $lines, $employeeId, $branchId));

        return $this->transactional(function () use ($journalId, $employeeId, $prepared, $date, $description): int {
            $this->periods->enforce($date, $prepared['branch_id']);
            $this->lockDraft($journalId, $employeeId);

            $this->db->table('accounting_journals')->where('journal_id', $journalId)->update([
                'branch_id'     => $prepared['branch_id'],
                'number'        => $this->numbering->nextManual(),
                'journal_date'  => $date,
                'description'   => $description === '' ? null : $description,
                'status'        => JournalStatus::Posted->value,
                'currency_code' => $prepared['currency'],
                'exchange_rate' => $prepared['rate'],
                'total_debit'   => $prepared['debit'],
                'total_credit'  => $prepared['credit'],
                'posted_by'     => $employeeId,
                'posted_at'     => new RawSql('CURRENT_TIMESTAMP'),
            ]);

            $this->db->table('accounting_journal_lines')->where('journal_id', $journalId)->delete();
            $this->insertLines($journalId, $prepared['lines']);

            $this->audit->write('journal', $journalId, 'posted', $employeeId, JournalOrigin::User, [
                'source_type' => JournalDraft::SOURCE_MANUAL,
                'total'       => $prepared['debit'],
            ], $prepared['branch_id']);

            return $journalId;
        });
    }

    /**
     * Creates and posts a manual journal in one step.
     *
     * @param array{date?: string, description?: string, branch_id?: int|string, lines?: list<array<string, mixed>>} $input
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function postNew(array $input, ?int $employeeId = null): int
    {
        $this->guard->require(AccountingGuard::JOURNALS_DRAFT, $employeeId);
        $this->guard->require(AccountingGuard::JOURNALS_POST, $employeeId);

        $branchId    = $this->resolveBranch($input['branch_id'] ?? null, $employeeId);
        $date        = $this->normalizeDate((string) ($input['date'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $lines       = $this->normalizeDraftLines($input['lines'] ?? []);

        return $this->posting->post($this->toPostingDraft($date, $description, $lines, $employeeId, $branchId))->journalId;
    }

    /**
     * Posts the mirror entry of a posted journal and marks the original as reversed. The
     * unique key on the reversal's source makes a second reversal impossible.
     *
     * @throws AccountingException|PostingException
     */
    public function reverse(int $journalId, string $date, string $reason, ?int $employeeId = null): int
    {
        $this->guard->require(AccountingGuard::JOURNALS_REVERSE, $employeeId);

        return $this->reverseJournal($journalId, $date, $reason, $employeeId, true);
    }

    /**
     * Reverses an opening balance journal. The caller has already checked the permission to
     * manage opening balances and that the branch is within the employee's scope, so the
     * journal does not have to be the employee's own.
     *
     * @throws AccountingException|PostingException
     */
    public function reverseOpening(int $journalId, string $date, string $reason, ?int $employeeId): int
    {
        $isOpening = $this->db->table('accounting_journals')->where('journal_id', $journalId)->whereIn('source_type', ['opening', OpeningStock::SOURCE])->countAllResults() > 0;

        if (! $isOpening) {
            throw new AccountingException(AccountingError::JournalNotFound, "Journal {$journalId} does not exist.");
        }

        return $this->reverseJournal($journalId, $date, $reason, $employeeId, false);
    }

    /**
     * Reverses the journal of a sale or a purchase, or of one of their payments, or of a transfer
     * between treasuries or an advance to a supplier, when the document is cancelled or a payment changes. Who may do that was decided where the document
     * changed, so no permission is asked here and the reversal counts as the system's. A journal
     * of any other source is refused.
     *
     * @throws AccountingException|PostingException
     */
    public function reverseAutomatic(int $journalId, string $date, string $reason, ?int $employeeId): int
    {
        $isAutomatic = $this->db->table('accounting_journals')->where('journal_id', $journalId)->whereIn('source_type', [
            SalePostingBuilder::SOURCE_SALE,
            SalePostingBuilder::SOURCE_PAYMENT,
            ReceivingPostingBuilder::SOURCE_PURCHASE,
            ReceivingPostingBuilder::SOURCE_PAYMENT,
            ReceivingPostingBuilder::SOURCE_SUPPLIER_PAYMENT,
            TreasuryTransfers::SOURCE,
            SupplierAdvances::SOURCE,
        ])->countAllResults() > 0;

        if (! $isAutomatic) {
            throw new AccountingException(AccountingError::JournalNotFound, "Journal {$journalId} does not exist.");
        }

        return $this->reverseJournal($journalId, $date, $reason, $employeeId, false, JournalOrigin::System);
    }

    private function reverseJournal(int $journalId, string $date, string $reason, ?int $employeeId, bool $checkAccess, JournalOrigin $origin = JournalOrigin::User): int
    {
        $date   = $this->normalizeDate($date);
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 255) {
            throw new AccountingException(AccountingError::ReasonRequired, 'A reason of 1 to 255 characters is required.');
        }

        return $this->transactional(function () use ($journalId, $date, $reason, $employeeId, $checkAccess, $origin): int {
            $table    = $this->db->prefixTable('accounting_journals');
            $original = LockedRead::row($this->db, "SELECT * FROM {$table} WHERE journal_id = ? FOR UPDATE", [$journalId]);

            if ($original === null) {
                throw new AccountingException(AccountingError::JournalNotFound, "Journal {$journalId} does not exist.");
            }

            if ($checkAccess) {
                $this->assertCanAccess($original, $employeeId);
            }

            if ($original->status === JournalStatus::Reversed->value) {
                throw new AccountingException(AccountingError::AlreadyReversed, 'The journal is already reversed.');
            }

            if ($original->status !== JournalStatus::Posted->value) {
                throw new AccountingException(AccountingError::NotPosted, 'Only a posted journal can be reversed.');
            }

            if ($original->reverses_journal_id !== null) {
                throw new AccountingException(AccountingError::CannotReverseReversal, 'A reversal cannot be reversed.');
            }

            $lines = [];

            foreach ($this->linesOf($journalId) as $line) {
                $lines[] = new JournalLineDraft(
                    accountId: (int) $line->account_id,
                    debit: $line->credit,
                    credit: $line->debit,
                    description: $line->description,
                    costCenterId: $line->cost_center_id === null ? null : (int) $line->cost_center_id,
                    partyType: $line->party_type,
                    partyId: $line->party_id === null ? null : (int) $line->party_id,
                );
            }

            $result = $this->posting->post(new JournalDraft(
                date: $date,
                lines: $lines,
                sourceType: JournalDraft::SOURCE_REVERSAL,
                sourceId: $journalId,
                revision: 1,
                origin: $origin,
                employeeId: $employeeId,
                description: $reason,
                currencyCode: $original->currency_code,
                exchangeRate: $original->exchange_rate,
                reversesJournalId: $journalId,
                branchId: (int) $original->branch_id,
            ));

            $this->db->table('accounting_journals')
                ->where('journal_id', $journalId)
                ->update(['status' => JournalStatus::Reversed->value]);

            $this->audit->write('journal', $journalId, 'reversed', $employeeId, $origin, [
                'reversal_id' => $result->journalId,
                'date'        => $date,
                'reason'      => $reason,
            ], (int) $original->branch_id);

            return $result->journalId;
        });
    }

    /**
     * A journal with its lines, the people who created and posted it and the journals it is
     * linked to by a reversal.
     *
     * @throws AccountingException
     */
    public function find(int $journalId, ?int $employeeId = null): object
    {
        $journals    = $this->db->prefixTable('accounting_journals');
        $people      = $this->db->prefixTable('people');
        $branchTable = $this->db->prefixTable('branches');

        $journal = $this->db->query(
            "SELECT j.*,
                TRIM(CONCAT(cp.first_name, ' ', cp.last_name)) AS created_by_name,
                TRIM(CONCAT(pp.first_name, ' ', pp.last_name)) AS posted_by_name,
                o.number AS reverses_number,
                r.journal_id AS reversed_by_id,
                r.number AS reversed_by_number,
                bb.code AS branch_code,
                bb.name AS branch_name
             FROM {$journals} j
             LEFT JOIN {$branchTable} bb ON bb.branch_id = j.branch_id
             LEFT JOIN {$people} cp ON cp.person_id = j.created_by
             LEFT JOIN {$people} pp ON pp.person_id = j.posted_by
             LEFT JOIN {$journals} o ON o.journal_id = j.reverses_journal_id
             LEFT JOIN {$journals} r ON r.reverses_journal_id = j.journal_id
             WHERE j.journal_id = ?",
            [$journalId],
        )->getRow();

        if ($journal === null) {
            throw new AccountingException(AccountingError::JournalNotFound, "Journal {$journalId} does not exist.");
        }

        $this->assertCanAccess($journal, $employeeId);

        $journal->lines = $this->linesOf($journalId);

        return $journal;
    }

    /**
     * @param array{account_id?: int|string, description?: string, number?: string, date_from?: string, date_to?: string, source_type?: string, entry_type?: string, status?: list<string>, amount_from?: string, amount_to?: string, created_from?: string, created_to?: string, created_by?: int|string, branch_id?: int|string} $filters
     *
     * @return array{total: int, rows: list<object>}
     */
    public function search(array $filters, string $sort = 'journal_date', string $order = 'desc', int $limit = 25, int $offset = 0, ?int $employeeId = null): array
    {
        $sort  = in_array($sort, self::SORTABLE, true) ? $sort : 'journal_date';
        $order = strtolower($order) === 'asc' ? 'ASC' : 'DESC';
        $limit = max(1, min($limit, 500));

        // Every other query is made before the builder exists: creating a builder resets the
        // table aliases the connection shares, which would break this one.
        $ownOnly    = ! $this->guard->has(AccountingGuard::JOURNALS_ALL, $employeeId);
        $allowed    = $this->branches->allowedBranchIds($employeeId);
        $accountIds = (int) ($filters['account_id'] ?? 0) > 0 ? $this->accountWithDescendants((int) $filters['account_id']) : [];

        $builder = $this->db->table('accounting_journals j')
            ->select("j.journal_id, j.branch_id, b.code AS branch_code, b.name AS branch_name, j.series, j.number, j.journal_date, j.description, j.source_type, j.entry_type, j.status, j.total_debit, j.total_credit, j.currency_code, j.created_by, j.created_at, TRIM(CONCAT(p.first_name, ' ', p.last_name)) AS created_by_name", false)
            ->join('people p', 'p.person_id = j.created_by', 'left')
            ->join('branches b', 'b.branch_id = j.branch_id')
            ->where('j.status !=', JournalStatus::Preview->value);

        if ($allowed !== null) {
            $allowed === [] ? $builder->where('1 = 0', null, false) : $builder->whereIn('j.branch_id', $allowed);
        }

        if ($ownOnly) {
            $builder->where('j.created_by', $employeeId);
        }

        $this->applyFilters($builder, $filters, $accountIds);

        $total = $builder->countAllResults(false);
        $rows  = $builder->orderBy("j.{$sort}", $order)->orderBy('j.journal_id', $order)->limit($limit, $offset)->get()->getResult();

        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * Active accounts that accept lines, for the journal line selector.
     *
     * @return list<object>
     */
    public function postableAccounts(): array
    {
        return $this->db->table('accounting_accounts')
            ->select('account_id, code, name')
            ->where('is_group', 0)
            ->where('active', 1)
            ->orderBy('code', 'ASC')
            ->get()
            ->getResult();
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<int>            $accountIds The filtered account and its descendants, or none.
     */
    private function applyFilters(BaseBuilder $builder, array $filters, array $accountIds): void
    {
        if ($accountIds !== []) {
            $ids = implode(',', array_map('intval', $accountIds));

            $builder->where('EXISTS (SELECT 1 FROM ' . $this->db->prefixTable('accounting_journal_lines') . " l WHERE l.journal_id = j.journal_id AND l.account_id IN ({$ids}))", null, false);
        }

        $description = trim((string) ($filters['description'] ?? ''));

        if ($description !== '') {
            $builder->like('j.description', $description);
        }

        $number = trim((string) ($filters['number'] ?? ''));

        if ($number !== '') {
            $builder->like('j.number', $number);
        }

        if ($this->isDate($filters['date_from'] ?? null)) {
            $builder->where('j.journal_date >=', $filters['date_from']);
        }

        if ($this->isDate($filters['date_to'] ?? null)) {
            $builder->where('j.journal_date <=', $filters['date_to']);
        }

        $sourceType = (string) ($filters['source_type'] ?? '');

        if ($sourceType !== '' && preg_match('/^[a-z][a-z0-9_]{0,31}$/', $sourceType) === 1) {
            $builder->where('j.source_type', $sourceType);
        }

        $entryType = JournalEntryType::tryFrom((string) ($filters['entry_type'] ?? ''));

        if ($entryType !== null) {
            $builder->where('j.entry_type', $entryType->value);
        }

        $known    = array_map(static fn (JournalStatus $status): string => $status->value, [JournalStatus::Draft, JournalStatus::Posted, JournalStatus::Reversed]);
        $statuses = array_values(array_intersect($known, array_map('strval', (array) ($filters['status'] ?? []))));

        if ($statuses !== []) {
            $builder->whereIn('j.status', $statuses);
        }

        foreach (['amount_from' => '>=', 'amount_to' => '<='] as $key => $operator) {
            $amount = trim((string) ($filters[$key] ?? ''));

            if ($amount !== '' && preg_match(self::AMOUNT_PATTERN, $amount) === 1) {
                $builder->where("j.total_debit {$operator}", $amount);
            }
        }

        if ($this->isDate($filters['created_from'] ?? null)) {
            $builder->where('j.created_at >=', $filters['created_from'] . ' 00:00:00');
        }

        if ($this->isDate($filters['created_to'] ?? null)) {
            $builder->where('j.created_at <=', $filters['created_to'] . ' 23:59:59');
        }

        $createdBy = (int) ($filters['created_by'] ?? 0);

        if ($createdBy > 0) {
            $builder->where('j.created_by', $createdBy);
        }

        $branchId = (int) ($filters['branch_id'] ?? 0);

        if ($branchId > 0) {
            $builder->where('j.branch_id', $branchId);
        }
    }

    /**
     * @return list<int>
     */
    private function accountWithDescendants(int $accountId): array
    {
        $children = [];

        foreach ($this->db->table('accounting_accounts')->select('account_id, parent_id')->get()->getResult() as $row) {
            if ($row->parent_id !== null) {
                $children[(int) $row->parent_id][] = (int) $row->account_id;
            }
        }

        $ids   = [];
        $queue = [$accountId];

        while ($queue !== []) {
            $current = array_pop($queue);
            $ids[]   = $current;

            foreach ($children[$current] ?? [] as $child) {
                $queue[] = $child;
            }
        }

        return $ids;
    }

    /**
     * @return list<object>
     */
    private function linesOf(int $journalId): array
    {
        return $this->db->table('accounting_journal_lines l')
            ->select('l.*, a.code AS account_code, a.name AS account_name, c.code AS cost_center_code, c.name AS cost_center_name')
            ->join('accounting_accounts a', 'a.account_id = l.account_id')
            ->join('accounting_cost_centers c', 'c.cost_center_id = l.cost_center_id', 'left')
            ->where('l.journal_id', $journalId)
            ->orderBy('l.line_no', 'ASC')
            ->get()
            ->getResult();
    }

    private function lockDraft(int $journalId, ?int $employeeId): object
    {
        $table = $this->db->prefixTable('accounting_journals');
        $row   = LockedRead::row($this->db, "SELECT * FROM {$table} WHERE journal_id = ? FOR UPDATE", [$journalId]);

        if ($row === null || $row->source_type !== JournalDraft::SOURCE_MANUAL) {
            throw new AccountingException(AccountingError::JournalNotFound, "Journal {$journalId} does not exist.");
        }

        $this->assertCanAccess($row, $employeeId);

        if ($row->status !== JournalStatus::Draft->value) {
            throw new AccountingException(AccountingError::NotADraft, 'Only a draft can be changed.');
        }

        return $row;
    }

    /**
     * A user without the permission to see every journal sees only the ones they created.
     */
    private function assertCanAccess(object $journal, ?int $employeeId): void
    {
        $outsideScope = ! $this->branches->canAccess((int) $journal->branch_id, $employeeId);
        $notTheirs    = $employeeId !== null && (int) $journal->created_by !== $employeeId && ! $this->guard->has(AccountingGuard::JOURNALS_ALL, $employeeId);

        if ($outsideScope || $notTheirs) {
            throw new AccountingException(AccountingError::JournalNotFound, "Journal {$journal->journal_id} does not exist.");
        }
    }

    /**
     * The branch a journal goes to: the one asked for, else the employee's default. It must be
     * an active branch of the company inside the employee's scope.
     *
     * @throws BranchException
     */
    private function resolveBranch(mixed $requested, ?int $employeeId): int
    {
        $branchId = (int) $requested > 0 ? (int) $requested : $this->branches->defaultBranchId($employeeId);
        $branch   = $this->db->table('branches')->where('branch_id', $branchId)->where('company_id', CompanyContext::id())->get()->getRow();

        if ($branch === null) {
            throw new BranchException(BranchError::NotFound, "Branch {$branchId} does not exist.", ['branch_id' => $branchId]);
        }

        $this->branches->requireBranch($branchId, $employeeId);

        if ((int) $branch->active === 0) {
            throw new BranchException(BranchError::Inactive, "Branch {$branchId} is inactive.", ['branch_id' => $branchId]);
        }

        return $branchId;
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    private function toPostingDraft(string $date, string $description, array $lines, ?int $employeeId, int $branchId): JournalDraft
    {
        return new JournalDraft(
            date: $date,
            lines: array_map(static fn (array $line): JournalLineDraft => new JournalLineDraft(
                accountId: (int) $line['account_id'],
                debit: (string) $line['debit'],
                credit: (string) $line['credit'],
                description: $line['description'],
                costCenterId: $line['cost_center_id'],
            ), $lines),
            sourceType: JournalDraft::SOURCE_MANUAL,
            origin: JournalOrigin::User,
            employeeId: $employeeId,
            description: $description,
            branchId: $branchId,
        );
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    private function insertLines(int $journalId, array $lines): void
    {
        $rows = [];

        foreach (array_values($lines) as $index => $line) {
            $rows[] = [
                'journal_id'     => $journalId,
                'line_no'        => $line['line_no'] ?? $index + 1,
                'account_id'     => $line['account_id'],
                'description'    => $line['description'] ?? null,
                'debit'          => $line['debit'],
                'credit'         => $line['credit'],
                'cost_center_id' => $line['cost_center_id'] ?? null,
                'party_type'     => $line['party_type'] ?? null,
                'party_id'       => $line['party_id'] ?? null,
            ];
        }

        if ($this->db->table('accounting_journal_lines')->insertBatch($rows) === false) {
            throw new AccountingException(AccountingError::WriteFailed, 'The journal lines could not be saved.');
        }
    }

    /**
     * @param list<array<string, mixed>> $lines
     *
     * @return array{0: string, 1: string}
     */
    private function totals(array $lines): array
    {
        $debit  = '0.0000';
        $credit = '0.0000';

        foreach ($lines as $line) {
            $debit  = bcadd($debit, $line['debit'], 4);
            $credit = bcadd($credit, $line['credit'], 4);
        }

        return [$debit, $credit];
    }

    /**
     * Drops blank lines and checks the rest. A draft may be unbalanced.
     *
     * @return list<array{line_no: int, account_id: int, description: ?string, cost_center_id: ?int, debit: string, credit: string}>
     *
     * @throws AccountingException
     */
    private function normalizeDraftLines(mixed $raw): array
    {
        $lines = [];

        foreach (is_array($raw) ? array_values($raw) : [] as $index => $line) {
            $lineNumber   = $index + 1;
            $line         = is_array($line) ? $line : [];
            $accountId    = (int) ($line['account_id'] ?? 0);
            $costCenterId = (int) ($line['cost_center_id'] ?? 0);
            $description  = trim((string) ($line['description'] ?? ''));
            $debit        = $this->normalizeAmount($line['debit'] ?? '', $lineNumber);
            $credit       = $this->normalizeAmount($line['credit'] ?? '', $lineNumber);
            $hasDebit     = bccomp($debit, '0', 4) > 0;
            $hasCredit    = bccomp($credit, '0', 4) > 0;

            if ($accountId === 0 && $costCenterId === 0 && $description === '' && ! $hasDebit && ! $hasCredit) {
                continue;
            }

            if ($accountId === 0) {
                throw new AccountingException(AccountingError::LineAccountRequired, "Line {$lineNumber} needs an account.", ['line' => $lineNumber]);
            }

            if ($hasDebit && $hasCredit) {
                throw new AccountingException(AccountingError::LineOnBothSides, "Line {$lineNumber} has both a debit and a credit.", ['line' => $lineNumber]);
            }

            $lines[] = [
                'line_no'        => count($lines) + 1,
                'account_id'     => $accountId,
                'description'    => $description === '' ? null : mb_substr($description, 0, 255),
                'cost_center_id' => $costCenterId === 0 ? null : $costCenterId,
                'debit'          => $debit,
                'credit'         => $credit,
            ];
        }

        if ($lines === []) {
            throw new AccountingException(AccountingError::NoLines, 'The journal has no lines.');
        }

        if (count($lines) > self::MAX_LINES) {
            throw new AccountingException(AccountingError::TooManyLines, 'The journal has too many lines.', ['max' => self::MAX_LINES]);
        }

        $this->assertLineReferences($lines);

        return $lines;
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    private function assertLineReferences(array $lines): void
    {
        $accountIds = array_values(array_unique(array_column($lines, 'account_id')));
        $accounts   = $this->db->table('accounting_accounts')->select('account_id, is_group, active')->whereIn('account_id', $accountIds)->get()->getResult();
        $postable   = [];

        foreach ($accounts as $account) {
            if ((int) $account->is_group === 0 && (int) $account->active === 1) {
                $postable[(int) $account->account_id] = true;
            }
        }

        foreach ($lines as $line) {
            if (! isset($postable[$line['account_id']])) {
                throw new AccountingException(AccountingError::LineAccountInvalid, "Account {$line['account_id']} is missing, a group or inactive.", ['line' => $line['line_no'], 'account_id' => $line['account_id']]);
            }
        }

        $costCenterIds = array_values(array_unique(array_filter(array_column($lines, 'cost_center_id'))));

        if ($costCenterIds === []) {
            return;
        }

        $found = array_column(
            $this->db->table('accounting_cost_centers')->select('cost_center_id')->whereIn('cost_center_id', $costCenterIds)->where('active', 1)->get()->getResultArray(),
            'cost_center_id',
        );

        foreach ($lines as $line) {
            if ($line['cost_center_id'] !== null && ! in_array((string) $line['cost_center_id'], array_map('strval', $found), true)) {
                throw new AccountingException(AccountingError::LineCostCenterInvalid, "Cost center {$line['cost_center_id']} is missing or inactive.", ['line' => $line['line_no']]);
            }
        }
    }

    private function normalizeAmount(mixed $value, int $lineNumber): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '0.0000';
        }

        if (preg_match(self::AMOUNT_PATTERN, $value) !== 1) {
            throw new AccountingException(AccountingError::LineAmountInvalid, "Line {$lineNumber} has an invalid amount.", ['line' => $lineNumber, 'value' => $value]);
        }

        return bcadd($value, '0', 4);
    }

    private function normalizeDate(string $date): string
    {
        if (! $this->isDate($date)) {
            throw new AccountingException(AccountingError::InvalidDate, 'The date must be a valid Y-m-d date.', ['date' => $date]);
        }

        return $date;
    }

    private function isDate(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }
}
