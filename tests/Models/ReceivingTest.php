<?php

namespace Tests\Models;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Models\Receiving;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * Regression tests: Receiving::delete_value() must
 * correctly reverse the stock quantity change it applied via
 * Item_quantity::changeQuantity(), using the same atomic upsert as the
 * sale-checkout and sale-cancel paths.
 */
class ReceivingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;
    protected $namespace   = null;

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->stopBuying();

        parent::tearDown();
    }

    protected function createSupplier(): int
    {
        $db = Database::connect();

        $db->table('people')->insert([
            'first_name'   => 'Test',
            'last_name'    => 'Supplier',
            'phone_number' => '555-0400',
            'email'        => 'supplier-' . uniqid() . '@test.com',
            'address_1'    => '',
            'address_2'    => '',
            'city'         => '',
            'state'        => '',
            'zip'          => '',
            'country'      => '',
            'comments'     => '',
        ]);
        $personId = (int) $db->insertID();

        $db->table('suppliers')->insert([
            'person_id' => $personId,
            'deleted'   => 0,
        ]);

        return $personId;
    }

    protected function buildReceivingLine(int $itemId, float $quantity, float $costPrice): array
    {
        return [
            0 => [
                'item_id'            => $itemId,
                'line'               => 0,
                'description'        => 'Test Item',
                'serialnumber'       => '',
                'quantity'           => $quantity,
                'receiving_quantity' => 1,
                'discount'           => 0,
                'discount_type'      => 0,
                'price'              => $costPrice,
                'item_location'      => self::LOCATION_ID,
            ],
        ];
    }

    public function testDeleteValueRestoresStockAfterReceiving(): void
    {
        $employeeId = $this->createEmployee();
        $supplierId = $this->createSupplier();
        $itemId     = $this->createTestItem(HAS_STOCK);

        // Deliberately no pre-existing item_quantities row for this item/location.

        $receivingModel = model(Receiving::class);
        $items          = $this->buildReceivingLine($itemId, 5, 1.00);

        $receivingId = $receivingModel->save_value(
            $items,
            $supplierId,
            $employeeId,
            'test receiving',
            'REF-' . uniqid(),
            'Cash'
        );

        $this->assertGreaterThan(0, $receivingId);
        $this->assertEqualsWithDelta(5.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);

        $deleteResult = $receivingModel->delete_value($receivingId, $employeeId, true);

        $this->assertTrue($deleteResult);
        $this->assertEqualsWithDelta(0.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
    }
    public function testBranchIdsOfItems_NamesEachBranchOfTheLocationsOfTheLinesOnce(): void
    {
        $this->startBuying();
        $branch = $this->createBranch('R9', 'Ninth branch');
        $depot  = $this->anotherLocation('Depot');
        $this->accountingDb()->table('stock_locations')->where('location_id', $depot)->update(['branch_id' => $branch]);
        $second = $this->anotherLocation('Second depot');
        $this->accountingDb()->table('stock_locations')->where('location_id', $second)->update(['branch_id' => $branch]);

        $receivings = model(Receiving::class);

        $this->assertSame([], $receivings->branchIdsOfItems([]));
        $this->assertSame([1], $receivings->branchIdsOfItems([['item_location' => self::LOCATION_ID], ['item_location' => self::LOCATION_ID]]));
        $this->assertSame([1, $branch], $receivings->branchIdsOfItems([['item_location' => $depot], ['item_location' => $second], ['item_location' => self::LOCATION_ID]]));
    }

    public function testGetBranchId_IsTheBranchOfThePurchaseAndNullWhenThereIsNone(): void
    {
        $this->startBuying();
        $branch = $this->createBranch('R8', 'Eighth branch');
        $depot  = $this->anotherLocation('Depot');
        $this->accountingDb()->table('stock_locations')->where('location_id', $depot)->update(['branch_id' => $branch]);
        $itemId = $this->item('0.00');

        $here  = $this->buy([$this->purchaseLine($itemId, 0, '1', '10')]);
        $there = $this->buy([$this->purchaseLine($itemId, 0, '1', '10', locationId: $depot)]);

        $this->assertSame(1, $this->purchases->getBranchId($here));
        $this->assertSame($branch, $this->purchases->getBranchId($there));
        $this->assertNull($this->purchases->getBranchId(0));
    }

    public function testHasReturns_IsTrueOnlyForAPurchaseGoodsWentBackFrom(): void
    {
        $this->startBuying();
        $itemId   = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]);
        $other    = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]);

        $this->assertFalse($this->purchases->hasReturns($purchase));

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '-2', '50')], -1, null, $purchase));

        $this->assertTrue($this->purchases->hasReturns($purchase));
        $this->assertFalse($this->purchases->hasReturns($other));
    }

    public function testGetReturnableQuantities_SaysWhatEachItemBroughtAndWhatEarlierReturnsGaveBack(): void
    {
        $this->startBuying();
        $first    = $this->item('0.00');
        $second   = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($first, 0, '10', '50'), $this->purchaseLine($second, 1, '2', '20', perPack: '6')]);

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($first, 0, '-3', '50')], -1, null, $purchase));
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($first, 0, '-1', '50'), $this->purchaseLine($second, 1, '-5', '20')], -1, null, $purchase));

        $this->assertEqualsWithDelta(['bought' => 10.0, 'returned' => 4.0], $this->purchases->getReturnableQuantities($purchase)[$first], 0.0001);
        $this->assertEqualsWithDelta(['bought' => 12.0, 'returned' => 5.0], $this->purchases->getReturnableQuantities($purchase)[$second], 0.0001);
        $this->assertSame([], $this->purchases->getReturnableQuantities(0));
    }

    public function testUpdate_RefusesAnotherSupplierOrDateForAPostedPurchaseAndKeepsTheRow(): void
    {
        $this->startBuying();
        $itemId   = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]);
        $other    = $this->supplier('Another supplier');
        $before   = $this->accountingDb()->table('receivings')->where('receiving_id', $purchase)->get()->getRow();

        $this->assertFalse($this->purchases->update($purchase, ['supplier_id' => $other]));
        $this->assertFalse($this->purchases->update($purchase, ['receiving_time' => '2020-02-02 10:00:00']));

        $failure = $this->purchases->accountingFailure();

        $this->assertInstanceOf(AccountingException::class, $failure);
        $this->assertSame(AccountingError::PurchaseLocked, $failure->error);

        $after = $this->accountingDb()->table('receivings')->where('receiving_id', $purchase)->get()->getRow();

        $this->assertSame($before->supplier_id, $after->supplier_id);
        $this->assertSame($before->receiving_time, $after->receiving_time);
    }

    public function testUpdate_StillTakesTheCommentAndTheSameSupplierAndDateOfAPostedPurchase(): void
    {
        $this->startBuying();
        $itemId   = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]);
        $time     = (string) $this->accountingDb()->table('receivings')->where('receiving_id', $purchase)->get()->getRow()->receiving_time;

        $this->assertTrue($this->purchases->update($purchase, ['comment' => 'changed', 'supplier_id' => $this->supplierId, 'receiving_time' => $time]));
        $this->assertNull($this->purchases->accountingFailure());
        $this->assertSame('changed', $this->accountingDb()->table('receivings')->where('receiving_id', $purchase)->get()->getRow()->comment);
    }

    public function testUpdate_ChangesTheSupplierOfAPurchaseWhileAccountingIsOff(): void
    {
        $itemId     = $this->createTestItem(HAS_STOCK);
        $employeeId = $this->createEmployee();
        $supplierId = $this->createSupplier();
        $other      = $this->createSupplier();
        $receivings = model(Receiving::class);

        $purchase = $receivings->save_value($this->buildReceivingLine($itemId, 2, 3.00), $supplierId, $employeeId, 'test', 'REF-' . uniqid(), 'Cash');

        $this->assertTrue($receivings->update($purchase, ['supplier_id' => $other]));
        $this->assertSame($other, (int) Database::connect()->table('receivings')->where('receiving_id', $purchase)->get()->getRow()->supplier_id);
    }
}
