<?php

namespace Tests\Support;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CreditRefunds;
use App\Libraries\Accounting\CreditSource;
use App\Libraries\Accounting\CustomerAdvances;
use App\Libraries\Accounting\CustomerLedger;
use App\Libraries\Accounting\CustomerOpening;
use App\Libraries\Accounting\SupplierAdvances;
use App\Libraries\Accounting\SupplierLedger;
use RuntimeException;

/**
 * The credit a customer or a supplier holds from each kind of source, made through the real services and
 * dated days back so that a refund of today finds it whatever the hour, and the change that takes each
 * source back. A class that uses it also uses SaleAccountingFixtureTrait, ChequeFixtureTrait and
 * CreditRefundFixtureTrait, PurchaseAccountingFixtureTrait for the credit of a supplier, and calls
 * stopCreditSources() when a test ends. The clerk, the cheque bank and the switch of the opening balances
 * are made when a source needs them, so a test that makes none leaves the books as it found them.
 */
trait CreditSourceFixtureTrait
{
    private int $creditClerk;

    /** @var array<int, int> The payment whose excess is the credit of an invoice, by invoice. */
    private array $excessPayments = [];

    /** @var array<int, int> The payment whose excess is the credit of a purchase, by purchase. */
    private array $supplierExcessPayments = [];

    private function creditClerk(): int
    {
        return $this->creditClerk ??= $this->employeeWithGrants([AccountingGuard::RECEIVABLES_MANAGE, AccountingGuard::RECEIVABLES_REFUND, AccountingGuard::TREASURIES_MANAGE, AccountingGuard::OPENING_MANAGE]);
    }

    private int $creditTreasurer;

    private function treasurerOfSuppliers(): int
    {
        return $this->creditTreasurer ??= $this->supplierTreasurer();
    }

    private function creditCheques(): void
    {
        if (! isset($this->chequeService)) {
            $this->startCheques();
        }
    }

    protected function stopCreditSources(): void
    {
        $this->releaseOpenTransactions();
        $this->setConfigValue('accounting_customer_opening', '0');

        $db = $this->accountingDb();

        $db->table('document_numbers')->truncate();
        $db->table('document_sequences')->truncate();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function customerCreditSources(): array
    {
        return [
            'a credit note'        => [CreditSource::CREDIT_NOTE],
            'the excess of a bill' => [CreditSource::INVOICE_EXCESS],
            'an advance payment'   => [CreditSource::CUSTOMER_ADVANCE],
            'an opening credit'    => [CreditSource::OPENING],
            'a received cheque'    => [CreditSource::CHEQUE],
        ];
    }

    /**
     * @return array<string, array{string}> The sources of a customer that are not an opening balance.
     */
    public static function customerCreditSourcesBesidesTheOpening(): array
    {
        return array_filter(self::customerCreditSources(), static fn (array $source): bool => $source[0] !== CreditSource::OPENING);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function supplierCreditSources(): array
    {
        return [
            'a debit note'         => [CreditSource::DEBIT_NOTE],
            'the excess of a bill' => [CreditSource::INVOICE_EXCESS],
            'an advance payment'   => [CreditSource::ADVANCE],
            'a delivered cheque'   => [CreditSource::CHEQUE],
        ];
    }

    /**
     * Credit of one source, dated three days ago.
     *
     * @return int The document that holds it: the credit note, the invoice whose payment overpaid it, the advance, the opening balance or the cheque.
     */
    protected function giveCustomerCredit(string $source, string $amount = '100.00', ?int $customerId = null): int
    {
        $customerId ??= $this->customerId;
        $day          = $this->daysAgo(3);

        $this->setConfigValue('accounting_customer_opening', '1');
        $this->creditCheques();

        return match ($source) {
            CreditSource::CUSTOMER_ADVANCE => (new CustomerAdvances())->receive($customerId, $amount, $day, 'Cash', null, '', '', $this->creditClerk()),
            CreditSource::OPENING          => (new CustomerOpening())->create($customerId, CustomerOpening::WE_OWE, $amount, $day, '', $this->creditClerk()),
            CreditSource::CHEQUE           => $this->receiveCheque($amount, $day, $day, $customerId),
            CreditSource::CREDIT_NOTE      => $this->giveCreditNote($amount, $day, $customerId),
            CreditSource::INVOICE_EXCESS   => $this->giveExcess($amount, $day, $customerId),
        };
    }

    private function giveCreditNote(string $amount, string $day, int $customerId): int
    {
        $invoice = $this->invoiceDated('100.00', $this->daysAgo(6), null, $customerId, [$this->payment(lang('Sales.cash'), '100.00')]);
        $note    = $this->creditNoteOf($invoice, $amount, $customerId);

        $this->redateSale($note, $day);

        return $note;
    }

    private function giveExcess(string $amount, string $day, int $customerId): int
    {
        $invoice   = $this->invoiceDated('60.00', $this->daysAgo(6), null, $customerId);
        $paymentId = $this->addPayment($invoice, bcadd('60.00', $amount, 2));

        $this->redatePayment('sales_payments', 'sale_payment', $paymentId, $day);

        $this->excessPayments[$invoice] = $paymentId;

        return $invoice;
    }

    protected function redateSale(int $saleId, string $day): void
    {
        $this->accountingDb()->table('sales')->where('sale_id', $saleId)->update(['sale_time' => $day . ' 10:00:00']);
        $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'sale', 'source_id' => $saleId])->update(['journal_date' => $day]);
    }

    private function redatePayment(string $table, string $source, int $paymentId, string $day): void
    {
        $this->accountingDb()->table($table)->where('payment_id', $paymentId)->update(['payment_time' => $day . ' 10:00:00']);
        $this->accountingDb()->table('accounting_journals')->whereIn('source_type', [$source, $source . '_fx'])->where('source_id', $paymentId)->update(['journal_date' => $day]);
    }

    /**
     * The change that takes the whole of the credit of a source away: the advance or the opening balance is
     * cancelled, the cheque is rejected, the credit note is paid out to the customer in cash and the excess of
     * a payment is cut off it.
     *
     * @throws AccountingException|RuntimeException What the books refuse it with.
     */
    protected function takeBackCustomerCredit(string $source, int $id): void
    {
        $this->creditCheques();

        match ($source) {
            CreditSource::CUSTOMER_ADVANCE => (new CustomerAdvances())->cancel($id, 'mistake', $this->creditClerk()),
            CreditSource::OPENING          => (new CustomerOpening())->cancel($id, 'mistake', $this->creditClerk()),
            CreditSource::CHEQUE           => $this->chequeService->reject($id, date('Y-m-d'), $this->chequeClerk),
            CreditSource::CREDIT_NOTE      => $this->expectSaleSaved($this->sales->update($id, ['comment' => 'paid out', 'payments' => [
                ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '-100.00', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId, 'reference_code' => null],
            ]])),
            CreditSource::INVOICE_EXCESS => $this->expectSaleSaved($this->sales->update($id, ['comment' => 'cut', 'payments' => [
                ['payment_id' => $this->excessPayments[$id], 'payment_type' => lang('Sales.cash'), 'payment_amount' => '60.00', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId, 'reference_code' => null],
            ]])),
        };
    }

    private function expectSaleSaved(bool $saved): void
    {
        if (! $saved) {
            throw $this->sales->accountingFailure() ?? new RuntimeException('The sale was refused for a reason the books do not give.');
        }
    }

    protected function refundCustomerCredit(string $amount, ?string $date = null, ?int $customerId = null, ?string $key = null): int
    {
        return (new CreditRefunds(CreditRefunds::CUSTOMER))->refund($customerId ?? $this->customerId, $amount, $date ?? date('Y-m-d'), 'Cash', null, '', '', $this->creditClerk(), $key);
    }

    protected function cancelCustomerRefund(int $refundId): void
    {
        (new CreditRefunds(CreditRefunds::CUSTOMER))->cancel($refundId, 'paid twice', $this->creditClerk());
    }

    protected function freeCustomerCredit(?int $customerId = null): string
    {
        return bcadd($this->position($customerId)->credit(), '0', 2);
    }

    protected function giveSupplierCredit(string $source, string $amount = '100.00', ?int $supplierId = null): int
    {
        $supplierId ??= $this->supplierId;
        $day          = $this->daysAgo(3);

        $this->creditCheques();
        $this->treasurerOfSuppliers();

        return match ($source) {
            CreditSource::ADVANCE        => (new SupplierAdvances())->pay($supplierId, $amount, $day, 'Cash', null, '', '', $this->treasurerOfSuppliers()),
            CreditSource::CHEQUE         => $this->issueCheque($amount, $day, $day, $supplierId),
            CreditSource::DEBIT_NOTE     => $this->debitNoteOf($this->purchaseOf('100.00', $supplierId, 'Cash'), $amount, $supplierId),
            CreditSource::INVOICE_EXCESS => $this->giveSupplierExcess($amount, $supplierId),
        };
    }

    private function giveSupplierExcess(string $amount, int $supplierId): int
    {
        $purchase = $this->purchaseOf('60.00', $supplierId);

        $this->supplierExcessPayments[$purchase] = $this->payPurchase($purchase, bcadd('60.00', $amount, 2));

        return $purchase;
    }

    /**
     * The change that takes the whole of the credit of a source of a supplier away.
     *
     * @throws AccountingException|RuntimeException
     */
    protected function takeBackSupplierCredit(string $source, int $id): void
    {
        $this->creditCheques();
        $this->treasurerOfSuppliers();

        match ($source) {
            CreditSource::ADVANCE        => (new SupplierAdvances())->cancel($id, 'mistake', $this->treasurerOfSuppliers()),
            CreditSource::CHEQUE         => $this->chequeService->reject($id, date('Y-m-d'), $this->chequeClerk),
            CreditSource::DEBIT_NOTE     => $this->expectPurchaseSaved($this->purchases->delete_value($id, $this->employeeId)),
            CreditSource::INVOICE_EXCESS => $this->expectPurchaseSaved($this->changePayment($id, $this->supplierExcessPayments[$id], ['payment_amount' => '60.00'])),
        };
    }

    private function expectPurchaseSaved(bool $saved): void
    {
        if (! $saved) {
            throw $this->purchases->accountingFailure() ?? new RuntimeException('The purchase was refused for a reason the books do not give.');
        }
    }

    protected function freeSupplierCredit(?int $supplierId = null): string
    {
        return bcadd($this->supplierPosition($supplierId)->credit(), '0', 2);
    }

    /**
     * A change of a source that the books refused wrote nothing, but for the note a refused edit of a sale
     * leaves in the list of failures of the books, so that someone sees that the edit did not go through: the
     * refused change of a credit note, of the excess of a customer's payment and of a debit note does so, and
     * the refused cut of the excess of a payment to a supplier does not.
     *
     * @param array<string, array{rows: int, checksum: string|null}> $before
     */
    protected function assertRefusedChangeLeftNoTrace(array $before, string $source, string $party = CreditRefunds::CUSTOMER): void
    {
        $failures = $this->accountingDb()->prefixTable('accounting_posting_failures');
        $changed  = [];

        foreach ($this->databaseFingerprint() as $table => $print) {
            if (($before[$table] ?? null) !== $print) {
                $changed[] = $table;
            }
        }

        $this->assertSame(
            in_array($party . ':' . $source, [CreditRefunds::CUSTOMER . ':' . CreditSource::CREDIT_NOTE, CreditRefunds::CUSTOMER . ':' . CreditSource::INVOICE_EXCESS, CreditRefunds::SUPPLIER . ':' . CreditSource::DEBIT_NOTE], true) ? [$failures] : [],
            $changed,
            'The change that was refused left a trace in these tables',
        );
    }

    /**
     * The refunds, the journals they wrote and the credit they took say one thing: each refund that stands has
     * one journal that stands for its amount and took exactly its amount from named credits, a refund that was
     * cancelled has none that stands, and the credit the ledger counts as paid back is what the refunds that
     * stand add up to.
     */
    protected function assertRefundsTie(string $party = CreditRefunds::CUSTOMER, ?int $partyId = null): void
    {
        $db      = $this->accountingDb();
        $column  = $party . '_id';
        $partyId ??= $party === CreditRefunds::CUSTOMER ? $this->customerId : $this->supplierId;
        $source  = CreditRefunds::source($party);
        $paidBack = '0.0000';
        $standing = 0;

        foreach ($db->table($party . '_credit_refunds')->where($column, $partyId)->get()->getResult() as $refund) {
            $journals = $db->table('accounting_journals')->where(['source_type' => $source, 'source_id' => $refund->refund_id, 'status' => 'posted'])->get()->getResult();

            if ($refund->status === 'cancelled') {
                $this->assertSame([], $journals, 'The refund ' . $refund->refund_id . ' was cancelled and a journal of it still stands');

                continue;
            }

            $this->assertCount(1, $journals, 'The refund ' . $refund->refund_id . ' stands with ' . count($journals) . ' journals that stand');

            $debit = $db->table('accounting_journal_lines')->selectSum('debit', 'total')->where('journal_id', $journals[0]->journal_id)->get()->getRow()->total;
            $taken = $db->table($party . '_credit_refund_sources')->selectSum('amount', 'total')->where('refund_id', $refund->refund_id)->get()->getRow()->total;

            $this->assertSame(bcadd($refund->amount, '0', 2), bcadd((string) $debit, '0', 2), 'The journal of the refund ' . $refund->refund_id . ' is for another amount');
            $this->assertSame(bcadd($refund->amount, '0', 2), bcadd((string) $taken, '0', 2), 'The refund ' . $refund->refund_id . ' took another amount from the credits than it paid');

            $paidBack = bcadd($paidBack, $refund->amount, 4);
            $standing++;
        }

        $this->assertSame($standing, $db->table('accounting_journals')->where(['source_type' => $source, 'status' => 'posted'])->countAllResults(), 'A journal of a refund stands for no refund');

        $credits = $party === CreditRefunds::CUSTOMER ? (new CustomerLedger($db))->position($partyId)->credits : (new SupplierLedger($db))->position($partyId)->credits;
        $counted = array_reduce($credits, static fn (string $sum, CreditSource $credit): string => bcadd($sum, $credit->refunded, 4), '0.0000');

        $this->assertSame(bcadd($paidBack, '0', 2), bcadd($counted, '0', 2), 'The ledger counts another amount as paid back than the refunds that stand');
    }
}
