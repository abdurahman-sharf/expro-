<?php

namespace App\Libraries\EInvoicing;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\LockedRead;
use App\Libraries\Accounting\RunsInTransaction;
use App\Libraries\Accounting\SalePostingBuilder;
use App\Libraries\Accounting\SalesDebitNotes;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use Closure;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Throwable;

/**
 * Issues the documents of the layer and reads them back. Issuing is one transaction under the lock of the
 * counter of the company: the counter goes up by one, the document carries the hash of the one before it,
 * the file is built, held against the books, stamped when a key is in place, stored with its hash and never
 * changed, and the transport is asked to carry it, which today records that nothing was sent. A sale or a
 * note is issued once: asking again gives the document that was issued, so a retry or a second click makes
 * nothing new. A document that is wrong is corrected by a note, never by writing it again. Nothing here
 * writes to a journal, a number, a stock movement or a total.
 */
final class EInvoiceService
{
    use RunsInTransaction;

    public const SOURCE_SALE = SalePostingBuilder::SOURCE_SALE;
    public const SOURCE_NOTE = SalesDebitNotes::SOURCE;

    private BaseConnection $db;
    private AccountingGuard $guard;
    private BranchGuard $branches;
    private EInvoiceSettings $settings;
    private EInvoiceBuilder $builder;
    private DocumentStamper $stamper;
    private JournalTie $tie;
    private AuditLog $audit;
    private EInvoiceTransport $transport;

    /** @var Closure(): int */
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
        $this->stamper   = new DocumentStamper();
        $this->tie       = new JournalTie($this->db);
        $this->audit     = new AuditLog($this->db);
        $this->transport = $transport ?? self::defaultTransport();
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

        $sale = $this->db->table('sales')->select('branch_id, sale_type, return_of_sale_id')->where('sale_id', $saleId)->get()->getRow();

        if ($sale === null) {
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
     * @throws AccountingException|BranchException|EInvoiceException
     */
    public function issueDebitNote(int $debitNoteId, string $reason, int $originalDocumentId, ?int $employeeId = null): IssuedEInvoice
    {
        $this->guard->require(AccountingGuard::EINVOICING_MANAGE, $employeeId);
        $this->assertOn();

        $note = $this->db->table('sales_debit_notes')->select('branch_id')->where('debit_note_id', $debitNoteId)->get()->getRow();

        if ($note === null) {
            throw new EInvoiceException(EInvoiceError::SourceNotFound, 'The debit note does not exist.', ['number' => (string) $debitNoteId]);
        }

        $this->branches->requireBranch((int) $note->branch_id, $employeeId);

        return $this->transactional(function () use ($debitNoteId, $reason, $originalDocumentId, $employeeId, $note): IssuedEInvoice {
            $counter = $this->lockCounter();
            $known   = $this->documentOf(self::SOURCE_NOTE, $debitNoteId);

            if ($known !== null) {
                return $known;
            }

            $referred = $this->find($originalDocumentId);

            if ($referred === null || $referred->kind === EInvoiceKind::DebitNote) {
                throw new EInvoiceException(EInvoiceError::OriginalNotIssued, 'The debit note must refer to an issued invoice or credit note.');
            }

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

        if ($document === null) {
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
     * The sales of a branch that have a document, newest first, and the ones that have none.
     *
     * @param list<int>|null $branchIds The branches the reader may see, null for every branch.
     *
     * @return list<object>
     */
    public function issuedIn(?array $branchIds, int $limit = 100): array
    {
        $builder = $this->db->table('einvoice_documents')
            ->select('document_id, branch_id, source_type, source_id, doc_kind, subtype, document_number, icv, issue_date, tax_inclusive, signed')
            ->where('company_id', CompanyContext::id())
            ->orderBy('document_id', 'DESC')
            ->limit($limit);

        if ($branchIds !== null) {
            if ($branchIds === []) {
                return [];
            }

            $builder->whereIn('branch_id', $branchIds);
        }

        return $builder->get()->getResult();
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

        $this->db->query("INSERT IGNORE INTO {$table} (company_id) VALUES (?)", [$companyId]);

        $row = LockedRead::row($this->db, "SELECT last_icv, last_hash FROM {$table} WHERE company_id = ? FOR UPDATE", [$companyId]);

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
            'reason'               => $chained->reason,
            'xml'                  => $stamped->xml,
            'qr_text'              => $stamped->qr,
            'signed'               => $stamped->signed ? 1 : 0,
            'profile'              => 'candidate',
            'created_by'           => $employeeId,
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

    private function uuid(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
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
