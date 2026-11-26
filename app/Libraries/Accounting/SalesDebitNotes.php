<?php

namespace App\Libraries\Accounting;

use App\Libraries\EInvoicing\EInvoiceException;
use App\Libraries\EInvoicing\EInvoiceRules;
use App\Libraries\EInvoicing\EInvoiceService;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use App\Libraries\Roles\RecordScope;
use App\Libraries\Tax\CompanyTaxProfile;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/**
 * A sales debit note: a document that raises what a customer owes without an invoice. Its journal debits
 * the account of the customer with the whole amount and credits the account of sales revenue (or the
 * account the note names) with the amount and the output tax with the tax, rounded like an invoice: the
 * tax as calculated, the total to the cent, and the revenue what is left. No treasury is involved. It is
 * an open item of the customer dated by its own day, which credit is spent on in the order of the
 * invoices, and which ages from that day. It is never edited and never deleted: it is taken back by
 * reversing its journal, with a reason, and what had been spent on it is given back.
 *
 * It works only while accounting and the setting for debit notes are on, and only for those who hold the
 * permission to manage them. Taking one back needs no setting, so the setting can be turned off while
 * notes stand.
 */
final class SalesDebitNotes
{
    use RunsInTransaction;

    public const SOURCE         = 'sales_debit_note';
    public const KEY_BASE       = 1_000_000_000;
    public const NOTE_MAX       = 500;
    public const REASON_MAX     = 255;
    private const TOTAL_MAX     = '99999999999.9999';
    private const ENTITY        = 'sales_debit_note';
    private const AMOUNT        = '/^\d{1,11}(\.\d{1,4})?$/';
    private const PERCENT       = '/^\d{1,3}(\.\d{1,4})?$/';
    private const ACCOUNT_TYPES = [AccountType::Revenue, AccountType::Expense];

    private BaseConnection $db;
    private AccountingGuard $guard;
    private BranchGuard $branches;
    private RecordScope $scope;
    private AccountingSettings $settings;
    private PostingService $posting;
    private JournalService $journals;
    private PeriodGuard $periods;
    private RoutingResolver $resolver;
    private CustomerLock $lock;
    private CreditSettlement $settlement;
    private AuditLog $audit;
    private DocumentNumbering $numbering;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db         = $db ?? Database::connect();
        $this->guard      = $guard ?? new AccountingGuard();
        $this->branches   = $branches ?? new BranchGuard($this->db);
        $this->scope      = new RecordScope($this->db);
        $this->settings   = new AccountingSettings($this->db);
        $this->posting    = new PostingService($this->db);
        $this->journals   = new JournalService($this->db);
        $this->periods    = new PeriodGuard($this->db);
        $this->resolver   = new RoutingResolver($this->db);
        $this->lock       = new CustomerLock($this->db);
        $this->settlement = new CreditSettlement($this->db);
        $this->audit      = new AuditLog($this->db);
        $this->numbering  = new DocumentNumbering($this->db);
    }

    /**
     * How an open debit note is numbered among the invoices of a position: minus its number, past the
     * numbers of the opening balances.
     */
    public static function keyOf(int $debitNoteId): int
    {
        return -(self::KEY_BASE + $debitNoteId);
    }

    /**
     * The debit note a position key stands for, or null when the key is that of an invoice or an opening.
     */
    public static function idOfKey(int $key): ?int
    {
        return $key <= -self::KEY_BASE ? -$key - self::KEY_BASE : null;
    }

    /**
     * Raises what a customer owes by an amount and the tax on it, dated up to today.
     *
     * Under the Saudi rules the note names the issued document it corrects, by its number, and the reason it is
     * issued for, and its own document is issued in the transaction that raises it.
     *
     * @param array{customer_id: int, date: string, amount: string, tax_percent?: string, account_id?: int|null, note?: string, branch_id?: int|null, original_document?: string, note_reason?: string} $input
     *
     * @throws AccountingException|BranchException|EInvoiceException|PostingException
     */
    public function create(array $input, ?int $employeeId = null): int
    {
        $this->guard->require(AccountingGuard::DEBIT_NOTES_MANAGE, $employeeId);
        $this->assertOn();

        $customerId = (int) ($input['customer_id'] ?? 0);
        $figures    = $this->figures((string) ($input['amount'] ?? ''), (string) ($input['tax_percent'] ?? '0'));
        $date       = $this->day((string) ($input['date'] ?? ''));
        $note       = $this->noteText((string) ($input['note'] ?? ''));

        $this->assertCustomer($customerId);

        $branchId = $this->branch(isset($input['branch_id']) ? (int) $input['branch_id'] : null, $employeeId);
        $account  = $this->revenueAccount(isset($input['account_id']) ? (int) $input['account_id'] : null);
        $issuing  = (new CompanyTaxProfile($this->db))->isSaudiMode();
        $reason   = trim((string) ($input['note_reason'] ?? '')) === '' ? EInvoiceRules::NOTE_REASONS[2] : trim((string) $input['note_reason']);
        $original = $issuing ? (new EInvoiceService($this->db))->originalForDebitNote($customerId, (string) ($input['original_document'] ?? ''), $employeeId) : null;

        return $this->lock->run($customerId, fn (): int => $this->transactional(function () use ($customerId, $figures, $date, $note, $branchId, $account, $employeeId, $original, $reason): int {
            $customerAccount = $this->resolver->accountFor(RoutingKey::Customers, $customerId);

            if ($customerAccount === null) {
                throw new PostingException(PostingError::EntityAccountMissing, 'The customer has no account of their own yet.', ['entity_id' => $customerId]);
            }

            $taxAccount = bccomp($figures['tax'], '0', SaleAmounts::SCALE) > 0 ? $this->routed(RoutingKey::TaxOutput) : null;
            $revenue    = $account ?? $this->routed(RoutingKey::SalesRevenue);

            $this->db->table('sales_debit_notes')->insert([
                'company_id'         => CompanyContext::id(),
                'branch_id'          => $branchId,
                'customer_id'        => $customerId,
                'note_date'          => $date,
                'amount'             => $figures['amount'],
                'tax_percent'        => $figures['percent'],
                'tax_amount'         => $figures['tax'],
                'total'              => $figures['total'],
                'revenue_account_id' => $account,
                'note'               => $note,
                'created_by'         => $employeeId,
            ]);

            $debitNoteId = (int) $this->db->insertID();
            $lines       = [new JournalLineDraft($customerAccount, debit: $figures['total']), new JournalLineDraft($revenue, credit: $figures['revenue'])];

            if ($taxAccount !== null) {
                $lines[] = new JournalLineDraft($taxAccount, credit: $figures['tax']);
            }

            $this->numbering->assign(DocumentType::SalesDebitNote, 'sales_debit_notes', $debitNoteId, $branchId);

            $result = $this->posting->post(new JournalDraft(
                date: $date,
                lines: $lines,
                sourceType: self::SOURCE,
                sourceId: $debitNoteId,
                revision: 1,
                origin: $this->origin($employeeId),
                employeeId: $employeeId,
                description: 'Sales debit note #' . $this->numbering->label(DocumentType::SalesDebitNote, 'sales_debit_notes', $debitNoteId),
                branchId: $branchId,
                entryType: JournalEntryType::Revenue,
            ));

            if (! $result->created) {
                throw new AccountingException(AccountingError::DebitNoteReversed, 'The journal of this debit note is already posted.', ['debit_note_id' => $debitNoteId]);
            }

            $this->settlement->settle($customerId, $employeeId);

            if ($original !== null) {
                (new EInvoiceService($this->db))->issueForDebitNote($debitNoteId, $reason, $original, $employeeId);
            }

            $this->audit->write(self::ENTITY, $debitNoteId, 'created', $employeeId, $this->origin($employeeId), [
                'customer_id' => $customerId,
                'amount'      => $figures['amount'],
                'tax'         => $figures['tax'],
                'total'       => $figures['total'],
                'date'        => $date,
            ], $branchId);

            return $debitNoteId;
        }));
    }

    /**
     * Takes a debit note back, with a reason: its journal is reversed on its own day (or today when that
     * day is in a closed period) and what had been spent on it is given back. Nothing is deleted.
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function reverse(int $debitNoteId, string $reason, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::DEBIT_NOTES_MANAGE, $employeeId);

        $reason = $this->reason($reason);
        $note   = $this->found($debitNoteId);

        if (! $this->scope->allows($employeeId, $note->created_by)) {
            throw new AccountingException(AccountingError::DebitNoteNotFound, "Debit note {$debitNoteId} does not exist.", ['debit_note_id' => $debitNoteId]);
        }

        $this->branches->requireBranch((int) $note->branch_id, $employeeId);

        $customerId = (int) $note->customer_id;

        $this->lock->run($customerId, fn () => $this->transactional(function () use ($debitNoteId, $customerId, $reason, $employeeId): void {
            if ((new EInvoiceService($this->db))->documentOf(self::SOURCE, $debitNoteId) !== null) {
                throw new AccountingException(AccountingError::DebitNoteDocumentIssued, 'The electronic document of the debit note was issued; the note stands.', ['debit_note_id' => $debitNoteId]);
            }

            $current = $this->standing($debitNoteId);
            $journal = $this->db->table('accounting_journals')
                ->select('journal_id, journal_date')
                ->where(['source_type' => self::SOURCE, 'source_id' => $debitNoteId, 'entry_type' => JournalEntryType::Revenue->value, 'status' => JournalStatus::Posted->value])
                ->get()
                ->getRow();

            if ($journal === null) {
                throw new AccountingException(AccountingError::DebitNoteReversed, 'There is nothing posted to take back.', ['debit_note_id' => $debitNoteId]);
            }

            $cutoff = $this->periods->effectiveCutoff((int) $current->branch_id, true);
            $date   = $cutoff !== null && $journal->journal_date <= $cutoff['date'] ? date('Y-m-d') : $journal->journal_date;

            $this->journals->reverseAutomatic((int) $journal->journal_id, $date, $reason, $employeeId);

            $this->db->table('sales_debit_notes')->where('debit_note_id', $debitNoteId)->update([
                'status'         => 'reversed',
                'reversed_by'    => $employeeId,
                'reversed_at'    => date('Y-m-d H:i:s'),
                'reverse_reason' => $reason,
            ]);

            $this->settlement->settle($customerId, $employeeId);
            $this->audit->write(self::ENTITY, $debitNoteId, 'reversed', $employeeId, $this->origin($employeeId), ['customer_id' => $customerId, 'reason' => $reason, 'total' => bcadd((string) $current->total, '0', SaleAmounts::SCALE)], (int) $current->branch_id);
        }));
    }

    public function find(int $debitNoteId, ?int $viewerId = null): ?object
    {
        $note = $this->db->table('sales_debit_notes n')
            ->select("n.*, b.code AS branch_code, TRIM(CONCAT(p.first_name, ' ', p.last_name)) AS customer_name, d.number AS document_number", false)
            ->join('branches b', 'b.branch_id = n.branch_id')
            ->join('people p', 'p.person_id = n.customer_id', 'left')
            ->join('document_numbers d', "d.doc_type = '" . DocumentType::SalesDebitNote->value . "' AND d.source_table = 'sales_debit_notes' AND d.source_id = n.debit_note_id", 'left')
            ->where('n.company_id', CompanyContext::id())
            ->where('n.debit_note_id', $debitNoteId)
            ->get()
            ->getRow();

        return $note !== null && $this->scope->allows($viewerId, $note->created_by) ? $note : null;
    }

    /**
     * The debit notes of the branches the employee works in, the newest first, and of the employee alone
     * when they see their own records only.
     *
     * @param array{status?: string, customer_id?: int, from?: string, to?: string, search?: string} $filters
     *
     * @return array{total: int, rows: list<object>}
     */
    public function search(array $filters, ?int $employeeId, int $limit = 25, int $offset = 0): array
    {
        $total = $this->searched($filters, $employeeId)->countAllResults();
        $rows  = $this->searched($filters, $employeeId)
            ->select("n.*, b.code AS branch_code, TRIM(CONCAT(p.first_name, ' ', p.last_name)) AS customer_name, d.number AS document_number", false)
            ->orderBy('n.note_date', 'DESC')
            ->orderBy('n.debit_note_id', 'DESC')
            ->limit(max(1, $limit), max(0, $offset))
            ->get()
            ->getResult();

        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * @param array{status?: string, customer_id?: int, from?: string, to?: string, search?: string} $filters
     */
    private function searched(array $filters, ?int $employeeId): BaseBuilder
    {
        $branchIds = $this->branches->allowedBranchIds($employeeId);
        $mine      = $this->scope->limit($employeeId);

        $builder = $this->db->table('sales_debit_notes n')
            ->join('branches b', 'b.branch_id = n.branch_id')
            ->join('people p', 'p.person_id = n.customer_id', 'left')
            ->join('document_numbers d', "d.doc_type = '" . DocumentType::SalesDebitNote->value . "' AND d.source_table = 'sales_debit_notes' AND d.source_id = n.debit_note_id", 'left')
            ->where('n.company_id', CompanyContext::id());

        if ($branchIds !== null) {
            $branchIds === [] ? $builder->where('1 = 0', null, false) : $builder->whereIn('n.branch_id', $branchIds);
        }

        if ($mine !== null) {
            $builder->where('n.created_by', $mine);
        }

        if (($filters['status'] ?? '') === 'posted' || ($filters['status'] ?? '') === 'reversed') {
            $builder->where('n.status', $filters['status']);
        }

        if (! empty($filters['customer_id'])) {
            $builder->where('n.customer_id', (int) $filters['customer_id']);
        }

        if (! empty($filters['from'])) {
            $builder->where('n.note_date >=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $builder->where('n.note_date <=', $filters['to']);
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $builder->groupStart()
                ->like('d.number', $search)
                ->orLike("CONCAT(p.first_name, ' ', p.last_name)", $search)
                ->groupEnd();
        }

        return $builder;
    }

    /**
     * The number a debit note shows: the one its sequence gave it, or its id in five digits.
     */
    public function numberOf(int $debitNoteId): string
    {
        return $this->numbering->label(DocumentType::SalesDebitNote, 'sales_debit_notes', $debitNoteId);
    }

    public function peekNumber(): ?string
    {
        return $this->numbering->peek(DocumentType::SalesDebitNote);
    }

    /**
     * The revenue and expense accounts a note may name: leaves that are open and belong to no customer,
     * supplier, stock location or treasury.
     *
     * @return list<object>
     */
    public function allowedAccounts(): array
    {
        return $this->db->table('accounting_accounts')
            ->select('account_id, code, name')
            ->where('company_id', CompanyContext::id())
            ->where(['is_group' => 0, 'active' => 1])
            ->where('entity_type', null)
            ->whereIn('account_type', array_map(static fn (AccountType $type): string => $type->value, self::ACCOUNT_TYPES))
            ->orderBy('code', 'ASC')
            ->get()
            ->getResult();
    }

    /**
     * @throws AccountingException
     */
    private function found(int $debitNoteId): object
    {
        $row = $this->db->table('sales_debit_notes')->where(['company_id' => CompanyContext::id(), 'debit_note_id' => $debitNoteId])->get()->getRow();

        if ($row === null) {
            throw new AccountingException(AccountingError::DebitNoteNotFound, "Debit note {$debitNoteId} does not exist.", ['debit_note_id' => $debitNoteId]);
        }

        return $row;
    }

    /**
     * The debit note as it stands now, read under a lock.
     *
     * @throws AccountingException
     */
    private function standing(int $debitNoteId): object
    {
        $table = $this->db->prefixTable('sales_debit_notes');
        $note  = LockedRead::row($this->db, "SELECT * FROM {$table} WHERE debit_note_id = ? FOR UPDATE", [$debitNoteId]);

        if ($note === null) {
            throw new AccountingException(AccountingError::DebitNoteNotFound, "Debit note {$debitNoteId} does not exist.", ['debit_note_id' => $debitNoteId]);
        }

        if ($note->status !== 'posted') {
            throw new AccountingException(AccountingError::DebitNoteReversed, 'This debit note is already taken back.', ['debit_note_id' => $debitNoteId]);
        }

        return $note;
    }

    /**
     * @throws AccountingException
     */
    private function assertOn(): void
    {
        if (! $this->settings->isEnabled() || ! $this->settings->debitNoteEnabled()) {
            throw new AccountingException(AccountingError::DebitNoteOff, 'Sales debit notes are not turned on.');
        }
    }

    /**
     * @throws AccountingException
     */
    private function assertCustomer(int $customerId): void
    {
        if ($customerId <= 0 || $this->db->table('customers')->where(['person_id' => $customerId, 'deleted' => 0])->countAllResults() === 0) {
            throw new AccountingException(AccountingError::DebitNoteCustomerMissing, "Customer {$customerId} does not exist.", ['customer_id' => $customerId]);
        }
    }

    /**
     * @throws BranchException
     */
    private function branch(?int $requested, ?int $employeeId): int
    {
        $branchId = $requested !== null && $requested > 0 ? $requested : $this->branches->defaultBranchId($employeeId);
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
     * The amount, the rate and what comes of them, the way an invoice rounds: the tax as calculated, the
     * total to the cent, and the revenue what is left of the total after the tax. Under the Saudi rules the
     * amount is taken to the halala first, as its document states it, and the tax worked out on that.
     *
     * @return array{amount: numeric-string, percent: numeric-string, tax: numeric-string, total: numeric-string, revenue: numeric-string}
     *
     * @throws AccountingException
     */
    private function figures(string $amount, string $percent): array
    {
        $amount  = trim($amount);
        $percent = trim($percent) === '' ? '0' : trim($percent);

        if (preg_match(self::AMOUNT, $amount) !== 1 || bccomp($amount, '0', SaleAmounts::SCALE) <= 0) {
            throw new AccountingException(AccountingError::DebitNoteAmountInvalid, 'The amount must be more than zero.');
        }

        if (preg_match(self::PERCENT, $percent) !== 1 || bccomp($percent, '100', SaleAmounts::SCALE) > 0) {
            throw new AccountingException(AccountingError::DebitNoteTaxInvalid, 'The tax must be a percentage from 0 to 100.');
        }

        $saudi  = (new CompanyTaxProfile($this->db))->isSaudiMode();
        $amount = bcadd($saudi ? SaleAmounts::round($amount, 2) : $amount, '0', SaleAmounts::SCALE);
        $tax    = SaleAmounts::round(bcmul($amount, bcdiv($percent, '100', 8), 8), $saudi ? 2 : SaleAmounts::SCALE);
        $split  = InvoiceRounding::split($amount, $tax);

        if (bccomp($split['total'], '0', SaleAmounts::SCALE) <= 0 || bccomp($split['total'], self::TOTAL_MAX, SaleAmounts::SCALE) > 0) {
            throw new AccountingException(AccountingError::DebitNoteAmountInvalid, 'The amount with its tax must come to at least one cent and fit the books.', ['total' => $split['total']]);
        }

        return ['amount' => $amount, 'percent' => bcadd($percent, '0', SaleAmounts::SCALE), 'tax' => $split['tax'], 'total' => $split['total'], 'revenue' => $split['revenue']];
    }

    /**
     * @throws AccountingException
     */
    private function day(string $date): string
    {
        $date = trim($date);
        $day  = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if ($day === false || $day->format('Y-m-d') !== $date) {
            throw new AccountingException(AccountingError::InvalidDate, 'The date is not a valid date.');
        }

        if ($date > date('Y-m-d')) {
            throw new AccountingException(AccountingError::InvalidDate, "A debit note is dated up to today, not {$date}.", ['date' => $date]);
        }

        return $date;
    }

    /**
     * @throws AccountingException
     */
    private function noteText(string $note): string
    {
        $note = trim($note);

        if (mb_strlen($note) > self::NOTE_MAX) {
            throw new AccountingException(AccountingError::DebitNoteNoteTooLong, 'The note is longer than 500 characters.', ['max' => self::NOTE_MAX]);
        }

        return $note;
    }

    /**
     * @throws AccountingException
     */
    private function reason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX) {
            throw new AccountingException(AccountingError::ReasonRequired, 'A reason of 1 to 255 characters is required.');
        }

        return $reason;
    }

    /**
     * The account a note names, which must be one it may credit, or null for the revenue of the routing.
     *
     * @throws AccountingException
     */
    private function revenueAccount(?int $accountId): ?int
    {
        if ($accountId === null || $accountId <= 0) {
            return null;
        }

        foreach ($this->allowedAccounts() as $account) {
            if ((int) $account->account_id === $accountId) {
                return $accountId;
            }
        }

        throw new AccountingException(AccountingError::DebitNoteAccountInvalid, 'The account of the note must be an open revenue or expense account without a party of its own.', ['account_id' => $accountId]);
    }

    /**
     * @throws PostingException
     */
    private function routed(RoutingKey $key): int
    {
        $account = $this->resolver->accountFor($key);

        if ($account === null) {
            throw new PostingException(PostingError::RoutingRowCancelled, "The routing of {$key->value} is cancelled, but a debit note cannot be posted without it.", ['routing_key' => $key->value]);
        }

        return $account;
    }

    private function origin(?int $employeeId): JournalOrigin
    {
        return $employeeId === null ? JournalOrigin::System : JournalOrigin::User;
    }
}
