<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Keeps the credit of a customer where it belongs. It runs after anything that can change what
 * a customer owes (an invoice, a payment, a credit note, a cancellation) and does two things:
 * it releases what no longer holds (credit applied to an invoice that is gone, or taken from a
 * credit that is gone or has shrunk, or applied beyond what the invoice still owed), and it
 * applies the credit that is free to the invoices that are open, the one dated earliest first, and
 * among invoices of one date the one created first. The invoice a credit note came from has no
 * priority: the reference system walks the open invoices by date alone. No journal is written,
 * because the account of the customer already carries the credit.
 *
 * The caller holds the lock of the customer (CustomerLock) and is inside a transaction, so the
 * ledger it reads is the ledger as the last change left it.
 */
final class CreditSettlement
{
    private BaseConnection $db;
    private CustomerLedger $ledger;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db     = $db ?? Database::connect();
        $this->ledger = new CustomerLedger($this->db);
    }

    public function settle(int $customerId, ?int $employeeId = null): void
    {
        $this->release($customerId, $employeeId);
        $this->apply($customerId, $employeeId);
    }

    private function release(int $customerId, ?int $employeeId): void
    {
        $position = $this->ledger->position($customerId);
        $invoices = [];
        $sources  = [];

        foreach ($position->invoices as $invoice) {
            $invoices[$invoice->saleId] = $invoice;
        }

        foreach ($position->credits as $source) {
            $sources[$source->type . ':' . $source->sourceId] = $source;
        }

        $applications = array_reverse($this->ledger->applications($customerId));

        // What stands on an invoice that is gone, or on a credit that is gone.
        foreach ($applications as $index => $application) {
            $invoiceGone = ! isset($invoices[CustomerLedger::targetOf($application)]);
            $sourceGone  = ! isset($sources[$application->source_type . ':' . $application->source_id]);

            if ($invoiceGone || $sourceGone) {
                $this->free((int) $application->application_id, $application->amount, $application->amount, $employeeId);
                unset($applications[$index]);
            }
        }

        // An invoice cannot hold more credit than it still owed after what was paid into it.
        $into = [];

        foreach ($applications as $index => $application) {
            $invoiceId = CustomerLedger::targetOf($application);
            $invoice   = $invoices[$invoiceId];
            $allowed   = bcsub($invoice->total, $invoice->paid, SaleAmounts::SCALE);
            $allowed   = bccomp($allowed, '0', SaleAmounts::SCALE) > 0 ? $allowed : '0.0000';
            $into[$invoiceId] ??= $this->sumInto($applications, $invoiceId);

            $surplus = bcsub($into[$invoiceId], $allowed, SaleAmounts::SCALE);

            if (bccomp($surplus, '0', SaleAmounts::SCALE) <= 0) {
                continue;
            }

            $cut = bccomp($surplus, $application->amount, SaleAmounts::SCALE) < 0 ? $surplus : $application->amount;

            $this->free((int) $application->application_id, $application->amount, $cut, $employeeId);
            $into[$invoiceId]             = bcsub($into[$invoiceId], $cut, SaleAmounts::SCALE);
            $applications[$index]->amount = bcsub($application->amount, $cut, SaleAmounts::SCALE);
        }

        // A credit cannot have given more than it came to.
        $position = $this->ledger->position($customerId);

        foreach ($position->credits as $source) {
            $over = bcsub(bcadd($source->applied, $source->refunded, SaleAmounts::SCALE), $source->amount, SaleAmounts::SCALE);

            if (bccomp($over, '0', SaleAmounts::SCALE) <= 0) {
                continue;
            }

            foreach (array_reverse($this->ledger->applications($customerId)) as $application) {
                if ($application->source_type !== $source->type || (int) $application->source_id !== $source->sourceId) {
                    continue;
                }

                $cut = bccomp($over, $application->amount, SaleAmounts::SCALE) < 0 ? $over : $application->amount;

                $this->free((int) $application->application_id, $application->amount, $cut, $employeeId);
                $over = bcsub($over, $cut, SaleAmounts::SCALE);

                if (bccomp($over, '0', SaleAmounts::SCALE) <= 0) {
                    break;
                }
            }
        }

        $this->assertRefundsHold($customerId);
    }

    /**
     * What was paid back in cash cannot be spent again: a credit that a refund took from must still
     * be there and still be as large as the refund, or the change that would shrink it is refused and
     * the refund is cancelled first.
     *
     * @throws AccountingException
     */
    private function assertRefundsHold(int $customerId): void
    {
        $refunded = $this->ledger->refundedSources($customerId);

        if ($refunded === []) {
            return;
        }

        $sources = [];

        foreach ($this->ledger->position($customerId)->credits as $source) {
            $sources[$source->type . ':' . $source->sourceId] = $source;
        }

        foreach ($refunded as $key => $amount) {
            if (! isset($sources[$key]) || bccomp($sources[$key]->amount, $amount, SaleAmounts::SCALE) < 0) {
                throw new AccountingException(AccountingError::CreditRefunded, 'The credit of this document was paid back in cash, so it cannot change until the refund is cancelled.', ['customer_id' => $customerId]);
            }
        }
    }

    private function apply(int $customerId, ?int $employeeId): void
    {
        $position = $this->ledger->position($customerId);
        $open     = [];

        foreach ($position->invoices as $invoice) {
            if (bccomp($invoice->open(), '0', SaleAmounts::SCALE) > 0) {
                $open[$invoice->saleId] = $invoice->open();
            }
        }

        if ($open === []) {
            return;
        }

        $sources = array_values(array_filter($position->credits, static fn (CreditSource $source): bool => bccomp($source->available(), '0', SaleAmounts::SCALE) > 0));

        foreach ($sources as $source) {
            $available = $source->available();

            foreach (array_keys($open) as $invoiceId) {
                if (bccomp($available, '0', SaleAmounts::SCALE) <= 0) {
                    break;
                }

                if ($source->type === CreditSource::INVOICE_EXCESS && $source->sourceId === $invoiceId) {
                    continue;
                }

                $available = $this->give($customerId, $source, $invoiceId, $available, $open, $employeeId);
            }
        }
    }

    /**
     * @param array<int, numeric-string> $open
     *
     * @return numeric-string What is left of the credit.
     */
    private function give(int $customerId, CreditSource $source, int $invoiceId, string $available, array &$open, ?int $employeeId): string
    {
        $owed        = $open[$invoiceId] ?? '0.0000';
        $take        = bccomp($available, $owed, SaleAmounts::SCALE) < 0 ? $available : $owed;
        $debitNoteId = SalesDebitNotes::idOfKey($invoiceId);

        if (bccomp($take, '0', SaleAmounts::SCALE) <= 0) {
            return $available;
        }

        $this->db->table('customer_credit_applications')->insert([
            'customer_id'     => $customerId,
            'invoice_sale_id' => $invoiceId > 0 ? $invoiceId : null,
            'opening_id'      => $invoiceId < 0 && $debitNoteId === null ? -$invoiceId : null,
            'debit_note_id'   => $debitNoteId,
            'source_type'     => $source->type,
            'source_id'       => $source->sourceId,
            'amount'          => $take,
            'automatic'       => 1,
            'created_by'      => $employeeId,
        ]);

        $open[$invoiceId] = bcsub($owed, $take, SaleAmounts::SCALE);

        if (bccomp($open[$invoiceId], '0', SaleAmounts::SCALE) <= 0) {
            unset($open[$invoiceId]);
        }

        return bcsub($available, $take, SaleAmounts::SCALE);
    }

    /**
     * Releases an application, or the part of it that is cut and keeps the rest as a new one.
     *
     * @param numeric-string $amount
     * @param numeric-string $cut
     */
    private function free(int $applicationId, string $amount, string $cut, ?int $employeeId): void
    {
        $row = $this->db->table('customer_credit_applications')->where('application_id', $applicationId)->get()->getRow();

        $this->db->table('customer_credit_applications')->where('application_id', $applicationId)->update([
            'released_at' => date('Y-m-d H:i:s'),
            'released_by' => $employeeId,
        ]);

        $rest = bcsub($amount, $cut, SaleAmounts::SCALE);

        if (bccomp($rest, '0', SaleAmounts::SCALE) > 0) {
            $this->db->table('customer_credit_applications')->insert([
                'customer_id'     => $row->customer_id,
                'invoice_sale_id' => $row->invoice_sale_id,
                'opening_id'      => $row->opening_id,
                'debit_note_id'   => $row->debit_note_id,
                'source_type'     => $row->source_type,
                'source_id'       => $row->source_id,
                'amount'          => $rest,
                'automatic'       => $row->automatic,
                'created_by'      => $row->created_by,
            ]);
        }
    }

    /**
     * What stands on an invoice, on an opening balance, which is numbered minus its number, or on a
     * sales debit note, which is numbered in a range of its own.
     *
     * @param list<object> $applications
     *
     * @return numeric-string
     */
    private function sumInto(array $applications, int $target): string
    {
        $sum = '0';

        foreach ($applications as $application) {
            if (CustomerLedger::targetOf($application) === $target) {
                $sum = bcadd($sum, $application->amount, SaleAmounts::SCALE);
            }
        }

        return bcadd($sum, '0', SaleAmounts::SCALE);
    }
}
