<?php

namespace App\Libraries\Accounting;

use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * What happens to a quote after it is saved: its state moves along the transitions of QuoteStatus
 * and nowhere else, a draft takes the number of the quote sequence when it is opened, and the quote
 * an invoice was made from becomes converted in the transaction that saves the invoice. A quote
 * posts no journal and moves no stock, so none of this touches the books; every change is written
 * in the audit log of the books while they are on. The row of the quote is locked while its state is read and changed, so two
 * requests never move it from the same state.
 */
final class QuoteLifecycle
{
    use RunsInTransaction;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * The state of a quote that is still being worked on, or null when the sale is not one.
     */
    public function statusOf(int $saleId): ?QuoteStatus
    {
        $row = $this->db->table('sales')->select('sale_status, sale_type, quote_status')->where('sale_id', $saleId)->get()->getRow();

        return $row === null || ! $this->isLive($row) ? null : QuoteStatus::fromStored($row->quote_status);
    }

    /**
     * Moves a quote to another state, written in the audit log. Asking for the state it has changes
     * nothing. Opening a draft gives it the number of the quote sequence while that is on.
     *
     * @throws AccountingException|BranchException
     */
    public function change(int $saleId, QuoteStatus $to, ?int $employeeId): QuoteStatus
    {
        return $this->transactional(function () use ($saleId, $to, $employeeId): QuoteStatus {
            $quote = $this->lock($saleId);

            (new BranchGuard($this->db))->requireBranch((int) $quote->branch_id, $employeeId);

            $from = QuoteStatus::fromStored($quote->quote_status);

            if ($from === $to) {
                return $from;
            }

            if (! $from->canMoveTo($to)) {
                throw new AccountingException(AccountingError::QuoteStatusNotAllowed, 'A quote cannot move from ' . $from->name . ' to ' . $to->name . '.', ['from' => lang($from->languageKey()), 'to' => lang($to->languageKey())]);
            }

            $number = null;

            if ($from === QuoteStatus::Draft && ($quote->quote_number ?? '') === '') {
                $number = (new DocumentNumbering($this->db))->assign(DocumentType::SalesQuote, 'sales', $saleId, (int) $quote->branch_id)?->number;
            }

            $this->db->table('sales')->where('sale_id', $saleId)->update(['quote_status' => $to->value]);
            $this->recordChange($saleId, $from, $to, $employeeId, (int) $quote->branch_id, $number);

            return $to;
        });
    }

    /**
     * Writes in the audit log that a quote moved from one state to another, with the number it was
     * given when a draft was opened. The register, which opens a draft when it saves it as a quote,
     * writes it through here too, so that every move of a quote is on record.
     */
    public function recordChange(int $saleId, QuoteStatus $from, QuoteStatus $to, ?int $employeeId, int $branchId, ?string $number = null): void
    {
        $details = ['from' => $from->name, 'to' => $to->name];

        if ($number !== null && $number !== '') {
            $details['number'] = $number;
        }

        $this->audit($saleId, 'quote_status_changed', $employeeId, $branchId, $details);
    }

    /**
     * Marks the quote an invoice was made from as converted. It runs inside the transaction that
     * saves the invoice, so the quote is converted exactly when the invoice exists.
     *
     * @throws AccountingException|BranchException
     */
    public function markConverted(int $quoteId, int $invoiceSaleId, ?int $employeeId): void
    {
        $this->transactional(function () use ($quoteId, $invoiceSaleId, $employeeId): void {
            $quote = $this->lock($quoteId);

            (new BranchGuard($this->db))->requireBranch((int) $quote->branch_id, $employeeId);

            $from = QuoteStatus::fromStored($quote->quote_status);

            if (! $from->canConvert()) {
                throw new AccountingException(AccountingError::QuoteNotConvertible, 'A quote that is ' . $from->name . ' does not become an invoice.', ['name' => lang($from->languageKey())]);
            }

            $this->db->table('sales')->where('sale_id', $quoteId)->update(['quote_status' => QuoteStatus::Converted->value]);
            $this->audit($quoteId, 'quote_converted', $employeeId, (int) $quote->branch_id, ['from' => $from->name, 'invoice_sale_id' => $invoiceSaleId]);
        });
    }

    /**
     * Whether an invoice may be made from the quote now, without taking any lock.
     */
    public function mayConvert(int $saleId): bool
    {
        return $this->statusOf($saleId)?->canConvert() ?? false;
    }

    /**
     * A quote that was converted is the record of its invoice and is neither saved again nor
     * cancelled. Anything else passes.
     *
     * @throws AccountingException
     */
    public function assertUnlocked(int $saleId): void
    {
        if ($this->statusOf($saleId)?->isLocked() === true) {
            throw new AccountingException(AccountingError::QuoteLocked, 'A quote that became an invoice cannot be changed.', ['sale_id' => $saleId]);
        }
    }

    /**
     * The first completed invoice made from the quote, or null when there is none.
     */
    public function invoiceOf(int $quoteId): ?int
    {
        $row = $this->db->table('sales')->select('sale_id')->where(['source_quote_id' => $quoteId, 'sale_status' => COMPLETED])->orderBy('sale_id', 'ASC')->get()->getRow();

        return $row === null ? null : (int) $row->sale_id;
    }

    /**
     * @throws AccountingException
     */
    private function lock(int $saleId): object
    {
        $quote = LockedRead::row($this->db, 'SELECT sale_id, sale_status, sale_type, quote_status, quote_number, branch_id FROM ' . $this->db->prefixTable('sales') . ' WHERE sale_id = ? FOR UPDATE', [$saleId]);

        if ($quote === null || ! $this->isLive($quote)) {
            throw new AccountingException(AccountingError::QuoteNotFound, 'The sale is not a quote.', ['sale_id' => $saleId]);
        }

        return $quote;
    }

    private function isLive(object $row): bool
    {
        return (int) $row->sale_type === SALE_TYPE_QUOTE && (int) $row->sale_status === SUSPENDED;
    }

    /**
     * @param array<string, mixed> $details
     */
    private function audit(int $saleId, string $action, ?int $employeeId, int $branchId, array $details): void
    {
        if (! (new AccountingSettings($this->db))->isEnabled()) {
            return;
        }

        (new AuditLog($this->db))->write('sale', $saleId, $action, $employeeId, $employeeId === null ? JournalOrigin::System : JournalOrigin::User, $details, $branchId);
    }
}
