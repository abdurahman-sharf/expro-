<?php

namespace Tests\Support;

use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\InvoiceDiscount;
use App\Libraries\Accounting\ReceivingPosting;
use App\Libraries\Accounting\StockLedger;
use App\Libraries\Accounting\SupplierLedger;
use App\Libraries\Accounting\SupplierPosition;
use App\Libraries\Accounting\SupplierSettlement;
use App\Models\Receiving;

/**
 * What a test needs to put purchases through the real purchase model with accounting on, and to
 * read back what they posted. A class that uses it also uses SaleAccountingFixtureTrait, which
 * turns accounting on and gives the stock location its account, AccountingFixtureTrait,
 * EmployeeFixtureTrait and ItemFixtureTrait.
 */
trait PurchaseAccountingFixtureTrait
{
    protected Receiving $purchases;
    protected int $supplierId;

    /** @var list<int> */
    protected array $receivingIds = [];

    protected bool $buyingStarted = false;

    protected function startBuying(): void
    {
        $this->startSelling();

        $this->purchases  = model(Receiving::class);
        $this->supplierId = $this->supplier();
        $this->buyingStarted = true;
    }

    /**
     * Removes the purchases a test saved, with their stock, and stops what startBuying started.
     */
    protected function stopBuying(): void
    {
        if (! $this->buyingStarted) {
            return;
        }

        $this->buyingStarted = false;

        $db = $this->accountingDb();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($this->receivingIds as $receivingId) {
            $db->table('receivings_payments')->where('receiving_id', $receivingId)->delete();
            $db->table('receivings_items')->where('receiving_id', $receivingId)->delete();
            $db->table('receivings')->where('receiving_id', $receivingId)->delete();
        }

        if ($this->insertedLocations !== []) {
            $db->table('item_quantities')->whereIn('location_id', $this->insertedLocations)->delete();
            $db->table('inventory')->whereIn('trans_location', $this->insertedLocations)->delete();
        }

        $db->table('inventory')->like('trans_comment', 'RECV ', 'after')->delete();
        $db->table('inventory')->like('trans_comment', 'Deleting receiving ', 'after')->delete();
        $db->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->receivingIds = [];

        $this->stopSelling();
    }

    /**
     * A supplier with the account of their own that posting needs.
     */
    protected function supplier(string $company = 'Gulf Supplies'): int
    {
        $supplierId = $this->insertSupplier($company);

        (new EntityAccountService())->ensure(EntityAccountService::SUPPLIER, $supplierId, $company);

        return $supplierId;
    }

    protected function supplierAccount(?int $supplierId = null): int
    {
        return (int) (new EntityAccountService())->accountFor(EntityAccountService::SUPPLIER, $supplierId ?? $this->supplierId);
    }

    protected function stockAccountOf(int $locationId): int
    {
        return (int) (new EntityAccountService())->accountFor(EntityAccountService::STOCK_LOCATION, $locationId);
    }

    /**
     * A second stock location of the same branch, with its account.
     */
    protected function anotherLocation(string $name = 'Second warehouse'): int
    {
        $locationId = $this->insertStockLocation($name);

        (new EntityAccountService())->ensure(EntityAccountService::STOCK_LOCATION, $locationId, $name);

        return $locationId;
    }

    /**
     * A stock location, with its account, that belongs to the branch given.
     */
    protected function locationOfBranch(int $branchId, string $name = 'Branch warehouse'): int
    {
        $locationId = $this->anotherLocation($name);

        $this->accountingDb()->table('stock_locations')->where('location_id', $locationId)->update(['branch_id' => $branchId]);

        return $locationId;
    }

    /**
     * Makes the employee of the test work in the branches given and no others.
     */
    protected function workInBranches(int ...$branchIds): void
    {
        $this->employeeId = $this->employeeWithGrants([], array_values($branchIds));
    }

    protected function workInEveryBranch(): void
    {
        $this->employeeId = $this->employeeWithGrants([]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function purchaseLine(int $itemId, int $line, string $quantity, string $price, string $tax = '0', string $discount = '0', int $discountType = PERCENT, int $locationId = self::LOCATION_ID, string $perPack = '1'): array
    {
        return [
            'item_id'            => $itemId,
            'line'               => $line,
            'description'        => 'Test item',
            'serialnumber'       => '',
            'quantity'           => $quantity,
            'receiving_quantity' => $perPack,
            'discount'           => $discount,
            'discount_type'      => $discountType,
            'price'              => $price,
            'item_location'      => $locationId,
            'tax_percent'        => $tax,
        ];
    }

    /**
     * Saves a purchase the way the screen does, and returns what the model returns.
     *
     * @param list<array<string, mixed>> $lines
     */
    protected function buy(array $lines, ?int $supplierId = -1, ?string $paymentType = null, ?int $origin = null, ?InvoiceDiscount $discount = null, ?string $invoiceDate = null, int|string|null $paymentTerms = null): int
    {
        $result = $this->purchases->save_value(
            $lines,
            $supplierId === -1 ? $this->supplierId : ($supplierId ?? NEW_ENTRY),
            $this->employeeId,
            'test',
            'REF-' . uniqid(),
            $paymentType ?? lang('Sales.due'),
            NEW_ENTRY,
            $origin,
            $discount,
            $invoiceDate,
            $paymentTerms,
        );

        if ($result > 0) {
            $this->receivingIds[] = $result;
        }

        return $result;
    }

    /**
     * @return list<array{id: int, source: string, entry: string, revision: int, status: string, date: string, lines: list<string>}>
     */
    protected function purchaseJournals(int $receivingId): array
    {
        $rows = [];

        foreach ((new ReceivingPosting())->journalsOf($receivingId) as $journal) {
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

    protected function storedLine(int $receivingId, int $itemId, int $line = 0): ?object
    {
        return $this->accountingDb()->table('receivings_items')->where(['receiving_id' => $receivingId, 'item_id' => $itemId, 'line' => $line])->get()->getRow();
    }

    /**
     * @return list<array{id: int, source: string, entry: string, revision: int, status: string, date: string, lines: list<string>}>
     */
    protected function standingPurchase(int $receivingId, string $source = 'purchase'): array
    {
        return array_values(array_filter(
            $this->purchaseJournals($receivingId),
            static fn (array $journal): bool => $journal['status'] === 'posted' && $journal['source'] === $source,
        ));
    }

    /**
     * What the movements say an item is worth in a stock location.
     *
     * @return array{quantity: string, value: string}
     */
    protected function stockOf(int $itemId, int $locationId = self::LOCATION_ID): array
    {
        $position = (new StockLedger())->position($itemId, $locationId);

        return ['quantity' => bcadd($position['quantity'], '0', 3), 'value' => bcadd($position['value'], '0', 2)];
    }

    protected function onHand(int $itemId, int $locationId = self::LOCATION_ID): string
    {
        return bcadd((string) $this->getItemQuantity($itemId, $locationId), '0', 3);
    }

    protected function itemCostOf(int $itemId): string
    {
        return bcadd($this->exactCostOf($itemId), '0', 4);
    }

    /**
     * The cost an item carries to every decimal it is kept at.
     */
    protected function exactCostOf(int $itemId): string
    {
        return (string) $this->accountingDb()->table('items')->select('cost_price')->where('item_id', $itemId)->get()->getRow()->cost_price;
    }

    /**
     * Every stock location says the same thing in the account and in the movements, and no
     * journal holds a value its movements do not.
     */
    protected function assertStockTies(): void
    {
        $ledger = new StockLedger();

        $this->assertSame([], $ledger->mismatchedJournals(), 'A journal and its stock movements disagree');

        foreach ($ledger->reconcile() as $location) {
            $this->assertSame(bcadd($location['movements_value'], '0', 2), bcadd($location['account_balance'], '0', 2), 'Location ' . $location['location_id'] . ': the account and the movements differ');
        }
    }

    /**
     * A second supplier with the account of their own that posting needs.
     */
    protected function anotherSupplier(string $company = 'Najd Trading'): int
    {
        return $this->supplier($company);
    }

    /**
     * A purchase of one unit that comes to exactly the amount, with no tax, left on credit, so a
     * test can talk about what is owed to a supplier without the stock in the way.
     */
    protected function purchaseOf(string $total, ?int $supplierId = null, ?string $paymentType = null): int
    {
        $item = $this->item('0.00');

        $receivingId = $this->buy([$this->purchaseLine($item, 0, '1', $total)], $supplierId ?? -1, $paymentType);

        $this->assertGreaterThan(0, $receivingId, 'The purchase of ' . $total . ' was not saved');

        return $receivingId;
    }

    /**
     * Lets the buyer of the test type the date of a purchase.
     */
    protected function grantPurchaseDate(): void
    {
        $this->accountingDb()->table('grants')->ignore(true)->insert(['permission_id' => 'receivings_invoice_date', 'person_id' => $this->employeeId, 'menu_group' => 'home']);
    }

    /**
     * A purchase of one unit dated on a day typed on it, with payment terms when it has them, left
     * on credit. The buyer is given the permission to type the date first.
     */
    protected function purchaseDated(string $total, string $date, int|string|null $terms = null, ?int $supplierId = null): int
    {
        $this->grantPurchaseDate();

        $item        = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($item, 0, '1', $total)], $supplierId ?? -1, null, null, null, $date, $terms);

        $this->assertGreaterThan(0, $receivingId, 'The purchase of ' . $total . ' dated ' . $date . ' was not saved');

        return $receivingId;
    }

    /**
     * A return that takes back the worth given of what a purchase brought in, which is a debit
     * note. The purchase must be of one unit, so the worth is a part of a unit.
     */
    protected function debitNoteOf(int $receivingId, string $worth, ?int $supplierId = null): int
    {
        $line  = $this->accountingDb()->table('receivings_items')->where('receiving_id', $receivingId)->get()->getRow();
        $units = bcdiv($worth, (string) $line->item_unit_price, 3);

        $returnId = $this->buy([$this->purchaseLine((int) $line->item_id, 0, '-' . $units, (string) $line->item_unit_price)], $supplierId ?? -1, null, $receivingId);

        $this->assertGreaterThan(0, $returnId, 'The debit note of ' . $worth . ' was not saved');

        return $returnId;
    }

    /**
     * Adds a payment to a purchase the way the edit form does, and returns its id.
     */
    protected function payPurchase(int $receivingId, string $amount, ?string $type = null, string $status = 'completed'): int
    {
        $this->assertTrue($this->purchases->update($receivingId, ['comment' => 'paid', 'payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => $type ?? lang('Sales.cash'), 'payment_amount' => $amount, 'employee_id' => $this->employeeId, 'payment_status' => $status, 'reference_code' => ''],
        ]]), 'The payment of ' . $amount . ' was refused');

        return (int) $this->accountingDb()->table('receivings_payments')->selectMax('payment_id', 'last')->where('receiving_id', $receivingId)->get()->getRow()->last;
    }

    /**
     * Changes a payment the way the edit form does: with an amount of nothing it is removed.
     *
     * @param array<string, mixed> $changes
     */
    protected function changePayment(int $receivingId, int $paymentId, array $changes): bool
    {
        $stored = $this->accountingDb()->table('receivings_payments')->where('payment_id', $paymentId)->get()->getRowArray();

        return $this->purchases->update($receivingId, ['comment' => 'changed', 'payments' => [
            ['payment_id' => $paymentId, 'payment_type' => $stored['payment_type'], 'payment_amount' => $stored['payment_amount'], 'payment_status' => $stored['payment_status'], 'employee_id' => $this->employeeId, ...$changes],
        ]]);
    }

    protected function supplierPosition(?int $supplierId = null, ?string $asOf = null): SupplierPosition
    {
        return (new SupplierLedger())->position($supplierId ?? $this->supplierId, $asOf);
    }

    /**
     * What the account of a supplier holds, as what is owed to them.
     */
    protected function owed(?int $supplierId = null): string
    {
        return bcsub('0', $this->balance((int) $this->supplierAccount($supplierId)), 2);
    }

    /**
     * What the purchases and the credit of a supplier say is what the account says, and the aging
     * adds up to what is open.
     */
    protected function assertSupplierTies(?int $supplierId = null): void
    {
        $position = $this->supplierPosition($supplierId);

        $this->assertTrue($position->agrees(), 'The account of the supplier holds ' . $position->accountBalance . ' and the purchases and credit say ' . $position->ledgerBalance());
        $this->assertSame($position->open(), array_reduce($position->aging(date('Y-m-d')), static fn (string $sum, string $amount): string => bcadd($sum, $amount, 4), '0.0000'));
        $this->assertSame(bcadd($position->accountBalance, '0', 2), $this->owed($supplierId));
    }

    /**
     * What stands in the applications of a supplier, as "source:id->purchase:amount".
     *
     * @return list<string>
     */
    protected function supplierApplications(?int $supplierId = null): array
    {
        return array_map(
            static fn (object $row): string => $row->source_type . ':' . $row->source_id . '->' . $row->invoice_receiving_id . ':' . bcadd($row->amount, '0', 2),
            (new SupplierLedger())->applications($supplierId ?? $this->supplierId),
        );
    }

    protected function settleSupplier(?int $supplierId = null): void
    {
        (new SupplierSettlement())->settle($supplierId ?? $this->supplierId, $this->employeeId);
    }
}
