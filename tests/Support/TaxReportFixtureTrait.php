<?php

namespace Tests\Support;

use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use App\Models\Expense;

/**
 * The documents and journals a test of the tax report puts in the books: sales and returns with
 * tax, purchases with input tax, expenses with tax, manual journals on the accounts of tax, and
 * documents saved before the books began. A class that uses it also uses AccountingFixtureTrait,
 * EmployeeFixtureTrait, ExpenseAccountingFixtureTrait, ItemFixtureTrait, SaleAccountingFixtureTrait,
 * PurchaseAccountingFixtureTrait and StatementFixtureTrait, and has called startTaxBooks().
 */
trait TaxReportFixtureTrait
{
    /**
     * Starts the books with a second branch, an employee who works in every branch and the expense
     * model, which startExpenses() would reset the chart for.
     */
    protected function startTaxBooks(): void
    {
        $this->clearExpenseRows();
        $this->resetBranches();
        $this->startBooks();
        $this->workInEveryBranch();

        $this->expenses        = model(Expense::class);
        $this->clerkId         = $this->employeeWithGrants([]);
        $this->expensesStarted = true;
    }

    protected function dayOf(int $saleId): string
    {
        return $this->journals($saleId)[0]['date'];
    }

    protected function stopTaxBooks(): void
    {
        $this->setConfigValue('accounting_start_date', '2020-01-01');
        $this->stopExpenses();
        $this->stopBuying();
        $this->clearJournals();
        $this->reinstallChart();
        $this->resetBranches();
    }

    /**
     * An invoice of a service for the net amount, with the tax on it, left on the customer.
     */
    protected function sell(string $net, string $tax, ?int $customerId = null, int $locationId = self::LOCATION_ID): int
    {
        $service = $this->item('0', false);
        $saleId  = $this->save([$this->line($service, 0, '1', $net, '0', '0', PERCENT, $locationId)], $tax === '0' ? [] : [$this->tax($service, 0, $tax)], [], SALE_TYPE_INVOICE, $customerId ?? -1);

        $this->assertGreaterThan(0, $saleId, 'The sale of ' . $net . ' was not saved');

        return $saleId;
    }

    /**
     * A credit note of a service for the net amount, with the tax of it, taken from an invoice.
     */
    protected function takeBack(int $invoiceId, string $net, string $tax): int
    {
        $service  = $this->item('0', false);
        $returnId = $this->save([$this->line($service, 0, '-1', $net)], [$this->tax($service, 0, '-' . $tax)], [], SALE_TYPE_RETURN, -1, true, $invoiceId);

        $this->assertGreaterThan(0, $returnId, 'The return of ' . $net . ' was not saved');

        return $returnId;
    }

    /**
     * A purchase of stock at the price, with tax at the rate, left on the supplier.
     */
    protected function buyTaxed(string $quantity, string $price, string $rate, ?int $supplierId = null): int
    {
        $item        = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($item, 0, $quantity, $price, $rate)], $supplierId ?? -1);

        $this->assertGreaterThan(0, $receivingId, 'The purchase was not saved');

        return $receivingId;
    }

    protected function giveBackTaxed(int $receivingId, string $quantity, string $rate): int
    {
        $line     = $this->accountingDb()->table('receivings_items')->where('receiving_id', $receivingId)->get()->getRow();
        $returnId = $this->buy([$this->purchaseLine((int) $line->item_id, 0, '-' . $quantity, (string) $line->item_unit_price, $rate)], -1, null, $receivingId);

        $this->assertGreaterThan(0, $returnId, 'The purchase return was not saved');

        return $returnId;
    }

    protected function spend(string $gross, string $tax, string $date = '2026-10-20 10:00:00'): int
    {
        $id = $this->saveExpense($this->expenseData($gross, $tax, 'Cash', null, $date));

        $this->assertNotNull($id, 'The expense of ' . $gross . ' was not saved');

        return $id;
    }

    /**
     * The sales and the return the reference system was read on: 2,210 of sales with 331.50 of
     * tax and 220 of returns with 33.00 of tax, so 298.50 is owed.
     *
     * @return array{invoice: int, return: int}
     */
    protected function observedScenario(): array
    {
        $invoice = $this->sell('2210.00', '331.50');

        return ['invoice' => $invoice, 'return' => $this->takeBack($invoice, '220.00', '33.00')];
    }

    protected function postManual(string $account, string $debit, string $credit, string $counter = 'cash', string $status = 'posted'): int
    {
        $journalId = (new PostingService())->post(new JournalDraft(
            date: '2026-10-21',
            lines: [
                new JournalLineDraft($this->accountIdByCode($account), debit: $debit, credit: $credit),
                new JournalLineDraft($this->accountId($counter), debit: $credit, credit: $debit),
            ],
            sourceType: 'manual',
            sourceId: null,
            employeeId: 1,
            currencyCode: 'SAR',
        ))->journalId;

        if ($status !== 'posted') {
            $this->accountingDb()->table('accounting_journals')->where('journal_id', $journalId)->update(['status' => $status]);
        }

        return $journalId;
    }

    protected function saveLegacySale(string $time, string $net, string $tax, int $branchId = 1, int $status = COMPLETED, int $type = SALE_TYPE_INVOICE): int
    {
        $db      = $this->accountingDb();
        $service = $this->item('0', false);

        $db->table('sales')->insert(['sale_time' => $time, 'employee_id' => $this->employeeId, 'comment' => '', 'sale_status' => $status, 'sale_type' => $type, 'branch_id' => $branchId]);
        $saleId = (int) $db->insertID();

        $db->table('sales_items')->insert(['sale_id' => $saleId, 'item_id' => $service, 'line' => 0, 'quantity_purchased' => '1', 'item_cost_price' => '0', 'item_unit_price' => $net, 'discount' => '0', 'discount_type' => 0, 'item_location' => 1, 'print_option' => 0]);

        if ($tax !== '0') {
            $db->table('sales_items_taxes')->insert([...$this->tax($service, 0, $tax), 'sale_id' => $saleId]);
        }

        return $this->saleRowIds[] = $saleId;
    }

    protected function saveLegacyPurchase(string $time, string $rate = '15', string $type = 'receive', int $branchId = 1): void
    {
        $db   = $this->accountingDb();
        $item = $this->item('0.00');

        $db->table('receivings')->insert(['receiving_time' => $time, 'supplier_id' => $this->supplierId, 'employee_id' => $this->employeeId, 'comment' => '', 'receiving_type' => $type, 'branch_id' => $branchId]);
        $receivingId = (int) $db->insertID();

        $db->table('receivings_items')->insert(['receiving_id' => $receivingId, 'item_id' => $item, 'line' => 0, 'quantity_purchased' => '1', 'item_cost_price' => '0', 'item_unit_price' => '100', 'discount' => '0', 'discount_type' => 0, 'item_location' => 1, 'receiving_quantity' => '1', 'tax_percent' => $rate]);

        $this->receivingIds[] = $receivingId;
    }

    protected function saveLegacyExpense(string $time, string $gross, ?string $tax, ?int $branchId = null, int $deleted = 0): void
    {
        $this->accountingDb()->table('expenses')->insert([
            'date'                => $time,
            'amount'              => $gross,
            'tax_amount'          => $tax,
            'payment_type'        => 'Cash',
            'expense_category_id' => $this->category(),
            'description'         => 'Legacy expense',
            'employee_id'         => $this->clerkId,
            'deleted'             => $deleted,
            'branch_id'           => $branchId,
        ]);
    }
}
