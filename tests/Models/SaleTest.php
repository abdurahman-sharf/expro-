<?php

namespace Tests\Models;

use App\Libraries\InvoiceDiscountProblem;
use App\Models\Reports\Summary_sales;
use App\Models\Sale;
use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\FailedQueryConnectionTrait;
use Tests\Support\ItemFixtureTrait;

/**
 * Regression tests: Sale::save_value() must reject
 * (and roll back) a payment that would overdraw a gift card or a customer's
 * reward points, instead of silently applying a stale/negative balance.
 */
class SaleTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use EmployeeFixtureTrait;
    use FailedQueryConnectionTrait;
    use ItemFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $seedOnce    = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private static bool $doneBootstrap = false;

    private const LOCATION_ID = 1;

    protected function setUp(): void
    {
        if (self::$doneBootstrap === false) {
            Config::seeder($this->DBGroup)->call('App\Database\Seeds\TestDatabaseBootstrapSeeder');
            Config::connect($this->DBGroup)->close();

            self::$doneBootstrap = true;
        }

        parent::setUp();
        $this->removeTheSalesOfTheReportDays();
    }

    protected function tearDown(): void
    {
        $this->dropReportTables();
        $this->removeTheSalesOfTheReportDays();

        parent::tearDown();
    }

    /**
     * The sales the report tests make are dated in months no other test uses, and are removed so
     * that the next run finds the days empty.
     */
    private function removeTheSalesOfTheReportDays(): void
    {
        $db  = \Config\Database::connect();
        $ids = [];

        foreach (['2001-02-', '2002-03-'] as $month) {
            $ids = array_merge($ids, array_column($db->table('sales')->select('sale_id')->like('sale_time', $month, 'after')->get()->getResultArray(), 'sale_id'));
        }

        if ($ids === []) {
            return;
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach (['sales_payments', 'sales_items_taxes', 'sales_items', 'sales_taxes', 'sales'] as $table) {
            $db->table($table)->whereIn('sale_id', $ids)->delete();
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * The reports build temporary tables that live as long as the connection, and a test that runs
     * after another on the same connection would find the other's tables in its way.
     */
    private function dropReportTables(): void
    {
        $db = \Config\Database::connect();

        foreach (['sales_items_temp', 'sales_payments_temp', 'sales_items_taxes_temp', 'sales_search_payments_temp', 'sales_report_payments_temp'] as $table) {
            $db->query('DROP TEMPORARY TABLE IF EXISTS ' . $db->prefixTable($table));
        }
    }

    protected function createGiftcard(float $value): int
    {
        $giftcardNumber = random_int(1000000, 9999999);

        \Config\Database::connect()->table('giftcards')->insert([
            'giftcard_number' => $giftcardNumber,
            'value'           => $value,
            'deleted'         => 0,
            'person_id'       => null,
        ]);

        return $giftcardNumber;
    }

    protected function createCustomerWithPoints(int $points): int
    {
        $db = \Config\Database::connect();

        $db->table('people')->insert([
            'first_name'   => 'Reward',
            'last_name'    => 'Customer',
            'phone_number' => '555-0300',
            'email'        => 'customer-' . uniqid() . '@test.com',
            'address_1'    => '',
            'address_2'    => '',
            'city'         => '',
            'state'        => '',
            'zip'          => '',
            'country'      => '',
            'comments'     => '',
        ]);
        $personId = (int) $db->insertID();

        $db->table('customers')->insert([
            'person_id'   => $personId,
            'employee_id' => 1,
            'points'      => $points,
        ]);

        return $personId;
    }

    protected function buildCartLine(int $itemId, float $quantity, float $price): array
    {
        return [
            0 => [
                'item_id'       => $itemId,
                'line'          => 0,
                'description'   => 'Test Item',
                'serialnumber'  => '',
                'quantity'      => $quantity,
                'discount'      => 0,
                'discount_type' => 0,
                'cost_price'    => 1.00,
                'price'         => $price,
                'item_location' => self::LOCATION_ID,
                'print_option'  => 0,
            ],
        ];
    }

    protected function saveInvoice(bool $delivered, ?int $itemId = null, float $quantity = 1): int
    {
        $itemId ??= $this->createTestItem(HAS_STOCK);
        $status   = COMPLETED;
        $items    = $this->buildCartLine($itemId, $quantity, 5.00);
        $taxes    = [[], []];

        return model(Sale::class)->save_value(NEW_ENTRY, $status, $items, NEW_ENTRY, $this->createEmployee(), 'invoice', null, null, null, SALE_TYPE_INVOICE, [], null, $taxes, $delivered);
    }

    protected function countSalesRows(): int
    {
        return \Config\Database::connect()->table('sales')->countAllResults();
    }

    protected function getGiftcardValue(int $giftcardNumber): float
    {
        $row = \Config\Database::connect()->table('giftcards')
            ->where('giftcard_number', $giftcardNumber)
            ->get()
            ->getRow();

        return (float) $row->value;
    }

    protected function getCustomerPoints(int $personId): int
    {
        $row = \Config\Database::connect()->table('customers')
            ->where('person_id', $personId)
            ->get()
            ->getRow();

        return (int) $row->points;
    }

    public function testSaveValueSucceedsWithSufficientGiftcardBalance(): void
    {
        $employeeId     = $this->createEmployee();
        $giftcardNumber = $this->createGiftcard(100.00);
        $itemId         = $this->createTestItem(HAS_NO_STOCK);

        $saleModel  = model(Sale::class);
        $saleStatus = COMPLETED;
        $items      = $this->buildCartLine($itemId, 1, 60.00);
        $payments   = [
            0 => [
                'payment_type'    => lang('Sales.giftcard') . ':' . $giftcardNumber,
                'payment_amount'  => 60.00,
                'cash_refund'     => 0,
                'cash_adjustment' => 0,
                'reference_code'  => null,
            ],
        ];
        $salesTaxes = [[], []];

        $result = $saleModel->save_value(
            NEW_ENTRY,
            $saleStatus,
            $items,
            NEW_ENTRY,
            $employeeId,
            'test sale',
            null,
            null,
            null,
            SALE_TYPE_POS,
            $payments,
            null,
            $salesTaxes
        );

        $this->assertGreaterThan(0, $result);
        $this->assertEqualsWithDelta(40.00, $this->getGiftcardValue($giftcardNumber), 0.001);
    }

    public function testSaveValueDoesNotMatchGiftcardSinkOnSubstringOnly(): void
    {
        $employeeId     = $this->createEmployee();
        $giftcardNumber = $this->createGiftcard(100.00);
        $itemId         = $this->createTestItem(HAS_NO_STOCK);

        $saleModel  = model(Sale::class);
        $saleStatus = COMPLETED;
        $items      = $this->buildCartLine($itemId, 1, 60.00);
        $payments   = [
            0 => [
                'payment_type'    => 'Foo ' . lang('Sales.giftcard') . ':' . $giftcardNumber,
                'payment_amount'  => 60.00,
                'cash_refund'     => 0,
                'cash_adjustment' => 0,
                'reference_code'  => null,
            ],
        ];
        $salesTaxes = [[], []];

        $result = $saleModel->save_value(
            NEW_ENTRY,
            $saleStatus,
            $items,
            NEW_ENTRY,
            $employeeId,
            'test sale',
            null,
            null,
            null,
            SALE_TYPE_POS,
            $payments,
            null,
            $salesTaxes
        );

        $this->assertGreaterThan(0, $result);
        $this->assertEqualsWithDelta(100.00, $this->getGiftcardValue($giftcardNumber), 0.001);
    }

    public function testSaveValueReturnsInsufficientGiftcardBalanceSentinel(): void
    {
        $employeeId     = $this->createEmployee();
        $giftcardNumber = $this->createGiftcard(50.00);
        $itemId         = $this->createTestItem(HAS_NO_STOCK);

        $salesCountBefore = $this->countSalesRows();

        $saleModel  = model(Sale::class);
        $saleStatus = COMPLETED;
        $items      = $this->buildCartLine($itemId, 1, 60.00);
        $payments   = [
            0 => [
                'payment_type'    => lang('Sales.giftcard') . ':' . $giftcardNumber,
                'payment_amount'  => 60.00,
                'cash_refund'     => 0,
                'cash_adjustment' => 0,
                'reference_code'  => null,
            ],
        ];
        $salesTaxes = [[], []];

        $result = $saleModel->save_value(
            NEW_ENTRY,
            $saleStatus,
            $items,
            NEW_ENTRY,
            $employeeId,
            'test sale',
            null,
            null,
            null,
            SALE_TYPE_POS,
            $payments,
            null,
            $salesTaxes
        );

        $this->assertSame(INSUFFICIENT_GIFTCARD_BALANCE, $result);
        $this->assertEqualsWithDelta(50.00, $this->getGiftcardValue($giftcardNumber), 0.001);
        $this->assertSame($salesCountBefore, $this->countSalesRows());
    }

    public function testSaveValueReturnsInsufficientRewardPointsSentinel(): void
    {
        $employeeId = $this->createEmployee();
        $customerId = $this->createCustomerWithPoints(20);
        $itemId     = $this->createTestItem(HAS_NO_STOCK);

        $salesCountBefore = $this->countSalesRows();

        $saleModel  = model(Sale::class);
        $saleStatus = COMPLETED;
        $items      = $this->buildCartLine($itemId, 1, 60.00);
        $payments   = [
            0 => [
                'payment_type'    => lang('Sales.rewards'),
                'payment_amount'  => 40.00,
                'cash_refund'     => 0,
                'cash_adjustment' => 0,
                'reference_code'  => null,
            ],
        ];
        $salesTaxes = [[], []];

        $result = $saleModel->save_value(
            NEW_ENTRY,
            $saleStatus,
            $items,
            $customerId,
            $employeeId,
            'test sale',
            null,
            null,
            null,
            SALE_TYPE_POS,
            $payments,
            null,
            $salesTaxes
        );

        $this->assertSame(INSUFFICIENT_REWARD_POINTS, $result);
        $this->assertSame(20, $this->getCustomerPoints($customerId));
        $this->assertSame($salesCountBefore, $this->countSalesRows());
    }

    public function testSaveValueDecrementsStockOnCompletedSale(): void
    {
        $employeeId = $this->createEmployee();
        $itemId     = $this->createTestItem(HAS_STOCK);

        \Config\Database::connect()->table('item_quantities')->insert([
            'item_id'     => $itemId,
            'location_id' => self::LOCATION_ID,
            'quantity'    => 10,
        ]);

        $saleModel  = model(Sale::class);
        $saleStatus = COMPLETED;
        $items      = $this->buildCartLine($itemId, 3, 5.00);
        $payments   = [
            0 => [
                'payment_type'    => lang('Sales.cash'),
                'payment_amount'  => 15.00,
                'cash_refund'     => 0,
                'cash_adjustment' => 0,
                'reference_code'  => null,
            ],
        ];
        $salesTaxes = [[], []];

        $result = $saleModel->save_value(
            NEW_ENTRY,
            $saleStatus,
            $items,
            NEW_ENTRY,
            $employeeId,
            'test sale',
            null,
            null,
            null,
            SALE_TYPE_POS,
            $payments,
            null,
            $salesTaxes
        );

        $this->assertGreaterThan(0, $result);
        $this->assertEqualsWithDelta(7.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
    }

    public function testSaveValueDecrementsStockOnCompletedSaleWhenNoQuantityRowExists(): void
    {
        $employeeId = $this->createEmployee();
        $itemId     = $this->createTestItem(HAS_STOCK);

        // Deliberately no item_quantities row inserted for this item/location.

        $saleModel  = model(Sale::class);
        $saleStatus = COMPLETED;
        $items      = $this->buildCartLine($itemId, 3, 5.00);
        $payments   = [
            0 => [
                'payment_type'    => lang('Sales.cash'),
                'payment_amount'  => 15.00,
                'cash_refund'     => 0,
                'cash_adjustment' => 0,
                'reference_code'  => null,
            ],
        ];
        $salesTaxes = [[], []];

        $result = $saleModel->save_value(
            NEW_ENTRY,
            $saleStatus,
            $items,
            NEW_ENTRY,
            $employeeId,
            'test sale',
            null,
            null,
            null,
            SALE_TYPE_POS,
            $payments,
            null,
            $salesTaxes
        );

        $this->assertGreaterThan(0, $result);
        $this->assertEqualsWithDelta(-3.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
    }

    public function testDeleteRestoresStockOnCanceledSale(): void
    {
        $employeeId = $this->createEmployee();
        $itemId     = $this->createTestItem(HAS_STOCK);

        \Config\Database::connect()->table('item_quantities')->insert([
            'item_id'     => $itemId,
            'location_id' => self::LOCATION_ID,
            'quantity'    => 10,
        ]);

        $saleModel  = model(Sale::class);
        $saleStatus = COMPLETED;
        $items      = $this->buildCartLine($itemId, 3, 5.00);
        $payments   = [
            0 => [
                'payment_type'    => lang('Sales.cash'),
                'payment_amount'  => 15.00,
                'cash_refund'     => 0,
                'cash_adjustment' => 0,
                'reference_code'  => null,
            ],
        ];
        $salesTaxes = [[], []];

        $saleId = $saleModel->save_value(
            NEW_ENTRY,
            $saleStatus,
            $items,
            NEW_ENTRY,
            $employeeId,
            'test sale',
            null,
            null,
            null,
            SALE_TYPE_POS,
            $payments,
            null,
            $salesTaxes
        );

        $this->assertGreaterThan(0, $saleId);
        $this->assertEqualsWithDelta(7.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);

        $deleteResult = $saleModel->delete($saleId, false, true, $employeeId);

        $this->assertTrue($deleteResult);
        $this->assertEqualsWithDelta(10.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
    }

    public function testGetAllSuspended_ReturnsEmptyArrayWhenQueryFails(): void
    {
        $model = $this->modelWithFailingQuery(Sale::class);

        // Exercises both the NEW_ENTRY branch and the customer-id branch.
        $this->assertSame([], $model->get_all_suspended());
        $this->assertSame([], $model->get_all_suspended(NEW_ENTRY));
        $this->assertSame([], $model->get_all_suspended(123));
    }

    public function testSaveValue_StoresTheBranchOfTheStockLocationOnTheSale(): void
    {
        $db         = \Config\Database::connect();
        $employeeId = $this->createEmployee();
        $itemId     = $this->createTestItem(HAS_NO_STOCK);
        $db->table('branches')->insert(['company_id' => 1, 'code' => 'S' . random_int(100, 999), 'name' => 'Sale branch', 'active' => 1]);
        $branchId = (int) $db->insertID();
        $db->table('stock_locations')->where('location_id', self::LOCATION_ID)->update(['branch_id' => $branchId]);

        $saleModel  = model(Sale::class);
        $saleStatus = COMPLETED;
        $items      = $this->buildCartLine($itemId, 1, 5.00);
        $payments   = [0 => ['payment_type' => lang('Sales.cash'), 'payment_amount' => 5.00, 'cash_refund' => 0, 'cash_adjustment' => 0, 'reference_code' => null]];
        $salesTaxes = [[], []];

        try {
            $saleId = $saleModel->save_value(NEW_ENTRY, $saleStatus, $items, NEW_ENTRY, $employeeId, 'branch sale', null, null, null, SALE_TYPE_POS, $payments, null, $salesTaxes);

            $this->assertGreaterThan(0, $saleId);
            $this->assertSame($branchId, $saleModel->getBranchId($saleId));
        } finally {
            $db->table('sales')->where('branch_id', $branchId)->update(['branch_id' => 1]);
            $db->table('stock_locations')->where('location_id', self::LOCATION_ID)->update(['branch_id' => 1]);
            $db->table('branches')->where('branch_id', $branchId)->delete();
        }
    }

    public function testSaveValue_StartsASaleOnTheDefaultBranchWhenTheLocationIsOnIt(): void
    {
        $employeeId = $this->createEmployee();
        $itemId     = $this->createTestItem(HAS_NO_STOCK);

        $saleModel  = model(Sale::class);
        $saleStatus = COMPLETED;
        $items      = $this->buildCartLine($itemId, 1, 5.00);
        $payments   = [0 => ['payment_type' => lang('Sales.cash'), 'payment_amount' => 5.00, 'cash_refund' => 0, 'cash_adjustment' => 0, 'reference_code' => null]];
        $salesTaxes = [[], []];

        $saleId = $saleModel->save_value(NEW_ENTRY, $saleStatus, $items, NEW_ENTRY, $employeeId, 'default branch sale', null, null, null, SALE_TYPE_POS, $payments, null, $salesTaxes);

        $this->assertSame(1, $saleModel->getBranchId($saleId));
    }

    public function testGetBranchId_ReturnsNullWhenThereIsNoSuchSale(): void
    {
        $this->assertNull(model(Sale::class)->getBranchId(999999999));
    }

    public function testIsDelivered_IsFalseOnlyForAnInvoiceWhoseGoodsHaveNotLeft(): void
    {
        $saleModel = model(Sale::class);
        $kept      = $this->saveInvoice(false);
        $sent      = $this->saveInvoice(true);

        $this->assertFalse($saleModel->isDelivered($kept));
        $this->assertTrue($saleModel->isDelivered($sent));
        $this->assertTrue($saleModel->isDelivered(999999999));
    }

    public function testGetReturnOrigin_NamesTheSaleAReturnComesFromAndNothingForOtherSales(): void
    {
        $saleModel = model(Sale::class);
        $invoice   = $this->saveInvoice(true);
        $itemId    = $this->createTestItem(HAS_STOCK);
        $status    = COMPLETED;
        $items     = $this->buildCartLine($itemId, -1, 5.00);
        $taxes     = [[], []];
        $return    = $saleModel->save_value(NEW_ENTRY, $status, $items, NEW_ENTRY, $this->createEmployee(), 'return', null, null, null, SALE_TYPE_RETURN, [], null, $taxes, true, $invoice);

        $this->assertGreaterThan(0, $return);
        $this->assertSame($invoice, $saleModel->getReturnOrigin($return));
        $this->assertNull($saleModel->getReturnOrigin($invoice));
        $this->assertNull($saleModel->getReturnOrigin(999999999));
    }

    public function testGetReturnedQuantities_AddsUpWhatCompletedReturnsTookBackItemByItem(): void
    {
        $saleModel = model(Sale::class);
        $itemId    = $this->createTestItem(HAS_STOCK);
        $other     = $this->createTestItem(HAS_STOCK);
        $employee  = $this->createEmployee();
        $status    = COMPLETED;
        $taxes     = [[], []];
        $items     = $this->buildCartLine($itemId, 5, 5.00);
        $invoice   = $saleModel->save_value(NEW_ENTRY, $status, $items, NEW_ENTRY, $employee, 'invoice', null, null, null, SALE_TYPE_INVOICE, [], null, $taxes);

        foreach ([[$itemId, -2], [$itemId, -1], [$other, -3]] as [$returned, $quantity]) {
            $items  = $this->buildCartLine($returned, $quantity, 5.00);
            $status = COMPLETED;
            $saleModel->save_value(NEW_ENTRY, $status, $items, NEW_ENTRY, $employee, 'return', null, null, null, SALE_TYPE_RETURN, [], null, $taxes, true, $invoice);
        }

        $this->assertEqualsWithDelta(3.0, $saleModel->getReturnedQuantities($invoice)[$itemId], 0.0001);
        $this->assertEqualsWithDelta(3.0, $saleModel->getReturnedQuantities($invoice)[$other], 0.0001);
        $this->assertSame([], $saleModel->getReturnedQuantities(999999999));
    }

    public function testGetSummary_GivesTheFactsOfASaleAndNothingForAMissingOne(): void
    {
        $saleModel = model(Sale::class);
        $saleId    = $this->saveInvoice(false);
        $summary   = $saleModel->getSummary($saleId);

        $this->assertSame((string) SALE_TYPE_INVOICE, (string) $summary->sale_type);
        $this->assertSame((string) COMPLETED, (string) $summary->sale_status);
        $this->assertSame('0', (string) $summary->delivered);
        $this->assertNull($saleModel->getSummary(999999999));
    }

    public function testConfirmDelivery_TakesTheStockOfAnUndeliveredInvoiceOnceWhileAccountingIsOff(): void
    {
        $saleModel = model(Sale::class);
        $itemId    = $this->createTestItem(HAS_STOCK);

        Database::connect()->table('item_quantities')->insert(['item_id' => $itemId, 'location_id' => self::LOCATION_ID, 'quantity' => 10]);

        $saleId = $this->saveInvoice(false, $itemId, 4);

        $this->assertEqualsWithDelta(10.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
        $this->assertSame($saleId, $saleModel->confirm_delivery($saleId, $this->createEmployee()));
        $this->assertEqualsWithDelta(6.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
        $this->assertTrue($saleModel->isDelivered($saleId));
        $this->assertSame($saleId, $saleModel->confirm_delivery($saleId, $this->createEmployee()));
        $this->assertEqualsWithDelta(6.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
    }

    public function testConfirmDelivery_RefusesASaleThatIsNotAnIssuedInvoice(): void
    {
        $saleModel  = model(Sale::class);
        $itemId     = $this->createTestItem(HAS_STOCK);
        $saleStatus = COMPLETED;
        $items      = $this->buildCartLine($itemId, 1, 5.00);
        $taxes      = [[], []];
        $payments   = [['payment_type' => lang('Sales.cash'), 'payment_amount' => 5.00, 'cash_refund' => 0, 'cash_adjustment' => 0, 'reference_code' => null]];
        $pos        = $saleModel->save_value(NEW_ENTRY, $saleStatus, $items, NEW_ENTRY, $this->createEmployee(), 'pos', null, null, null, SALE_TYPE_POS, $payments, null, $taxes);

        $this->assertSame(NEW_ENTRY, $saleModel->confirm_delivery($pos, null));
        $this->assertSame(NEW_ENTRY, $saleModel->confirm_delivery(999999999, null));
    }

    public function testBranchIdsOfItems_ListsTheBranchOfEveryLocationOnceAndNothingForAnEmptyCart(): void
    {
        $saleModel = model(Sale::class);
        $lines     = [['item_location' => self::LOCATION_ID], ['item_location' => self::LOCATION_ID]];

        $this->assertSame([1], $saleModel->branchIdsOfItems($lines));
        $this->assertSame([], $saleModel->branchIdsOfItems([]));
    }

    /**
     * @param array<string, mixed> $line
     *
     * @return array<int, array<string, mixed>>
     */
    private function discountedLines(int $itemId, string $quantity, string $price, string $discount = '0', int $discountType = PERCENT): array
    {
        $line = $this->buildCartLine($itemId, 1, 1.0)[0];

        return [0 => array_merge($line, ['quantity' => $quantity, 'price' => $price, 'discount' => $discount, 'discount_type' => $discountType])];
    }

    /**
     * @param array{type: int, value: string}|null $invoiceDiscount
     */
    private function saveSaleWithDiscount(int $itemId, string $quantity, string $price, ?array $invoiceDiscount, array $taxes = [], int $type = SALE_TYPE_POS, string $date = '2001-02-03'): int
    {
        $status     = COMPLETED;
        $items      = $this->discountedLines($itemId, $quantity, $price);
        $salesTaxes = [[], $taxes];
        $saleId     = model(Sale::class)->save_value(NEW_ENTRY, $status, $items, NEW_ENTRY, $this->createEmployee(), 'discount', null, null, null, $type, [], null, $salesTaxes, true, null, $invoiceDiscount);

        if ($saleId > 0) {
            \Config\Database::connect()->table('sales')->where('sale_id', $saleId)->update(['sale_time' => $date . ' 10:00:00']);
        }

        return $saleId;
    }

    /**
     * @return array<string, mixed>
     */
    private function theSaleRow(int $saleId): array
    {
        return \Config\Database::connect()->table('sales')->where('sale_id', $saleId)->get()->getRowArray();
    }

    public function testSaveValue_SavesTheDiscountOnTheWholeOfTheSaleAndTheShareOfItsLineWhileAccountingIsOff(): void
    {
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $saleId = $this->saveSaleWithDiscount($itemId, '2', '50', ['type' => PERCENT, 'value' => '10']);

        $this->assertGreaterThan(0, $saleId);
        $this->assertSame((string) PERCENT, (string) $this->theSaleRow($saleId)['invoice_discount_type']);
        $this->assertSame('10.0000', $this->theSaleRow($saleId)['invoice_discount']);
        $this->assertSame('10.0000', \Config\Database::connect()->table('sales_items')->where('sale_id', $saleId)->get()->getRow()->invoice_discount_share);
        $this->assertSame(['type' => PERCENT, 'value' => '10.0000'], model(Sale::class)->getInvoiceDiscount($saleId));
    }

    public function testSaveValue_SavesNothingOfTheDiscountOnASaleThatHasNone(): void
    {
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $saleId = $this->saveSaleWithDiscount($itemId, '2', '50', null);

        $this->assertNull($this->theSaleRow($saleId)['invoice_discount_type']);
        $this->assertNull($this->theSaleRow($saleId)['invoice_discount']);
        $this->assertNull(\Config\Database::connect()->table('sales_items')->where('sale_id', $saleId)->get()->getRow()->invoice_discount_share);
        $this->assertNull(model(Sale::class)->getInvoiceDiscount($saleId));
        $this->assertNull(model(Sale::class)->getInvoiceDiscount(999999999));
    }

    public function testSaveValue_RefusesADiscountThatCannotBeTakenOffAndLeavesNothingOfTheSale(): void
    {
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $sales  = $this->countSalesRows();

        $this->assertSame(INVOICE_DISCOUNT_INVALID, $this->saveSaleWithDiscount($itemId, '2', '50', ['type' => FIXED, 'value' => '100.01']));
        $this->assertSame(InvoiceDiscountProblem::ExceedsTotal, model(Sale::class)->invoiceDiscountProblem());
        $this->assertSame($sales, $this->countSalesRows());

        $this->assertGreaterThan(0, $this->saveSaleWithDiscount($itemId, '2', '50', ['type' => FIXED, 'value' => '100']));
        $this->assertNull(model(Sale::class)->invoiceDiscountProblem());
    }

    public function testGetInfo_GivesTheAmountDueAfterTheDiscountOnTheWholeOfTheSale(): void
    {
        $this->dropReportTables();

        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $saleId = $this->saveSaleWithDiscount($itemId, '2', '50', ['type' => PERCENT, 'value' => '10']);

        $this->assertSame('90.00', bcadd((string) model(Sale::class)->get_info($saleId)->getRow()->amount_due, '0', 2));
    }

    public function testGetInfo_GivesTheAmountDueOfASaleWithoutADiscountAsItAlwaysDid(): void
    {
        $this->dropReportTables();

        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $saleId = $this->saveSaleWithDiscount($itemId, '2', '50', null);

        $this->assertSame('100.00', bcadd((string) model(Sale::class)->get_info($saleId)->getRow()->amount_due, '0', 2));
    }

    public function testCreateTempTable_GivesTheLinesOfASaleTheirSubtotalTaxAndTotalAfterTheDiscountOnTheWholeOfTheSale(): void
    {
        $this->dropReportTables();

        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $saleId = $this->saveSaleWithDiscount($itemId, '2', '50', ['type' => PERCENT, 'value' => '10'], [['item_id' => $itemId, 'line' => 0, 'name' => 'VAT', 'percent' => '15.0000', 'tax_type' => 1, 'rounding_code' => 0, 'cascade_sequence' => 0, 'item_tax_amount' => '13.50', 'sales_tax_code_id' => null, 'tax_category_id' => null, 'jurisdiction_id' => null]]);

        model(Sale::class)->create_temp_table(['sale_id' => $saleId]);

        $row = \Config\Database::connect()->table('sales_items_temp')->where('sale_id', $saleId)->get()->getRow();

        $this->assertSame('90.00', bcadd((string) $row->subtotal, '0', 2));
        $this->assertSame('13.50', bcadd((string) $row->tax, '0', 2));
        $this->assertSame('103.50', bcadd((string) $row->total, '0', 2));

    }

    public function testSearch_ListsTheTotalOfASaleAfterTheDiscountOnTheWholeOfTheSale(): void
    {
        $this->dropReportTables();

        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $saleId = $this->saveSaleWithDiscount($itemId, '2', '50', ['type' => FIXED, 'value' => '12.5'], [], SALE_TYPE_POS, '2001-02-03');
        $plain  = $this->saveSaleWithDiscount($itemId, '1', '30', null, [], SALE_TYPE_POS, '2001-02-03');

        $filters = ['start_date' => '2001-02-03', 'end_date' => '2001-02-03', 'location_id' => 'all', 'selected_customer' => false, 'only_invoices' => false, 'only_cash' => false, 'only_creditcard' => false, 'only_debit' => false, 'only_due' => false, 'only_check' => false, 'only_bank_transfer' => false, 'only_wallet' => false, 'is_valid_receipt' => true];

        $found = model(Sale::class)->search('POS ' . $saleId, $filters)->getRow();
        $other = model(Sale::class)->search('POS ' . $plain, $filters)->getRow();

        $this->assertSame('87.50', bcadd((string) $found->total, '0', 2));
        $this->assertSame('87.50', bcadd((string) $found->subtotal, '0', 2));
        $this->assertSame('30.00', bcadd((string) $other->total, '0', 2));

    }

    public function testInvoiceDiscountOrigin_GivesWhatASaleGaveAndWhatItsCompletedReturnsTookBackItemByItem(): void
    {
        $saleModel = model(Sale::class);
        $itemId    = $this->createTestItem(HAS_NO_STOCK);
        $invoice   = $this->saveSaleWithDiscount($itemId, '4', '50', ['type' => FIXED, 'value' => '20'], [], SALE_TYPE_INVOICE);

        $this->assertSame([$itemId => ['sold' => '4.000', 'share' => '20.0000', 'returned' => '0.000', 'returnedShare' => '0.0000']], $saleModel->invoiceDiscountOrigin($invoice));

        $status = COMPLETED;
        $items  = $this->discountedLines($itemId, '-1', '50');
        $taxes  = [[], []];
        $return = $saleModel->save_value(NEW_ENTRY, $status, $items, NEW_ENTRY, $this->createEmployee(), 'return', null, null, null, SALE_TYPE_RETURN, [], null, $taxes, true, $invoice);

        $this->assertGreaterThan(0, $return);
        $this->assertSame('-5.0000', \Config\Database::connect()->table('sales_items')->where('sale_id', $return)->get()->getRow()->invoice_discount_share);
        $this->assertSame([$itemId => ['sold' => '4.000', 'share' => '20.0000', 'returned' => '1.000', 'returnedShare' => '5.0000']], $saleModel->invoiceDiscountOrigin($invoice));
        $this->assertSame([$itemId => ['sold' => '4.000', 'share' => '20.0000', 'returned' => '0.000', 'returnedShare' => '0.0000']], $saleModel->invoiceDiscountOrigin($invoice, $return), 'a return is not counted against itself');
        $this->assertSame([], $saleModel->invoiceDiscountOrigin(999999999));
    }

    public function testInvoiceDiscountOrigin_DoesNotCountAReturnThatIsNotCompleted(): void
    {
        $saleModel = model(Sale::class);
        $itemId    = $this->createTestItem(HAS_NO_STOCK);
        $invoice   = $this->saveSaleWithDiscount($itemId, '4', '50', ['type' => FIXED, 'value' => '20'], [], SALE_TYPE_INVOICE);
        $status    = COMPLETED;
        $items     = $this->discountedLines($itemId, '-1', '50');
        $taxes     = [[], []];
        $return    = $saleModel->save_value(NEW_ENTRY, $status, $items, NEW_ENTRY, $this->createEmployee(), 'return', null, null, null, SALE_TYPE_RETURN, [], null, $taxes, true, $invoice);

        $saleModel->update_sale_status($return, CANCELED);

        $this->assertSame('0.000', $saleModel->invoiceDiscountOrigin($invoice)[$itemId]['returned']);
    }

    public function testSummarySales_AddsUpTheSalesOfADayAfterTheirDiscountsOnTheWholeOfTheSale(): void
    {
        $this->dropReportTables();

        $itemId = $this->createTestItem(HAS_NO_STOCK);

        $this->saveSaleWithDiscount($itemId, '2', '50', ['type' => PERCENT, 'value' => '10'], [], SALE_TYPE_POS, '2002-03-04');
        $this->saveSaleWithDiscount($itemId, '1', '30', null, [], SALE_TYPE_POS, '2002-03-04');

        $summary = (new Summary_sales())->getSummaryData(['start_date' => '2002-03-04', 'end_date' => '2002-03-04', 'location_id' => 'all', 'sale_type' => 'complete']);

        $this->assertSame('120.00', bcadd((string) $summary['subtotal'], '0', 2));
        $this->assertSame('120.00', bcadd((string) $summary['total'], '0', 2));

    }
}
