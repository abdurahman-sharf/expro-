<?php

namespace Tests\Libraries\MasterData;

use App\Libraries\Accounting\QuoteStatus;
use App\Libraries\MasterData\ItemOpenDocuments;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ItemFixtureTrait;

/**
 * @internal
 */
final class ItemOpenDocumentsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ItemFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    /** @var list<int> */
    private array $itemIds = [];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        $db = $this->accountingDb();

        foreach (['purchase_order_receiving_lines', 'purchase_order_receivings', 'purchase_order_lines', 'purchase_orders', 'supplier_quotation_lines', 'supplier_quotations', 'quotation_request_lines', 'quotation_requests', 'purchase_request_lines', 'purchase_requests', 'stock_request_lines', 'stock_requests', 'stocktake_lines', 'stocktakes', 'recurring_schedule_lines', 'recurring_schedules', 'sales_order_invoice_lines', 'sales_order_invoices', 'sales_order_lines', 'sales_orders', 'sales_items', 'sales'] as $table) {
            $db->query('DELETE FROM ' . $db->prefixTable($table));
        }

        $db->table('receivings')->where('comment', 'received')->delete();

        foreach ($this->itemIds as $itemId) {
            $db->table('item_quantities')->where('item_id', $itemId)->delete();
            $db->table('items')->where('item_id', $itemId)->delete();
        }

        $this->itemIds = [];
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function item(): int
    {
        return $this->itemIds[] = $this->createTestItem();
    }

    private function documents(): ItemOpenDocuments
    {
        return new ItemOpenDocuments();
    }

    private function saleNaming(int $itemId, int $status, int $type = SALE_TYPE_INVOICE): int
    {
        $db = $this->accountingDb();

        $db->table('sales')->insert(['sale_status' => $status, 'sale_type' => $type, 'employee_id' => 1, 'comment' => '']);
        $saleId = (int) $db->insertID();
        $db->table('sales_items')->insert(['sale_id' => $saleId, 'item_id' => $itemId, 'line' => 1, 'quantity_purchased' => 1, 'item_cost_price' => 1, 'item_unit_price' => 5, 'item_location' => 1]);

        return $saleId;
    }

    public function testCounts_CountsASuspendedSaleOfAnyTypeAndNotACompletedOrACancelledOne(): void
    {
        $itemId = $this->item();

        foreach ([SALE_TYPE_POS, SALE_TYPE_INVOICE, SALE_TYPE_QUOTE, SALE_TYPE_WORK_ORDER] as $type) {
            $this->saleNaming($itemId, SUSPENDED, $type);
        }

        $this->saleNaming($itemId, COMPLETED);
        $this->saleNaming($itemId, CANCELED);

        $this->assertSame([$itemId => 4], $this->documents()->counts([$itemId]));
    }

    public function testCounts_LeavesOutAQuoteThatBecameAnInvoiceAndKeepsEveryOtherQuote(): void
    {
        $itemId = $this->item();
        $db     = $this->accountingDb();

        foreach ([null, QuoteStatus::Draft->value, QuoteStatus::Open->value, QuoteStatus::Approved->value, QuoteStatus::Ordered->value, QuoteStatus::Converted->value] as $state) {
            $saleId = $this->saleNaming($itemId, SUSPENDED, SALE_TYPE_QUOTE);
            $db->table('sales')->where('sale_id', $saleId)->update(['quote_status' => $state]);
        }

        $this->assertSame([$itemId => 5], $this->documents()->counts([$itemId]));
    }

    public function testCounts_CountsAnOrderLineWithSomethingLeftToInvoiceAndNotOneInvoicedInFull(): void
    {
        $invoiced   = $this->item();
        $remaining  = $this->item();
        $customerId = $this->insertCustomer();
        $db         = $this->accountingDb();

        $db->table('sales_orders')->insert(['customer_id' => $customerId, 'order_date' => '2026-10-01', 'status' => 'approved']);
        $orderId = (int) $db->insertID();
        $db->table('sales_order_lines')->insert(['order_id' => $orderId, 'line_no' => 1, 'item_id' => $invoiced, 'quantity' => 2, 'unit_price' => 5, 'item_location' => 1]);
        $firstLine = (int) $db->insertID();
        $db->table('sales_order_lines')->insert(['order_id' => $orderId, 'line_no' => 2, 'item_id' => $remaining, 'quantity' => 2, 'unit_price' => 5, 'item_location' => 1]);
        $secondLine = (int) $db->insertID();

        $db->table('sales')->insert(['sale_status' => SUSPENDED, 'sale_type' => SALE_TYPE_INVOICE, 'employee_id' => 1, 'comment' => '']);
        $draftId = (int) $db->insertID();
        $db->table('sales_order_invoices')->insert(['sale_id' => $draftId, 'order_id' => $orderId]);
        $db->table('sales_order_invoice_lines')->insert(['sale_id' => $draftId, 'order_line_id' => $firstLine, 'quantity' => 2]);

        $this->assertSame([$invoiced => 1, $remaining => 1], $this->documents()->counts([$invoiced, $remaining]), 'a draft invoice has not taken the quantity yet');

        $db->table('sales')->where('sale_id', $draftId)->update(['sale_status' => COMPLETED]);
        $db->table('sales_order_invoice_lines')->update(['quantity' => 1]);

        $this->assertSame([$invoiced => 1, $remaining => 1], $this->documents()->counts([$invoiced, $remaining]), 'half of the first line is still to invoice');

        $db->table('sales_order_invoice_lines')->update(['quantity' => 2]);

        $this->assertSame([$remaining => 1], $this->documents()->counts([$invoiced, $remaining]), 'the first line is done and the second is not');

        $db->table('sales_order_invoice_lines')->insert(['sale_id' => $draftId, 'order_line_id' => $secondLine, 'quantity' => 2]);

        $this->assertSame([], $this->documents()->counts([$invoiced, $remaining]), 'an order invoiced in full names nothing');
    }

    public function testCounts_CountsAnOpenAndAnApprovedSalesOrderButNotACancelledOne(): void
    {
        $itemId     = $this->item();
        $customerId = $this->insertCustomer();
        $db         = $this->accountingDb();

        foreach (['open', 'approved', 'cancelled'] as $status) {
            $db->table('sales_orders')->insert(['customer_id' => $customerId, 'order_date' => '2026-10-01', 'status' => $status]);
            $db->table('sales_order_lines')->insert(['order_id' => (int) $db->insertID(), 'line_no' => 1, 'item_id' => $itemId, 'quantity' => 1, 'unit_price' => 5, 'item_location' => 1]);
        }

        $this->assertSame([$itemId => 2], $this->documents()->counts([$itemId]));
    }

    public function testCounts_CountsAnActiveAndAPausedScheduleButNotAnEndedOne(): void
    {
        $itemId     = $this->item();
        $customerId = $this->insertCustomer();
        $db         = $this->accountingDb();

        foreach (['active', 'paused', 'ended'] as $status) {
            $db->table('recurring_schedules')->insert(['kind' => 'invoice', 'name' => 'S ' . $status, 'start_date' => '2026-10-01', 'employee_id' => 1, 'status' => $status, 'customer_id' => $customerId, 'issue_mode' => 'draft']);
            $db->table('recurring_schedule_lines')->insert(['schedule_id' => (int) $db->insertID(), 'line_no' => 1, 'item_id' => $itemId, 'quantity' => 1, 'unit_price' => 5, 'item_location' => 1]);
        }

        $this->assertSame([$itemId => 2], $this->documents()->counts([$itemId]));
    }

    public function testCounts_CountsAnUnsettledStockRequestAndAStocktakeStillCountingButNotASettledOne(): void
    {
        $itemId = $this->item();
        $other  = $this->insertStockLocation('Other');
        $db     = $this->accountingDb();

        foreach (['requested', 'approved', 'fulfilled', 'cancelled'] as $status) {
            $db->table('stock_requests')->insert(['from_location_id' => 1, 'to_location_id' => $other, 'request_date' => '2026-10-01', 'status' => $status]);
            $db->table('stock_request_lines')->insert(['request_id' => (int) $db->insertID(), 'line_no' => 1, 'item_id' => $itemId, 'quantity' => 1]);
        }

        $this->assertSame([$itemId => 2], $this->documents()->counts([$itemId]));

        $db->table('stocktakes')->insert(['location_id' => 1, 'count_date' => '2026-10-01', 'status' => 'counting']);
        $db->table('stocktake_lines')->insert(['stocktake_id' => (int) $db->insertID(), 'item_id' => $itemId, 'system_quantity' => 1]);

        $this->assertSame([$itemId => 3], $this->documents()->counts([$itemId]));
    }

    public function testCounts_CountsAPurchaseDocumentThatIsOpenSentOrApprovedAndNotACancelledOne(): void
    {
        $itemId = $this->item();
        $db     = $this->accountingDb();

        foreach (['open', 'sent', 'approved', 'cancelled'] as $status) {
            $db->table('purchase_requests')->insert(['location_id' => 1, 'document_date' => '2026-10-01', 'status' => $status]);
            $db->table('purchase_request_lines')->insert(['request_id' => (int) $db->insertID(), 'line_no' => 1, 'item_id' => $itemId, 'quantity' => 1]);
        }

        $this->assertSame([$itemId => 3], $this->documents()->counts([$itemId]));

        $db->table('quotation_requests')->insert(['location_id' => 1, 'document_date' => '2026-10-01', 'status' => 'open']);
        $db->table('quotation_request_lines')->insert(['rfq_id' => (int) $db->insertID(), 'line_no' => 1, 'item_id' => $itemId, 'quantity' => 1]);

        $supplierId = $this->insertSupplier('Quoting supplier');

        $db->table('supplier_quotations')->insert(['location_id' => 1, 'supplier_id' => $supplierId, 'document_date' => '2026-10-01', 'status' => 'sent']);
        $db->table('supplier_quotation_lines')->insert(['quotation_id' => (int) $db->insertID(), 'line_no' => 1, 'item_id' => $itemId, 'quantity' => 1]);

        $this->assertSame([$itemId => 5], $this->documents()->counts([$itemId]));
    }

    public function testCounts_CountsAPurchaseOrderOnlyWhileALineHasSomethingLeftToReceive(): void
    {
        $itemId     = $this->item();
        $supplierId = $this->insertSupplier('Ordering supplier');
        $db         = $this->accountingDb();

        $db->table('purchase_orders')->insert(['location_id' => 1, 'supplier_id' => $supplierId, 'document_date' => '2026-10-01', 'status' => 'approved']);
        $orderId = (int) $db->insertID();
        $db->table('purchase_order_lines')->insert(['order_id' => $orderId, 'line_no' => 1, 'item_id' => $itemId, 'quantity' => 4]);
        $lineId = (int) $db->insertID();

        $this->assertSame([$itemId => 1], $this->documents()->counts([$itemId]));

        $db->table('receivings')->insert(['employee_id' => 1, 'supplier_id' => $supplierId, 'comment' => 'received']);
        $receivingId = (int) $db->insertID();
        $db->table('purchase_order_receivings')->insert(['receiving_id' => $receivingId, 'order_id' => $orderId]);
        $db->table('purchase_order_receiving_lines')->insert(['receiving_id' => $receivingId, 'order_line_id' => $lineId, 'quantity' => 4]);

        $this->assertSame([], $this->documents()->counts([$itemId]));
    }

    public function testCounts_ListsOnlyTheItemsThatAreNamedAndIgnoresIdsThatAreNotItems(): void
    {
        $named = $this->item();
        $free  = $this->item();
        $this->saleNaming($named, SUSPENDED);

        $this->assertSame([$named => 1], $this->documents()->counts([$named, $free, 0, -3]));
        $this->assertSame([], $this->documents()->counts([]));
        $this->assertSame([], $this->documents()->counts([$free]));
    }

    public function testCounts_AsksNothingOfTheDatabaseWhenNoIdIsAnItem(): void
    {
        $queries  = 0;
        $listener = static function () use (&$queries): void {
            $queries++;
        };

        Events::on('DBQuery', $listener);

        try {
            $counts = $this->documents()->counts([0, -3]);
        } finally {
            Events::removeListener('DBQuery', $listener);
        }

        $this->assertSame([], $counts);
        $this->assertSame(0, $queries);
    }

    public function testFirst_NamesTheItemAndCountsItsDocuments(): void
    {
        $itemId = $this->item();
        $this->saleNaming($itemId, SUSPENDED);
        $this->saleNaming($itemId, SUSPENDED, SALE_TYPE_QUOTE);

        $first = $this->documents()->first([$itemId]);

        $this->assertSame([$itemId, $this->accountingDb()->table('items')->where('item_id', $itemId)->get()->getRow()->name, 2], [$first['item_id'], $first['name'], $first['count']]);
        $this->assertNull($this->documents()->first([$this->item()]));
        $this->assertNull($this->documents()->first([]));
    }

    public function testCounts_AnswersTheSameUnderALockAsWithoutOne(): void
    {
        $named = $this->item();
        $free  = $this->item();

        $this->saleNaming($named, SUSPENDED);

        $this->assertSame($this->documents()->counts([$named, $free]), $this->documents()->counts([$named, $free], true));
        $this->assertSame($this->documents()->first([$named, $free]), $this->documents()->first([$named, $free], true));
    }

    public function testCounts_SeesADocumentCommittedAfterTheSnapshotOnlyWhenAskedToLock(): void
    {
        $itemId = $this->item();

        $this->startWithSnapshot($this->accountingDb());

        $other = $this->openSecondConnection();

        $other->table('sales')->insert(['sale_status' => SUSPENDED, 'sale_type' => SALE_TYPE_INVOICE, 'employee_id' => 1, 'comment' => '']);
        $other->table('sales_items')->insert(['sale_id' => (int) $other->insertID(), 'item_id' => $itemId, 'line' => 1, 'quantity_purchased' => 1, 'item_cost_price' => 1, 'item_unit_price' => 5, 'item_location' => 1]);
        $other->close();

        try {
            $this->assertSame([], $this->documents()->counts([$itemId]), 'the plain read keeps to the snapshot');
            $this->assertSame([$itemId => 1], $this->documents()->counts([$itemId], true));
        } finally {
            $this->releaseOpenTransactions();
        }
    }

    public function testCounts_WaitsForARequestThatHoldsADocumentLineWhenAskedToLock(): void
    {
        $itemId = $this->item();

        $this->saleNaming($itemId, SUSPENDED);

        $other = $this->openSecondConnection();

        $other->transStart();
        $other->query('SELECT sale_id FROM ' . $other->prefixTable('sales_items') . ' WHERE item_id = ' . $itemId . ' FOR UPDATE');
        $this->accountingDb()->query('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $this->assertSame([$itemId => 1], $this->documents()->counts([$itemId]), 'the plain read does not wait');
            $this->assertWaitsForLock(fn () => $this->documents()->counts([$itemId], true));
        } finally {
            $other->transRollback();
            $other->close();
            $this->accountingDb()->query('SET SESSION innodb_lock_wait_timeout = 50');
            $this->releaseOpenTransactions();
        }
    }
}
