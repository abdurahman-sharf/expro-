<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Keeps the credit held with a supplier where it belongs. It runs after anything that can change
 * what is owed to a supplier (a purchase, a payment, a debit note, a deletion) and does two
 * things: it releases what no longer holds (credit applied to a purchase that is gone, or taken
 * from a credit that is gone or has shrunk, or applied beyond what the purchase still owed), and
 * it applies the credit that is free to the purchases that are open, the oldest first. A debit
 * note goes to the purchase it came from first. No journal is written, because the account of
 * the supplier already carries the credit.
 *
 * The caller holds the lock of the supplier (SupplierLock) and is inside a transaction, so the
 * ledger it reads is the ledger as the last change left it.
 *
 * Credit is kept in the base currency, and a purchase in another currency is settled by payments in its
 * own currency, each with the exchange difference it causes. Credit applied to such a purchase would settle
 * it with none, so it is refused by name, except a debit note of the same currency taken at the same rate,
 * which is the purchase's own figures in the other direction.
 */
final class SupplierSettlement
{
    private BaseConnection $db;
    private SupplierLedger $ledger;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db     = $db ?? Database::connect();
        $this->ledger = new SupplierLedger($this->db);
    }

    public function settle(int $supplierId, ?int $employeeId = null): void
    {
        $this->release($supplierId, $employeeId);
        $this->apply($supplierId, $employeeId);
    }

    private function release(int $supplierId, ?int $employeeId): void
    {
        $position = $this->ledger->position($supplierId);
        $invoices = [];
        $sources  = [];

        foreach ($position->purchases as $invoice) {
            $invoices[$invoice->receivingId] = $invoice;
        }

        foreach ($position->credits as $source) {
            $sources[$source->type . ':' . $source->sourceId] = $source;
        }

        $applications = array_reverse($this->ledger->applications($supplierId));

        // What stands on an invoice that is gone, or on a credit that is gone.
        foreach ($applications as $index => $application) {
            $invoiceGone = ! isset($invoices[(int) $application->invoice_receiving_id]);
            $sourceGone  = ! isset($sources[$application->source_type . ':' . $application->source_id]);

            if ($invoiceGone || $sourceGone) {
                $this->free((int) $application->application_id, $application->amount, $application->amount, $employeeId);
                unset($applications[$index]);
            }
        }

        // An invoice cannot hold more credit than it still owed after what was paid into it.
        $into = [];

        foreach ($applications as $index => $application) {
            $invoiceId = (int) $application->invoice_receiving_id;
            $invoice   = $invoices[$invoiceId];
            $allowed   = bcsub($invoice->total, $invoice->paid, SaleAmounts::SCALE);
            $allowed   = bccomp($allowed, '0', SaleAmounts::SCALE) > 0 ? $allowed : '0.0000';
            $into[$invoiceId] ??= $this->sum($applications, 'invoice_receiving_id', $invoiceId);

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
        $position = $this->ledger->position($supplierId);

        foreach ($position->credits as $source) {
            $over = bcsub(bcadd($source->applied, $source->refunded, SaleAmounts::SCALE), $source->amount, SaleAmounts::SCALE);

            if (bccomp($over, '0', SaleAmounts::SCALE) <= 0) {
                continue;
            }

            foreach (array_reverse($this->ledger->applications($supplierId)) as $application) {
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

        $this->assertRefundsHold($supplierId);
    }

    /**
     * What was paid back in cash cannot be spent again: a credit that a refund took from must still
     * be there and still be as large as the refund, or the change that would shrink it is refused and
     * the refund is cancelled first.
     *
     * @throws AccountingException
     */
    private function assertRefundsHold(int $supplierId): void
    {
        $refunded = $this->ledger->refundedSources($supplierId);

        if ($refunded === []) {
            return;
        }

        $sources = [];

        foreach ($this->ledger->position($supplierId)->credits as $source) {
            $sources[$source->type . ':' . $source->sourceId] = $source;
        }

        foreach ($refunded as $key => $amount) {
            if (! isset($sources[$key]) || bccomp($sources[$key]->amount, $amount, SaleAmounts::SCALE) < 0) {
                throw new AccountingException(AccountingError::CreditRefunded, 'The credit of this document was paid back in cash, so it cannot change until the refund is cancelled.', ['supplier_id' => $supplierId]);
            }
        }
    }

    private function apply(int $supplierId, ?int $employeeId): void
    {
        $position = $this->ledger->position($supplierId);
        $open     = [];
        $figures  = [];

        foreach ($position->purchases as $invoice) {
            if (bccomp($invoice->open(), '0', SaleAmounts::SCALE) > 0) {
                $open[$invoice->receivingId]    = $invoice->open();
                $figures[$invoice->receivingId] = $invoice;
            }
        }

        if ($open === []) {
            return;
        }

        $sources = array_values(array_filter($position->credits, static fn (CreditSource $source): bool => bccomp($source->available(), '0', SaleAmounts::SCALE) > 0));

        foreach ($sources as $source) {
            $available = $source->available();

            if ($source->originId !== null && isset($open[$source->originId])) {
                $this->assertSameCurrency($supplierId, $source, $figures[$source->originId]);

                $available = $this->give($supplierId, $source, $source->originId, $available, $open, $employeeId);
            }

            foreach (array_keys($open) as $invoiceId) {
                if (bccomp($available, '0', SaleAmounts::SCALE) <= 0) {
                    break;
                }

                if ($source->type === CreditSource::INVOICE_EXCESS && $source->sourceId === $invoiceId) {
                    continue;
                }

                $this->assertSameCurrency($supplierId, $source, $figures[$invoiceId]);

                $available = $this->give($supplierId, $source, $invoiceId, $available, $open, $employeeId);
            }
        }
    }

    /**
     * @throws AccountingException
     */
    private function assertSameCurrency(int $supplierId, CreditSource $source, PurchaseFigures $purchase): void
    {
        if ($purchase->currencyCode === null) {
            return;
        }

        $mirror = $source->currencyCode === $purchase->currencyCode
            && $purchase->currencyRate !== null
            && bccomp((string) $source->currencyRate, $purchase->currencyRate, 8) === 0;

        if ($mirror) {
            return;
        }

        throw new AccountingException(
            AccountingError::CreditInForeignPurchase,
            'Credit cannot be applied to a purchase in a foreign currency.',
            ['supplier_id' => $supplierId, 'receiving_id' => $purchase->receivingId, 'code' => $purchase->currencyCode, 'name' => 'RECV ' . $purchase->receivingId],
        );
    }

    /**
     * @param array<int, numeric-string> $open
     *
     * @return numeric-string What is left of the credit.
     */
    private function give(int $supplierId, CreditSource $source, int $invoiceId, string $available, array &$open, ?int $employeeId): string
    {
        $owed = $open[$invoiceId] ?? '0.0000';
        $take = bccomp($available, $owed, SaleAmounts::SCALE) < 0 ? $available : $owed;

        if (bccomp($take, '0', SaleAmounts::SCALE) <= 0) {
            return $available;
        }

        $this->db->table('supplier_credit_applications')->insert([
            'supplier_id'          => $supplierId,
            'invoice_receiving_id' => $invoiceId,
            'source_type'          => $source->type,
            'source_id'            => $source->sourceId,
            'amount'               => $take,
            'automatic'            => 1,
            'created_by'           => $employeeId,
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
        $row = $this->db->table('supplier_credit_applications')->where('application_id', $applicationId)->get()->getRow();

        $this->db->table('supplier_credit_applications')->where('application_id', $applicationId)->update([
            'released_at' => date('Y-m-d H:i:s'),
            'released_by' => $employeeId,
        ]);

        $rest = bcsub($amount, $cut, SaleAmounts::SCALE);

        if (bccomp($rest, '0', SaleAmounts::SCALE) > 0) {
            $this->db->table('supplier_credit_applications')->insert([
                'supplier_id'          => $row->supplier_id,
                'invoice_receiving_id' => $row->invoice_receiving_id,
                'source_type'          => $row->source_type,
                'source_id'            => $row->source_id,
                'amount'               => $rest,
                'automatic'            => $row->automatic,
                'created_by'           => $row->created_by,
            ]);
        }
    }

    /**
     * @param list<object> $applications
     *
     * @return numeric-string
     */
    private function sum(array $applications, string $field, int $value): string
    {
        $sum = '0';

        foreach ($applications as $application) {
            if ((int) $application->{$field} === $value) {
                $sum = bcadd($sum, $application->amount, SaleAmounts::SCALE);
            }
        }

        return bcadd($sum, '0', SaleAmounts::SCALE);
    }
}
