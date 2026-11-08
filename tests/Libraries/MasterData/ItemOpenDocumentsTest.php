<?php

namespace Tests\Libraries\MasterData;

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

        foreach (['stock_request_lines', 'stock_requests', 'stocktake_lines', 'stocktakes', 'recurring_schedule_lines', 'recurring_schedules', 'sales_order_lines', 'sales_orders', 'sales_items', 'sales'] as $table) {
            $db->query('DELETE FROM ' . $db->prefixTable($table));
        }

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

    private function saleNaming(int $itemId, int $status, int $type = SALE_TYPE_INVOICE): void
    {
        $db = $this->accountingDb();

        $db->table('sales')->insert(['sale_status' => $status, 'sale_type' => $type, 'employee_id' => 1, 'comment' => '']);
        $db->table('sales_items')->insert(['sale_id' => (int) $db->insertID(), 'item_id' => $itemId, 'line' => 1, 'quantity_purchased' => 1, 'item_cost_price' => 1, 'item_unit_price' => 5, 'item_location' => 1]);
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
}
