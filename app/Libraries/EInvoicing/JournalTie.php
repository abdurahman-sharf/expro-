<?php

namespace App\Libraries\EInvoicing;

use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\JournalStatus;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\RoutingResolver;
use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Accounting\SaleDocument;
use App\Libraries\Accounting\SaleLoader;
use App\Libraries\Accounting\SalesDebitNotes;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Holds a document against the journal the books stand on for its sale or its debit note: the line of the
 * customer must be the total of the document, the lines of tax its tax rounded to two decimals, and the
 * lines of revenue, after what the sale gave away, its amount without tax to within the rounding of the
 * tax (the books keep the tax to four decimals and the document states it to two). Nothing is read twice
 * from the sale and nothing is calculated here: the journal is read as it stands.
 */
final class JournalTie
{
    private const ROUNDING = '0.005';

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return array{customer: string, tax: string, revenue: string}|null The figures of the standing journal, or null when none stands.
     */
    public function figures(string $sourceType, int $sourceId): ?array
    {
        $journal = $this->db->table('accounting_journals')
            ->select('journal_id')
            ->where(['source_type' => $sourceType, 'source_id' => $sourceId, 'entry_type' => JournalEntryType::Revenue->value, 'status' => JournalStatus::Posted->value])
            ->orderBy('revision', 'DESC')
            ->limit(1)
            ->get()
            ->getRow();

        if ($journal === null) {
            return null;
        }

        $resolver = new RoutingResolver($this->db);
        $customer = null;
        $taxes    = [];
        $isNote   = $sourceType === SalesDebitNotes::SOURCE;
        $sale     = $isNote ? null : (new SaleLoader($this->db))->load($sourceId);

        if ($sale instanceof SaleDocument) {
            $customer = $sale->customerId === null ? $resolver->accountFor(RoutingKey::CounterCustomers) : $resolver->accountFor(RoutingKey::Customers, $sale->customerId);
            $taxes    = array_keys(SaleAmounts::accountTaxes($sale));
        } else {
            $row      = $this->db->table('sales_debit_notes')->select('customer_id')->where('debit_note_id', $sourceId)->get()->getRow();
            $customer = $row === null ? null : $resolver->accountFor(RoutingKey::Customers, (int) $row->customer_id);
        }

        $taxes[] = $resolver->accountFor(RoutingKey::TaxOutput);

        $own     = '0';
        $tax     = '0';
        $revenue = '0';
        $credit  = $sale instanceof SaleDocument && $sale->isReturn();

        foreach ($this->db->table('accounting_journal_lines')->select('account_id, debit, credit')->where('journal_id', (int) $journal->journal_id)->get()->getResult() as $line) {
            $net = $credit ? bcsub((string) $line->debit, (string) $line->credit, SaleAmounts::SCALE) : bcsub((string) $line->credit, (string) $line->debit, SaleAmounts::SCALE);

            if ((int) $line->account_id === $customer) {
                $own = bcsub($own, $net, SaleAmounts::SCALE);
            } elseif (in_array((int) $line->account_id, $taxes, true)) {
                $tax = bcadd($tax, $net, SaleAmounts::SCALE);
            } else {
                $revenue = bcadd($revenue, $net, SaleAmounts::SCALE);
            }
        }

        return ['customer' => $own, 'tax' => $tax, 'revenue' => $revenue];
    }

    /**
     * @throws EInvoiceException
     */
    public function assertTies(string $sourceType, int $sourceId, string $taxInclusive, string $taxAmount, string $taxExclusive): void
    {
        $figures = $this->figures($sourceType, $sourceId);

        if ($figures === null) {
            throw new EInvoiceException(EInvoiceError::SourceNotPosted, 'The books hold no posted journal for the document.', ['number' => (string) $sourceId]);
        }

        if (bccomp($figures['customer'], '0', SaleAmounts::SCALE) < 0 || bccomp(SaleAmounts::round($figures['customer'], 2), $taxInclusive, 2) !== 0) {
            throw new EInvoiceException(EInvoiceError::TotalsDoNotTie, 'The total of the document is not the amount the books put on the customer.', ['rule' => 'journal total', 'expected' => bcadd($figures['customer'], '0', 2), 'actual' => $taxInclusive]);
        }

        if (bccomp(SaleAmounts::round($figures['tax'], 2), $taxAmount, 2) !== 0) {
            throw new EInvoiceException(EInvoiceError::TotalsDoNotTie, 'The tax of the document is not the tax the books hold.', ['rule' => 'journal tax', 'expected' => SaleAmounts::round($figures['tax'], 2), 'actual' => $taxAmount]);
        }

        $gap = SaleAmounts::absolute(bcsub($figures['revenue'], $taxExclusive, SaleAmounts::SCALE));

        if (bccomp($gap, self::ROUNDING, SaleAmounts::SCALE) > 0) {
            throw new EInvoiceException(EInvoiceError::TotalsDoNotTie, 'The amount without tax of the document is not the revenue the books hold.', ['rule' => 'journal revenue', 'expected' => bcadd($figures['revenue'], '0', 2), 'actual' => $taxExclusive]);
        }
    }
}
