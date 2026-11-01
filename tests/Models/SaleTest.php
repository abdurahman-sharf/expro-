<?php

namespace Tests\Models;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\ReturnDocument;
use App\Libraries\InvoiceDiscountProblem;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Pricing\SalesControlSettings;
use App\Models\Price_list;
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
    private function saveSale(int $type, array $payments, int $status = COMPLETED, int $saleId = NEW_ENTRY, ?string $invoiceNumber = null, ?string $quoteNumber = null, ?int $origin = null, float $quantity = 1, ReturnDocument $returnDocument = ReturnDocument::CreditNote): int
    {
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $items  = $this->buildCartLine($itemId, $quantity, 10.00);
        $taxes  = [[], []];

        return model(Sale::class)->save_value($saleId, $status, $items, NEW_ENTRY, $this->createEmployee(), 'numbering', $invoiceNumber, null, $quoteNumber, $type, $payments, null, $taxes, true, $origin, null, $returnDocument);
    }

    /**
     * @return array<string, mixed>
     */
    private function cashPayment(float $amount = 10.00, int $adjustment = 0): array
    {
        return ['payment_type' => lang('Sales.cash'), 'payment_amount' => $amount, 'cash_refund' => 0, 'cash_adjustment' => $adjustment, 'reference_code' => null];
    }

    /**
     * The sales the report tests make are dated in a month no other test uses, and are removed so
     * that the next run finds the days empty.
     */
    private function removeTheSalesOfTheReportDays(): void
    {
        $db  = Database::connect();
        $ids = array_column($db->table('sales')->select('sale_id')->like('sale_time', '2001-02-', 'after')->get()->getResultArray(), 'sale_id');

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
        $db = Database::connect();

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

    private function saveCreditNote(int $invoiceId, int $itemId, float $units, int $employeeId): int
    {
        $status = COMPLETED;
        $items  = $this->buildCartLine($itemId, -$units, 5.00);
        $taxes  = [[], []];

        return model(Sale::class)->save_value(NEW_ENTRY, $status, $items, NEW_ENTRY, $employeeId, 'return', null, null, null, SALE_TYPE_RETURN, [], null, $taxes, true, $invoiceId);
    }

    private function stockedItemWith(float $units): int
    {
        $itemId = $this->createTestItem(HAS_STOCK);

        Database::connect()->table('item_quantities')->insert(['item_id' => $itemId, 'location_id' => self::LOCATION_ID, 'quantity' => $units]);

        return $itemId;
    }

    private function deletionRowsOf(int $saleId): int
    {
        return Database::connect()->table('inventory')->where('trans_comment', 'Deleting sale ' . $saleId)->countAllResults();
    }

    public function testDelete_PutsBackNoStockForACreditNoteOnAnInvoiceWhoseGoodsNeverLeft(): void
    {
        $saleModel = model(Sale::class);
        $employee  = $this->createEmployee();
        $itemId    = $this->stockedItemWith(10);
        $invoice   = $this->saveInvoice(false, $itemId, 10);
        $note      = $this->saveCreditNote($invoice, $itemId, 4, $employee);

        $this->assertGreaterThan(0, $note);
        $this->assertEqualsWithDelta(10.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);

        $this->assertTrue($saleModel->delete($note, false, true, $employee));

        $this->assertEqualsWithDelta(10.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
        $this->assertSame(0, $this->deletionRowsOf($note));

        $this->assertSame($invoice, $saleModel->confirm_delivery($invoice, $employee));
        $this->assertEqualsWithDelta(0.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
    }

    public function testDelete_TakesOutTheUnitsADeliveryLeftBehindWhenTheCreditNoteOfAnInvoiceDeliveredLaterIsDeleted(): void
    {
        $saleModel = model(Sale::class);
        $employee  = $this->createEmployee();
        $itemId    = $this->stockedItemWith(10);
        $invoice   = $this->saveInvoice(false, $itemId, 10);
        $note      = $this->saveCreditNote($invoice, $itemId, 4, $employee);

        $this->assertSame($invoice, $saleModel->confirm_delivery($invoice, $employee));
        $this->assertEqualsWithDelta(4.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);

        $this->assertTrue($saleModel->delete($note, false, true, $employee));

        $this->assertEqualsWithDelta(0.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
        $this->assertSame(1, $this->deletionRowsOf($note));
    }

    public function testDelete_PutsBackTheStockOfACreditNoteOnADeliveredInvoice(): void
    {
        $saleModel = model(Sale::class);
        $employee  = $this->createEmployee();
        $itemId    = $this->stockedItemWith(20);
        $invoice   = $this->saveInvoice(true, $itemId, 10);
        $note      = $this->saveCreditNote($invoice, $itemId, 4, $employee);

        $this->assertEqualsWithDelta(14.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);

        $this->assertTrue($saleModel->delete($note, false, true, $employee));

        $this->assertEqualsWithDelta(10.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
        $this->assertSame(1, $this->deletionRowsOf($note));
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

    public function testSaveValue_NumbersARefundInvoiceFromItsOwnSequenceAndLeavesTheCreditNotesAlone(): void
    {
        $this->switchOn(DocumentType::CreditNote, 'CN-', 5, 1);
        $this->switchOn(DocumentType::RefundInvoice, 'RI-', 5, 1);

        $invoice = $this->saveSale(SALE_TYPE_INVOICE, []);
        $refund  = $this->saveSale(SALE_TYPE_RETURN, [], COMPLETED, NEW_ENTRY, null, null, $invoice, -1, ReturnDocument::RefundInvoice);
        $note    = $this->saveSale(SALE_TYPE_RETURN, [], COMPLETED, NEW_ENTRY, null, null, $invoice, -1);

        $this->assertSame('RI-00001', $this->numberOf(DocumentType::RefundInvoice, 'sales', $refund));
        $this->assertNull($this->numberOf(DocumentType::CreditNote, 'sales', $refund));
        $this->assertSame('CN-00001', $this->numberOf(DocumentType::CreditNote, 'sales', $note));
        $this->assertNull($this->numberOf(DocumentType::RefundInvoice, 'sales', $note));
        $this->assertNull($this->numberOf(DocumentType::RefundInvoice, 'sales', $invoice));
        $this->assertSame(2, (new DocumentNumbering())->sequence(DocumentType::RefundInvoice)->nextNumber);
        $this->assertSame(2, (new DocumentNumbering())->sequence(DocumentType::CreditNote)->nextNumber);
    }

    public function testSaveValue_GivesARefundInvoiceNoNumberWhileItsSequenceIsOffWhateverTheCreditNotesDo(): void
    {
        $this->switchOn(DocumentType::CreditNote, 'CN-', 5, 1);

        $invoice = $this->saveSale(SALE_TYPE_INVOICE, []);
        $refund  = $this->saveSale(SALE_TYPE_RETURN, [], COMPLETED, NEW_ENTRY, null, null, $invoice, -1, ReturnDocument::RefundInvoice);

        $this->assertGreaterThan(0, $refund);
        $this->assertSame(0, Database::connect()->table('document_numbers')->where('source_id', $refund)->countAllResults());
        $this->assertSame(1, (new DocumentNumbering())->sequence(DocumentType::CreditNote)->nextNumber);
    }

    public function testSaveValue_SavesAReturnAsACreditNoteUnlessItIsIssuedAsARefundInvoice(): void
    {
        $invoice = $this->saveSale(SALE_TYPE_INVOICE, [], COMPLETED, NEW_ENTRY, null, null, null, 1, ReturnDocument::RefundInvoice);
        $note    = $this->saveSale(SALE_TYPE_RETURN, [], COMPLETED, NEW_ENTRY, null, null, $invoice, -1);
        $refund  = $this->saveSale(SALE_TYPE_RETURN, [], COMPLETED, NEW_ENTRY, null, null, $invoice, -1, ReturnDocument::RefundInvoice);

        $this->assertNull($this->theSaleRow($invoice)['return_document']);
        $this->assertNull($this->theSaleRow($note)['return_document']);
        $this->assertSame('refund_invoice', $this->theSaleRow($refund)['return_document']);
    }

    public function testGetReturnDocument_ReadsWhichDocumentAReturnWasIssuedAs(): void
    {
        $invoice = $this->saveSale(SALE_TYPE_INVOICE, []);
        $note    = $this->saveSale(SALE_TYPE_RETURN, [], COMPLETED, NEW_ENTRY, null, null, $invoice, -1);
        $refund  = $this->saveSale(SALE_TYPE_RETURN, [], COMPLETED, NEW_ENTRY, null, null, $invoice, -1, ReturnDocument::RefundInvoice);
        $sale    = model(Sale::class);

        $this->assertSame(ReturnDocument::CreditNote, $sale->getReturnDocument($note));
        $this->assertSame(ReturnDocument::RefundInvoice, $sale->getReturnDocument($refund));
        $this->assertSame(ReturnDocument::CreditNote, $sale->getReturnDocument($invoice));
        $this->assertSame(ReturnDocument::CreditNote, $sale->getReturnDocument(999999999));
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

    public function testSaveValue_TakesBackTheInvoiceNumberWhenAPaymentCannotBeNumbered(): void
    {
        $db     = Database::connect();
        $prefix = $this->freshPrefix();
        $this->switchOn(DocumentType::SalesInvoice, $prefix, 3, 1);
        $this->switchOn(DocumentType::CustomerPayment, 'PAY-', 0, 1);
        $db->table('document_sequences')->where('doc_type', 'customer_payment')->update(['next_number' => 4294967294]);
        $db->table('document_numbers')->insert(['company_id' => 1, 'doc_type' => 'customer_payment', 'source_table' => 'sales_payments', 'source_id' => 999999999, 'sequence_number' => 4294967294, 'number' => 'PAY-4294967294']);

        $sales  = $db->table('sales')->countAllResults();
        $result = $this->saveSale(SALE_TYPE_INVOICE, [$this->cashPayment()]);

        $this->assertSame(ACCOUNTING_FAILED, $result);
        $this->assertSame($sales, $db->table('sales')->countAllResults());
        $this->assertSame(0, $db->table('document_numbers')->where('doc_type', 'sales_invoice')->countAllResults());
        $this->assertSame(1, (new DocumentNumbering())->sequence(DocumentType::SalesInvoice)->nextNumber);

        (new DocumentNumbering())->configure(DocumentType::CustomerPayment, ['enabled' => false]);

        $invoice = $this->saveSale(SALE_TYPE_INVOICE, [$this->cashPayment()]);

        $this->assertSame($prefix . '001', model(Sale::class)->getInvoiceNumber($invoice));
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

    /**
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
            Database::connect()->table('sales')->where('sale_id', $saleId)->update(['sale_time' => $date . ' 10:00:00']);
        }

        return $saleId;
    }

    /**
     * @return array<string, mixed>
     */
    private function theSaleRow(int $saleId): array
    {
        return Database::connect()->table('sales')->where('sale_id', $saleId)->get()->getRowArray();
    }

    public function testSaveValue_SavesTheDiscountOnTheWholeOfTheSaleAndTheShareOfItsLineWhileAccountingIsOff(): void
    {
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $saleId = $this->saveSaleWithDiscount($itemId, '2', '50', ['type' => PERCENT, 'value' => '10']);

        $this->assertGreaterThan(0, $saleId);
        $this->assertSame((string) PERCENT, (string) $this->theSaleRow($saleId)['invoice_discount_type']);
        $this->assertSame('10.0000', $this->theSaleRow($saleId)['invoice_discount']);
        $this->assertSame('10.0000', Database::connect()->table('sales_items')->where('sale_id', $saleId)->get()->getRow()->invoice_discount_share);
        $this->assertSame(['type' => PERCENT, 'value' => '10.0000'], model(Sale::class)->getInvoiceDiscount($saleId));
    }

    public function testSaveValue_SavesNothingOfTheDiscountOnASaleThatHasNone(): void
    {
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $saleId = $this->saveSaleWithDiscount($itemId, '2', '50', null);

        $this->assertNull($this->theSaleRow($saleId)['invoice_discount_type']);
        $this->assertNull($this->theSaleRow($saleId)['invoice_discount']);
        $this->assertNull(Database::connect()->table('sales_items')->where('sale_id', $saleId)->get()->getRow()->invoice_discount_share);
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

        $row = Database::connect()->table('sales_items_temp')->where('sale_id', $saleId)->get()->getRow();

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
        $this->assertSame('-5.0000', Database::connect()->table('sales_items')->where('sale_id', $return)->get()->getRow()->invoice_discount_share);
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

    // ---------------------------------------------------------------------------------------
    // WP36: the controls on prices, held to a sale when it is completed, and the list a sale was priced from
    // ---------------------------------------------------------------------------------------

    private function resetPriceControls(): void
    {
        $db = Database::connect();

        foreach ([SalesControlSettings::MINIMUM_PRICE, SalesControlSettings::MAX_DISCOUNT_ON] as $key) {
            $db->table('app_config')->replace(['key' => $key, 'value' => '0']);
        }

        $db->table('app_config')->replace(['key' => SalesControlSettings::MAX_DISCOUNT, 'value' => '']);
        $db->table('items')->update(['minimum_price' => 0, 'max_discount_percent' => null]);
        $db->table('accounting_audit_log')->whereIn('action', ['price_override', 'price_refused'])->delete();
        $db->table('sales')->update(['price_list_id' => null]);
        $db->table('price_list_items')->where('price_list_id >', 0)->delete();
        $db->table('price_lists')->where('price_list_id >', 0)->delete();
    }

    private function controlledItem(string $minimum = '90', ?string $ceiling = null): int
    {
        $itemId = $this->createTestItem(HAS_NO_STOCK);

        Database::connect()->table('items')->where('item_id', $itemId)->update(['minimum_price' => $minimum, 'max_discount_percent' => $ceiling]);
        Database::connect()->table('app_config')->replace(['key' => SalesControlSettings::MINIMUM_PRICE, 'value' => '1']);

        return $itemId;
    }

    private function saveControlled(int $itemId, string $price, int $type = SALE_TYPE_INVOICE, int $status = COMPLETED, ?int $employeeId = null, string $discount = '0', ?array $invoiceDiscount = null, string $quantity = '1', int $saleId = NEW_ENTRY, ?int $priceListId = null, ?int $origin = null): int
    {
        $items = $this->discountedLines($itemId, $quantity, $price, $discount);
        $taxes = [[], []];

        return model(Sale::class)->save_value($saleId, $status, $items, NEW_ENTRY, $employeeId ?? $this->createEmployee(), 'controls', null, null, null, $type, [], null, $taxes, true, $origin, $invoiceDiscount, priceListId: $priceListId);
    }

    private function overrider(): int
    {
        return $this->createEmployee(grants: [['permission_id' => 'sales_price_override', 'menu_group' => 'home']]);
    }

    public function testSaveValue_RefusesALineUnderTheMinimumPriceWithAccountingOffAndSavesNothing(): void
    {
        $this->resetPriceControls();

        $itemId = $this->controlledItem();
        $sales  = $this->countSalesRows();
        $model  = model(Sale::class);

        $this->assertSame(ACCOUNTING_FAILED, $this->saveControlled($itemId, '80'));
        $this->assertSame(AccountingError::PriceBelowMinimum, $model->accountingFailure()->error);
        $this->assertSame($sales, $this->countSalesRows());
        $this->assertSame(1, Database::connect()->table('accounting_audit_log')->where('action', 'price_refused')->countAllResults());

        $this->resetPriceControls();
    }

    public function testSaveValue_SavesALineAtTheMinimumPriceAndTouchesNoAuditLog(): void
    {
        $this->resetPriceControls();

        $this->assertGreaterThan(0, $this->saveControlled($this->controlledItem(), '90'));
        $this->assertSame(0, Database::connect()->table('accounting_audit_log')->whereIn('action', ['price_refused', 'price_override'])->countAllResults());

        $this->resetPriceControls();
    }

    public function testSaveValue_LetsAnEmployeeWhoMayOverridePricesSellUnderTheMinimumAndWritesTheLines(): void
    {
        $this->resetPriceControls();

        $employee = $this->overrider();
        $itemId   = $this->controlledItem();
        $saleId   = $this->saveControlled($itemId, '80', employeeId: $employee);

        $this->assertGreaterThan(0, $saleId);

        $entry = Database::connect()->table('accounting_audit_log')->where('action', 'price_override')->get()->getRow();

        $this->assertSame($saleId, (int) $entry->entity_id);
        $this->assertSame($employee, (int) $entry->employee_id);
        $this->assertSame($itemId, (int) json_decode($entry->details, true)['lines'][0]['item_id']);

        $this->resetPriceControls();
    }

    public function testSaveValue_NeverHoldsASuspendedSaleOrAQuoteToThePriceControls(): void
    {
        $this->resetPriceControls();

        $itemId = $this->controlledItem();

        $this->assertGreaterThan(0, $this->saveControlled($itemId, '80', SALE_TYPE_INVOICE, SUSPENDED));
        $this->assertGreaterThan(0, $this->saveControlled($itemId, '80', SALE_TYPE_QUOTE, SUSPENDED));
        $this->assertGreaterThan(0, $this->saveControlled($itemId, '80', SALE_TYPE_WORK_ORDER, SUSPENDED));
        $this->assertSame(0, Database::connect()->table('accounting_audit_log')->where('action', 'price_refused')->countAllResults());

        $this->resetPriceControls();
    }

    public function testSaveValue_NeverHoldsAReturnToThePriceControls(): void
    {
        $this->resetPriceControls();

        $itemId = $this->controlledItem();

        $this->assertGreaterThan(0, $this->saveControlled($itemId, '80', SALE_TYPE_RETURN, COMPLETED, null, '0', null, '-1'));
        $this->assertGreaterThan(0, $this->saveControlled($itemId, '80', SALE_TYPE_RETURN, COMPLETED, null, '0', null, '1'), 'a return is held to nothing, whatever the sign of its lines');

        $this->resetPriceControls();
    }

    public function testSaveValue_HoldsAReceiptSaleToThePriceControlsToo(): void
    {
        $this->resetPriceControls();

        $this->assertSame(ACCOUNTING_FAILED, $this->saveControlled($this->controlledItem(), '80', SALE_TYPE_POS));

        $this->resetPriceControls();
    }

    public function testSaveValue_AddsTheShareOfTheDiscountOnTheWholeSaleToTheDiscountOfTheLine(): void
    {
        $this->resetPriceControls();

        $itemId = $this->createTestItem(HAS_NO_STOCK);

        Database::connect()->table('app_config')->replace(['key' => SalesControlSettings::MAX_DISCOUNT_ON, 'value' => '1']);
        Database::connect()->table('app_config')->replace(['key' => SalesControlSettings::MAX_DISCOUNT, 'value' => '10']);

        $this->assertGreaterThan(0, $this->saveControlled($itemId, '100', invoiceDiscount: ['type' => PERCENT, 'value' => '10']));
        $this->assertSame(ACCOUNTING_FAILED, $this->saveControlled($itemId, '100', discount: '5', invoiceDiscount: ['type' => PERCENT, 'value' => '10']));
        $this->assertSame(AccountingError::DiscountAboveCeiling, model(Sale::class)->accountingFailure()->error);

        $this->resetPriceControls();
    }

    public function testIssueDraft_RefusesADraftThatSellsUnderTheMinimumPriceAndKeepsItAsADraft(): void
    {
        $this->resetPriceControls();

        $itemId = $this->controlledItem();
        $draft  = $this->saveControlled($itemId, '80', SALE_TYPE_INVOICE, SUSPENDED);
        $model  = model(Sale::class);

        $this->assertSame(ACCOUNTING_FAILED, $model->issueDraft($draft, $this->createEmployee()));
        $this->assertSame(AccountingError::PriceBelowMinimum, $model->accountingFailure()->error);
        $this->assertSame((string) SUSPENDED, (string) $this->theSaleRow($draft)['sale_status']);

        $this->assertGreaterThan(0, $model->issueDraft($draft, $this->overrider()));
        $this->assertSame((string) COMPLETED, (string) $this->theSaleRow($draft)['sale_status']);

        $this->resetPriceControls();
    }

    public function testSaveValue_StoresThePriceListOfASaleAndKeepsItWhenTheDraftIsIssued(): void
    {
        $this->resetPriceControls();

        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $listId = model(Price_list::class)->saveList('Wholesale', true, null, null);
        $draft  = $this->saveControlled($itemId, '80', SALE_TYPE_INVOICE, SUSPENDED, priceListId: $listId);

        $this->assertSame($listId, model(Sale::class)->getPriceListId($draft));

        $this->assertGreaterThan(0, model(Sale::class)->issueDraft($draft, $this->createEmployee()));
        $this->assertSame($listId, model(Sale::class)->getPriceListId($draft), 'issuing the draft keeps the list it was priced from');
        $this->assertSame('80.00', Database::connect()->table('sales_items')->where('sale_id', $draft)->get()->getRow()->item_unit_price);

        $this->resetPriceControls();
    }

    public function testSaveValue_StoresNoPriceListForASaleWithNoneAndForAReturn(): void
    {
        $this->resetPriceControls();

        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $listId = model(Price_list::class)->saveList('Wholesale', true, null, null);
        $plain  = $this->saveControlled($itemId, '80');
        $return = $this->saveControlled($itemId, '80', SALE_TYPE_RETURN, COMPLETED, null, '0', null, '-1', priceListId: $listId);

        $this->assertNull(model(Sale::class)->getPriceListId($plain));
        $this->assertNull(model(Sale::class)->getPriceListId($return), 'a return is not priced from a list');

        $this->resetPriceControls();
    }

    public function testSaveValue_ClearsThePriceListWhenASuspendedSaleIsKeptAgainWithNone(): void
    {
        $this->resetPriceControls();

        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $listId = model(Price_list::class)->saveList('Wholesale', true, null, null);
        $draft  = $this->saveControlled($itemId, '80', SALE_TYPE_INVOICE, SUSPENDED, priceListId: $listId);

        $this->saveControlled($itemId, '80', SALE_TYPE_INVOICE, SUSPENDED, saleId: $draft, priceListId: null);

        $this->assertNull(model(Sale::class)->getPriceListId($draft));

        $this->resetPriceControls();
    }
}
