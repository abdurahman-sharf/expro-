<?php

namespace Tests\Support;

use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\ReceivingPosting;
use App\Libraries\Accounting\StockLedger;
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
    protected function buy(array $lines, ?int $supplierId = -1, ?string $paymentType = null, ?int $origin = null): int
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
        return bcadd((string) $this->accountingDb()->table('items')->select('cost_price')->where('item_id', $itemId)->get()->getRow()->cost_price, '0', 4);
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
}
