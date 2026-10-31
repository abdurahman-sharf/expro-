<?php

namespace App\Libraries\Accounting;

use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/**
 * The cheques received from customers and paid to suppliers, from the day they are entered to the
 * day they are collected or bounce. A status change posts a journal of its own and is taken back by
 * reversing that journal, never by editing or deleting it, so the books keep what they said on every
 * day. The party of a cheque never changes: a cheque entered for the wrong party is deleted and entered
 * again. What a customer or a supplier holds in cheques is credit that settles their open invoices
 * (ChequeCredits), and it follows the status: a bounced cheque gives the invoices back.
 */
final class ChequeService
{
    use RunsInTransaction;

    public const ENTITY      = 'cheque';
    private const NUMBER_MAX = 64;
    private const NAME_MAX   = 255;
    private const NOTE_MAX   = 500;

    private BaseConnection $db;
    private AccountingGuard $guard;
    private BranchGuard $branches;
    private PostingService $posting;
    private DocumentJournals $journals;
    private TreasuryScopes $scopes;
    private TreasuryService $treasuries;
    private ChequePostingBuilder $builder;
    private CustomerLock $customerLock;
    private SupplierLock $supplierLock;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db           = $db ?? Database::connect();
        $this->guard        = $guard ?? new AccountingGuard();
        $this->branches     = $branches ?? new BranchGuard($this->db);
        $this->posting      = new PostingService($this->db);
        $this->journals     = new DocumentJournals($this->db, $this->branches);
        $this->scopes       = new TreasuryScopes($this->db, $this->branches);
        $this->treasuries   = new TreasuryService($this->db, null, $this->branches);
        $this->builder      = new ChequePostingBuilder(new RoutingResolver($this->db));
        $this->customerLock = new CustomerLock($this->db);
        $this->supplierLock = new SupplierLock($this->db);
        $this->audit        = new AuditLog($this->db);
    }

    /**
     * Enters a cheque received from a customer. Its journal is dated the day it was issued, whatever
     * its due date, and the bank it is to be collected into decides its branch.
     *
     * @param array{party_id?: int|string, treasury_id?: int|string, cheque_number?: string, amount?: string, issue_date?: string, due_date?: string, drawer_name?: string, endorsed_to?: string, description?: string} $input
     *
     * @return int The cheque.
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function receive(array $input, ?int $employeeId = null): int
    {
        $this->guard->require(AccountingGuard::CHEQUES_MANAGE, $employeeId);

        $data     = $this->common($input);
        $partyId  = (int) ($input['party_id'] ?? 0);
        $treasury = $this->treasury((int) ($input['treasury_id'] ?? 0));

        $this->customer($partyId);
        $this->branches->requireBranch((int) $treasury->branch_id, $employeeId);

        if ($data['issue_date'] > date('Y-m-d')) {
            throw new AccountingException(AccountingError::ChequeDateInvalid, 'A cheque is received on a day up to today.', ['date' => $data['issue_date']]);
        }

        $row = [...$data, 'direction' => ChequeDirection::Received->value, 'status' => ChequeStatus::Received->value, 'party_id' => $partyId, 'treasury_id' => (int) $treasury->treasury_id, 'branch_id' => (int) $treasury->branch_id, 'book_id' => null];

        return $this->attempt(
            fn (): int => $this->locked(ChequeDirection::Received, $partyId, function () use ($row, $employeeId): int {
                $chequeId = $this->insert($row, DocumentType::ReceivedCheque, $employeeId);

                $this->perform($this->row($chequeId, true), ChequeTransition::Receive, $row['issue_date'], null, '', $employeeId);
                $this->audit->write(self::ENTITY, $chequeId, 'created', $employeeId, JournalOrigin::User, ['direction' => 'received', 'amount' => $row['amount']], (int) $row['branch_id']);

                return $chequeId;
            }),
            ChequeTransition::Receive,
            null,
            (int) $treasury->branch_id,
            $employeeId,
        );
    }

    /**
     * Writes a cheque to a supplier out of a cheque book of a bank account. Issuing posts nothing: the
     * books hear of the cheque when it is delivered, which this does too when it is given a day.
     *
     * @param array{party_id?: int|string, book_id?: int|string, cheque_number?: string, amount?: string, issue_date?: string, due_date?: string, drawer_name?: string, endorsed_to?: string, description?: string} $input
     *
     * @return int The cheque.
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function issue(array $input, ?int $employeeId = null, ?string $deliverOn = null): int
    {
        $this->guard->require(AccountingGuard::CHEQUES_MANAGE, $employeeId);

        $data    = $this->common($input);
        $partyId = (int) ($input['party_id'] ?? 0);
        $book    = $this->book((int) ($input['book_id'] ?? 0));

        $this->supplier($partyId);
        $this->branches->requireBranch((int) $book->branch_id, $employeeId);
        $this->assertSerial($book, $data['cheque_number']);

        $row = [...$data, 'direction' => ChequeDirection::Paid->value, 'status' => ChequeStatus::Issued->value, 'party_id' => $partyId, 'treasury_id' => (int) $book->treasury_id, 'branch_id' => (int) $book->branch_id, 'book_id' => (int) $book->book_id];

        return $this->attempt(
            fn (): int => $this->locked(ChequeDirection::Paid, $partyId, function () use ($row, $deliverOn, $employeeId): int {
                $chequeId = $this->insert($row, DocumentType::PaidCheque, $employeeId);

                $this->perform($this->row($chequeId, true), ChequeTransition::Issue, $row['issue_date'], null, '', $employeeId);

                if ($deliverOn !== null) {
                    $this->perform($this->row($chequeId, true), ChequeTransition::Deliver, $deliverOn, null, '', $employeeId);
                }

                $this->audit->write(self::ENTITY, $chequeId, 'created', $employeeId, JournalOrigin::User, ['direction' => 'paid', 'amount' => $row['amount']], (int) $row['branch_id']);

                return $chequeId;
            }),
            ChequeTransition::Deliver,
            null,
            (int) $row['branch_id'],
            $employeeId,
        );
    }

    /**
     * Changes a cheque. The text of a cheque can always change. Its amount, its dates, its number and
     * the bank (or the book) it belongs to can change only while it is in its first status, and the
     * journal of a received cheque is then reversed and posted again. The party never changes.
     *
     * @param array<string, mixed> $input
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function update(int $chequeId, array $input, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::CHEQUES_MANAGE, $employeeId);

        $stored = $this->row($chequeId);

        $this->branches->requireBranch((int) $stored->branch_id, $employeeId);

        $direction = ChequeDirection::from($stored->direction);

        $this->attempt(
            fn () => $this->locked($direction, (int) $stored->party_id, function () use ($chequeId, $input, $employeeId): void {
                $row = $this->row($chequeId, true);

                $this->change($row, $input, $employeeId);
            }),
            ChequeTransition::Receive,
            $chequeId,
            (int) $stored->branch_id,
            $employeeId,
        );
    }

    /**
     * @throws AccountingException|BranchException|PostingException
     */
    public function deposit(int $chequeId, string $date, ?int $employeeId = null, string $note = ''): void
    {
        $this->move($chequeId, static fn (object $row): ChequeTransition => ChequeTransition::Deposit, $date, null, $note, $employeeId);
    }

    /**
     * Collects a cheque. One that was deposited needs the fees the bank kept, which may be none; one
     * that was not deposited is collected into its bank directly, and a cheque paid to a supplier
     * leaves its bank.
     *
     * @param numeric-string|null $fees
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function collect(int $chequeId, string $date, ?string $fees = null, ?int $employeeId = null, string $note = ''): void
    {
        $this->move($chequeId, static fn (object $row): ChequeTransition => match ($row->status) {
            ChequeStatus::Deposited->value => ChequeTransition::DepositCollect,
            ChequeStatus::Delivered->value => ChequeTransition::PayCollect,
            default                        => ChequeTransition::Collect,
        }, $date, $fees, $note, $employeeId);
    }

    /**
     * @throws AccountingException|BranchException|PostingException
     */
    public function reject(int $chequeId, string $date, ?int $employeeId = null, string $note = ''): void
    {
        $this->move($chequeId, static fn (object $row): ChequeTransition => match ($row->status) {
            ChequeStatus::Deposited->value => ChequeTransition::DepositReject,
            ChequeStatus::Delivered->value => ChequeTransition::PayReject,
            default                        => ChequeTransition::Reject,
        }, $date, null, $note, $employeeId);
    }

    /**
     * @throws AccountingException|BranchException|PostingException
     */
    public function deliver(int $chequeId, string $date, ?int $employeeId = null, string $note = ''): void
    {
        $this->move($chequeId, static fn (object $row): ChequeTransition => ChequeTransition::Deliver, $date, null, $note, $employeeId);
    }

    /**
     * @throws AccountingException|BranchException|PostingException
     */
    public function cancel(int $chequeId, ?int $employeeId = null, string $note = ''): void
    {
        $this->move($chequeId, static fn (object $row): ChequeTransition => ChequeTransition::Cancel, date('Y-m-d'), null, $note, $employeeId);
    }

    /**
     * Takes back the last status change that stands: its journals are reversed and the cheque goes
     * back to the status it came from. The entry of the cheque itself is not taken back this way, it
     * is deleted.
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function undo(int $chequeId, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::CHEQUES_MANAGE, $employeeId);

        $stored = $this->row($chequeId);

        $this->branches->requireBranch((int) $stored->branch_id, $employeeId);

        $direction = ChequeDirection::from($stored->direction);

        $this->attempt(
            fn () => $this->locked($direction, (int) $stored->party_id, function () use ($chequeId, $direction, $employeeId): void {
                $row   = $this->row($chequeId, true);
                $event = $this->lastEvent($chequeId);

                if ($event === null || $event->transition === ChequeTransition::Receive->value || $event->transition === ChequeTransition::Issue->value) {
                    throw new AccountingException(AccountingError::ChequeNothingToUndo, 'There is no status change of the cheque to take back.', ['cheque_id' => $chequeId]);
                }

                $transition = ChequeTransition::from($event->transition);

                $this->reverseSource($transition, $chequeId, $employeeId, 'Cheque ' . $row->cheque_number . ' ' . $transition->value . ' undone');

                $this->db->table('cheque_events')->where('event_id', $event->event_id)->update(['undone_by' => $employeeId, 'undone_at' => date('Y-m-d H:i:s')]);

                $previous = $this->lastEvent($chequeId);

                $this->db->table('cheques')->where('cheque_id', $chequeId)->update([
                    'status'       => $event->from_status,
                    'status_date'  => $previous === null ? null : $previous->event_date,
                    'status_note'  => '',
                    'deposit_fees' => '0',
                ]);

                $this->settle($direction, (int) $row->party_id, $employeeId);
                $this->audit->write(self::ENTITY, $chequeId, 'cheque_undone', $employeeId, JournalOrigin::User, ['transition' => $transition->value, 'to' => $event->from_status], (int) $row->branch_id);
            }),
            ChequeTransition::Receive,
            $chequeId,
            (int) $stored->branch_id,
            $employeeId,
        );
    }

    /**
     * Deletes a cheque in its first status (received, or issued), taking back its journal. A cheque
     * that has moved on is not deleted: its last status change is undone first.
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function delete(int $chequeId, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::CHEQUES_MANAGE, $employeeId);

        $stored = $this->row($chequeId);

        $this->branches->requireBranch((int) $stored->branch_id, $employeeId);

        $direction = ChequeDirection::from($stored->direction);

        $this->attempt(
            fn () => $this->locked($direction, (int) $stored->party_id, function () use ($chequeId, $direction, $employeeId): void {
                $row = $this->row($chequeId, true);

                if (! ChequeStatus::from($row->status)->isFirst()) {
                    throw new AccountingException(AccountingError::ChequeDeleteLocked, 'A cheque is deleted only while it is in its first status.', ['cheque_id' => $chequeId, 'status' => $row->status]);
                }

                foreach ([ChequeTransition::Receive, ChequeTransition::Deliver] as $transition) {
                    $this->reverseSource($transition, $chequeId, $employeeId, 'Cheque ' . $row->cheque_number . ' deleted');
                }

                $this->db->table('cheques')->where('cheque_id', $chequeId)->update(['deleted' => 1]);
                $this->settle($direction, (int) $row->party_id, $employeeId);
                $this->audit->write(self::ENTITY, $chequeId, 'deleted', $employeeId, JournalOrigin::User, ['amount' => $row->amount], (int) $row->branch_id);
            }),
            ChequeTransition::Receive,
            $chequeId,
            (int) $stored->branch_id,
            $employeeId,
        );
    }

    public function find(int $chequeId): ?object
    {
        return $this->db->table('cheques c')
            ->select('c.*, ' . ChequeList::PARTY_NAME . ' AS party_name, t.name AS treasury_name, b.code AS branch_code, k.name AS book_name', false)
            ->join('people p', 'p.person_id = c.party_id')
            ->join('customers cu', 'cu.person_id = c.party_id', 'left')
            ->join('suppliers s', 's.person_id = c.party_id', 'left')
            ->join('treasuries t', 't.treasury_id = c.treasury_id')
            ->join('branches b', 'b.branch_id = c.branch_id')
            ->join('cheque_books k', 'k.book_id = c.book_id', 'left')
            ->where('c.company_id', CompanyContext::id())
            ->where('c.cheque_id', $chequeId)
            ->get()
            ->getRow();
    }

    /**
     * The status changes of a cheque, oldest first, with the ones that were taken back.
     *
     * @return list<object>
     */
    public function events(int $chequeId): array
    {
        return $this->db->table('cheque_events')->where('cheque_id', $chequeId)->orderBy('event_id', 'ASC')->get()->getResult();
    }

    /**
     * The journals a cheque has written, oldest first.
     *
     * @return list<object>
     */
    public function journalsOf(int $chequeId): array
    {
        return $this->db->table('accounting_journals')
            ->select('journal_id, number, series, journal_date, status, revision, source_type, total_debit, reverses_journal_id')
            ->whereIn('source_type', ChequeSources::ALL)
            ->where('source_id', $chequeId)
            ->orderBy('journal_id', 'ASC')
            ->get()
            ->getResult();
    }

    public function branchOf(int $chequeId): ?int
    {
        $row = $this->db->table('cheques')->select('branch_id')->where(['company_id' => CompanyContext::id(), 'cheque_id' => $chequeId])->get()->getRow();

        return $row === null ? null : (int) $row->branch_id;
    }

    /**
     * What a cheque in a status can be asked to do.
     *
     * @return list<string> The names of the actions.
     */
    public function actionsOf(object $cheque): array
    {
        return match (ChequeStatus::from($cheque->status)) {
            ChequeStatus::Received  => ['deposit', 'collect', 'reject', 'edit', 'delete'],
            ChequeStatus::Deposited => ['collect', 'reject', 'undo'],
            ChequeStatus::Issued    => ['deliver', 'cancel', 'edit', 'delete'],
            ChequeStatus::Delivered => ['collect', 'reject', 'undo'],
            default                 => ['undo'],
        };
    }

    /**
     * @param callable(object): ChequeTransition $pick
     * @param numeric-string|null                $fees
     *
     * @throws AccountingException|BranchException|PostingException
     */
    private function move(int $chequeId, callable $pick, string $date, ?string $fees, string $note, ?int $employeeId): void
    {
        $this->guard->require(AccountingGuard::CHEQUES_MANAGE, $employeeId);

        $stored = $this->row($chequeId);

        $this->branches->requireBranch((int) $stored->branch_id, $employeeId);

        $direction  = ChequeDirection::from($stored->direction);
        $transition = $pick($stored);

        $this->attempt(
            fn () => $this->locked($direction, (int) $stored->party_id, function () use ($chequeId, $pick, $date, $fees, $note, $employeeId): void {
                $row = $this->row($chequeId, true);

                $this->perform($row, $pick($row), $date, $fees, $note, $employeeId);
            }),
            $transition,
            $chequeId,
            (int) $stored->branch_id,
            $employeeId,
        );
    }

    /**
     * One status change inside the transaction that holds the cheque and its party: it is checked,
     * its journals are posted, the cheque and its history are written and the credit of the party is
     * settled.
     *
     * @param numeric-string|null $fees
     *
     * @throws AccountingException|BranchException|PostingException
     */
    private function perform(object $row, ChequeTransition $transition, string $date, ?string $fees, string $note, ?int $employeeId): void
    {
        $cheque = $this->document($row);

        if ($transition->fromStatus() !== null && $cheque->status !== $transition->fromStatus()) {
            throw new AccountingException(AccountingError::ChequeStatusInvalid, "A cheque that is {$cheque->status->value} cannot be {$transition->toStatus()->value}.", ['status' => $cheque->status->value, 'transition' => $transition->value]);
        }

        $date = $this->date($date);
        $fees = $this->feesFor($transition, $fees);

        $this->assertTransitionDate($cheque, $transition, $date, $row);

        $source = $transition->source();

        if ($source !== null) {
            $first = $this->nextRevision($source, $cheque->chequeId);

            foreach ($this->builder->drafts($cheque, $transition, $date, $fees, $first, $employeeId) as $draft) {
                $this->scopes->assertMayPost($draft->lines, $employeeId);
                $this->posting->post($draft);
            }
        }

        $this->db->table('cheque_events')->insert([
            'cheque_id'   => $cheque->chequeId,
            'transition'  => $transition->value,
            'from_status' => $transition->fromStatus()?->value,
            'to_status'   => $transition->toStatus()->value,
            'event_date'  => $date,
            'fees'        => $fees,
            'note'        => mb_substr(trim($note), 0, self::NOTE_MAX),
            'created_by'  => $employeeId,
        ]);

        $this->db->table('cheques')->where('cheque_id', $cheque->chequeId)->update([
            'status'       => $transition->toStatus()->value,
            'status_date'  => $date,
            'status_note'  => mb_substr(trim($note), 0, self::NOTE_MAX),
            'deposit_fees' => $fees,
        ]);

        $this->settle($cheque->direction, $cheque->partyId, $employeeId);

        if ($transition !== ChequeTransition::Receive && $transition !== ChequeTransition::Issue) {
            $this->audit->write(self::ENTITY, $cheque->chequeId, $this->auditAction($transition), $employeeId, JournalOrigin::User, ['transition' => $transition->value, 'date' => $date, 'fees' => $fees], $cheque->branchId);
        }
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws AccountingException|BranchException|PostingException
     */
    private function change(object $row, array $input, ?int $employeeId): void
    {
        $status    = ChequeStatus::from($row->status);
        $direction = ChequeDirection::from($row->direction);

        if (isset($input['party_id']) && (int) $input['party_id'] !== (int) $row->party_id) {
            throw new AccountingException(AccountingError::ChequeLocked, 'The party of a cheque does not change: delete the cheque and enter it again.', ['cheque_id' => (int) $row->cheque_id]);
        }

        $merged  = [...(array) $row, ...array_intersect_key($input, array_flip(['cheque_number', 'amount', 'issue_date', 'due_date', 'drawer_name', 'endorsed_to', 'description', 'treasury_id', 'book_id']))];
        $data    = $this->common($merged);
        $changes = [];

        foreach (['cheque_number', 'amount', 'issue_date', 'due_date'] as $field) {
            if ($field === 'amount' ? bccomp((string) $data[$field], (string) $row->{$field}, SaleAmounts::SCALE) !== 0 : (string) $data[$field] !== (string) $row->{$field}) {
                $changes[] = $field;
            }
        }

        $treasuryId = (int) $row->treasury_id;
        $bookId     = $row->book_id === null ? null : (int) $row->book_id;
        $branchId   = (int) $row->branch_id;

        if ($direction === ChequeDirection::Received && (int) ($merged['treasury_id'] ?? 0) !== $treasuryId) {
            $treasury   = $this->treasury((int) $merged['treasury_id']);
            $treasuryId = (int) $treasury->treasury_id;
            $branchId   = (int) $treasury->branch_id;
            $changes[]  = 'treasury_id';

            $this->branches->requireBranch($branchId, $employeeId);
        }

        if ($direction === ChequeDirection::Paid && (int) ($merged['book_id'] ?? 0) !== (int) $bookId) {
            $book       = $this->book((int) $merged['book_id']);
            $bookId     = (int) $book->book_id;
            $treasuryId = (int) $book->treasury_id;
            $branchId   = (int) $book->branch_id;
            $changes[]  = 'book_id';

            $this->branches->requireBranch($branchId, $employeeId);
        }

        if ($changes !== [] && ! $status->isFirst()) {
            throw new AccountingException(AccountingError::ChequeLocked, 'The amount, the dates, the number and the bank of a cheque are locked once it has moved on.', ['cheque_id' => (int) $row->cheque_id, 'fields' => implode(',', $changes)]);
        }

        if ($direction === ChequeDirection::Received && in_array('issue_date', $changes, true) && $data['issue_date'] > date('Y-m-d')) {
            throw new AccountingException(AccountingError::ChequeDateInvalid, 'A cheque is received on a day up to today.', ['date' => $data['issue_date']]);
        }

        if ($direction === ChequeDirection::Paid && $bookId !== null && (in_array('cheque_number', $changes, true) || in_array('book_id', $changes, true))) {
            $this->assertSerial($this->book($bookId), $data['cheque_number']);
        }

        $this->db->table('cheques')->where('cheque_id', $row->cheque_id)->update([
            'cheque_number' => $data['cheque_number'],
            'amount'        => $data['amount'],
            'issue_date'    => $data['issue_date'],
            'due_date'      => $data['due_date'],
            'drawer_name'   => $data['drawer_name'],
            'endorsed_to'   => $data['endorsed_to'],
            'description'   => $data['description'],
            'treasury_id'   => $treasuryId,
            'book_id'       => $bookId,
            'branch_id'     => $branchId,
        ]);

        $changed = $this->row((int) $row->cheque_id, true);

        if ($direction === ChequeDirection::Received && $changes !== []) {
            $cheque = $this->document($changed);
            $draft  = $this->builder->drafts($cheque, ChequeTransition::Receive, $cheque->issueDate, '0', $this->nextRevision((string) ChequeTransition::Receive->source(), $cheque->chequeId), $employeeId)[0];

            $this->journals->sync((string) ChequeTransition::Receive->source(), $cheque->chequeId, $draft, $employeeId, 'Cheque ' . $cheque->chequeNumber . ' changed');
            $this->settle($direction, $cheque->partyId, $employeeId);
        }

        $this->audit->write(self::ENTITY, (int) $row->cheque_id, 'updated', $employeeId, JournalOrigin::User, ['fields' => $changes], $branchId);
    }

    /**
     * Takes back the journals a status change of a cheque wrote that still stand. A change that wrote
     * none leaves nothing to do.
     *
     * @throws AccountingException|PostingException
     */
    private function reverseSource(ChequeTransition $transition, int $chequeId, ?int $employeeId, string $reason): void
    {
        $source = $transition->source();

        if ($source === null) {
            return;
        }

        while ($this->journals->active($source, $chequeId) !== null) {
            $this->journals->sync($source, $chequeId, null, $employeeId, $reason);
        }
    }

    private function nextRevision(string $source, int $chequeId): int
    {
        return $this->journals->nextRevision($source, $chequeId);
    }

    private function settle(ChequeDirection $direction, int $partyId, ?int $employeeId): void
    {
        $direction === ChequeDirection::Received
            ? (new CreditSettlement($this->db))->settle($partyId, $employeeId)
            : (new SupplierSettlement($this->db))->settle($partyId, $employeeId);
    }

    private function auditAction(ChequeTransition $transition): string
    {
        return match ($transition) {
            ChequeTransition::Deposit => 'cheque_deposited',
            ChequeTransition::Collect, ChequeTransition::DepositCollect, ChequeTransition::PayCollect => 'cheque_collected',
            ChequeTransition::Reject, ChequeTransition::DepositReject, ChequeTransition::PayReject => 'cheque_rejected',
            ChequeTransition::Deliver => 'cheque_delivered',
            ChequeTransition::Cancel  => 'cancelled',
            default                   => 'created',
        };
    }

    /**
     * @param numeric-string|null $fees
     *
     * @return numeric-string
     *
     * @throws AccountingException
     */
    private function feesFor(ChequeTransition $transition, ?string $fees): string
    {
        if ($transition === ChequeTransition::DepositCollect) {
            if ($fees === null || trim($fees) === '') {
                throw new AccountingException(AccountingError::ChequeFeesRequired, 'The fees the bank kept are needed to collect a deposited cheque, even when there are none.');
            }

            return $this->money(trim($fees), AccountingError::ChequeFeesInvalid, true);
        }

        if ($fees !== null && trim($fees) !== '' && bccomp(trim($fees), '0', SaleAmounts::SCALE) !== 0) {
            throw new AccountingException(AccountingError::ChequeFeesInvalid, 'Only a deposited cheque has deposit fees.', ['fees' => $fees]);
        }

        return '0.0000';
    }

    /**
     * The day of a status change must not come before the cheque or before the change it follows. A
     * collection is not before the due date; it may be on a day to come, as the reference system takes
     * it. The other changes are made up to today.
     *
     * @throws AccountingException
     */
    private function assertTransitionDate(ChequeDocument $cheque, ChequeTransition $transition, string $date, object $row): void
    {
        if ($transition === ChequeTransition::Receive || $transition === ChequeTransition::Issue) {
            return;
        }

        $last = $this->lastEvent($cheque->chequeId);
        $from = $last === null ? $cheque->issueDate : max($cheque->issueDate, (string) $last->event_date);

        if ($date < $from) {
            throw new AccountingException(AccountingError::ChequeDateInvalid, "The change is dated {$date}, before {$from}.", ['date' => $date, 'until' => $from]);
        }

        if ($transition->isCollection()) {
            if ($date < $cheque->dueDate) {
                throw new AccountingException(AccountingError::ChequeCollectBeforeDue, 'A cheque is not collected before its due date.', ['date' => $date, 'until' => $cheque->dueDate]);
            }

            return;
        }

        if ($date > date('Y-m-d')) {
            throw new AccountingException(AccountingError::ChequeDateInvalid, 'A status change is dated up to today.', ['date' => $date]);
        }
    }

    private function lastEvent(int $chequeId): ?object
    {
        return $this->db->table('cheque_events')->where('cheque_id', $chequeId)->where('undone_at', null)->orderBy('event_id', 'DESC')->limit(1)->get()->getRow();
    }

    /**
     * @param callable(): mixed $work
     */
    private function locked(ChequeDirection $direction, int $partyId, callable $work): mixed
    {
        $lock = $direction === ChequeDirection::Received ? $this->customerLock : $this->supplierLock;

        return $lock->run($partyId, fn (): mixed => $this->transactional($work));
    }

    /**
     * Runs a change of a cheque and, when the books refuse it, records the refusal after the
     * transaction has rolled back.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     *
     * @throws AccountingException|BranchException|PostingException
     */
    private function attempt(callable $work, ChequeTransition $transition, ?int $chequeId, int $branchId, ?int $employeeId): mixed
    {
        try {
            return $work();
        } catch (PostingException $failure) {
            (new PostingFailureLog($this->db))->recordUnbuilt((string) ($transition->source() ?? 'cheque_in_received'), $chequeId, 1, JournalEntryType::General, $failure, $employeeId, $branchId);

            throw $failure;
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insert(array $row, DocumentType $type, ?int $employeeId): int
    {
        $this->db->table('cheques')->insert([
            'company_id'    => CompanyContext::id(),
            'branch_id'     => $row['branch_id'],
            'direction'     => $row['direction'],
            'status'        => $row['status'],
            'party_id'      => $row['party_id'],
            'treasury_id'   => $row['treasury_id'],
            'book_id'       => $row['book_id'],
            'cheque_number' => $row['cheque_number'],
            'amount'        => $row['amount'],
            'issue_date'    => $row['issue_date'],
            'due_date'      => $row['due_date'],
            'drawer_name'   => $row['drawer_name'],
            'endorsed_to'   => $row['endorsed_to'],
            'description'   => $row['description'],
            'created_by'    => $employeeId,
        ]);

        $chequeId = (int) $this->db->insertID();

        (new DocumentNumbering($this->db))->assign($type, 'cheques', $chequeId, (int) $row['branch_id']);

        return $chequeId;
    }

    /**
     * @throws AccountingException
     */
    private function row(int $chequeId, bool $lock = false): object
    {
        $table = $this->db->prefixTable('cheques');
        $sql   = "SELECT * FROM {$table} WHERE company_id = ? AND cheque_id = ? AND deleted = 0" . ($lock ? ' FOR UPDATE' : '');
        $row   = $lock ? LockedRead::row($this->db, $sql, [CompanyContext::id(), $chequeId]) : $this->db->query($sql, [CompanyContext::id(), $chequeId])->getRow();

        if ($row === null) {
            throw new AccountingException(AccountingError::ChequeNotFound, "Cheque {$chequeId} does not exist.", ['cheque_id' => $chequeId]);
        }

        return $row;
    }

    private function document(object $row): ChequeDocument
    {
        return new ChequeDocument(
            chequeId: (int) $row->cheque_id,
            direction: ChequeDirection::from($row->direction),
            status: ChequeStatus::from($row->status),
            partyId: (int) $row->party_id,
            treasuryId: (int) $row->treasury_id,
            branchId: (int) $row->branch_id,
            chequeNumber: (string) $row->cheque_number,
            amount: bcadd((string) $row->amount, '0', SaleAmounts::SCALE),
            issueDate: substr((string) $row->issue_date, 0, 10),
            dueDate: substr((string) $row->due_date, 0, 10),
        );
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{cheque_number: string, amount: numeric-string, issue_date: string, due_date: string, drawer_name: string, endorsed_to: string, description: string}
     *
     * @throws AccountingException
     */
    private function common(array $input): array
    {
        $number = trim((string) ($input['cheque_number'] ?? ''));

        if ($number === '' || mb_strlen($number) > self::NUMBER_MAX) {
            throw new AccountingException(AccountingError::ChequeNumberRequired, 'A cheque has a number of 1 to 64 characters.');
        }

        $issue = $this->date((string) ($input['issue_date'] ?? ''));
        $due   = trim((string) ($input['due_date'] ?? '')) === '' ? $issue : $this->date((string) $input['due_date']);

        if ($due < $issue) {
            throw new AccountingException(AccountingError::ChequeDateInvalid, 'The due date of a cheque is not before the day it was issued.', ['date' => $due, 'until' => $issue]);
        }

        return [
            'cheque_number' => $number,
            'amount'        => $this->money(trim((string) ($input['amount'] ?? '')), AccountingError::ChequeAmountInvalid, false),
            'issue_date'    => $issue,
            'due_date'      => $due,
            'drawer_name'   => mb_substr(trim((string) ($input['drawer_name'] ?? '')), 0, self::NAME_MAX),
            'endorsed_to'   => mb_substr(trim((string) ($input['endorsed_to'] ?? '')), 0, self::NAME_MAX),
            'description'   => mb_substr(trim((string) ($input['description'] ?? '')), 0, self::NOTE_MAX),
        ];
    }

    /**
     * @return numeric-string
     *
     * @throws AccountingException
     */
    private function money(string $value, AccountingError $error, bool $allowZero): string
    {
        if (preg_match('/^\d{1,11}(\.\d{1,4})?$/', $value) !== 1 || (! $allowZero && bccomp($value, '0', SaleAmounts::SCALE) <= 0)) {
            throw new AccountingException($error, $allowZero ? 'The amount is not a number of zero or more.' : 'The amount must be more than zero.');
        }

        return bcadd($value, '0', SaleAmounts::SCALE);
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

    /**
     * @throws AccountingException
     */
    private function customer(int $customerId): void
    {
        if ($this->db->table('customers')->where(['person_id' => $customerId, 'deleted' => 0])->countAllResults() === 0) {
            throw new AccountingException(AccountingError::ChequePartyMissing, "Customer {$customerId} does not exist.", ['party_id' => $customerId]);
        }
    }

    /**
     * @throws AccountingException
     */
    private function supplier(int $supplierId): void
    {
        if ($this->db->table('suppliers')->where(['person_id' => $supplierId, 'deleted' => 0])->countAllResults() === 0) {
            throw new AccountingException(AccountingError::ChequePartyMissing, "Supplier {$supplierId} does not exist.", ['party_id' => $supplierId]);
        }
    }

    /**
     * An active treasury.
     *
     * @throws AccountingException
     */
    private function treasury(int $treasuryId): object
    {
        $treasury = $this->treasuries->find($treasuryId);

        if ($treasury === null) {
            throw new AccountingException(AccountingError::TreasuryNotFound, "Treasury {$treasuryId} does not exist.", ['treasury_id' => $treasuryId]);
        }

        if ((int) $treasury->active === 0) {
            throw new AccountingException(AccountingError::TreasuryInactive, 'A cheque cannot be collected into a deactivated treasury.', ['treasury_id' => $treasuryId]);
        }

        return $treasury;
    }

    /**
     * An active cheque book.
     *
     * @throws AccountingException
     */
    private function book(int $bookId): object
    {
        if ($bookId <= 0) {
            throw new AccountingException(AccountingError::ChequeBookRequired, 'A cheque paid to a supplier is written out of a cheque book.');
        }

        $book = $this->db->table('cheque_books')->where(['company_id' => CompanyContext::id(), 'book_id' => $bookId])->get()->getRow();

        if ($book === null) {
            throw new AccountingException(AccountingError::ChequeBookNotFound, "Cheque book {$bookId} does not exist.", ['book_id' => $bookId]);
        }

        if ((int) $book->active === 0) {
            throw new AccountingException(AccountingError::ChequeBookInactive, 'A cheque is not written out of a book that is not in use.', ['book_id' => $bookId]);
        }

        return $book;
    }

    /**
     * The number of a cheque out of a book is one of its serials that no other cheque of the book has.
     *
     * @throws AccountingException
     */
    private function assertSerial(object $book, string $number): void
    {
        if (preg_match('/^\d{1,18}$/', $number) !== 1 || bccomp($number, (string) $book->first_serial, 0) < 0 || bccomp($number, (string) $book->last_serial, 0) > 0) {
            throw new AccountingException(AccountingError::ChequeNumberOutOfBook, 'The number of the cheque is not a serial of its book.', ['book_id' => (int) $book->book_id, 'min' => $book->first_serial, 'max' => $book->last_serial]);
        }

        if ($this->db->table('cheques')->where(['book_id' => (int) $book->book_id, 'cheque_number' => $number, 'deleted' => 0])->countAllResults() > 0) {
            throw new AccountingException(AccountingError::ChequeNumberTaken, 'Another cheque of the book has this number.', ['book_id' => (int) $book->book_id]);
        }
    }
}
