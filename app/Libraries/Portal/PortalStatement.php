<?php

namespace App\Libraries\Portal;

use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\CreditSource;
use App\Libraries\Accounting\Currencies;
use App\Libraries\Accounting\CustomerLedger;
use App\Libraries\Accounting\Money;
use App\Libraries\Accounting\SalesDebitNotes;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * What a customer owes, as the customer sees it: the balance of the account, the invoices that are still
 * open with the day they fall due and how late they are, the six buckets of their age, and the credit the
 * customer holds. It is read from the same ledger as the statement the employees see, for the customer
 * given and no other, and it shows no journal, no account and nothing of the books beyond these figures.
 * With accounting off there is no ledger to read, and the statement says so.
 */
final class PortalStatement
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return array{available: bool, as_of: string, balance: string, open: string, credit: string, overdue: string, aging: array<string, string>, invoices: list<array<string, mixed>>, credits: list<array<string, mixed>>}
     */
    public function of(int $customerId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $empty = ['available' => false, 'as_of' => to_date(strtotime($today)), 'balance' => '', 'open' => '', 'credit' => '', 'overdue' => '', 'aging' => [], 'invoices' => [], 'credits' => []];

        if (! (new AccountingSettings($this->db))->isEnabled()) {
            return $empty;
        }

        $position = (new CustomerLedger($this->db))->position($customerId);
        $labels   = $this->invoiceNumbers(array_values(array_filter(array_map(static fn ($invoice): int => $invoice->saleId, $position->invoices), static fn (int $saleId): bool => $saleId > 0)));
        $invoices = [];

        foreach ($position->invoices as $invoice) {
            if (bccomp($invoice->open(), '0', 4) <= 0) {
                continue;
            }

            $invoices[] = [
                'sale_id'      => $invoice->saleId > 0 ? $invoice->saleId : null,
                'label'        => $this->labelOf($invoice->saleId, $invoice->opening, $labels),
                'date'         => to_date(strtotime($invoice->date)),
                'due_date'     => to_date(strtotime($invoice->dueDate)),
                'total'        => $this->money($invoice->total),
                'settled'      => $this->money($invoice->settled()),
                'open'         => $this->money($invoice->open()),
                'days_overdue' => $invoice->daysOverdue($today),
            ];
        }

        $credits = [];

        foreach ($position->credits as $source) {
            if (bccomp($source->available(), '0', 4) <= 0) {
                continue;
            }

            $credits[] = [
                'kind'   => $this->creditLabel($source->type),
                'date'   => to_date(strtotime($source->date)),
                'amount' => $this->money($source->available()),
            ];
        }

        $aging = [];

        foreach ($position->aging($today) as $bucket => $amount) {
            $aging[$bucket] = $this->money($amount);
        }

        return [
            'available' => true,
            'as_of'     => $empty['as_of'],
            'balance'   => $this->money($position->accountBalance),
            'open'      => $this->money($position->open()),
            'credit'    => $this->money($position->credit()),
            'overdue'   => $this->money($position->overdue($today)),
            'aging'     => $aging,
            'invoices'  => $invoices,
            'credits'   => $credits,
        ];
    }

    /**
     * @param list<int> $saleIds The sales the ledger of the customer holds.
     *
     * @return array<int, string>
     */
    private function invoiceNumbers(array $saleIds): array
    {
        $numbers = [];

        if ($saleIds === []) {
            return $numbers;
        }

        foreach ($this->db->table('sales')->select('sale_id, invoice_number')->whereIn('sale_id', $saleIds)->get()->getResult() as $sale) {
            $numbers[(int) $sale->sale_id] = (string) ($sale->invoice_number ?: 'POS ' . (int) $sale->sale_id);
        }

        return $numbers;
    }

    /**
     * @param array<int, string> $labels
     */
    private function labelOf(int $key, bool $opening, array $labels): string
    {
        if ($opening) {
            return lang('Portal.opening_balance');
        }

        $debitNoteId = SalesDebitNotes::idOfKey($key);

        if ($debitNoteId !== null) {
            return lang('Portal.debit_note') . ' ' . (new SalesDebitNotes($this->db))->numberOf($debitNoteId);
        }

        return $labels[$key] ?? 'POS ' . $key;
    }

    private function creditLabel(string $type): string
    {
        return lang(match ($type) {
            CreditSource::CREDIT_NOTE      => 'Portal.credit_note',
            CreditSource::CUSTOMER_ADVANCE => 'Portal.credit_advance',
            CreditSource::CHEQUE           => 'Portal.credit_cheque',
            CreditSource::OPENING          => 'Portal.opening_balance',
            default                        => 'Portal.credit_excess',
        });
    }

    private function money(string $amount): string
    {
        $currencies = new Currencies($this->db);

        return Money::format($amount, $currencies->baseDecimals(), $currencies->baseCode());
    }
}
