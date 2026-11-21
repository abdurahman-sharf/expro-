<?php

namespace Tests\Models;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\QuoteStatus;
use App\Libraries\Accounting\ReturnDocument;
use App\Libraries\InvoiceDiscountProblem;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Pricing\SalesControlSettings;
use App\Libraries\ShippingTaxOption;
use App\Libraries\Tax\LineVat;
use App\Models\Appconfig;
use App\Models\Price_list;
use App\Models\Sale;
use CodeIgniter\Database\Config;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\TestLogger;
use Config\Database;
use Config\OSPOS;
use ReflectionClass;
use Tests\Support\BulkSalesFixtureTrait;
use Tests\Support\ConcurrentDbRaceTrait;
use Tests\Support\DatabaseFailureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\FailedQueryConnectionTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\RoleFixtureTrait;
use Tests\Support\UncommittedWriterTrait;

/**
 * Regression tests: Sale::save_value() must reject
 * (and roll back) a payment that would overdraw a gift card or a customer's
 * reward points, instead of silently applying a stale/negative balance.
 */
class SaleTest extends CIUnitTestCase
{
    use BulkSalesFixtureTrait;
    use ConcurrentDbRaceTrait;
    use DatabaseFailureTrait;
    use DatabaseTestTrait;
    use EmployeeFixtureTrait;
    use FailedQueryConnectionTrait;
    use ItemFixtureTrait;
    use RoleFixtureTrait;
    use UncommittedWriterTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $seedOnce    = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private static bool $doneBootstrap = false;
    private int $boundaryItem          = 0;

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

    public function testSaveValue_SavesTheNextSaleOfTheProcessAfterTheDatabaseRefusedOne(): void
    {
        $itemId     = $this->createTestItem(HAS_NO_STOCK);
        $employeeId = $this->createEmployee();
        $saved      = [];

        $this->failWritesTo('sales', 'comment', self::REFUSED_MARKER);

        try {
            foreach (['first', self::REFUSED_MARKER, 'third'] as $comment) {
                $status  = COMPLETED;
                $items   = $this->buildCartLine($itemId, 1, 10.00);
                $taxes   = [[], []];
                $saved[] = model(Sale::class)->save_value(NEW_ENTRY, $status, $items, NEW_ENTRY, $employeeId, $comment, null, null, null, SALE_TYPE_POS, [$this->cashPayment()], null, $taxes, true) > 0;
            }
        } finally {
            $this->stopFailingWrites();
        }

        $this->assertSame([true, false, true], $saved);
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

    public function testGetSaleTime_ReadsTheTimeASaleWasSavedAtWithoutBuildingATemporaryTable(): void
    {
        $saleModel = model(Sale::class);
        $saleId    = $this->saveSale(SALE_TYPE_INVOICE, []);

        $saleModel->get_info($saleId);
        $this->assertTrue($this->temporaryTableExists('sales_items_temp'), 'Reading a sale whole builds the temporary table.');
        Database::connect()->query('DROP TEMPORARY TABLE IF EXISTS ' . Database::connect()->prefixTable('sales_items_temp'));

        $time = $saleModel->getSaleTime($saleId);

        $this->assertSame($this->theSaleRow($saleId)['sale_time'], $time);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $time);
        $this->assertSame('', $saleModel->getSaleTime(999999999));
        $this->assertFalse($this->temporaryTableExists('sales_items_temp'), 'Reading the time of a sale must leave no temporary table behind.');
    }

    public function testSearch_ReadsTheListWithoutWaitingForASaleThatIsBeingSaved(): void
    {
        $db         = Database::connect();
        $employeeId = $this->createEmployee();
        $config     = (new Database())->tests;
        $writer     = Database::connect($config, false);

        $writer->transStart();
        $writer->table('sales')->insert(['sale_time' => '2026-10-20 12:00:00', 'employee_id' => $employeeId, 'comment' => '', 'sale_status' => COMPLETED, 'sale_type' => SALE_TYPE_POS, 'branch_id' => 1]);
        $writer->table('sales_payments')->insert(['sale_id' => $writer->insertID(), 'payment_type' => 'Cash', 'payment_amount' => '10', 'cash_refund' => '0', 'employee_id' => $employeeId, 'reference_code' => '']);

        $db->query('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $filters = ['sale_type' => 'all', 'location_id' => 'all', 'start_date' => '2026-10-20', 'end_date' => '2026-10-20', 'only_cash' => false, 'only_due' => false, 'only_check' => false, 'selected_customer' => false, 'only_creditcard' => false, 'only_debit' => false, 'only_bank_transfer' => false, 'only_wallet' => false, 'only_invoices' => false, 'is_valid_receipt' => false];

            $this->assertNotNull(model(Sale::class)->search('', $filters, 25, 0, 'sale_id', 'desc'));
        } finally {
            $writer->transRollback();
            $writer->close();
            $db->query('SET SESSION innodb_lock_wait_timeout = 50');
            $this->dropReportTables();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function filtersForDays(string $firstDay, string $lastDay): array
    {
        return ['sale_type' => 'all', 'location_id' => 'all', 'start_date' => $firstDay, 'end_date' => $lastDay, 'only_cash' => false, 'only_due' => false, 'only_check' => false, 'selected_customer' => false, 'only_creditcard' => false, 'only_debit' => false, 'only_bank_transfer' => false, 'only_wallet' => false, 'only_invoices' => false, 'is_valid_receipt' => false];
    }

    /**
     * @return list<int>
     */
    private function listedSaleIds(string $firstDay, string $lastDay): array
    {
        $ids = array_map('intval', array_column(model(Sale::class)->search('', $this->filtersForDays($firstDay, $lastDay), 0, 0, 'sales.sale_id', 'asc')->getResultArray(), 'sale_id'));

        $this->dropReportTables();

        return $ids;
    }

    private function saleAt(string $moment, int $employeeId): int
    {
        $db = Database::connect();

        $db->table('sales')->insert(['sale_time' => $moment, 'employee_id' => $employeeId, 'comment' => '', 'sale_status' => COMPLETED, 'sale_type' => SALE_TYPE_POS, 'branch_id' => 1]);

        $saleId = (int) $db->insertID();

        $db->table('sales_items')->insert(['sale_id' => $saleId, 'item_id' => $this->boundaryItem, 'line' => 1, 'quantity_purchased' => '1', 'item_cost_price' => '1', 'item_unit_price' => '10', 'item_location' => self::LOCATION_ID]);

        return $saleId;
    }

    public function testSearch_KeepsTheSalesOfTheDaysAskedForFromTheirFirstSecondToTheirLast(): void
    {
        $employeeId         = $this->createEmployee();
        $this->boundaryItem = $this->createTestItem(HAS_NO_STOCK);
        $before             = $this->saleAt('2001-02-11 23:59:59', $employeeId);
        $first              = $this->saleAt('2001-02-12 00:00:00', $employeeId);
        $noon               = $this->saleAt('2001-02-12 12:00:00', $employeeId);
        $last               = $this->saleAt('2001-02-12 23:59:59', $employeeId);
        $after              = $this->saleAt('2001-02-13 00:00:00', $employeeId);
        $late               = $this->saleAt('2001-02-13 23:59:59', $employeeId);

        $statements = [];

        $listener   = static function (Query $query) use (&$statements): void {
            $statements[] = $query->getQuery();
        };

        Events::on('DBQuery', $listener);

        try {
            $listed = $this->listedSaleIds('2001-02-12', '2001-02-12');
        } finally {
            Events::removeListener('DBQuery', $listener);
        }

        $this->assertSame([$first, $noon, $last], $listed);

        $built = array_values(array_filter($statements, static fn (string $statement): bool => str_contains($statement, 'CREATE TEMPORARY TABLE') && str_contains($statement, 'sales_search_payments_temp')));

        $this->assertCount(1, $built);
        $this->assertStringContainsString("`ospos_sales`.`sale_time` >= '2001-02-12 00:00:00' AND `ospos_sales`.`sale_time` < '2001-02-13 00:00:00'", $built[0], 'The list reads the days as a range on the column, which its index serves.');
        $this->assertStringNotContainsString('DATE(`ospos_sales`.`sale_time`)', $built[0]);
        $this->assertSame([$first, $noon, $last, $after, $late], $this->listedSaleIds('2001-02-12', '2001-02-13'));
        $this->assertSame([$before, $first, $noon, $last, $after, $late], array_values(array_intersect($this->listedSaleIds('2001-02-01', '2099-12-31'), [$before, $first, $noon, $last, $after, $late])));
        $this->assertSame([], $this->listedSaleIds('2001-02-13', '2001-02-12'));
        $this->assertSame(3, model(Sale::class)->get_found_rows('', $this->filtersForDays('2001-02-12', '2001-02-12')));
    }

    public function testSearch_KeepsTheSalesOfTheDaysOfTheConnectionWhenItsClockMovesAtMidnight(): void
    {
        $db       = Database::connect();
        $employee = $this->createEmployee();
        $original = (string) $db->query('SELECT @@session.time_zone AS zone')->getRow()->zone;

        $this->boundaryItem = $this->createTestItem(HAS_NO_STOCK);

        try {
            $db->query("SET time_zone = '+00:00'");

            $ids = [];

            foreach (['2001-02-10 21:59:59', '2001-02-10 22:00:00', '2001-02-10 22:30:00', '2001-02-10 23:00:00', '2001-02-11 21:59:59', '2001-02-11 22:00:00'] as $instant) {
                $ids[] = $this->saleAt($instant, $employee);
            }

            $db->query("SET time_zone = 'Africa/Cairo'");

            foreach (['2001-02-10', '2001-02-11', '2001-02-12'] as $day) {
                $byDate = array_map('intval', array_column($db->query('SELECT sale_id FROM ' . $db->prefixTable('sales') . ' WHERE DATE(sale_time) = ? AND employee_id = ? ORDER BY sale_id', [$day, $employee])->getResultArray(), 'sale_id'));

                $this->assertSame($byDate, array_values(array_intersect($this->listedSaleIds($day, $day), $ids)), 'the day ' . $day);
            }
        } finally {
            $db->query('SET time_zone = ' . $db->escape($original));
        }
    }

    public function testSearch_WritesNothingToTheLog(): void
    {
        $logged = static fn (): int => count((new ReflectionClass(TestLogger::class))->getStaticPropertyValue('op_logs'));
        $before = $logged();

        $this->listedSaleIds('2001-02-12', '2001-02-12');
        model(Sale::class)->get_found_rows('', $this->filtersForDays('2001-02-12', '2001-02-12'));
        $this->dropReportTables();

        $this->assertSame($before, $logged());
    }

    public function testCreateTempTable_ReadsTheSalesOfADayWithoutWaitingForASaleThatIsBeingSaved(): void
    {
        $this->whileASaleIsBeingSaved($this->createEmployee(), $this->createTestItem(HAS_NO_STOCK), null, static function (): void {
            model(Sale::class)->create_temp_table(['start_date' => self::UNCOMMITTED_DAY, 'end_date' => self::UNCOMMITTED_DAY]);
        });

        $this->assertTrue($this->temporaryTableExists('sales_items_temp'));
    }

    public function testCreateTempTable_CutsToTheViewerGivenAndToNoOneWhenNoneIsGiven(): void
    {
        $own    = $this->ownRecordsEmployee();
        $mine   = $this->saleOfEmployee($own);
        $theirs = $this->saleOfEmployee($this->createEmployee());
        $today  = date('Y-m-d');
        $db     = Database::connect();
        $seen   = function (array $extra) use ($db, $today): array {
            $this->dropReportTables();
            model(Sale::class)->create_temp_table(['start_date' => $today, 'end_date' => $today] + $extra);

            return array_map('intval', array_column($db->table('sales_items_temp')->select('sale_id')->distinct()->get()->getResultArray(), 'sale_id'));
        };

        try {
            $this->assertSame([$mine], $seen(['viewer_id' => $own]));
            $this->assertSame([$mine], $seen(['viewer_id' => $own, 'sale_id' => null]));
            $this->assertSame([], $seen(['viewer_id' => $own, 'sale_id' => $theirs]));
            $this->assertContains($theirs, $seen(['viewer_id' => $this->createEmployee()]));
            $this->assertContains($theirs, $seen(['viewer_id' => null]));

            $_SESSION['person_id'] = $own;

            $this->assertContains($theirs, $seen([]));
            $this->assertContains($mine, $seen([]));
        } finally {
            unset($_SESSION['person_id']);
            $this->dropReportTables();
        }
    }

    public function testSearch_ReadsMoreSalesThanTheMemoryTablesOfTheServerCanHold(): void
    {
        $this->insertTaxedSales($this->createEmployee(), $this->createTestItem(), self::LOCATION_ID, '2001-02-10', 1600, 10);
        $this->capTheMemoryTablesOfTheServer(1048576);

        try {
            $this->assertSame(1600, model(Sale::class)->get_found_rows('', $this->filtersForDays('2001-02-10', '2001-02-10')));
        } finally {
            $this->releaseTheMemoryTablesOfTheServer();
            $this->dropReportTables();
        }
    }

    public function testCreateTempTable_ReadsMoreSalesThanTheMemoryTablesOfTheServerCanHold(): void
    {
        $this->insertTaxedSales($this->createEmployee(), $this->createTestItem(), self::LOCATION_ID, '2001-02-11', 1600, 10);
        $this->capTheMemoryTablesOfTheServer(1048576);

        try {
            model(Sale::class)->create_temp_table(['start_date' => '2001-02-11', 'end_date' => '2001-02-11']);

            $taxRows = (int) Database::connect()->query('SELECT COUNT(*) AS taxRows FROM ' . Database::connect()->prefixTable('sales_items_taxes_temp'))->getRow()->taxRows;

            $this->assertSame(16000, $taxRows);
        } finally {
            $this->releaseTheMemoryTablesOfTheServer();
            $this->dropReportTables();
        }
    }

    private function temporaryTableExists(string $table): bool
    {
        $db = Database::connect();

        try {
            $db->query('SELECT 1 FROM ' . $db->prefixTable($table) . ' LIMIT 1');

            return true;
        } catch (DatabaseException) {
            return false;
        }
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

    public function testDelete_RefusesToCancelASaleWhileAReturnOfItStandsAndLeavesTheStockAsItIs(): void
    {
        $saleModel = model(Sale::class);
        $employee  = $this->createEmployee();
        $itemId    = $this->stockedItemWith(20);
        $invoice   = $this->saveInvoice(true, $itemId, 10);
        $note      = $this->saveCreditNote($invoice, $itemId, 4, $employee);

        $this->assertGreaterThan(0, $note);
        $this->assertEqualsWithDelta(14.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);

        $this->assertFalse($saleModel->delete($invoice, false, true, $employee));

        $this->assertInstanceOf(AccountingException::class, $saleModel->accountingFailure());
        $this->assertSame(AccountingError::SaleHasReturns, $saleModel->accountingFailure()->error);
        $this->assertSame(COMPLETED, (int) $saleModel->getSaleStatus($invoice));
        $this->assertEqualsWithDelta(14.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
        $this->assertSame(0, $this->deletionRowsOf($invoice));
    }

    public function testDelete_RefusesToCancelASaleWhoseReturnStandsEvenWhenTheStockIsNotToBeTouched(): void
    {
        $saleModel = model(Sale::class);
        $employee  = $this->createEmployee();
        $itemId    = $this->stockedItemWith(20);
        $invoice   = $this->saveInvoice(true, $itemId, 10);

        $this->saveCreditNote($invoice, $itemId, 4, $employee);

        $this->assertFalse($saleModel->delete($invoice, false, false, $employee));
        $this->assertSame(AccountingError::SaleHasReturns, $saleModel->accountingFailure()->error);
        $this->assertSame(COMPLETED, (int) $saleModel->getSaleStatus($invoice));
    }

    public function testDelete_CancelsTheSaleOnceItsReturnWasCancelledAndTheStockComesBackOnce(): void
    {
        $saleModel = model(Sale::class);
        $employee  = $this->createEmployee();
        $itemId    = $this->stockedItemWith(20);
        $invoice   = $this->saveInvoice(true, $itemId, 10);
        $note      = $this->saveCreditNote($invoice, $itemId, 4, $employee);

        $this->assertTrue($saleModel->delete($note, false, true, $employee));
        $this->assertEqualsWithDelta(10.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);

        $this->assertTrue($saleModel->delete($invoice, false, true, $employee));

        $this->assertNull($saleModel->accountingFailure());
        $this->assertSame(CANCELED, (int) $saleModel->getSaleStatus($invoice));
        $this->assertEqualsWithDelta(20.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
    }

    public function testDelete_CancelsASaleWhoseOnlyReturnWasSavedForAnotherSaleAndOneWithAReturnStillWaiting(): void
    {
        $saleModel = model(Sale::class);
        $employee  = $this->createEmployee();
        $itemId    = $this->stockedItemWith(40);
        $first     = $this->saveInvoice(true, $itemId, 10);
        $second    = $this->saveInvoice(true, $itemId, 5);

        $this->saveCreditNote($first, $itemId, 4, $employee);

        $this->assertTrue($saleModel->delete($second, false, true, $employee));
        $this->assertSame(CANCELED, (int) $saleModel->getSaleStatus($second));
        $this->assertEqualsWithDelta(34.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);

        $suspendedItem = $this->stockedItemWith(10);
        $third         = $this->saveInvoice(true, $suspendedItem, 3);
        $status        = SUSPENDED;
        $lines         = $this->buildCartLine($suspendedItem, -1, 5.00);
        $taxes         = [[], []];
        $waiting       = $saleModel->save_value(NEW_ENTRY, $status, $lines, NEW_ENTRY, $employee, 'return', null, null, null, SALE_TYPE_RETURN, [], null, $taxes, true, $third);

        $this->assertGreaterThan(0, $waiting);
        $this->assertTrue($saleModel->delete($third, false, true, $employee), 'a return that waits to be completed has taken nothing back yet');
    }

    public function testSaveValue_RefusesAReturnOfASaleCancelledAfterTheReturnWasStagedAndLeavesTheStockAsItIs(): void
    {
        $saleModel = model(Sale::class);
        $employee  = $this->createEmployee();
        $itemId    = $this->stockedItemWith(20);
        $invoice   = $this->saveInvoice(true, $itemId, 10);

        $this->assertTrue($saleModel->delete($invoice, false, true, $employee));
        $this->assertEqualsWithDelta(20.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);

        $note = $this->saveCreditNote($invoice, $itemId, 4, $employee);

        $this->assertSame(ACCOUNTING_FAILED, $note);
        $this->assertInstanceOf(PostingException::class, $saleModel->accountingFailure());
        $this->assertSame(PostingError::ReturnOriginInvalid, $saleModel->accountingFailure()->error);
        $this->assertEqualsWithDelta(20.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
        $this->assertSame(0, Database::connect()->table('sales')->where('return_of_sale_id', $invoice)->countAllResults());
    }

    public function testSaveValue_RefusesAReturnWhoseOriginIsAReturnItself(): void
    {
        $saleModel = model(Sale::class);
        $employee  = $this->createEmployee();
        $itemId    = $this->stockedItemWith(20);
        $invoice   = $this->saveInvoice(true, $itemId, 10);
        $note      = $this->saveCreditNote($invoice, $itemId, 4, $employee);

        $this->assertGreaterThan(0, $note);
        $this->assertSame(ACCOUNTING_FAILED, $this->saveCreditNote($note, $itemId, 1, $employee));
        $this->assertSame(PostingError::ReturnOriginInvalid, $saleModel->accountingFailure()->error);
        $this->assertEqualsWithDelta(14.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
    }

    public function testSaveValue_RefusesAReturnWhoseOriginDoesNotExistAndLeavesTheStockAsItIs(): void
    {
        $saleModel = model(Sale::class);
        $employee  = $this->createEmployee();
        $itemId    = $this->stockedItemWith(20);

        $this->assertSame(ACCOUNTING_FAILED, $this->saveCreditNote(987654321, $itemId, 4, $employee));
        $this->assertSame(PostingError::ReturnOriginInvalid, $saleModel->accountingFailure()->error);
        $this->assertEqualsWithDelta(20.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
    }

    public function testSaveValue_RefusesToCompleteAWaitingReturnWhoseSaleWasCancelledMeanwhileButLetsItWaitAgain(): void
    {
        $saleModel = model(Sale::class);
        $employee  = $this->createEmployee();
        $itemId    = $this->stockedItemWith(20);
        $invoice   = $this->saveInvoice(true, $itemId, 10);
        $status    = SUSPENDED;
        $lines     = $this->buildCartLine($itemId, -4, 5.00);
        $taxes     = [[], []];
        $waiting   = $saleModel->save_value(NEW_ENTRY, $status, $lines, NEW_ENTRY, $employee, 'return', null, null, null, SALE_TYPE_RETURN, [], null, $taxes, true, $invoice);

        $this->assertGreaterThan(0, $waiting);
        $this->assertTrue($saleModel->delete($invoice, false, true, $employee));
        $this->assertEqualsWithDelta(20.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);

        $again = $saleModel->save_value($waiting, $status, $lines, NEW_ENTRY, $employee, 'return', null, null, null, SALE_TYPE_RETURN, [], null, $taxes, true, $invoice);

        $this->assertSame($waiting, $again, 'a return that waits is not refused before it is completed');

        $completed = COMPLETED;

        $this->assertSame(ACCOUNTING_FAILED, $saleModel->save_value($waiting, $completed, $lines, NEW_ENTRY, $employee, 'return', null, null, null, SALE_TYPE_RETURN, [], null, $taxes, true, $invoice));
        $this->assertSame(PostingError::ReturnOriginInvalid, $saleModel->accountingFailure()->error);
        $this->assertSame(SUSPENDED, (int) $saleModel->getSaleStatus($waiting));
        $this->assertEqualsWithDelta(20.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
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

    public function testUpdate_RefusesAQuoteThatBecameAnInvoiceAndChangesNothingOfIt(): void
    {
        $saleModel = model(Sale::class);
        $quote     = $this->saveSale(SALE_TYPE_QUOTE, [], SUSPENDED);

        Database::connect()->table('sales')->where('sale_id', $quote)->update(['quote_status' => QuoteStatus::Converted->value, 'comment' => 'kept']);

        $this->assertFalse($saleModel->update($quote, ['comment' => 'changed']));
        $this->assertInstanceOf(AccountingException::class, $saleModel->accountingFailure());
        $this->assertSame(AccountingError::QuoteLocked, $saleModel->accountingFailure()->error);
        $this->assertSame('kept', Database::connect()->table('sales')->where('sale_id', $quote)->get()->getRow()->comment);
    }

    public function testUpdate_StillEditsAQuoteThatWasNotConverted(): void
    {
        $saleModel = model(Sale::class);
        $quote     = $this->saveSale(SALE_TYPE_QUOTE, [], SUSPENDED);

        $this->assertTrue($saleModel->update($quote, ['comment' => 'changed']));
        $this->assertNull($saleModel->accountingFailure());
        $this->assertSame('changed', Database::connect()->table('sales')->where('sale_id', $quote)->get()->getRow()->comment);
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

    /**
     * @param array<string, mixed> $charges
     */
    private function amountDueWithCharges(array $charges, int $type = SALE_TYPE_POS, string $quantity = '2'): string
    {
        $this->dropReportTables();

        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $saleId = $this->saveSaleWithDiscount($itemId, $quantity, '50', null, [], $type);

        Database::connect()->table('sales')->where('sale_id', $saleId)->update($charges);

        $info = model(Sale::class)->get_info($saleId)->getRow();

        return bcadd((string) $info->amount_due, '0', 2);
    }

    public function testGetHeaderCharges_GivesBackWhatTheSaleWasSavedWithForTheRegisterToTakeUp(): void
    {
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $saleId = $this->saveSaleWithDiscount($itemId, '2', '50', null);

        Database::connect()->table('sales')->where('sale_id', $saleId)->update(['adjustment_label' => 'Handling fee', 'adjustment_amount' => '-10.0000', 'shipping_amount' => '20.0000', 'shipping_options' => 2, 'shipping_tax_option' => 'added', 'shipping_tax_percent' => '15.0000', 'shipping_tax_amount' => '3.0000']);

        $charges = model(Sale::class)->getHeaderCharges($saleId);

        $this->assertSame('Handling fee', $charges->adjustmentLabel);
        $this->assertSame('-10.0000', $charges->adjustment);
        $this->assertSame('20.0000', $charges->shipping);
        $this->assertSame(ShippingTaxOption::Added, $charges->shippingTaxOption);
        $this->assertSame('15.0000', $charges->shippingTaxPercent);
        $this->assertSame(2, $charges->shippingOptions);
        $this->assertFalse($charges->isEmpty());
    }

    public function testGetHeaderCharges_GivesNoChargesForASaleThatHasNoneOrIsNotThere(): void
    {
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $saleId = $this->saveSaleWithDiscount($itemId, '2', '50', null);

        $this->assertTrue(model(Sale::class)->getHeaderCharges($saleId)->isEmpty());
        $this->assertTrue(model(Sale::class)->getHeaderCharges(999999999)->isEmpty());
        $this->assertSame(ShippingTaxOption::None, model(Sale::class)->getHeaderCharges($saleId)->shippingTaxOption);
        $this->assertNull(model(Sale::class)->getHeaderCharges($saleId)->shippingOptions);
    }

    public function testGetInfo_GivesTheAmountDueWithTheShippingTheTaxOnItAndTheAdjustmentOfTheHeader(): void
    {
        $this->assertSame('113.00', $this->amountDueWithCharges(['adjustment_label' => 'Fee', 'adjustment_amount' => '-10.0000', 'shipping_amount' => '20.0000', 'shipping_tax_option' => 'added', 'shipping_tax_amount' => '3.0000']));
    }

    public function testGetInfo_LeavesTheTaxOnShippingInsideTheAmountWhenTheTaxIsIncluded(): void
    {
        $this->assertSame('120.00', $this->amountDueWithCharges(['shipping_amount' => '20.0000', 'shipping_tax_option' => 'included', 'shipping_tax_amount' => '2.6087']));
    }

    public function testGetInfo_RoundsTheTotalWithTheChargesToTheCentOnce(): void
    {
        $this->assertSame('135.83', $this->amountDueWithCharges(['shipping_amount' => '33.3300', 'shipping_tax_option' => 'added', 'shipping_tax_amount' => '2.4998']));
    }

    public function testGetInfo_GivesTheChargesOfACreditNoteInTheDirectionOfTheCredit(): void
    {
        $this->assertSame('-120.00', $this->amountDueWithCharges(['shipping_amount' => '20.0000'], SALE_TYPE_RETURN, '-2'));
    }

    public function testGetInfo_DoesNotRoundTheAmountDueOfASaleWithoutChargesToTheDecimalsOfTheTotals(): void
    {
        $this->dropReportTables();

        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $saleId = $this->saveSaleWithDiscount($itemId, '2', '50', null);

        Database::connect()->table('sales_payments')->insert(['sale_id' => $saleId, 'payment_type' => 'Cash', 'payment_amount' => '0.50', 'cash_refund' => 0, 'cash_adjustment' => 1, 'employee_id' => 1]);

        $before = config(OSPOS::class)->settings;

        config(OSPOS::class)->settings['currency_decimals'] = '0';

        try {
            $amountDue = bcadd((string) model(Sale::class)->get_info($saleId)->getRow()->amount_due, '0', 2);
        } finally {
            config(OSPOS::class)->settings = $before;
        }

        $this->assertSame('100.50', $amountDue, 'what a sale without charges owed is as it was, whatever the cash adjustment added');
    }

    public function testGetInfo_GivesTheAmountDueOfASaleWhoseChargesAreEmptyAsItAlwaysDid(): void
    {
        $this->assertSame('100.00', $this->amountDueWithCharges(['adjustment_amount' => null, 'shipping_amount' => null, 'shipping_tax_option' => null, 'shipping_tax_amount' => null]));
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
    // A tax of each mode on a line, and the setting that asks for a tax on every line (WP25)
    // ---------------------------------------------------------------------------------------

    private function setRequireTax(bool $on): void
    {
        model(Appconfig::class)->save(['require_tax_1' => $on ? '1' : '0']);
    }

    /**
     * @param list<array{0: string, 1: string, 2: int, 3: string}> $rows The name, the percent, the mode (0 inside the price, 1 added) and the amount of each tax.
     *
     * @return array{int, list<array<string, mixed>>}
     */
    private function saveTaxed(array $rows, int $status = COMPLETED, int $type = SALE_TYPE_POS, int $customerId = NEW_ENTRY, string $price = '100'): array
    {
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $items  = $this->buildCartLine($itemId, 1, (float) $price);
        $taxes  = [[], array_map(static fn (array $row): array => [
            'item_id'           => $itemId,
            'line'              => 0,
            'name'              => $row[0],
            'percent'           => $row[1],
            'tax_type'          => $row[2],
            'rounding_code'     => 0,
            'cascade_sequence'  => 0,
            'item_tax_amount'   => $row[3],
            'sales_tax_code_id' => null,
            'tax_category_id'   => null,
            'jurisdiction_id'   => null,
        ], $rows)];

        $saleId = model(Sale::class)->save_value(NEW_ENTRY, $status, $items, $customerId, $this->createEmployee(), 'tax', null, null, null, $type, [$this->cashPayment((float) $price)], null, $taxes, true);

        return [$saleId, $items];
    }

    public function testSaveValue_RefusesALineWithNoTaxWhenTheSettingAsksForOneAndSavesNothing(): void
    {
        $this->setRequireTax(true);

        try {
            $sales = Database::connect()->table('sales')->countAllResults();

            [$saleId] = $this->saveTaxed([]);

            $this->assertSame(ACCOUNTING_FAILED, $saleId);
            $this->assertSame($sales, Database::connect()->table('sales')->countAllResults());

            $failure = model(Sale::class)->accountingFailure();

            $this->assertInstanceOf(AccountingException::class, $failure);
            $this->assertSame(AccountingError::TaxOneRequired, $failure->error);
            $this->assertSame(0, $failure->context['line']);
        } finally {
            $this->setRequireTax(false);
        }
    }

    public function testSaveValue_AcceptsALineWithATaxOfNothingWhenTheSettingAsksForOne(): void
    {
        $this->setRequireTax(true);

        try {
            [$saleId] = $this->saveTaxed([['No tax', '0', 1, '0']]);

            $this->assertGreaterThan(0, $saleId);
        } finally {
            $this->setRequireTax(false);
        }
    }

    public function testSaveValue_AcceptsALineWithNoTaxWhenTheSettingIsOff(): void
    {
        [$saleId] = $this->saveTaxed([]);

        $this->assertGreaterThan(0, $saleId);
    }

    public function testSaveValue_AsksASuspendedSaleForNoTaxWhateverTheSetting(): void
    {
        $this->setRequireTax(true);

        try {
            [$saleId] = $this->saveTaxed([], SUSPENDED);

            $this->assertGreaterThan(0, $saleId);
        } finally {
            $this->setRequireTax(false);
        }
    }

    public function testSaveValue_AsksNoTaxOfACustomerWhoIsNotCharged(): void
    {
        $db = Database::connect();

        $db->table('people')->insert(['first_name' => 'Exempt', 'last_name' => 'Customer', 'phone_number' => '', 'email' => 'exempt-' . uniqid() . '@test.com', 'address_1' => '', 'address_2' => '', 'city' => '', 'state' => '', 'zip' => '', 'country' => '', 'comments' => '']);
        $personId = (int) $db->insertID();
        $db->table('customers')->insert(['person_id' => $personId, 'employee_id' => 1, 'taxable' => 0]);

        $this->setRequireTax(true);

        try {
            [$saleId] = $this->saveTaxed([], COMPLETED, SALE_TYPE_POS, $personId);

            $this->assertGreaterThan(0, $saleId);
        } finally {
            $this->setRequireTax(false);
        }
    }

    public function testSaveValue_AsksAnInvoiceForATaxAsASaleIsAsked(): void
    {
        $this->setRequireTax(true);

        try {
            [$saleId] = $this->saveTaxed([], COMPLETED, SALE_TYPE_INVOICE);

            $this->assertSame(ACCOUNTING_FAILED, $saleId);
        } finally {
            $this->setRequireTax(false);
        }
    }

    public function testSaveValue_AsksNoTaxOfAReturnWhateverTheSetting(): void
    {
        $invoice = $this->saveSale(SALE_TYPE_INVOICE, [$this->cashPayment()]);

        $this->setRequireTax(true);

        try {
            $return = $this->saveSale(SALE_TYPE_RETURN, [], COMPLETED, NEW_ENTRY, null, null, $invoice, -1);

            $this->assertGreaterThan(0, $return);
        } finally {
            $this->setRequireTax(false);
        }
    }

    public function testSaveValue_AsksNoTaxOfAShopThatTaxesByDestination(): void
    {
        model(Appconfig::class)->save(['use_destination_based_tax' => '1']);
        $this->setRequireTax(true);

        try {
            [$saleId] = $this->saveTaxed([]);

            $this->assertGreaterThan(0, $saleId);
        } finally {
            $this->setRequireTax(false);
            model(Appconfig::class)->save(['use_destination_based_tax' => '0']);
        }
    }

    public function testCreateTempTable_SplitsASaleIntoTheSubtotalWithoutTheTaxInsideThePriceAndTheTotalWithTheTaxAddedToItWhateverTheDefaultModeIs(): void
    {
        foreach (['0', '1'] as $default) {
            model(Appconfig::class)->save(['tax_included' => $default]);

            try {
                [$saleId] = $this->saveTaxed([['VAT', '15', 0, '13.0435'], ['Service', '5', 1, '4.35']]);

                $this->dropReportTables();
                model(Sale::class)->create_temp_table(['sale_id' => $saleId]);

                $row = Database::connect()->table('sales_items_temp')->where('sale_id', $saleId)->get()->getRow();

                $this->assertSame(['86.96', '17.39', '104.35'], [bcadd((string) $row->subtotal, '0', 2), bcadd((string) $row->tax, '0', 2), bcadd((string) $row->total, '0', 2)], 'default ' . $default);
            } finally {
                model(Appconfig::class)->save(['tax_included' => '0']);
                $this->dropReportTables();
            }
        }
    }

    public function testGetInfo_ComesToThePriceAndOnlyTheTaxesAddedToItWhateverTheDefaultModeIs(): void
    {
        foreach (['0', '1'] as $default) {
            model(Appconfig::class)->save(['tax_included' => $default]);

            try {
                [$saleId] = $this->saveTaxed([['VAT', '15', 0, '13.0435'], ['Service', '5', 1, '4.35']]);

                $this->assertSame('104.35', bcadd((string) model(Sale::class)->get_info($saleId)->getRow()->amount_due, '0', 2), 'default ' . $default);
            } finally {
                model(Appconfig::class)->save(['tax_included' => '0']);
                $this->dropReportTables();
            }
        }
    }

    public function testGetSalesItemTaxes_GivesTheModeAndTheAccountEachRowWasSavedWithAndEachTaxOnce(): void
    {
        [$saleId, $items] = $this->saveTaxed([['VAT', '15', 0, '13.0435'], ['Service', '5', 1, '4.35']]);

        $db = Database::connect();

        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->table('sales_items_taxes')->insert(['sale_id' => $saleId, 'item_id' => $items[0]['item_id'], 'line' => 1, 'name' => 'VAT', 'percent' => '15.0000', 'tax_type' => 0, 'item_tax_amount' => '13.0435']);
        $db->query('SET FOREIGN_KEY_CHECKS = 1');

        $rows = model(Sale::class)->get_sales_item_taxes($saleId, (int) $items[0]['item_id']);

        usort($rows, static fn (array $first, array $second): int => strcmp($first['name'], $second['name']));

        $this->assertSame(['Service', 'VAT'], array_column($rows, 'name'));
        $this->assertSame(['1', '0'], array_map('strval', array_column($rows, 'tax_type')));
        $this->assertSame([null, null], array_column($rows, 'liability_account_id'));
    }

    public function testGetSalesItemTaxes_SumsTheAmountsAndTheUnitsOfAnItemOverTheLinesOfTheSale(): void
    {
        [$saleId, $items] = $this->saveTaxed([['VAT', '15', 0, '13.0435'], ['Service', '5', 1, '4.35']]);

        $db     = Database::connect();
        $itemId = (int) $items[0]['item_id'];
        $first  = (array) $db->table('sales_items')->where(['sale_id' => $saleId, 'item_id' => $itemId])->get()->getRow();

        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->table('sales_items')->insert([...$first, 'line' => 5, 'quantity_purchased' => '3.000']);
        $db->table('sales_items_taxes')->insertBatch([
            ['sale_id' => $saleId, 'item_id' => $itemId, 'line' => 5, 'name' => 'VAT', 'percent' => '15.0000', 'tax_type' => 0, 'item_tax_amount' => '39.1304'],
            ['sale_id' => $saleId, 'item_id' => $itemId, 'line' => 5, 'name' => 'Service', 'percent' => '5.0000', 'tax_type' => 1, 'item_tax_amount' => '13.05'],
        ]);
        $db->query('SET FOREIGN_KEY_CHECKS = 1');

        $rows = model(Sale::class)->get_sales_item_taxes($saleId, $itemId);

        $this->assertSame(['Service', 'VAT'], array_column($rows, 'name'));
        $this->assertSame(['17.4000', '52.1739'], array_map(static fn (array $row): string => bcadd((string) $row['item_tax_amount'], '0', 4), $rows));
        $this->assertSame(['4.000', '4.000'], array_map(static fn (array $row): string => bcadd((string) $row['sold_quantity'], '0', 3), $rows));
    }

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

    private function ownRecordsEmployee(): int
    {
        return $this->employeeWithRoles([$this->createRole([], 'own')]);
    }

    private function saleOfEmployee(int $employeeId, int $status = COMPLETED, int $type = SALE_TYPE_POS): int
    {
        $itemId     = $this->createTestItem(HAS_NO_STOCK);
        $items      = $this->buildCartLine($itemId, 1, 10.00);
        $taxes      = [[], []];
        $saleStatus = $status;
        $payments   = $status === COMPLETED ? [$this->cashPayment()] : [];
        $saleId     = model(Sale::class)->save_value(NEW_ENTRY, $saleStatus, $items, NEW_ENTRY, $employeeId, 'own scope', null, null, null, $type, $payments, null, $taxes, true);

        $this->assertGreaterThan(0, $saleId);

        return $saleId;
    }

    private function invoiceOfEmployee(int $employeeId, int $itemId, ?string $invoiceNumber = null): int
    {
        $status = COMPLETED;
        $items  = $this->buildCartLine($itemId, 5, 5.00);
        $taxes  = [[], []];
        $saleId = model(Sale::class)->save_value(NEW_ENTRY, $status, $items, NEW_ENTRY, $employeeId, 'invoice', $invoiceNumber, null, null, SALE_TYPE_INVOICE, [], null, $taxes, true);

        $this->assertGreaterThan(0, $saleId);

        return $saleId;
    }

    private function suspendedSaleOfCustomer(int $employeeId, int $customerId): int
    {
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $items  = $this->buildCartLine($itemId, 1, 10.00);
        $taxes  = [[], []];
        $status = SUSPENDED;
        $saleId = model(Sale::class)->save_value(NEW_ENTRY, $status, $items, $customerId, $employeeId, 'suspended', null, null, null, SALE_TYPE_POS, [], null, $taxes, true);

        $this->assertGreaterThan(0, $saleId);

        return $saleId;
    }

    /**
     * @return array<string, mixed>
     */
    private function listFilters(): array
    {
        return [
            'sale_type'          => 'all',
            'location_id'        => 'all',
            'start_date'         => date('Y-m-d', strtotime('-1 day')),
            'end_date'           => date('Y-m-d', strtotime('+1 day')),
            'only_cash'          => false,
            'only_due'           => false,
            'only_check'         => false,
            'selected_customer'  => false,
            'only_creditcard'    => false,
            'only_debit'         => false,
            'only_bank_transfer' => false,
            'only_wallet'        => false,
            'only_invoices'      => false,
            'is_valid_receipt'   => false,
        ];
    }

    /**
     * @return list<int>
     */
    private function listedFor(?int $viewerId): array
    {
        $this->dropReportTables();

        return array_map(static fn (object $row): int => (int) $row->sale_id, model(Sale::class)->search('', $this->listFilters(), 500, 0, 'sales.sale_id', 'asc', false, $viewerId)->getResult());
    }

    private function statusOfSale(int $saleId): int
    {
        return (int) Database::connect()->table('sales')->select('sale_status')->where('sale_id', $saleId)->get()->getRow()->sale_status;
    }

    public function testSearch_ListsOnlyTheSalesOfAnEmployeeWhoSeesTheirOwnRecords(): void
    {
        $own      = $this->ownRecordsEmployee();
        $other    = $this->createEmployee();
        $mine     = [$this->saleOfEmployee($own), $this->saleOfEmployee($own)];
        $theirs   = $this->saleOfEmployee($other);
        $everyone = $this->createEmployee();

        $this->assertSame($mine, $this->listedFor($own));
        $this->assertSame(2, model(Sale::class)->get_found_rows('', $this->listFilters(), $own));

        $seen = $this->listedFor($everyone);

        $this->assertContains($theirs, $seen);
        $this->assertContains($mine[0], $seen);
        $this->assertContains($mine[1], $seen);
    }

    public function testSearch_ListsEverySaleForTheBooksWithoutAnEmployee(): void
    {
        $own   = $this->ownRecordsEmployee();
        $mine  = $this->saleOfEmployee($own);
        $other = $this->saleOfEmployee($this->createEmployee());

        unset($_SESSION['person_id']);

        $seen = $this->listedFor(null);

        $this->assertContains($mine, $seen);
        $this->assertContains($other, $seen);
    }

    public function testSearch_CutsTheListToTheEmployeeOfTheSessionWhenNoViewerIsGiven(): void
    {
        $own   = $this->ownRecordsEmployee();
        $mine  = $this->saleOfEmployee($own);
        $other = $this->saleOfEmployee($this->createEmployee());

        $_SESSION['person_id'] = $own;

        $seen = $this->listedFor(null);

        $this->assertSame([$mine], $seen);
        $this->assertNotContains($other, $seen);
    }

    public function testGetPaymentsSummary_AddsUpOnlyThePaymentsOfTheSalesAnOwnRecordsEmployeeMade(): void
    {
        $own = $this->ownRecordsEmployee();

        $this->saleOfEmployee($own);
        $this->saleOfEmployee($this->createEmployee());

        $summary = model(Sale::class)->getPaymentsSummary('', $this->listFilters(), $own);

        $this->assertSame(1, array_sum(array_column($summary, 'count')));
        $this->assertEquals(10.00, array_sum(array_column($summary, 'payment_amount')));
    }

    public function testGetInfo_ReturnsNothingForAnotherEmployeesSaleAndThePlainRowForTheirOwnAndForAnEmployeeWhoSeesEverything(): void
    {
        $own    = $this->ownRecordsEmployee();
        $other  = $this->createEmployee();
        $mine   = $this->saleOfEmployee($own);
        $theirs = $this->saleOfEmployee($other);
        $sale   = model(Sale::class);

        $this->assertSame(1, $sale->get_info($mine, $own)->getNumRows());
        $this->assertSame(0, $sale->get_info($theirs, $own)->getNumRows(), 'Another employee sale reads as one that does not exist');
        $this->assertSame(0, $sale->get_info(999999, $own)->getNumRows());
        $this->assertSame(1, $sale->get_info($theirs, $this->createEmployee())->getNumRows());
        $this->assertSame(1, $sale->get_info($theirs, $other)->getNumRows());
    }

    public function testGetInfo_ReadsTheEmployeeOfTheSessionWhenNoViewerIsGiven(): void
    {
        $own    = $this->ownRecordsEmployee();
        $theirs = $this->saleOfEmployee($this->createEmployee());

        $_SESSION['person_id'] = $own;

        $this->assertSame(0, model(Sale::class)->get_info($theirs)->getNumRows());

        unset($_SESSION['person_id']);

        $this->assertSame(1, model(Sale::class)->get_info($theirs)->getNumRows());
    }

    public function testUpdate_RefusesAnotherEmployeesSaleAndChangesNothing(): void
    {
        $own    = $this->ownRecordsEmployee();
        $theirs = $this->saleOfEmployee($this->createEmployee());
        $sale   = model(Sale::class);

        $this->assertFalse($sale->update($theirs, ['comment' => 'changed by a stranger', 'changed_by' => $own]));
        $this->assertSame('own scope', Database::connect()->table('sales')->select('comment')->where('sale_id', $theirs)->get()->getRow()->comment);
        $this->assertNull($sale->accountingFailure(), 'The refusal is not told apart from one for a sale that does not exist');
        $this->assertFalse($sale->update(999999, ['comment' => 'nothing', 'changed_by' => $own]));
    }

    public function testUpdate_ChangesTheSaleOfTheOwnRecordsEmployeeAndOfAnEmployeeWhoSeesEverything(): void
    {
        $own   = $this->ownRecordsEmployee();
        $mine  = $this->saleOfEmployee($own);
        $sale  = model(Sale::class);
        $other = $this->saleOfEmployee($this->createEmployee());

        $this->assertTrue($sale->update($mine, ['comment' => 'mine, changed', 'changed_by' => $own]));
        $this->assertTrue($sale->update($other, ['comment' => 'theirs, changed by an employee who sees everything', 'changed_by' => $this->createEmployee()]));
        $this->assertSame('mine, changed', Database::connect()->table('sales')->select('comment')->where('sale_id', $mine)->get()->getRow()->comment);
    }

    public function testDelete_RefusesAnotherEmployeesSaleLikeOneThatDoesNotExist(): void
    {
        $own    = $this->ownRecordsEmployee();
        $theirs = $this->saleOfEmployee($this->createEmployee());
        $sale   = model(Sale::class);

        $this->assertFalse($sale->delete($theirs, false, true, $own));
        $this->assertSame(COMPLETED, $this->statusOfSale($theirs));
        $this->assertNull($sale->accountingFailure());
        $this->assertFalse($sale->delete(999999, false, true, $own));
    }

    public function testDelete_CancelsTheOwnSaleOfAnOwnRecordsEmployeeAndAnyOneForAnEmployeeWhoSeesEverything(): void
    {
        $own    = $this->ownRecordsEmployee();
        $mine   = $this->saleOfEmployee($own);
        $theirs = $this->saleOfEmployee($this->createEmployee());
        $sale   = model(Sale::class);

        $this->assertTrue($sale->delete($mine, false, true, $own));
        $this->assertSame(CANCELED, $this->statusOfSale($mine));
        $this->assertTrue($sale->delete($theirs, false, true, $this->createEmployee()));
        $this->assertSame(CANCELED, $this->statusOfSale($theirs));
    }

    public function testDeleteList_RefusesTheWholeListWhenOneSaleIsAnotherEmployees(): void
    {
        $own    = $this->ownRecordsEmployee();
        $mine   = $this->saleOfEmployee($own);
        $theirs = $this->saleOfEmployee($this->createEmployee());

        $this->assertFalse(model(Sale::class)->delete_list([$mine, $theirs], $own));
        $this->assertSame(COMPLETED, $this->statusOfSale($mine), 'Nothing of a refused list is cancelled');
        $this->assertSame(COMPLETED, $this->statusOfSale($theirs));
    }

    public function testRestoreList_RefusesTheWholeListWhenOneSaleIsAnotherEmployees(): void
    {
        $own    = $this->ownRecordsEmployee();
        $mine   = $this->saleOfEmployee($own);
        $theirs = $this->saleOfEmployee($this->createEmployee());

        $this->assertFalse(model(Sale::class)->restore_list([$mine, $theirs], $own));
        $this->assertSame(COMPLETED, $this->statusOfSale($mine));
        $this->assertTrue(model(Sale::class)->restore_list([$mine], $own));
        $this->assertSame(SUSPENDED, $this->statusOfSale($mine));
    }

    public function testConfirmDelivery_RefusesAnotherEmployeesInvoice(): void
    {
        $own    = $this->ownRecordsEmployee();
        $theirs = $this->saleOfEmployee($this->createEmployee(), COMPLETED, SALE_TYPE_INVOICE);

        $this->assertSame(NEW_ENTRY, model(Sale::class)->confirm_delivery($theirs, $own));
    }

    public function testSaveValue_RefusesToCompleteAnotherEmployeesSuspendedSale(): void
    {
        $own       = $this->ownRecordsEmployee();
        $suspended = $this->saleOfEmployee($this->createEmployee(), SUSPENDED);
        $itemId    = $this->createTestItem(HAS_NO_STOCK);
        $items     = $this->buildCartLine($itemId, 1, 10.00);
        $taxes     = [[], []];
        $status    = COMPLETED;

        $this->assertSame(NEW_ENTRY, model(Sale::class)->save_value($suspended, $status, $items, NEW_ENTRY, $own, 'taken over', null, null, null, SALE_TYPE_POS, [$this->cashPayment()], null, $taxes, true));
        $this->assertSame(SUSPENDED, $this->statusOfSale($suspended));
    }

    public function testGetAllSuspended_ListsOnlyTheSuspendedSalesOfAnOwnRecordsEmployee(): void
    {
        $own    = $this->ownRecordsEmployee();
        $mine   = $this->saleOfEmployee($own, SUSPENDED);
        $theirs = $this->saleOfEmployee($this->createEmployee(), SUSPENDED);
        $sale   = model(Sale::class);
        $listed = array_map('intval', array_column($sale->get_all_suspended(NEW_ENTRY, $own), 'sale_id'));

        $this->assertSame([$mine], $listed);
        $this->assertContains($theirs, array_map('intval', array_column($sale->get_all_suspended(NEW_ENTRY, $this->createEmployee()), 'sale_id')));
    }

    public function testGetSuspendedSaleInfo_ReturnsNothingForAnotherEmployeesSuspendedSale(): void
    {
        $own    = $this->ownRecordsEmployee();
        $theirs = $this->saleOfEmployee($this->createEmployee(), SUSPENDED);
        $mine   = $this->saleOfEmployee($own, SUSPENDED);
        $sale   = model(Sale::class);

        $this->assertSame(0, $sale->get_suspended_sale_info($theirs, $own)->getNumRows());
        $this->assertSame(1, $sale->get_suspended_sale_info($mine, $own)->getNumRows());
        $this->assertSame(1, $sale->get_suspended_sale_info($theirs, $this->createEmployee())->getNumRows());
    }

    public function testSaveSalesItemsTaxes_KeepsTheVatClassOfTheLineUntilTheSuspendedSaleIsCleared(): void
    {
        $db     = Database::connect();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $vat    = new LineVat(null, 'Z', '0.000', 'VATEX-SA-32', 'Export of goods', 'VAT');
        $taxes  = [[], [['item_id' => $itemId, 'line' => 0, 'name' => 'VAT', 'percent' => '0.000', 'tax_type' => 1, 'rounding_code' => 1, 'cascade_sequence' => 0, 'item_tax_amount' => '0.00', 'sales_tax_code_id' => null, 'tax_category_id' => null, 'jurisdiction_id' => null, 'vat' => $vat]]];
        $status = SUSPENDED;
        $items  = $this->buildCartLine($itemId, 1, 10.00);
        $saleId = model(Sale::class)->save_value(NEW_ENTRY, $status, $items, NEW_ENTRY, $this->createEmployee(), 'vat class', null, null, null, SALE_TYPE_POS, [], null, $taxes, true);

        try {
            $row = $db->table('sales_items_vat')->where('sale_id', $saleId)->get()->getRow();

            $this->assertSame(['Z', '0.000', 'VATEX-SA-32'], [$row->category, $row->percent, $row->exemption_code]);
            $this->assertTrue(model(Sale::class)->clear_suspended_sale_detail($saleId));
            $this->assertSame(0, $db->table('sales_items_vat')->where('sale_id', $saleId)->countAllResults());
        } finally {
            $db->table('sales_items_vat')->where('sale_id', $saleId)->delete();
        }
    }

    public function testDeleteSuspendedSale_RefusesAnotherEmployeesSuspendedSaleForTheEmployeeOfTheSession(): void
    {
        $own    = $this->ownRecordsEmployee();
        $theirs = $this->saleOfEmployee($this->createEmployee(), SUSPENDED);
        $sale   = model(Sale::class);

        $_SESSION['person_id'] = $own;

        $this->assertFalse($sale->delete_suspended_sale($theirs));
        $this->assertFalse($sale->clear_suspended_sale_detail($theirs));
        $this->assertSame(SUSPENDED, $this->statusOfSale($theirs));
        $this->assertSame(1, Database::connect()->table('sales_items')->where('sale_id', $theirs)->countAllResults(), 'The lines of the sale are still there');
    }

    public function testIssueDraft_RefusesAnotherEmployeesDraftAsIfItWereNoDraft(): void
    {
        $own    = $this->ownRecordsEmployee();
        $theirs = $this->saleOfEmployee($this->createEmployee(), SUSPENDED, SALE_TYPE_INVOICE);
        $sale   = model(Sale::class);

        $this->assertSame(ACCOUNTING_FAILED, $sale->issueDraft($theirs, $own));
        $this->assertSame(AccountingError::DraftNotFound, $sale->accountingFailure()->error);
        $this->assertSame(SUSPENDED, $this->statusOfSale($theirs));
    }

    public function testGetDocumentHeader_ReturnsNothingForAnotherEmployeesDraftOrQuote(): void
    {
        $own    = $this->ownRecordsEmployee();
        $theirs = $this->saleOfEmployee($this->createEmployee(), SUSPENDED, SALE_TYPE_QUOTE);
        $sale   = model(Sale::class);

        $this->assertNull($sale->getDocumentHeader($theirs, $own));
        $this->assertNotNull($sale->getDocumentHeader($theirs, $this->createEmployee()));
    }

    public function testSaveValue_RefusesAReturnOfAnotherEmployeesSaleForAnOwnRecordsEmployeeOnly(): void
    {
        $own    = $this->ownRecordsEmployee();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $theirs = $this->invoiceOfEmployee($this->createEmployee(), $itemId);
        $mine   = $this->invoiceOfEmployee($own, $itemId);
        $before = $this->countSalesRows();

        $this->assertSame(NEW_ENTRY, $this->saveCreditNote($theirs, $itemId, 1, $own));
        $this->assertSame($before, $this->countSalesRows(), 'Nothing was stored for the return');
        $this->assertGreaterThan(0, $this->saveCreditNote($mine, $itemId, 1, $own));
        $this->assertGreaterThan(0, $this->saveCreditNote($theirs, $itemId, 1, $this->createEmployee()));
    }

    public function testIsValidReceipt_AcceptsTheSaleOfAnOwnRecordsEmployeeAndRejectsAnotherEmployeesLikeOneThatDoesNotExist(): void
    {
        $own       = $this->ownRecordsEmployee();
        $itemId    = $this->createTestItem(HAS_NO_STOCK);
        $mine      = $this->invoiceOfEmployee($own, $itemId);
        $theirs    = $this->invoiceOfEmployee($this->createEmployee(), $itemId);
        $sale      = model(Sale::class);
        $settings  = config(OSPOS::class)->settings;

        $_SESSION['person_id'] = $own;

        try {
            $receipt = 'POS ' . $mine;
            $this->assertTrue($sale->isValidReceipt($receipt));

            $receipt = 'POS ' . $theirs;
            $this->assertFalse($sale->isValidReceipt($receipt));

            $receipt = 'POS 999999';
            $this->assertFalse($sale->isValidReceipt($receipt));

            $_SESSION['person_id'] = $this->createEmployee();

            $receipt = 'POS ' . $theirs;
            $this->assertTrue($sale->isValidReceipt($receipt), 'Whoever sees everything finds the sale');
        } finally {
            unset($_SESSION['person_id']);
            config(OSPOS::class)->settings = $settings;
        }
    }

    public function testIsValidReceipt_FindsAnInvoiceNumberOnlyAmongTheSalesAnOwnRecordsEmployeeMade(): void
    {
        $own      = $this->ownRecordsEmployee();
        $itemId   = $this->createTestItem(HAS_NO_STOCK);
        $mineNo   = $this->freshPrefix() . 'A';
        $theirsNo = $this->freshPrefix() . 'B';
        $mine     = $this->invoiceOfEmployee($own, $itemId, $mineNo);
        $theirs   = $this->invoiceOfEmployee($this->createEmployee(), $itemId, $theirsNo);
        $sale     = model(Sale::class);
        $settings = config(OSPOS::class)->settings;

        config(OSPOS::class)->settings['invoice_enable'] = 1;
        $_SESSION['person_id']                           = $own;

        try {
            $term = $mineNo;
            $this->assertTrue($sale->isValidReceipt($term));
            $this->assertSame('POS ' . $mine, $term);

            $term = $theirsNo;
            $this->assertFalse($sale->isValidReceipt($term));
            $this->assertSame($theirsNo, $term, 'The id of the other employee\'s sale is not given away');

            $_SESSION['person_id'] = $this->createEmployee();

            $term = $theirsNo;
            $this->assertTrue($sale->isValidReceipt($term));
            $this->assertSame('POS ' . $theirs, $term);
        } finally {
            unset($_SESSION['person_id']);
            config(OSPOS::class)->settings = $settings;
        }
    }

    public function testGetAllSuspended_ListsOnlyTheSuspendedSalesOfAnOwnRecordsEmployeeForTheSelectedCustomerToo(): void
    {
        $own      = $this->ownRecordsEmployee();
        $customer = $this->createCustomerWithPoints(0);
        $mine     = $this->suspendedSaleOfCustomer($own, $customer);
        $theirs   = $this->suspendedSaleOfCustomer($this->createEmployee(), $customer);
        $sale     = model(Sale::class);

        $this->assertSame([$mine], array_map('intval', array_column($sale->get_all_suspended($customer, $own), 'sale_id')));
        $this->assertEqualsCanonicalizing([$mine, $theirs], array_map('intval', array_column($sale->get_all_suspended($customer, $this->createEmployee()), 'sale_id')));
    }

    private function withTheClockOfTheDatabaseInAnotherZone(callable $action): int
    {
        $db       = Database::connect();
        $previous = (string) $db->query('SELECT @@session.time_zone AS zone')->getRow()->zone;

        $db->query("SET time_zone = '+13:00'");

        try {
            return $action();
        } finally {
            $db->query('SET time_zone = ' . $db->escape($previous));
        }
    }

    private function secondsBetweenNowAndThePaymentStamp(int $paymentId): int
    {
        $stamp = (string) Database::connect()->table('sales_payments')->select('payment_time')->where('payment_id', $paymentId)->get()->getRow()->payment_time;

        return abs(time() - (int) strtotime($stamp));
    }

    public function testSaveValue_StampsThePaymentWithTheClockOfTheApplicationAndNotTheClockOfTheDatabase(): void
    {
        $seconds = $this->withTheClockOfTheDatabaseInAnotherZone(function (): int {
            $saleId      = $this->saveSale(SALE_TYPE_POS, [$this->cashPayment(4.00)]);
            [$paymentId] = $this->paymentIdsOf($saleId);

            return $this->secondsBetweenNowAndThePaymentStamp($paymentId);
        });

        $this->assertLessThan(120, $seconds, 'The day of a payment is the day the shop saved it on, whatever zone the database server keeps');
    }

    public function testUpdate_StampsAPaymentAddedToACompletedSaleWithTheClockOfTheApplicationAndNotTheClockOfTheDatabase(): void
    {
        $saleId   = $this->saveSale(SALE_TYPE_POS, [$this->cashPayment(4.00)]);
        $employee = $this->createEmployee();
        $model    = model(Sale::class);

        $seconds = $this->withTheClockOfTheDatabaseInAnotherZone(function () use ($model, $saleId, $employee): int {
            $this->assertTrue($model->update($saleId, ['comment' => 'more', 'payments' => [
                ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.debit'), 'payment_amount' => '6.00', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $employee, 'reference_code' => null],
            ]]));

            [, $added] = $this->paymentIdsOf($saleId);

            return $this->secondsBetweenNowAndThePaymentStamp($added);
        });

        $this->assertLessThan(120, $seconds);
    }

    public function testOutsideRecordScope_TreatsASaleThatDoesNotExistLikeAnotherEmployeesForAnOwnRecordsEmployeeOnly(): void
    {
        $own   = $this->ownRecordsEmployee();
        $mine  = $this->saleOfEmployee($own);
        $sale  = model(Sale::class);

        $this->assertFalse($sale->outsideRecordScope($mine, $own));
        $this->assertTrue($sale->outsideRecordScope(999999, $own));
        $this->assertFalse($sale->outsideRecordScope(999999, $this->createEmployee()), 'Whoever sees everything is told nothing new');
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentWith(string $type, float $amount): array
    {
        return ['payment_type' => $type, 'payment_amount' => $amount, 'cash_refund' => 0, 'cash_adjustment' => 0, 'reference_code' => null];
    }

    /**
     * @param list<array<string, mixed>> $payments
     */
    private function holdOrComplete(int $status, array $payments, int $customerId = NEW_ENTRY, int $saleId = NEW_ENTRY): int
    {
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $items  = $this->buildCartLine($itemId, 1, 100.00);
        $taxes  = [[], []];

        return model(Sale::class)->save_value($saleId, $status, $items, $customerId, $this->createEmployee(), 'held', null, null, null, SALE_TYPE_POS, $payments, null, $taxes);
    }

    public function testSaveValue_DeductsAGiftCardOnceWhenTheSuspendedSaleItWasHeldForIsCompleted(): void
    {
        $card = $this->createGiftcard(100.00);
        $pay  = $this->paymentWith(lang('Sales.giftcard') . ':' . $card, 40.00);

        $saleId = $this->holdOrComplete(SUSPENDED, [$pay]);

        $this->assertGreaterThan(0, $saleId);
        $this->assertEqualsWithDelta(60.00, $this->getGiftcardValue($card), 0.001);

        $this->assertSame($saleId, $this->holdOrComplete(COMPLETED, [$pay], NEW_ENTRY, $saleId));
        $this->assertEqualsWithDelta(60.00, $this->getGiftcardValue($card), 0.001, 'the card is debited by the sale once and not by the hold and the completion');
    }

    public function testSaveValue_KeepsTheGiftCardDebitedOnceWhileTheSaleIsHeldAgainAndAgain(): void
    {
        $card = $this->createGiftcard(100.00);
        $pay  = $this->paymentWith(lang('Sales.giftcard') . ':' . $card, 40.00);

        $saleId = $this->holdOrComplete(SUSPENDED, [$pay]);
        $this->holdOrComplete(SUSPENDED, [$pay], NEW_ENTRY, $saleId);
        $this->holdOrComplete(SUSPENDED, [$pay], NEW_ENTRY, $saleId);

        $this->assertEqualsWithDelta(60.00, $this->getGiftcardValue($card), 0.001);
    }

    public function testSaveValue_GivesTheGiftCardBackWhenTheHeldSaleIsSavedAgainWithoutIt(): void
    {
        $card = $this->createGiftcard(100.00);

        $saleId = $this->holdOrComplete(SUSPENDED, [$this->paymentWith(lang('Sales.giftcard') . ':' . $card, 40.00)]);
        $this->holdOrComplete(SUSPENDED, [$this->cashPayment(100.00)], NEW_ENTRY, $saleId);

        $this->assertEqualsWithDelta(100.00, $this->getGiftcardValue($card), 0.001);
    }

    public function testSaveValue_GivesBackTheCardOfAHeldSaleWhateverLanguageItsNameWasStoredIn(): void
    {
        $card = $this->createGiftcard(100.00);

        $saleId = $this->holdOrComplete(SUSPENDED, [$this->paymentWith('بطاقة هدية:' . $card, 40.00)]);

        $this->assertEqualsWithDelta(60.00, $this->getGiftcardValue($card), 0.001);

        $this->holdOrComplete(COMPLETED, [$this->paymentWith('Gift Card:' . $card, 40.00)], NEW_ENTRY, $saleId);

        $this->assertEqualsWithDelta(60.00, $this->getGiftcardValue($card), 0.001);
    }

    public function testSaveValue_DeductsRewardPointsOnceWhenTheSuspendedSaleItWasHeldForIsCompleted(): void
    {
        $customer = $this->createCustomerWithPoints(200);
        $pay      = $this->paymentWith(lang('Sales.rewards'), 50.00);

        $saleId = $this->holdOrComplete(SUSPENDED, [$pay], $customer);

        $this->assertSame(150, $this->getCustomerPoints($customer));

        $this->assertSame($saleId, $this->holdOrComplete(COMPLETED, [$pay], $customer, $saleId));
        $this->assertSame(150, $this->getCustomerPoints($customer), 'the points are spent by the sale once');
    }

    public function testSaveValue_EarnsThePointsOfASaleWhenItIsCompletedAndNotWhenItIsHeld(): void
    {
        $db       = Database::connect();
        $before   = config(OSPOS::class)->settings['customer_reward_enable'] ?? null;
        $customer = $this->createCustomerWithPoints(200);

        $db->table('customers_packages')->insert(['package_name' => 'Held', 'points_percent' => '10', 'deleted' => 0]);

        $packageId = (int) $db->insertID();

        $db->table('customers')->where('person_id', $customer)->update(['package_id' => $packageId]);
        config(OSPOS::class)->settings['customer_reward_enable'] = '1';

        try {
            $saleId = $this->holdOrComplete(SUSPENDED, [$this->cashPayment(100.00), $this->paymentWith(lang('Sales.rewards'), 50.00)], $customer);

            $this->assertSame(150, $this->getCustomerPoints($customer), 'nothing is earned by a sale that is only held');

            $this->holdOrComplete(COMPLETED, [$this->cashPayment(100.00), $this->paymentWith(lang('Sales.rewards'), 50.00)], $customer, $saleId);

            $this->assertSame(165, $this->getCustomerPoints($customer), '50 spent and 10% of the 150 paid earned, once');
            $this->assertSame(1, $db->table('sales_reward_points')->where('sale_id', $saleId)->countAllResults());
        } finally {
            config(OSPOS::class)->settings['customer_reward_enable'] = $before;
            $db->table('customers')->where('person_id', $customer)->update(['package_id' => null]);
            $db->table('customers_packages')->where('package_id', $packageId)->delete();
        }
    }

    public function testSaveValue_TakesBackThePointsAHeldSaleEarnedBeforeTheFixWhenItIsCompleted(): void
    {
        $db       = Database::connect();
        $before   = config(OSPOS::class)->settings['customer_reward_enable'] ?? null;
        $customer = $this->createCustomerWithPoints(200);

        $db->table('customers_packages')->insert(['package_name' => 'Legacy', 'points_percent' => '10', 'deleted' => 0]);

        $packageId = (int) $db->insertID();

        $db->table('customers')->where('person_id', $customer)->update(['package_id' => $packageId]);
        config(OSPOS::class)->settings['customer_reward_enable'] = '1';

        try {
            $saleId = $this->holdOrComplete(SUSPENDED, [$this->cashPayment(100.00)], $customer);

            $db->table('sales_reward_points')->insert(['sale_id' => $saleId, 'earned' => 10, 'used' => 0]);
            $db->table('customers')->where('person_id', $customer)->update(['points' => 210]);

            $this->holdOrComplete(COMPLETED, [$this->cashPayment(100.00)], $customer, $saleId);

            $this->assertSame(210, $this->getCustomerPoints($customer), 'the 10 points the hold earned are taken back before the completion earns them');
            $this->assertSame(1, $db->table('sales_reward_points')->where('sale_id', $saleId)->countAllResults());
        } finally {
            config(OSPOS::class)->settings['customer_reward_enable'] = $before;
            $db->table('customers')->where('person_id', $customer)->update(['package_id' => null]);
            $db->table('customers_packages')->where('package_id', $packageId)->delete();
        }
    }

    public function testSaveValue_LeavesTheHeldSaleAndItsCardAsTheyWereWhenTheCompletionIsRefused(): void
    {
        $card = $this->createGiftcard(100.00);

        $saleId = $this->holdOrComplete(SUSPENDED, [$this->paymentWith(lang('Sales.giftcard') . ':' . $card, 40.00)]);

        $this->assertSame(INSUFFICIENT_GIFTCARD_BALANCE, $this->holdOrComplete(COMPLETED, [$this->paymentWith(lang('Sales.giftcard') . ':' . $card, 150.00)], NEW_ENTRY, $saleId));
        $this->assertEqualsWithDelta(60.00, $this->getGiftcardValue($card), 0.001);
        $this->assertSame(SUSPENDED, (int) model(Sale::class)->getSaleStatus($saleId));
        $this->assertSame(1, Database::connect()->table('sales_payments')->where('sale_id', $saleId)->countAllResults());
    }

    public function testDelete_GivesBackTheGiftCardAndThePointsOfASaleThatWasOnlyHeld(): void
    {
        $card     = $this->createGiftcard(100.00);
        $customer = $this->createCustomerWithPoints(200);
        $employee = $this->createEmployee();

        $saleId = $this->holdOrComplete(SUSPENDED, [$this->paymentWith(lang('Sales.giftcard') . ':' . $card, 40.00), $this->paymentWith(lang('Sales.rewards'), 50.00), $this->cashPayment(10.00)], $customer);

        $this->assertEqualsWithDelta(60.00, $this->getGiftcardValue($card), 0.001);
        $this->assertSame(150, $this->getCustomerPoints($customer));

        $this->assertTrue(model(Sale::class)->delete($saleId, false, true, $employee));

        $this->assertEqualsWithDelta(100.00, $this->getGiftcardValue($card), 0.001);
        $this->assertSame(200, $this->getCustomerPoints($customer));
        $this->assertSame(CANCELED, (int) model(Sale::class)->getSaleStatus($saleId));
        $this->assertSame(1, Database::connect()->table('sales_payments')->where('sale_id', $saleId)->countAllResults(), 'only the cash stays on the record of the cancelled sale');

        $this->assertTrue(model(Sale::class)->delete($saleId, false, true, $employee));
        $this->assertEqualsWithDelta(100.00, $this->getGiftcardValue($card), 0.001, 'cancelling a cancelled sale gives nothing back a second time');
        $this->assertSame(200, $this->getCustomerPoints($customer));
    }

    public function testDeleteSuspendedSale_GivesBackWhatTheDiscardedSaleHeld(): void
    {
        $card     = $this->createGiftcard(100.00);
        $customer = $this->createCustomerWithPoints(200);

        $saleId = $this->holdOrComplete(SUSPENDED, [$this->paymentWith(lang('Sales.giftcard') . ':' . $card, 40.00), $this->paymentWith(lang('Sales.rewards'), 50.00)], $customer);

        $this->assertTrue(model(Sale::class)->delete_suspended_sale($saleId));
        $this->assertEqualsWithDelta(100.00, $this->getGiftcardValue($card), 0.001);
        $this->assertSame(200, $this->getCustomerPoints($customer));

        $this->assertTrue(model(Sale::class)->delete_suspended_sale($saleId));
        $this->assertEqualsWithDelta(100.00, $this->getGiftcardValue($card), 0.001);
        $this->assertSame(200, $this->getCustomerPoints($customer));
    }

    public function testSaveValue_DeductsTheGiftCardOnceWhenTwoRequestsCompleteTheSameSuspendedSale(): void
    {
        $card     = $this->createGiftcard(100.00);
        $customer = $this->createCustomerWithPoints(0);
        $employee = $this->createEmployee();
        $itemId   = $this->createTestItem(HAS_NO_STOCK);
        $saleId   = $this->holdOrComplete(SUSPENDED, [$this->paymentWith(lang('Sales.giftcard') . ':' . $card, 40.00)], $customer);
        $label    = lang('Sales.giftcard') . ':' . $card;

        [$first, $second] = $this->raceTwoProcesses(
            'completeSuspended',
            [$saleId, $itemId, $employee, $label, 40, $customer],
            [$saleId, $itemId, $employee, $label, 40, $customer],
        );

        $this->assertSame(1, (int) $first + (int) $second, 'one completion wins and the other is refused');
        $this->assertSame(COMPLETED, (int) model(Sale::class)->getSaleStatus($saleId));
        $this->assertEqualsWithDelta(60.00, $this->getGiftcardValue($card), 0.001);
    }

    /**
     * A sale of 10.00 paid with the cash given in full and the change handed back.
     */
    private function saleWithChange(string $paid = '20.00', string $change = '10.00'): int
    {
        $saleId = $this->saveSale(SALE_TYPE_POS, [['payment_type' => lang('Sales.cash'), 'payment_amount' => $paid, 'cash_refund' => $change, 'cash_adjustment' => 0, 'reference_code' => null]]);

        $this->assertGreaterThan(0, $saleId);

        return $saleId;
    }

    /**
     * @return array<string, mixed>
     */
    private function editedPayment(int $saleId, array $changes): array
    {
        $row = Database::connect()->table('sales_payments')->where('sale_id', $saleId)->get()->getRow();

        return ['payment_id' => (int) $row->payment_id, 'payment_type' => $row->payment_type, 'payment_amount' => $row->payment_amount, 'cash_refund' => $row->cash_refund, 'cash_adjustment' => 0, 'employee_id' => $this->createEmployee(), 'reference_code' => null, ...$changes];
    }

    private function storedPayment(int $saleId): object
    {
        return Database::connect()->table('sales_payments')->where('sale_id', $saleId)->get()->getRow();
    }

    public function testUpdate_RejectsAPaymentEditThatLeavesTheAmountBelowTheChangeAlreadyGiven(): void
    {
        $saleId = $this->saleWithChange();
        $model  = model(Sale::class);

        $this->assertFalse($model->update($saleId, ['comment' => 'edited', 'payments' => [$this->editedPayment($saleId, ['payment_amount' => '8.30'])]]));
        $this->assertSame(AccountingError::SalePaymentBelowChange, $model->accountingFailure()->error);
        $this->assertSame('20.00', bcadd((string) $this->storedPayment($saleId)->payment_amount, '0', 2));
    }

    public function testUpdate_RejectsAPaymentEditThatLeavesTheChangeLargerThanWhatWasPaidAboveTheTotal(): void
    {
        $saleId = $this->saleWithChange();
        $model  = model(Sale::class);

        $this->assertFalse($model->update($saleId, ['comment' => 'edited', 'payments' => [$this->editedPayment($saleId, ['payment_amount' => '15.00'])]]));
        $this->assertSame(AccountingError::SalePaymentBelowChange, $model->accountingFailure()->error);
        $this->assertSame('20.00', bcadd((string) $this->storedPayment($saleId)->payment_amount, '0', 2));
    }

    public function testUpdate_RejectsRemovingThePaymentThatLeftTheChangeUncoveredWhileAnotherKeepsIt(): void
    {
        $saleId = $this->saveSale(SALE_TYPE_POS, [
            ['payment_type' => lang('Sales.debit'), 'payment_amount' => '10.00', 'cash_refund' => 0, 'cash_adjustment' => 0, 'reference_code' => 'ABC123'],
            ['payment_type' => lang('Sales.cash'), 'payment_amount' => '20.00', 'cash_refund' => '20.00', 'cash_adjustment' => 0, 'reference_code' => null],
        ]);
        $model = model(Sale::class);
        $card  = Database::connect()->table('sales_payments')->where('sale_id', $saleId)->like('payment_type', lang('Sales.debit'))->get()->getRow();

        $this->assertFalse($model->update($saleId, ['comment' => 'edited', 'payments' => [['payment_id' => (int) $card->payment_id, 'payment_type' => $card->payment_type, 'payment_amount' => '0', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->createEmployee(), 'reference_code' => null]]]));
        $this->assertSame(AccountingError::SalePaymentBelowChange, $model->accountingFailure()->error);
        $this->assertSame(2, Database::connect()->table('sales_payments')->where('sale_id', $saleId)->countAllResults());
    }

    public function testUpdate_AcceptsAPaymentEditThatKeepsTheChangeCovered(): void
    {
        $saleId = $this->saleWithChange();

        $this->assertTrue(model(Sale::class)->update($saleId, ['comment' => 'edited', 'payments' => [$this->editedPayment($saleId, ['payment_amount' => '18.00', 'cash_refund' => '8.00'])]]));
        $this->assertSame('18.00', bcadd((string) $this->storedPayment($saleId)->payment_amount, '0', 2));
    }

    public function testUpdate_AcceptsAChangeOfTheTypeAloneWhateverTheAmountsOfAnOldSaleAre(): void
    {
        $saleId = $this->saleWithChange('12.00', '10.00');

        $this->assertTrue(model(Sale::class)->update($saleId, ['comment' => 'edited', 'payments' => [$this->editedPayment($saleId, ['payment_type' => lang('Sales.debit')])]]));
        $this->assertSame(lang('Sales.debit'), $this->storedPayment($saleId)->payment_type);
    }

    public function testUpdate_AcceptsANewNegativePaymentThatRefundsTheCustomerWithoutAnyChange(): void
    {
        $saleId = $this->saveSale(SALE_TYPE_POS, [$this->cashPayment(10.00)]);

        $this->assertTrue(model(Sale::class)->update($saleId, ['comment' => 'refund', 'payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.debit'), 'payment_amount' => '-3.00', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->createEmployee(), 'reference_code' => 'ABC123'],
        ]]));
        $this->assertSame(2, Database::connect()->table('sales_payments')->where('sale_id', $saleId)->countAllResults());
    }

    public function testUpdate_AcceptsAPaymentLoweredOnASaleThatGaveNoChange(): void
    {
        $saleId = $this->saveSale(SALE_TYPE_POS, [$this->cashPayment(10.00)]);

        $this->assertTrue(model(Sale::class)->update($saleId, ['comment' => 'edited', 'payments' => [$this->editedPayment($saleId, ['payment_amount' => '6.00'])]]));
        $this->assertSame('6.00', bcadd((string) $this->storedPayment($saleId)->payment_amount, '0', 2));
    }
}
