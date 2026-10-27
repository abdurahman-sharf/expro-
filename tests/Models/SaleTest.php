<?php

namespace Tests\Models;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
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
    }

    protected function tearDown(): void
    {
        $db = Database::connect();
        $db->table('document_numbers')->truncate();
        $db->table('document_sequences')->truncate();
        $db->table('accounting_audit_log')->where('entity_type', 'document_numbering')->delete();

        parent::tearDown();
    }

    /**
     * A prefix no other run of the tests used, because the invoices these tests save stay in the
     * database and an invoice number is never given twice.
     */
    private function freshPrefix(): string
    {
        return 'T' . random_int(100000, 999999) . '-';
    }

    private function switchOn(DocumentType $type, string $prefix = '', int $padding = 3, ?int $next = null): void
    {
        (new DocumentNumbering())->configure($type, array_filter(['enabled' => true, 'prefix' => $prefix, 'padding' => $padding, 'next_number' => $next], static fn (mixed $value): bool => $value !== null));
    }

    private function numberOf(DocumentType $type, string $table, int $id): ?string
    {
        return (new DocumentNumbering())->numbers($type, $table, [$id])[$id] ?? null;
    }

    /**
     * @return list<int>
     */
    private function paymentIdsOf(int $saleId): array
    {
        return array_map('intval', array_column(Database::connect()->table('sales_payments')->select('payment_id')->where('sale_id', $saleId)->orderBy('payment_id')->get()->getResultArray(), 'payment_id'));
    }

    /**
     * @param list<array<string, mixed>> $payments
     */
    private function saveSale(int $type, array $payments, int $status = COMPLETED, int $saleId = NEW_ENTRY, ?string $invoiceNumber = null, ?string $quoteNumber = null, ?int $origin = null, float $quantity = 1): int
    {
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $items  = $this->buildCartLine($itemId, $quantity, 10.00);
        $taxes  = [[], []];

        return model(Sale::class)->save_value($saleId, $status, $items, NEW_ENTRY, $this->createEmployee(), 'numbering', $invoiceNumber, null, $quoteNumber, $type, $payments, null, $taxes, true, $origin);
    }

    /**
     * @return array<string, mixed>
     */
    private function cashPayment(float $amount = 10.00, int $adjustment = 0): array
    {
        return ['payment_type' => lang('Sales.cash'), 'payment_amount' => $amount, 'cash_refund' => 0, 'cash_adjustment' => $adjustment, 'reference_code' => null];
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

    public function testSaveValue_GivesNoNumberWhileTheSequencesAreOff(): void
    {
        $pos     = $this->saveSale(SALE_TYPE_POS, [$this->cashPayment()]);
        $invoice = $this->saveSale(SALE_TYPE_INVOICE, [$this->cashPayment()]);
        $quote   = $this->saveSale(SALE_TYPE_QUOTE, [], SUSPENDED);
        $return  = $this->saveSale(SALE_TYPE_RETURN, [], COMPLETED, NEW_ENTRY, null, null, $invoice, -1);

        $this->assertGreaterThan(0, min($pos, $invoice, $quote, $return));
        $this->assertSame(0, Database::connect()->table('document_numbers')->countAllResults());
        $this->assertNull(model(Sale::class)->getInvoiceNumber($invoice));
        $this->assertNull(model(Sale::class)->get_quote_number($quote));
    }

    public function testSaveValue_KeepsTheNumbersTypedByHandWhileTheSequencesAreOff(): void
    {
        $prefix  = $this->freshPrefix();
        $invoice = $this->saveSale(SALE_TYPE_INVOICE, [], COMPLETED, NEW_ENTRY, $prefix . 'A');
        $quote   = $this->saveSale(SALE_TYPE_QUOTE, [], SUSPENDED, NEW_ENTRY, null, $prefix . 'Q');

        $this->assertSame($prefix . 'A', model(Sale::class)->getInvoiceNumber($invoice));
        $this->assertSame($prefix . 'Q', model(Sale::class)->get_quote_number($quote));
    }

    public function testSaveValue_NumbersEachPaymentOfACompletedSaleExceptWhatOnlyRecordsWhatStaysUnpaid(): void
    {
        $this->switchOn(DocumentType::CustomerPayment, 'PAY-', 4, 1);

        $saleId = $this->saveSale(SALE_TYPE_POS, [
            $this->cashPayment(4.00),
            ['payment_type' => lang('Sales.due'), 'payment_amount' => 3.00, 'cash_refund' => 0, 'cash_adjustment' => 0, 'reference_code' => null],
            ['payment_type' => lang('Sales.debit'), 'payment_amount' => 2.00, 'cash_refund' => 0, 'cash_adjustment' => 0, 'reference_code' => null],
            $this->cashPayment(1.00, 1),
        ]);

        [$cash, $due, $debit, $adjustment] = $this->paymentIdsOf($saleId);

        $this->assertSame('PAY-0001', $this->numberOf(DocumentType::CustomerPayment, 'sales_payments', $cash));
        $this->assertNull($this->numberOf(DocumentType::CustomerPayment, 'sales_payments', $due));
        $this->assertSame('PAY-0002', $this->numberOf(DocumentType::CustomerPayment, 'sales_payments', $debit));
        $this->assertNull($this->numberOf(DocumentType::CustomerPayment, 'sales_payments', $adjustment));
        $this->assertSame(3, (new DocumentNumbering())->sequence(DocumentType::CustomerPayment)->nextNumber);
    }

    public function testSaveValue_GivesTheNumbersOfAConsecutiveSalesInOrder(): void
    {
        $this->switchOn(DocumentType::CustomerPayment, 'PAY-', 4, 1);

        $first  = $this->paymentIdsOf($this->saveSale(SALE_TYPE_POS, [$this->cashPayment()]))[0];
        $second = $this->paymentIdsOf($this->saveSale(SALE_TYPE_POS, [$this->cashPayment()]))[0];

        $this->assertSame('PAY-0001', $this->numberOf(DocumentType::CustomerPayment, 'sales_payments', $first));
        $this->assertSame('PAY-0002', $this->numberOf(DocumentType::CustomerPayment, 'sales_payments', $second));
    }

    public function testSaveValue_NumbersThePaymentsOfASuspendedSaleOnlyWhenItIsCompleted(): void
    {
        $this->switchOn(DocumentType::CustomerPayment, 'PAY-', 4, 1);

        $saleId = $this->saveSale(SALE_TYPE_POS, [$this->cashPayment()], SUSPENDED);

        $this->assertSame(0, Database::connect()->table('document_numbers')->countAllResults());

        $this->saveSale(SALE_TYPE_POS, [$this->cashPayment()], SUSPENDED, $saleId);

        $this->assertSame(0, Database::connect()->table('document_numbers')->countAllResults());
        $this->assertSame(1, (new DocumentNumbering())->sequence(DocumentType::CustomerPayment)->nextNumber);

        $this->saveSale(SALE_TYPE_POS, [$this->cashPayment()], COMPLETED, $saleId);

        $this->assertSame(1, Database::connect()->table('document_numbers')->countAllResults());
        $this->assertSame('PAY-0001', $this->numberOf(DocumentType::CustomerPayment, 'sales_payments', $this->paymentIdsOf($saleId)[0]));
    }

    public function testSaveValue_NumbersAReturnAsACreditNote(): void
    {
        $this->switchOn(DocumentType::CreditNote, 'CN-', 5, 1);

        $invoice = $this->saveSale(SALE_TYPE_INVOICE, []);
        $return  = $this->saveSale(SALE_TYPE_RETURN, [], COMPLETED, NEW_ENTRY, null, null, $invoice, -1);

        $this->assertSame('CN-00001', $this->numberOf(DocumentType::CreditNote, 'sales', $return));
        $this->assertNull($this->numberOf(DocumentType::CreditNote, 'sales', $invoice));
    }

    public function testSaveValue_GivesAnInvoiceTheNextNumberOfItsSequenceWhenNoneIsTyped(): void
    {
        $prefix = $this->freshPrefix();
        $this->switchOn(DocumentType::SalesInvoice, $prefix, 6, 1);

        $first  = $this->saveSale(SALE_TYPE_INVOICE, []);
        $second = $this->saveSale(SALE_TYPE_INVOICE, []);

        $this->assertSame($prefix . '000001', model(Sale::class)->getInvoiceNumber($first));
        $this->assertSame($prefix . '000002', model(Sale::class)->getInvoiceNumber($second));
        $this->assertSame($prefix . '000002', $this->numberOf(DocumentType::SalesInvoice, 'sales', $second));
    }

    public function testSaveValue_KeepsAnInvoiceNumberTypedByHandAndGivesNoSequenceNumber(): void
    {
        $prefix = $this->freshPrefix();
        $this->switchOn(DocumentType::SalesInvoice, $prefix, 6, 1);

        $invoice = $this->saveSale(SALE_TYPE_INVOICE, [], COMPLETED, NEW_ENTRY, $prefix . 'HAND');

        $this->assertSame($prefix . 'HAND', model(Sale::class)->getInvoiceNumber($invoice));
        $this->assertNull($this->numberOf(DocumentType::SalesInvoice, 'sales', $invoice));
        $this->assertSame(1, (new DocumentNumbering())->sequence(DocumentType::SalesInvoice)->nextNumber);
    }

    public function testSaveValue_SkipsTheNumberAnInvoiceTypedByHandAlreadyUsed(): void
    {
        $prefix = $this->freshPrefix();
        $this->switchOn(DocumentType::SalesInvoice, $prefix, 3, 1);
        $this->saveSale(SALE_TYPE_INVOICE, [], COMPLETED, NEW_ENTRY, $prefix . '001');

        $invoice = $this->saveSale(SALE_TYPE_INVOICE, []);

        $this->assertSame($prefix . '002', model(Sale::class)->getInvoiceNumber($invoice));
    }

    public function testSaveValue_NumbersAQuoteWhenItIsSavedAndKeepsItWhenItIsSavedAgain(): void
    {
        $prefix = $this->freshPrefix();
        $this->switchOn(DocumentType::SalesQuote, $prefix, 4, 1);

        $quote = $this->saveSale(SALE_TYPE_QUOTE, [], SUSPENDED);

        $this->assertSame($prefix . '0001', model(Sale::class)->get_quote_number($quote));

        $this->saveSale(SALE_TYPE_QUOTE, [], SUSPENDED, $quote);

        $this->assertSame($prefix . '0001', model(Sale::class)->get_quote_number($quote));
        $this->assertSame(2, (new DocumentNumbering())->sequence(DocumentType::SalesQuote)->nextNumber);
    }

    public function testSaveValue_DoesNotNumberAnInvoiceThatIsNotCompletedYet(): void
    {
        $this->switchOn(DocumentType::SalesInvoice, $this->freshPrefix(), 3, 1);

        $draft = $this->saveSale(SALE_TYPE_INVOICE, [], SUSPENDED);

        $this->assertNull(model(Sale::class)->getInvoiceNumber($draft));
        $this->assertSame(1, (new DocumentNumbering())->sequence(DocumentType::SalesInvoice)->nextNumber);
    }

    public function testSaveValue_LeavesNoNumberBehindWhenTheSaleIsRefused(): void
    {
        $this->switchOn(DocumentType::CustomerPayment, 'PAY-', 4, 1);

        $giftcard = $this->createGiftcard(5.00);
        $result   = $this->saveSale(SALE_TYPE_POS, [
            $this->cashPayment(4.00),
            ['payment_type' => lang('Sales.giftcard') . ':' . $giftcard, 'payment_amount' => 60.00, 'cash_refund' => 0, 'cash_adjustment' => 0, 'reference_code' => null],
        ]);

        $this->assertSame(INSUFFICIENT_GIFTCARD_BALANCE, $result);
        $this->assertSame(0, Database::connect()->table('document_numbers')->countAllResults());
        $this->assertSame(1, (new DocumentNumbering())->sequence(DocumentType::CustomerPayment)->nextNumber);
    }

    public function testSaveValue_RefusesTheWholeSaleWhenNoNumberCanBeGiven(): void
    {
        $db = Database::connect();
        $this->switchOn(DocumentType::CustomerPayment, 'PAY-', 0, 1);
        $db->table('document_sequences')->where('doc_type', 'customer_payment')->update(['next_number' => 4294967294]);
        $db->table('document_numbers')->insert(['company_id' => 1, 'doc_type' => 'customer_payment', 'source_table' => 'sales_payments', 'source_id' => 999999999, 'sequence_number' => 4294967294, 'number' => 'PAY-4294967294']);

        $before = $db->table('sales')->countAllResults();
        $result = $this->saveSale(SALE_TYPE_POS, [$this->cashPayment()]);
        $failure = model(Sale::class)->accountingFailure();

        $this->assertSame(ACCOUNTING_FAILED, $result);
        $this->assertSame($before, $db->table('sales')->countAllResults());
        $this->assertInstanceOf(AccountingException::class, $failure);
        $this->assertSame(AccountingError::NumberingExhausted, $failure->error);
        $this->assertSame(1, $db->table('document_numbers')->countAllResults());
    }

    public function testUpdate_NumbersAPaymentAddedToACompletedSale(): void
    {
        $this->switchOn(DocumentType::CustomerPayment, 'PAY-', 4, 1);

        $saleId   = $this->saveSale(SALE_TYPE_POS, [$this->cashPayment(4.00)]);
        $employee = $this->createEmployee();
        $model    = model(Sale::class);

        $this->assertTrue($model->update($saleId, ['comment' => 'more', 'payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.debit'), 'payment_amount' => '6.00', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $employee, 'reference_code' => null],
        ]]));

        [$first, $second] = $this->paymentIdsOf($saleId);

        $this->assertSame('PAY-0001', $this->numberOf(DocumentType::CustomerPayment, 'sales_payments', $first));
        $this->assertSame('PAY-0002', $this->numberOf(DocumentType::CustomerPayment, 'sales_payments', $second));
    }

    public function testUpdate_GivesNoNumberToAPaymentAddedToASaleThatIsNotCompleted(): void
    {
        $this->switchOn(DocumentType::CustomerPayment, 'PAY-', 4, 1);

        $saleId   = $this->saveSale(SALE_TYPE_POS, [], SUSPENDED);
        $employee = $this->createEmployee();

        $this->assertTrue(model(Sale::class)->update($saleId, ['comment' => 'more', 'payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.debit'), 'payment_amount' => '6.00', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $employee, 'reference_code' => null],
        ]]));

        $this->assertCount(1, $this->paymentIdsOf($saleId));
        $this->assertSame(0, Database::connect()->table('document_numbers')->countAllResults());
        $this->assertSame(1, (new DocumentNumbering())->sequence(DocumentType::CustomerPayment)->nextNumber);
    }

    public function testUpdate_KeepsThePaymentNumberWhenThePaymentIsEdited(): void
    {
        $this->switchOn(DocumentType::CustomerPayment, 'PAY-', 4, 1);

        $saleId   = $this->saveSale(SALE_TYPE_POS, [$this->cashPayment(4.00)]);
        $employee = $this->createEmployee();
        $payment  = $this->paymentIdsOf($saleId)[0];

        $this->assertTrue(model(Sale::class)->update($saleId, ['comment' => 'edited', 'payments' => [
            ['payment_id' => $payment, 'payment_type' => lang('Sales.debit'), 'payment_amount' => '4.00', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $employee, 'reference_code' => null],
        ]]));

        $this->assertSame('PAY-0001', $this->numberOf(DocumentType::CustomerPayment, 'sales_payments', $payment));
        $this->assertSame(2, (new DocumentNumbering())->sequence(DocumentType::CustomerPayment)->nextNumber);
    }

    public function testUpdate_KeepsTheInvoiceNumberOfTheSequenceWhenTheFormLeavesItBlank(): void
    {
        $prefix = $this->freshPrefix();
        $this->switchOn(DocumentType::SalesInvoice, $prefix, 3, 1);
        $invoice = $this->saveSale(SALE_TYPE_INVOICE, []);

        $this->assertTrue(model(Sale::class)->update($invoice, ['comment' => 'edited', 'invoice_number' => null]));

        $this->assertSame($prefix . '001', model(Sale::class)->getInvoiceNumber($invoice));
    }

    public function testUpdate_ClearsATypedInvoiceNumberTheFormLeavesBlankAsBefore(): void
    {
        $prefix  = $this->freshPrefix();
        $invoice = $this->saveSale(SALE_TYPE_INVOICE, [], COMPLETED, NEW_ENTRY, $prefix . 'HAND');

        $this->assertTrue(model(Sale::class)->update($invoice, ['comment' => 'edited', 'invoice_number' => null]));

        $this->assertNull(model(Sale::class)->getInvoiceNumber($invoice));
    }

    public function testUpdate_ReplacesTheInvoiceNumberOfTheSequenceWithOneTypedByHand(): void
    {
        $prefix = $this->freshPrefix();
        $this->switchOn(DocumentType::SalesInvoice, $prefix, 3, 1);
        $invoice = $this->saveSale(SALE_TYPE_INVOICE, []);

        $this->assertTrue(model(Sale::class)->update($invoice, ['comment' => 'edited', 'invoice_number' => $prefix . 'HAND']));

        $this->assertSame($prefix . 'HAND', model(Sale::class)->getInvoiceNumber($invoice));
    }

    public function testGetInvoiceNumber_ReturnsNothingForAMissingSale(): void
    {
        $this->assertNull(model(Sale::class)->getInvoiceNumber(999999999));
    }
}
