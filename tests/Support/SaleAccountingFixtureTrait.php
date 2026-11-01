<?php

namespace Tests\Support;

use App\Libraries\HeaderCharges;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\CreditSettlement;
use App\Libraries\Accounting\CustomerLedger;
use App\Libraries\Accounting\CustomerPosition;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\QuoteStatus;
use App\Libraries\Accounting\ReturnDocument;
use App\Libraries\Accounting\SalePosting;
use App\Libraries\Organization\BranchGuard;
use App\Models\Sale;

/**
 * What a test needs to put sales through the real sale model with accounting on, and to read
 * back what they posted. A class that uses it also uses AccountingFixtureTrait,
 * EmployeeFixtureTrait and ItemFixtureTrait.
 */
trait SaleAccountingFixtureTrait
{
    protected const LOCATION_ID = 1;

    protected Sale $sales;
    protected int $employeeId;
    protected int $customerId;

    /** @var list<int> */
    protected array $saleIds = [];

    protected bool $sellingStarted = false;

    /**
     * Installs the chart, turns accounting on, and gives the employee, the customer and the
     * stock location the accounts they need.
     */
    protected function startSelling(): void
    {
        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');
        $this->setConfigValue('accounting_start_date', '2020-01-01');
        $this->setConfigValue('accounting_enabled', '1');

        $this->sales      = model(Sale::class);
        $this->employeeId = $this->createEmployee();
        $this->customerId = $this->insertCustomer();

        $entities = new EntityAccountService();
        $entities->ensure(EntityAccountService::CUSTOMER, $this->customerId, 'Omar Khaled');
        $entities->ensure(EntityAccountService::STOCK_LOCATION, self::LOCATION_ID, 'stock');

        $this->dropReportTables();
        $this->sellingStarted = true;
    }

    /**
     * The sales report builds temporary tables that live as long as the connection, and a test
     * that runs after another on the same connection would find the other's tables in its way.
     */
    protected function dropReportTables(): void
    {
        foreach (['sales_items_temp', 'sales_payments_temp', 'sales_items_taxes_temp', 'sales_search_payments_temp', 'sales_report_payments_temp', 'sumpay_taxes_temp', 'sumpay_items_temp', 'sumpay_payments_temp'] as $table) {
            $this->accountingDb()->query('DROP TEMPORARY TABLE IF EXISTS ' . $this->accountingDb()->prefixTable($table));
        }
    }

    /**
     * Removes the sales a test saved and turns accounting off again.
     */
    protected function stopSelling(): void
    {
        if (! $this->sellingStarted) {
            return;
        }

        $this->sellingStarted = false;

        $db = $this->accountingDb();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach (['customer_credit_applications', 'customer_advances', 'customer_credit_refund_sources', 'customer_credit_refunds', 'supplier_credit_refund_sources', 'supplier_credit_refunds', 'sale_return_overrides', 'sales_debit_notes'] as $table) {
            $db->table($table)->truncate();
        }

        foreach ($this->saleIds as $saleId) {
            foreach (['sales_payments', 'sales_items_taxes', 'sales_items', 'sales_taxes'] as $table) {
                $db->table($table)->where('sale_id', $saleId)->delete();
            }

            $db->table('sales')->where('sale_id', $saleId)->delete();
        }

        $db->table('inventory')->like('trans_comment', 'POS ', 'after')->delete();
        $db->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->saleIds = [];

        $this->setConfigValue('accounting_enabled', '0');
        $this->removeInsertedEntities();
    }

    protected function item(string $cost, bool $stock = true): int
    {
        $itemId = $this->createTestItem($stock ? HAS_STOCK : HAS_NO_STOCK);

        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['cost_price' => $cost]);

        return $itemId;
    }

    /**
     * @return array<string, mixed>
     */
    protected function line(int $itemId, int $line, string $quantity, string $price, string $cost = '0', string $discount = '0', int $discountType = PERCENT, int $locationId = self::LOCATION_ID): array
    {
        return [
            'item_id'       => $itemId,
            'line'          => $line,
            'description'   => 'Test item',
            'serialnumber'  => '',
            'quantity'      => $quantity,
            'discount'      => $discount,
            'discount_type' => $discountType,
            'cost_price'    => $cost,
            'price'         => $price,
            'item_location' => $locationId,
            'print_option'  => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function tax(int $itemId, int $line, string $amount): array
    {
        return [
            'item_id'           => $itemId,
            'line'              => $line,
            'name'              => 'VAT',
            'percent'           => '15.0000',
            'tax_type'          => 1,
            'rounding_code'     => 0,
            'cascade_sequence'  => 0,
            'item_tax_amount'   => $amount,
            'sales_tax_code_id' => null,
            'tax_category_id'   => null,
            'jurisdiction_id'   => null,
        ];
    }

    /**
     * A tax row of any mode: 0 is a tax inside the price, 1 one added to it. A tax with an account of
     * its own carries it, as the register stamps it.
     *
     * @return array<string, mixed>
     */
    protected function taxRow(int $itemId, int $line, string $name, string $percent, int $type, string $amount, ?int $accountId = null): array
    {
        return [...$this->tax($itemId, $line, $amount), 'name' => $name, 'percent' => $percent, 'tax_type' => $type, 'liability_account_id' => $accountId];
    }

    /**
     * @return array<string, mixed>
     */
    protected function payment(string $type, string $amount, string $refund = '0', int $adjustment = 0): array
    {
        return ['payment_type' => $type, 'payment_amount' => $amount, 'cash_refund' => $refund, 'cash_adjustment' => $adjustment, 'reference_code' => null];
    }

    /**
     * The invoice of the reference cycle: 4 creams at 75 with 10% off and 2 perfumes at 220, tax
     * 15%, so 816.50 for 710 of goods and 106.50 of tax, and goods that cost 400.
     *
     * @return array{items: list<array<string, mixed>>, taxes: list<array<string, mixed>>, cream: int, perfume: int}
     */
    protected function referenceInvoice(): array
    {
        $cream   = $this->item('40.00');
        $perfume = $this->item('120.00');

        return [
            'items'   => [$this->line($cream, 0, '4', '75', '40.00', '10'), $this->line($perfume, 1, '2', '220', '120.00')],
            'taxes'   => [$this->tax($cream, 0, '40.50'), $this->tax($perfume, 1, '66.00')],
            'cream'   => $cream,
            'perfume' => $perfume,
        ];
    }

    /**
     * The reference invoice with a discount on the whole of it, as the register would save it: the
     * taxes are of what is left of each line after its share. A tenth of 710 takes 27 off the creams and
     * 44 off the perfumes, so there is 639 of goods and 95.85 of tax. An amount of 20 takes 7.61 and
     * 12.39 off, so there is 690 of goods and 103.50 of tax.
     *
     * @return array{items: list<array<string, mixed>>, taxes: list<array<string, mixed>>, cream: int, perfume: int, discount: array{type: int, value: string}}
     */
    protected function discountedReferenceInvoice(bool $percent = true): array
    {
        $cycle = $this->referenceInvoice();
        [$creamTax, $perfumeTax] = $percent ? ['36.45', '59.40'] : ['39.3585', '64.1415'];

        return [
            'items'    => $cycle['items'],
            'taxes'    => [$this->tax($cycle['cream'], 0, $creamTax), $this->tax($cycle['perfume'], 1, $perfumeTax)],
            'cream'    => $cycle['cream'],
            'perfume'  => $cycle['perfume'],
            'discount' => $percent ? ['type' => PERCENT, 'value' => '10'] : ['type' => FIXED, 'value' => '20'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param list<array<string, mixed>> $taxes
     * @param list<array<string, mixed>>           $payments
     * @param array{type: int, value: string}|null $invoiceDiscount
     */
    protected function save(array $items, array $taxes = [], array $payments = [], int $type = SALE_TYPE_INVOICE, ?int $customerId = -1, bool $delivered = true, ?int $origin = null, int $status = COMPLETED, int $saleId = NEW_ENTRY, ?array $invoiceDiscount = null, ReturnDocument $returnDocument = ReturnDocument::CreditNote, ?string $invoiceDate = null, int|string|null $paymentTerms = null, ?QuoteStatus $quoteStatus = null, ?int $quoteSourceId = null, ?string $draftFingerprint = null, ?string $returnOverrideReason = null, ?HeaderCharges $headerCharges = null): int
    {
        $saleStatus = $status;
        $salesTaxes = [[], $taxes];
        $customer   = $customerId === -1 ? $this->customerId : ($customerId ?? NEW_ENTRY);
        $result     = $this->sales->save_value($saleId, $saleStatus, $items, $customer, $this->employeeId, 'test', null, null, null, $type, $payments, null, $salesTaxes, $delivered, $origin, $invoiceDiscount, $returnDocument, $invoiceDate, $paymentTerms, $quoteStatus, $quoteSourceId, $draftFingerprint, $returnOverrideReason, $headerCharges);

        if ($result > 0) {
            $this->saleIds[] = $result;
        }

        return $result;
    }

    /**
     * The journals of a sale, oldest first, each with its lines as "account:debit:credit".
     *
     * @return list<array{id: int, source: string, entry: string, revision: int, status: string, date: string, lines: list<string>}>
     */
    protected function journals(int $saleId): array
    {
        $rows = [];

        foreach ((new SalePosting())->journalsOf($saleId) as $journal) {
            $lines = array_map(
                static fn (object $line): string => $line->account_id . ':' . bcadd($line->debit, '0', 2) . ':' . bcadd($line->credit, '0', 2),
                $this->accountingDb()->table('accounting_journal_lines')->select('account_id, debit, credit')->where('journal_id', $journal->journal_id)->orderBy('line_no', 'ASC')->get()->getResult(),
            );

            $rows[] = [
                'id'       => (int) $journal->journal_id,
                'source'   => $journal->source_type,
                'entry'    => $journal->entry_type,
                'revision' => (int) $journal->revision,
                'status'   => $journal->status,
                'date'     => $journal->journal_date,
                'lines'    => $lines,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{id: int, source: string, entry: string, revision: int, status: string, date: string, lines: list<string>}>
     */
    protected function standing(int $saleId, ?string $entry = null): array
    {
        return array_values(array_filter(
            $this->journals($saleId),
            static fn (array $journal): bool => $journal['status'] === 'posted' && ($entry === null || $journal['entry'] === $entry),
        ));
    }

    /**
     * Debit minus credit of an account over everything that was posted, reversals included.
     */
    protected function balance(int $accountId): string
    {
        $row = $this->accountingDb()->query(
            'SELECT COALESCE(SUM(l.debit), 0) - COALESCE(SUM(l.credit), 0) AS balance FROM ' . $this->accountingDb()->prefixTable('accounting_journal_lines') . ' l JOIN '
            . $this->accountingDb()->prefixTable('accounting_journals') . ' j ON j.journal_id = l.journal_id WHERE j.status IN (?, ?) AND l.account_id = ?',
            ['posted', 'reversed', $accountId],
        )->getRow();

        return bcadd((string) $row->balance, '0', 2);
    }

    protected function customerAccount(?int $customerId = null): int
    {
        return (int) (new EntityAccountService())->accountFor(EntityAccountService::CUSTOMER, $customerId ?? $this->customerId);
    }

    protected function stockAccount(): int
    {
        return (int) (new EntityAccountService())->accountFor(EntityAccountService::STOCK_LOCATION, self::LOCATION_ID);
    }

    /**
     * A second customer with the account of their own that posting needs.
     */
    protected function anotherCustomer(string $firstName = 'Hind', string $lastName = 'Saad'): int
    {
        $customerId = $this->insertCustomer($firstName, $lastName);

        (new EntityAccountService())->ensure(EntityAccountService::CUSTOMER, $customerId, trim($firstName . ' ' . $lastName));

        return $customerId;
    }

    /**
     * An invoice of a service that comes to exactly the amount, with no tax and no stock, so a
     * test can talk about what a customer owes without the cost of goods in the way.
     *
     * @param list<array<string, mixed>> $payments
     */
    protected function invoiceOf(string $total, ?int $customerId = null, array $payments = [], int $type = SALE_TYPE_INVOICE): int
    {
        $service = $this->item('0', false);

        return $this->save([$this->line($service, 0, '1', $total)], [], $payments, $type, $customerId ?? -1);
    }

    /**
     * A stock location, with its account, that belongs to the branch given.
     */
    protected function branchLocation(int $branchId, string $name = 'Branch store'): int
    {
        $location = $this->insertStockLocation($name);

        (new EntityAccountService())->ensure(EntityAccountService::STOCK_LOCATION, $location, $name);
        $this->accountingDb()->table('stock_locations')->where('location_id', $location)->update(['branch_id' => $branchId]);

        return $location;
    }

    /**
     * An invoice of a service that comes to exactly the amount, sold from a stock location of the
     * branch given, which makes it a sale of that branch.
     *
     * @param list<array<string, mixed>> $payments
     */
    protected function invoiceInBranch(int $branchId, string $total, array $payments = [], ?int $customerId = null): int
    {
        $service = $this->item('0', false);

        return $this->save([$this->line($service, 0, '1', $total, '0', '0', PERCENT, $this->branchLocation($branchId))], [], $payments, SALE_TYPE_INVOICE, $customerId ?? -1);
    }

    /**
     * A draft invoice of the reference lines, or of one service line, saved the way the register
     * suspends an invoice: its taxes are not stored.
     */
    protected function draftInvoice(?array $items = null, ?int $customerId = null): int
    {
        return $this->save($items ?? $this->referenceInvoice()['items'], [], [], SALE_TYPE_INVOICE, $customerId ?? -1, true, null, SUSPENDED);
    }

    /**
     * Suspended rows of one kind put straight into the table, for what needs many of them at once.
     *
     * @return list<int> Their ids, oldest first.
     */
    protected function suspendedRows(int $count, int $type, int $branchId = 1): array
    {
        $db     = $this->accountingDb();
        $marker = 'rows-' . uniqid();
        $rows   = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'sale_time'    => date('Y-m-d H:i:s'),
                'customer_id'  => null,
                'employee_id'  => $this->employeeId,
                'comment'      => $marker,
                'sale_status'  => SUSPENDED,
                'sale_type'    => $type,
                'branch_id'    => $branchId,
                'delivered'    => 1,
                'quote_status' => $type === SALE_TYPE_QUOTE ? QuoteStatus::Open->value : null,
            ];
        }

        $db->table('sales')->insertBatch($rows);

        $ids = array_map('intval', array_column($db->table('sales')->select('sale_id')->where('comment', $marker)->orderBy('sale_id', 'ASC')->get()->getResultArray(), 'sale_id'));

        $this->saleIds = [...$this->saleIds, ...$ids];

        return $ids;
    }

    /**
     * A quote of the reference lines in the state given, or open when none is.
     */
    protected function quote(?QuoteStatus $status = null, ?array $items = null, ?int $customerId = null): int
    {
        return $this->save($items ?? $this->referenceInvoice()['items'], [], [], SALE_TYPE_QUOTE, $customerId ?? -1, true, null, SUSPENDED, NEW_ENTRY, null, ReturnDocument::CreditNote, null, null, $status);
    }

    /**
     * Lets the seller of the test work in every branch, as an employee of the books does.
     */
    protected function allowEmployeeEverywhere(): void
    {
        $this->accountingDb()->table('grants')->ignore(true)->insert(['permission_id' => BranchGuard::ALL_BRANCHES, 'person_id' => $this->employeeId, 'menu_group' => '--']);
    }

    /**
     * Lets the seller of the test let a return of stock through with no sale to take it back from.
     */
    protected function grantReturnOverride(): void
    {
        $this->accountingDb()->table('grants')->ignore(true)->insert(['permission_id' => 'sales_return_override', 'person_id' => $this->employeeId, 'menu_group' => 'home']);
    }

    /**
     * Lets the seller of the test type the date of an invoice.
     */
    protected function grantInvoiceDate(): void
    {
        $this->accountingDb()->table('grants')->ignore(true)->insert(['permission_id' => 'sales_invoice_date', 'person_id' => $this->employeeId, 'menu_group' => 'home']);
    }

    /**
     * An invoice of a service dated on a day typed on it, with payment terms when it has them. The
     * seller is given the permission to type the date first.
     *
     * @param list<array<string, mixed>> $payments
     */
    protected function invoiceDated(string $total, string $date, int|string|null $terms = null, ?int $customerId = null, array $payments = []): int
    {
        $this->grantInvoiceDate();

        $service = $this->item('0', false);

        return $this->save([$this->line($service, 0, '1', $total)], [], $payments, SALE_TYPE_INVOICE, $customerId ?? -1, true, null, COMPLETED, NEW_ENTRY, null, invoiceDate: $date, paymentTerms: $terms);
    }

    /**
     * The day a number of days ago.
     */
    protected function daysAgo(int $days): string
    {
        return date('Y-m-d', strtotime("-{$days} days"));
    }

    /**
     * A credit note of a service for the amount, taken from an invoice.
     */
    protected function creditNoteOf(int $invoiceId, string $total, ?int $customerId = null): int
    {
        $service = $this->item('0', false);

        return $this->save([$this->line($service, 0, '-1', $total)], [], [], SALE_TYPE_RETURN, $customerId ?? -1, true, $invoiceId);
    }

    /**
     * A refund invoice of a service for the amount, taken from an invoice: the same return as a
     * credit note, issued as the other document.
     */
    protected function refundInvoiceOf(int $invoiceId, string $total, ?int $customerId = null): int
    {
        $service = $this->item('0', false);

        return $this->save([$this->line($service, 0, '-1', $total)], [], [], SALE_TYPE_RETURN, $customerId ?? -1, true, $invoiceId, COMPLETED, NEW_ENTRY, null, ReturnDocument::RefundInvoice);
    }

    /**
     * Adds a payment to a sale the way the edit form does, and returns its id.
     */
    protected function addPayment(int $saleId, string $amount, ?string $type = null, int $refund = 0): int
    {
        $this->assertTrue($this->sales->update($saleId, ['comment' => 'paid', 'payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => $type ?? lang('Sales.cash'), 'payment_amount' => $amount, 'cash_refund' => $refund, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId, 'reference_code' => null],
        ]]));

        return (int) $this->accountingDb()->table('sales_payments')->selectMax('payment_id', 'last')->where('sale_id', $saleId)->get()->getRow()->last;
    }

    protected function removePayment(int $saleId, int $paymentId): void
    {
        $this->assertTrue($this->sales->update($saleId, ['comment' => 'removed', 'payments' => [
            ['payment_id' => $paymentId, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '0', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId, 'reference_code' => null],
        ]]));
    }

    protected function position(?int $customerId = null, ?string $asOf = null): CustomerPosition
    {
        return (new CustomerLedger())->position($customerId ?? $this->customerId, $asOf);
    }

    /**
     * The open invoices, the credit and the account of the customer say the same thing, and the
     * aging adds up to what is open.
     */
    protected function assertTies(?int $customerId = null): void
    {
        $position = $this->position($customerId);

        $this->assertTrue($position->agrees(), 'The account of the customer holds ' . $position->accountBalance . ' and the invoices and credit say ' . $position->ledgerBalance());
        $this->assertSame($position->open(), array_reduce($position->aging(date('Y-m-d')), static fn (string $sum, string $amount): string => bcadd($sum, $amount, 4), '0.0000'));
        $this->assertSame(bcadd($position->accountBalance, '0', 2), $this->balance((int) $position->accountId));
    }

    /**
     * What stands in the applications of a customer, as "source:id->invoice:amount".
     *
     * @return list<string>
     */
    protected function applications(?int $customerId = null): array
    {
        return array_map(
            static fn (object $row): string => $row->source_type . ':' . $row->source_id . '->' . $row->invoice_sale_id . ':' . bcadd($row->amount, '0', 2),
            (new CustomerLedger())->applications($customerId ?? $this->customerId),
        );
    }

    protected function settle(?int $customerId = null): void
    {
        (new CreditSettlement())->settle($customerId ?? $this->customerId, $this->employeeId);
    }

    protected function saleRow(int $saleId): ?object
    {
        return $this->accountingDb()->table('sales')->where('sale_id', $saleId)->get()->getRow();
    }

    protected function countSales(): int
    {
        return $this->accountingDb()->table('sales')->countAllResults();
    }

    protected function countJournals(): int
    {
        return $this->accountingDb()->table('accounting_journals')->countAllResults();
    }

    protected function stock(int $itemId): float
    {
        return $this->getItemQuantity($itemId, self::LOCATION_ID);
    }

    protected function failure(): PostingException|AccountingException
    {
        $failure = $this->sales->accountingFailure();

        $this->assertTrue($failure instanceof PostingException || $failure instanceof AccountingException, 'The books were expected to refuse.');

        return $failure;
    }
}
