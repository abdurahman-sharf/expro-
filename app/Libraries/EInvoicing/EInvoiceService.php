<?php

namespace App\Libraries\EInvoicing;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\CustomerAdvances;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\LockedRead;
use App\Libraries\Accounting\RunsInTransaction;
use App\Libraries\Accounting\SalePostingBuilder;
use App\Libraries\Accounting\SalesDebitNotes;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use App\Libraries\Roles\RecordScope;
use App\Libraries\Tax\AdvanceVat;
use App\Libraries\Tax\CompanyTaxProfile;
use Closure;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Issues the documents of the layer and reads them back. Issuing is one transaction under the lock of the
 * counter of the company: the counter goes up by one, the document carries the hash of the one before it,
 * the file is built, held against the books, stamped when a key is in place, stored with its hash and never
 * changed, and the transport is asked to carry it, which today records that nothing was sent. A sale or a
 * note is issued once: asking again gives the document that was issued, so a retry or a second click makes
 * nothing new. A document that is wrong is corrected by a note, never by writing it again. Nothing here
 * writes to a journal, a number, a stock movement or a total. Whoever sees their own records only neither
 * issues nor reads the document of a sale or a note another employee made, and cannot tell it from one that
 * does not exist.
 */
final class EInvoiceService
{
    use RunsInTransaction;

    public const SOURCE_SALE    = SalePostingBuilder::SOURCE_SALE;
    public const SOURCE_NOTE    = SalesDebitNotes::SOURCE;
    public const SOURCE_ADVANCE = CustomerAdvances::SOURCE;
    public const SOURCE_RETURN  = 'advance_return';

    private BaseConnection $db;
    private AccountingGuard $guard;
    private BranchGuard $branches;
    private EInvoiceSettings $settings;
    private EInvoiceBuilder $builder;
    private EInvoiceParties $parties;
    private DocumentStamper $stamper;
    private JournalTie $tie;
    private AuditLog $audit;
    private EInvoiceTransport $transport;
    private RecordScope $scope;

    /**
     * @var Closure(): int
     */
    private Closure $clock;

    /**
     * @param (Closure(): int)|null $clock Seconds since the epoch, for the moment of signing.
     */
    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null, ?EInvoiceTransport $transport = null, ?Closure $clock = null)
    {
        $this->db        = $db ?? Database::connect();
        $this->guard     = $guard ?? new AccountingGuard();
        $this->branches  = $branches ?? new BranchGuard($this->db);
        $this->settings  = new EInvoiceSettings($this->db, $this->guard);
        $this->builder   = new EInvoiceBuilder($this->db, $this->settings);
        $this->parties   = new EInvoiceParties($this->db, $this->settings);
        $this->stamper   = new DocumentStamper();
        $this->tie       = new JournalTie($this->db);
        $this->audit     = new AuditLog($this->db);
        $this->transport = $transport ?? self::defaultTransport();
        $this->scope     = new RecordScope($this->db);
        $this->clock     = $clock ?? static fn (): int => time();
    }

    public static function defaultTransport(): EInvoiceTransport
    {
        return new NullEInvoiceTransport();
    }

    /**
     * @throws AccountingException|BranchException|EInvoiceException
     */
    public function issueSale(int $saleId, ?EInvoiceSubtype $subtype = null, string $reason = '', ?int $employeeId = null): IssuedEInvoice
    {
        $this->guard->require(AccountingGuard::EINVOICING_MANAGE, $employeeId);
        $this->assertOn();

        $sale = $this->db->table('sales')->select('branch_id, sale_type, return_of_sale_id, employee_id')->where('sale_id', $saleId)->get()->getRow();

        if ($sale === null || ! $this->scope->allows($employeeId, $sale->employee_id)) {
            throw new EInvoiceException(EInvoiceError::SourceNotFound, 'The sale does not exist.', ['number' => (string) $saleId]);
        }

        $this->branches->requireBranch((int) $sale->branch_id, $employeeId);

        return $this->transactional(function () use ($saleId, $subtype, $reason, $employeeId, $sale): IssuedEInvoice {
            $counter = $this->lockCounter();
            $known   = $this->documentOf(self::SOURCE_SALE, $saleId);

            if ($known !== null) {
                return $known;
            }

            $original = (int) $sale->sale_type === SALE_TYPE_RETURN ? $this->originalOfSale($sale->return_of_sale_id) : null;
            $document = $this->builder->fromSale($saleId, $subtype, $reason, $original);

            $this->tie->assertTies(self::SOURCE_SALE, $saleId, $document->taxInclusive, $document->taxAmount, $document->taxExclusive);

            return $this->store($document, self::SOURCE_SALE, $saleId, (int) $sale->branch_id, $original, $counter, $employeeId);
        });
    }

    /**
     * Issues the prepayment invoice (386) of an advance received under the Saudi rules.
     *
     * @throws AccountingException|BranchException|EInvoiceException
     */
    public function issueAdvance(int $advanceId, ?EInvoiceSubtype $subtype = null, ?int $employeeId = null): IssuedEInvoice
    {
        $this->guard->require(AccountingGuard::EINVOICING_MANAGE, $employeeId);
        $this->assertOn();

        $advance = $this->db->table('customer_advances')->select('branch_id, created_by')->where(['company_id' => CompanyContext::id(), 'advance_id' => $advanceId])->get()->getRow();

        if ($advance === null || ! $this->scope->allows($employeeId, $advance->created_by)) {
            throw new EInvoiceException(EInvoiceError::SourceNotFound, 'The advance does not exist.', ['number' => (string) $advanceId]);
        }

        $this->branches->requireBranch((int) $advance->branch_id, $employeeId);

        return $this->transactional(function () use ($advanceId, $subtype, $employeeId, $advance): IssuedEInvoice {
            $counter = $this->lockCounter();
            $known   = $this->documentOf(self::SOURCE_ADVANCE, $advanceId);

            if ($known !== null) {
                return $known;
            }

            $document = $this->builder->fromAdvance($advanceId, $subtype);

            $this->tie->assertAdvanceTies($advanceId, $document->payable(), $document->taxAmount);

            return $this->store($document, self::SOURCE_ADVANCE, $advanceId, (int) $advance->branch_id, null, $counter, $employeeId);
        });
    }

    /**
     * Issues the document of a sale completed under the Saudi rules, inside the transaction that completes it: an
     * invoice for a sale at the register or an invoice, a credit note for a return. Whoever completes the sale has
     * it issued, so no permission is asked. The file is built and held against the books before the counter is
     * locked, which is the last lock taken before the sale is committed. A sale that already has a document is
     * refused, never given that document back: it could only be reached around the rule.
     *
     * @throws AccountingException|EInvoiceException
     */
    public function issueForCompletedSale(int $saleId, ?int $employeeId = null): IssuedEInvoice
    {
        $this->assertOn();

        $sale = $this->db->table('sales')->select('branch_id, sale_type, return_of_sale_id')->where('sale_id', $saleId)->get()->getRow();

        if ($sale === null) {
            throw new EInvoiceException(EInvoiceError::SourceNotFound, 'The sale does not exist.', ['number' => (string) $saleId]);
        }

        return $this->transactional(function () use ($saleId, $employeeId, $sale): IssuedEInvoice {
            $original = (int) $sale->sale_type === SALE_TYPE_RETURN ? $this->originalOfReturn($sale->return_of_sale_id === null ? null : (int) $sale->return_of_sale_id) : null;
            $document = $this->builder->fromSale($saleId, null, $original === null ? '' : EInvoiceRules::NOTE_REASONS[3], $original);

            $this->tie->assertTies(self::SOURCE_SALE, $saleId, $document->taxInclusive, $document->taxAmount, $document->taxExclusive);

            $counter = $this->lockCounter();

            if ($this->documentOf(self::SOURCE_SALE, $saleId) !== null) {
                throw new EInvoiceException(EInvoiceError::AlreadyIssued, 'The sale already has a document.', ['number' => (string) $saleId]);
            }

            return $this->store($document, self::SOURCE_SALE, $saleId, (int) $sale->branch_id, $original, $counter, $employeeId);
        });
    }

    /**
     * Issues the prepayment invoice of an advance received under the Saudi rules, inside the transaction that
     * receives it, the way issueForCompletedSale issues the document of a sale.
     *
     * @throws AccountingException|EInvoiceException
     */
    public function issueForReceivedAdvance(int $advanceId, ?int $employeeId = null): IssuedEInvoice
    {
        $this->assertOn();

        $advance = $this->db->table('customer_advances')->select('branch_id')->where(['company_id' => CompanyContext::id(), 'advance_id' => $advanceId])->get()->getRow();

        if ($advance === null) {
            throw new EInvoiceException(EInvoiceError::SourceNotFound, 'The advance does not exist.', ['number' => (string) $advanceId]);
        }

        return $this->transactional(function () use ($advanceId, $employeeId, $advance): IssuedEInvoice {
            $document = $this->builder->fromAdvance($advanceId);

            $this->tie->assertAdvanceTies($advanceId, $document->payable(), $document->taxAmount);

            $counter = $this->lockCounter();

            if ($this->documentOf(self::SOURCE_ADVANCE, $advanceId) !== null) {
                throw new EInvoiceException(EInvoiceError::AlreadyIssued, 'The advance already has a document.', ['number' => (string) $advanceId]);
            }

            return $this->store($document, self::SOURCE_ADVANCE, $advanceId, (int) $advance->branch_id, null, $counter, $employeeId);
        });
    }

    /**
     * Issues the credit note of what went back of an advance under the Saudi rules, paid back or left when the advance
     * was cancelled, inside the transaction that gives it back: it refers to the prepayment invoice of the advance.
     *
     * @throws AccountingException|EInvoiceException
     */
    public function issueForAdvanceReturn(int $returnId, string $reason, ?int $employeeId = null): IssuedEInvoice
    {
        $this->assertOn();

        $return  = (new AdvanceVat($this->db))->returnOf($returnId);
        $advance = $return === null ? null : $this->db->table('customer_advances')->select('branch_id')->where(['company_id' => CompanyContext::id(), 'advance_id' => (int) $return->advance_id])->get()->getRow();

        if ($return === null || $advance === null) {
            throw new EInvoiceException(EInvoiceError::SourceNotFound, 'Nothing of the advance went back.', ['number' => (string) $returnId]);
        }

        return $this->transactional(function () use ($returnId, $reason, $employeeId, $return, $advance): IssuedEInvoice {
            $prepayment = $this->documentOf(self::SOURCE_ADVANCE, (int) $return->advance_id);

            if ($prepayment === null) {
                throw new EInvoiceException(EInvoiceError::OriginalNotIssued, 'The advance has no prepayment invoice for the credit note to refer to.');
            }

            $original = new OriginalReference($prepayment->documentId, $prepayment->number, $prepayment->subtype);
            $document = $this->builder->fromAdvanceReturn($returnId, $reason, $original);

            $this->tie->assertAdvanceReturnTies($returnId, $document->payable(), $document->taxAmount);

            $counter = $this->lockCounter();

            if ($this->documentOf(self::SOURCE_RETURN, $returnId) !== null) {
                throw new EInvoiceException(EInvoiceError::AlreadyIssued, 'What went back of the advance already has a document.', ['number' => (string) $returnId]);
            }

            return $this->store($document, self::SOURCE_RETURN, $returnId, (int) $advance->branch_id, $original, $counter, $employeeId);
        });
    }

    /**
     * The issued document a debit note raised under the Saudi rules corrects, named by its number: an invoice or a
     * credit note of the customer of the note, in a branch the employee works in and out of the records they may
     * see, checked before the note is raised.
     *
     * @throws EInvoiceException
     */
    public function originalForDebitNote(int $customerId, string $number, ?int $employeeId): int
    {
        $this->assertOn();

        $number = trim($number);

        if ($number === '') {
            throw new EInvoiceException(EInvoiceError::OriginalNotIssued, 'Under the Saudi rules a debit note names the issued document it corrects.');
        }

        $candidates = $this->db->table('einvoice_documents')->select('document_id')->where(['company_id' => CompanyContext::id(), 'document_number' => $number])->whereIn('doc_kind', [EInvoiceKind::Invoice->value, EInvoiceKind::CreditNote->value])->orderBy('document_id', 'DESC')->get()->getResult();
        $refusal    = new EInvoiceException(EInvoiceError::OriginalNotIssued, 'The debit note must refer to an issued invoice or credit note.', ['number' => $number]);

        foreach ($candidates as $candidate) {
            try {
                return $this->referable((int) $candidate->document_id, $customerId, $employeeId)->documentId;
            } catch (EInvoiceException $failure) {
                $refusal = $failure;
            }
        }

        throw $refusal;
    }

    /**
     * Issues the document of a debit note raised under the Saudi rules, inside the transaction that raises it.
     * Whoever may raise the note has it issued; the document it refers to is held to the same rules as when the
     * note is issued by hand.
     *
     * @throws AccountingException|EInvoiceException
     */
    public function issueForDebitNote(int $debitNoteId, string $reason, int $originalDocumentId, ?int $employeeId = null): IssuedEInvoice
    {
        $this->assertOn();

        $note = $this->db->table('sales_debit_notes')->select('branch_id, customer_id')->where('debit_note_id', $debitNoteId)->get()->getRow();

        if ($note === null) {
            throw new EInvoiceException(EInvoiceError::SourceNotFound, 'The debit note does not exist.', ['number' => (string) $debitNoteId]);
        }

        return $this->transactional(function () use ($debitNoteId, $reason, $originalDocumentId, $employeeId, $note): IssuedEInvoice {
            $referred = $this->referable($originalDocumentId, (int) $note->customer_id, $employeeId);
            $original = new OriginalReference($referred->documentId, $referred->number, $referred->subtype);
            $document = $this->builder->fromDebitNote($debitNoteId, $reason, $original);

            $this->tie->assertTies(self::SOURCE_NOTE, $debitNoteId, $document->taxInclusive, $document->taxAmount, $document->taxExclusive);

            $counter = $this->lockCounter();

            if ($this->documentOf(self::SOURCE_NOTE, $debitNoteId) !== null) {
                throw new EInvoiceException(EInvoiceError::AlreadyIssued, 'The debit note already has a document.', ['number' => (string) $debitNoteId]);
            }

            return $this->store($document, self::SOURCE_NOTE, $debitNoteId, (int) $note->branch_id, $original, $counter, $employeeId);
        });
    }

    /**
     * What is checked before a sale under the Saudi rules is completed, so that issuing its document inside the
     * transaction seldom fails: the layer is on, the seller is complete, the buyer of a tax invoice is complete,
     * and a return takes back a sale its credit note can refer to.
     *
     * @throws EInvoiceException
     */
    public function assertIssuable(?int $customerId, int $saleType, ?int $returnOfSaleId): void
    {
        $this->assertOn();
        $this->parties->seller();

        $subtype = $saleType === SALE_TYPE_RETURN ? $this->originalOfReturn($returnOfSaleId)->subtype : $this->parties->defaultSubtype($customerId);

        $this->parties->buyer($customerId, $subtype);
    }

    /**
     * @throws AccountingException|BranchException|EInvoiceException
     */
    public function issueDebitNote(int $debitNoteId, string $reason, int $originalDocumentId, ?int $employeeId = null): IssuedEInvoice
    {
        $this->guard->require(AccountingGuard::EINVOICING_MANAGE, $employeeId);
        $this->assertOn();

        $note = $this->db->table('sales_debit_notes')->select('branch_id, customer_id, created_by')->where('debit_note_id', $debitNoteId)->get()->getRow();

        if ($note === null || ! $this->scope->allows($employeeId, $note->created_by)) {
            throw new EInvoiceException(EInvoiceError::SourceNotFound, 'The debit note does not exist.', ['number' => (string) $debitNoteId]);
        }

        $this->branches->requireBranch((int) $note->branch_id, $employeeId);

        return $this->transactional(function () use ($debitNoteId, $reason, $originalDocumentId, $employeeId, $note): IssuedEInvoice {
            $counter = $this->lockCounter();
            $known   = $this->documentOf(self::SOURCE_NOTE, $debitNoteId);

            if ($known !== null) {
                return $known;
            }

            $referred = $this->referable($originalDocumentId, (int) $note->customer_id, $employeeId);

            $original = new OriginalReference($referred->documentId, $referred->number, $referred->subtype);
            $document = $this->builder->fromDebitNote($debitNoteId, $reason, $original);

            $this->tie->assertTies(self::SOURCE_NOTE, $debitNoteId, $document->taxInclusive, $document->taxAmount, $document->taxExclusive);

            return $this->store($document, self::SOURCE_NOTE, $debitNoteId, (int) $note->branch_id, $original, $counter, $employeeId);
        });
    }

    /**
     * The document that was issued for a sale or a note, or null when none was.
     */
    public function documentOf(string $sourceType, int $sourceId): ?IssuedEInvoice
    {
        $row = $this->db->table('einvoice_documents')->where(['company_id' => CompanyContext::id(), 'source_type' => $sourceType, 'source_id' => $sourceId])->get()->getRow();

        return $row === null ? null : $this->model($row);
    }

    public function find(int $documentId): ?IssuedEInvoice
    {
        $row = $this->db->table('einvoice_documents')->where(['company_id' => CompanyContext::id(), 'document_id' => $documentId])->get()->getRow();

        return $row === null ? null : $this->model($row);
    }

    /**
     * The file of an issued document, for someone who holds the permission and works in its branch.
     *
     * @throws AccountingException|BranchException|EInvoiceException
     */
    public function xml(int $documentId, ?int $employeeId = null): IssuedEInvoice
    {
        $this->guard->require(AccountingGuard::EINVOICING_MANAGE, $employeeId);

        $document = $this->find($documentId);

        if ($document === null || $this->outsideScope($document->sourceType, $document->sourceId, $employeeId)) {
            throw new EInvoiceException(EInvoiceError::DocumentNotFound, 'The document does not exist.', ['number' => (string) $documentId]);
        }

        $this->branches->requireBranch($document->branchId, $employeeId);

        return $document;
    }

    /**
     * What the transport says about a document, recorded as one more row of the log: a transport that sends
     * nothing records that nothing was sent.
     *
     * @throws AccountingException|BranchException|EInvoiceException
     */
    public function submit(int $documentId, ?int $employeeId = null): TransportResult
    {
        $document = $this->xml($documentId, $employeeId);
        $result   = $this->transport->submit($document);

        $this->db->table('einvoice_transport_log')->insert(['document_id' => $document->documentId, 'transport' => $this->transport->name(), 'status' => $result->status, 'note' => mb_substr($result->note, 0, 500)]);
        $this->audit->write('einvoice_document', $document->documentId, 'submission_recorded', $employeeId, JournalOrigin::User, ['transport' => $this->transport->name(), 'status' => $result->status], $document->branchId);

        return $result;
    }

    /**
     * Where a document stands in the log of the transport: the last thing the transport said, or that it was
     * not submitted when it never said anything.
     */
    public function submissionOf(int $documentId): string
    {
        $row = $this->db->table('einvoice_transport_log')->select('status')->where('document_id', $documentId)->orderBy('log_id', 'DESC')->limit(1)->get()->getRow();

        return $row === null ? TransportResult::NOT_SUBMITTED : (string) $row->status;
    }

    /**
     * Whether the books still hold what the document says: false once the sale or the note changed after the
     * document was issued, which only a credit note or a debit note puts right.
     */
    public function inSync(IssuedEInvoice $document): bool
    {
        try {
            $this->tie->assertTies($document->sourceType, $document->sourceId, $document->taxInclusive, $document->taxAmount, $document->taxExclusive);
        } catch (EInvoiceException) {
            return false;
        }

        return true;
    }

    /**
     * The documents issued in the branches the reader may see, newest first.
     *
     * @param list<int>|null $branchIds The branches the reader may see, null for every branch.
     * @param int|null       $viewerId  The reader, whose own records alone are listed when they see those only.
     *
     * @return list<object>
     */
    public function issuedIn(?array $branchIds, int $limit = 100, ?int $viewerId = null, int $offset = 0): array
    {
        if ($branchIds === []) {
            return [];
        }

        return $this->issuedBuilder($branchIds, $viewerId)
            ->select('document_id, branch_id, source_type, source_id, doc_kind, subtype, document_number, icv, issue_date, tax_inclusive, signed')
            ->orderBy('document_id', 'DESC')
            ->limit($limit, $offset)
            ->get()
            ->getResult();
    }

    /**
     * How many documents issuedIn would list across its pages.
     *
     * @param list<int>|null $branchIds
     */
    public function countIssued(?array $branchIds, ?int $viewerId = null): int
    {
        return $branchIds === [] ? 0 : $this->issuedBuilder($branchIds, $viewerId)->countAllResults();
    }

    /**
     * @param list<int>|null $branchIds
     */
    private function issuedBuilder(?array $branchIds, ?int $viewerId): BaseBuilder
    {
        $mine    = $this->scope->limit($viewerId);
        $builder = $this->db->table('einvoice_documents')->where('company_id', CompanyContext::id());

        if ($branchIds !== null) {
            $builder->whereIn('branch_id', $branchIds);
        }

        if ($mine !== null) {
            $builder->groupStart()
                ->groupStart()->where('source_type', self::SOURCE_SALE)->whereIn('source_id', static fn (BaseBuilder $sales): BaseBuilder => $sales->select('sale_id')->from('sales', true)->where('employee_id', $mine))->groupEnd()
                ->orGroupStart()->where('source_type', self::SOURCE_NOTE)->whereIn('source_id', static fn (BaseBuilder $notes): BaseBuilder => $notes->select('debit_note_id')->from('sales_debit_notes', true)->where('created_by', $mine))->groupEnd()
                ->orGroupStart()->where('source_type', self::SOURCE_ADVANCE)->whereIn('source_id', static fn (BaseBuilder $advances): BaseBuilder => $advances->select('advance_id')->from('customer_advances', true)->where('created_by', $mine))->groupEnd()
                ->orGroupStart()->where('source_type', self::SOURCE_RETURN)->whereIn('source_id', static fn (BaseBuilder $returns): BaseBuilder => $returns->select('r.return_id')->from('customer_advance_vat_returns AS r', true)->join('customer_advances AS a', 'a.advance_id = r.advance_id')->where('a.created_by', $mine))->groupEnd()
                ->groupEnd();
        }

        return $builder;
    }

    /**
     * The document a debit note may refer to: an invoice or a credit note that was issued, in a branch the
     * employee works in, out of the records they may see, and to the same customer as the note. One that
     * the employee may not see is told apart from none by nothing.
     *
     * @throws EInvoiceException
     */
    private function referable(int $documentId, int $customerId, ?int $employeeId): IssuedEInvoice
    {
        $referred = $this->find($documentId);

        if ($referred === null || $referred->kind === EInvoiceKind::DebitNote || ! $this->branches->canAccess($referred->branchId, $employeeId) || $this->outsideScope($referred->sourceType, $referred->sourceId, $employeeId)) {
            throw new EInvoiceException(EInvoiceError::OriginalNotIssued, 'The debit note must refer to an issued invoice or credit note.');
        }

        if ($this->customerOf($referred->sourceType, $referred->sourceId) !== $customerId) {
            throw new EInvoiceException(EInvoiceError::OriginalOfAnotherCustomer, 'The debit note must refer to a document of its own customer.', ['number' => $referred->number]);
        }

        return $referred;
    }

    private function outsideScope(string $sourceType, int $sourceId, ?int $employeeId): bool
    {
        if (! $this->scope->ownOnly($employeeId)) {
            return false;
        }

        $row = match ($sourceType) {
            self::SOURCE_NOTE    => $this->db->table('sales_debit_notes')->select('created_by AS owner')->where('debit_note_id', $sourceId)->get()->getRow(),
            self::SOURCE_ADVANCE => $this->db->table('customer_advances')->select('created_by AS owner')->where('advance_id', $sourceId)->get()->getRow(),
            self::SOURCE_RETURN  => $this->db->table('customer_advance_vat_returns AS r')->select('a.created_by AS owner')->join('customer_advances AS a', 'a.advance_id = r.advance_id')->where('r.return_id', $sourceId)->get()->getRow(),
            default              => $this->db->table('sales')->select('employee_id AS owner')->where('sale_id', $sourceId)->get()->getRow(),
        };

        return ! $this->scope->allows($employeeId, $row?->owner);
    }

    private function customerOf(string $sourceType, int $sourceId): ?int
    {
        $row = match ($sourceType) {
            self::SOURCE_NOTE    => $this->db->table('sales_debit_notes')->select('customer_id')->where('debit_note_id', $sourceId)->get()->getRow(),
            self::SOURCE_ADVANCE => $this->db->table('customer_advances')->select('customer_id')->where('advance_id', $sourceId)->get()->getRow(),
            self::SOURCE_RETURN  => $this->db->table('customer_advance_vat_returns AS r')->select('a.customer_id')->join('customer_advances AS a', 'a.advance_id = r.advance_id')->where('r.return_id', $sourceId)->get()->getRow(),
            default              => $this->db->table('sales')->select('customer_id')->where('sale_id', $sourceId)->get()->getRow(),
        };

        return $row === null || $row->customer_id === null ? null : (int) $row->customer_id;
    }

    /**
     * @throws EInvoiceException
     */
    private function assertOn(): void
    {
        if (! (new AccountingSettings($this->db))->isEnabled()) {
            throw new EInvoiceException(EInvoiceError::AccountingDisabled, 'Electronic invoicing needs accounting to be on.');
        }

        if (! $this->settings->isEnabled()) {
            throw new EInvoiceException(EInvoiceError::Disabled, 'Electronic invoicing is off.');
        }
    }

    /**
     * Takes the lock of the counter of the company, which is what puts the documents in one line: a second
     * request waits here until the first has stored its document.
     *
     * @return array{icv: int, hash: string}
     *
     * @throws AccountingException
     */
    private function lockCounter(): array
    {
        $companyId = CompanyContext::id();
        $table     = $this->db->prefixTable('einvoice_counters');

        $sql = "SELECT last_icv, last_hash FROM {$table} WHERE company_id = ? FOR UPDATE";
        $row = LockedRead::row($this->db, $sql, [$companyId]);

        if ($row === null) {
            if ($this->db->query("INSERT IGNORE INTO {$table} (company_id) VALUES (?)", [$companyId]) === false) {
                throw new AccountingException(AccountingError::WriteFailed, 'The counter of the documents could not be created.');
            }

            $row = LockedRead::row($this->db, $sql, [$companyId]);
        }

        if ($row === null) {
            throw new AccountingException(AccountingError::WriteFailed, 'The counter of the documents could not be locked.');
        }

        return ['icv' => (int) $row->last_icv, 'hash' => (string) $row->last_hash];
    }

    /**
     * @param array{icv: int, hash: string} $counter
     *
     * @throws AccountingException|EInvoiceException
     */
    private function store(EInvoiceDocument $document, string $sourceType, int $sourceId, int $branchId, ?OriginalReference $original, array $counter, ?int $employeeId): IssuedEInvoice
    {
        $icv     = $counter['icv'] + 1;
        $chained = $document->chained($this->uuid(), $icv, $counter['hash'] === '' ? InvoiceHash::FIRST : $counter['hash']);
        $stamped = $this->stamp($chained);

        $this->db->table('einvoice_documents')->insert([
            'company_id'           => CompanyContext::id(),
            'branch_id'            => $branchId,
            'source_type'          => $sourceType,
            'source_id'            => $sourceId,
            'doc_kind'             => $chained->kind->value,
            'subtype'              => $chained->subtype->value,
            'document_number'      => $chained->number,
            'uuid'                 => $chained->uuid,
            'icv'                  => $icv,
            'previous_hash'        => $chained->previousHash,
            'invoice_hash'         => $stamped->hash,
            'issue_date'           => $chained->issueDate,
            'issue_time'           => $chained->issueTime,
            'currency'             => $chained->currency,
            'tax_exclusive'        => $chained->taxExclusive,
            'tax_amount'           => $chained->taxAmount,
            'tax_inclusive'        => $chained->taxInclusive,
            'original_document_id' => $original?->documentId,
            ...($original !== null && $original->isPrinted() ? ['original_reference' => $original->number, 'original_sale_id' => $original->saleId] : []),
            'reason'     => $chained->reason,
            'xml'        => $stamped->xml,
            'qr_text'    => $stamped->qr,
            'signed'     => $stamped->signed ? 1 : 0,
            'profile'    => 'candidate',
            'created_by' => $employeeId,
        ]);

        $documentId = (int) $this->db->insertID();

        $this->db->table('einvoice_counters')->where('company_id', CompanyContext::id())->update(['last_icv' => $icv, 'last_hash' => $stamped->hash]);

        $this->audit->write('einvoice_document', $documentId, 'issued', $employeeId, JournalOrigin::User, [
            'source'  => $sourceType . ':' . $sourceId,
            'kind'    => $chained->kind->value,
            'subtype' => $chained->subtype->value,
            'icv'     => $icv,
            'hash'    => $stamped->hash,
            'signed'  => $stamped->signed,
            'total'   => $chained->taxInclusive,
        ], $branchId);

        $issued = $this->find($documentId);

        if ($issued === null) {
            throw new AccountingException(AccountingError::WriteFailed, 'The document could not be stored.');
        }

        $result = $this->transport->submit($issued);

        $this->db->table('einvoice_transport_log')->insert(['document_id' => $documentId, 'transport' => $this->transport->name(), 'status' => $result->status, 'note' => mb_substr($result->note, 0, 500)]);

        return $issued;
    }

    /**
     * @throws EInvoiceException
     */
    private function stamp(EInvoiceDocument $document): StampedDocument
    {
        $values  = $this->settings->all();
        $keyPath = $values['key_path'];
        $cert    = $values['certificate_path'];

        if ($keyPath === '' && $cert === '') {
            return $this->stamper->unsigned($document);
        }

        $material = StampKeys::load($keyPath, $cert);

        return $this->stamper->signed($document, $material, gmdate('Y-m-d\TH:i:s\Z', ($this->clock)()));
    }

    /**
     * @throws EInvoiceException
     */
    private function originalOfSale(mixed $originSaleId): OriginalReference
    {
        $origin = $originSaleId === null ? null : $this->documentOf(self::SOURCE_SALE, (int) $originSaleId);

        if ($origin === null) {
            throw new EInvoiceException(EInvoiceError::OriginalNotIssued, 'The invoice this return takes back has not been issued as a document.');
        }

        return new OriginalReference($origin->documentId, $origin->number, $origin->subtype);
    }

    /**
     * What the credit note of a return completed under the Saudi rules refers to: the document of the sale it takes
     * back, or, for a sale completed before the rules and never issued, the number printed for it (D-26), as the
     * kind of tax invoice that sale was. A return of nothing has nothing to refer to (D-11), and a sale completed
     * under the rules without a document could only have been made around them: both are refused.
     *
     * @throws EInvoiceException
     */
    private function originalOfReturn(?int $originSaleId): OriginalReference
    {
        if ($originSaleId === null) {
            throw new EInvoiceException(EInvoiceError::ReturnWithoutOrigin, 'Under the Saudi rules a return takes back a sale, which its credit note refers to.');
        }

        $origin = $this->documentOf(self::SOURCE_SALE, $originSaleId);

        if ($origin !== null) {
            return new OriginalReference($origin->documentId, $origin->number, $origin->subtype);
        }

        $sale = $this->db->table('sales')->select('sale_time, invoice_number, customer_id')->where('sale_id', $originSaleId)->get()->getRow();

        if ($sale === null || (new CompanyTaxProfile($this->db))->current()->inSaudiModeAt((string) $sale->sale_time)) {
            throw new EInvoiceException(EInvoiceError::OriginalNotIssued, 'The invoice this return takes back has not been issued as a document.');
        }

        return OriginalReference::printed($originSaleId, $this->printedNumberOf($originSaleId, (string) ($sale->invoice_number ?? '')), $this->parties->defaultSubtype($sale->customer_id === null ? null : (int) $sale->customer_id));
    }

    /**
     * The number a sale was printed with: the number its sequence gave it, else its invoice number, else the
     * receipt number of the register.
     */
    private function printedNumberOf(int $saleId, string $invoiceNumber): string
    {
        $label = (new DocumentNumbering($this->db))->label(DocumentType::SalesInvoice, 'sales', $saleId);

        if ($label !== '') {
            return $label;
        }

        return trim($invoiceNumber) !== '' ? trim($invoiceNumber) : 'POS ' . $saleId;
    }

    private function uuid(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex      = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    private function model(object $row): IssuedEInvoice
    {
        return new IssuedEInvoice(
            documentId: (int) $row->document_id,
            branchId: (int) $row->branch_id,
            sourceType: (string) $row->source_type,
            sourceId: (int) $row->source_id,
            kind: EInvoiceKind::from((string) $row->doc_kind),
            subtype: EInvoiceSubtype::from((string) $row->subtype),
            number: (string) $row->document_number,
            uuid: (string) $row->uuid,
            icv: (int) $row->icv,
            previousHash: (string) $row->previous_hash,
            hash: (string) $row->invoice_hash,
            issueDate: (string) $row->issue_date,
            issueTime: (string) $row->issue_time,
            taxExclusive: (string) $row->tax_exclusive,
            taxAmount: (string) $row->tax_amount,
            taxInclusive: (string) $row->tax_inclusive,
            originalDocumentId: $row->original_document_id === null ? null : (int) $row->original_document_id,
            reason: (string) $row->reason,
            signed: (int) $row->signed === 1,
            createdAt: (string) $row->created_at,
            xml: (string) $row->xml,
            qr: (string) ($row->qr_text ?? ''),
        );
    }
}
