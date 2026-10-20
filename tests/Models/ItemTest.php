<?php

namespace Tests\Models;

use App\Models\Item;
use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;

class ItemTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $seedOnce    = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private static bool $doneBootstrap = false;

    protected function setUp(): void
    {
        if (self::$doneBootstrap === false) {
            Config::seeder($this->DBGroup)->call('App\Database\Seeds\TestDatabaseBootstrapSeeder');
            Config::connect($this->DBGroup)->close();

            self::$doneBootstrap = true;
        }

        parent::setUp();
    }

    private function recordSale(int $itemId, float $quantity, int $status = COMPLETED, string $daysAgo = '0'): void
    {
        $db = Database::connect();

        $db->table('sales')->insert([
            'sale_time'   => date('Y-m-d H:i:s', strtotime("-$daysAgo days")),
            'employee_id' => $this->createEmployee(),
            'comment'     => 'top seller fixture',
            'sale_status' => $status,
        ]);
        $saleId = (int) $db->insertID();

        $db->table('sales_items')->insert([
            'sale_id'            => $saleId,
            'item_id'            => $itemId,
            'line'               => 1,
            'quantity_purchased' => $quantity,
            'item_cost_price'    => 1,
            'item_unit_price'    => 5,
            'item_location'      => 1,
        ]);
    }

    /**
     * @return list<int>
     */
    private function topSellerIds(int $limit = 1000): array
    {
        return array_map(static fn (object $row): int => (int) $row->item_id, model(Item::class)->get_top_sellers($limit));
    }

    public function testSuggestionsColumnValidationRejectsSQLInjection(): void
    {
        // These malicious values should not be in the allowed columns list
        $malicious_columns = [
            'name, SLEEP(5)',
            'name; DROP TABLE items',
            "name' OR '1'='1",
            'name UNION SELECT * FROM users',
            'name--comment'
        ];

        foreach ($malicious_columns as $malicious) {
            $this->assertNotContains($malicious, Item::ALLOWED_SUGGESTIONS_COLUMNS,
                "Malicious input should not be in allowed columns: $malicious");
        }
    }

    public function testSuggestionsColumnValidationAcceptsValidColumns(): void
    {
        // Valid columns should be preserved
        $valid_columns = ['name', 'item_number', 'description', 'cost_price', 'unit_price'];

        foreach ($valid_columns as $column) {
            $this->assertContains($column, Item::ALLOWED_SUGGESTIONS_COLUMNS,
                "Valid column should be in allowed list: $column");
        }
    }

    public function testSuggestionsColumnValidationHandlesEmptyString(): void
    {
        // Empty string should only be allowed for second and third columns
        $this->assertContains('', Item::ALLOWED_SUGGESTIONS_COLUMNS_WITH_EMPTY,
            'Empty string should be allowed for optional columns');
    }

    public function testSuggestionsColumnValidationConstantsAreConsistent(): void
    {
        // Ensure the constants are properly defined
        $this->assertCount(5, Item::ALLOWED_SUGGESTIONS_COLUMNS,
            'Should have exactly 5 allowed columns');
        $this->assertCount(6, Item::ALLOWED_SUGGESTIONS_COLUMNS_WITH_EMPTY,
            'Should have exactly 6 allowed columns including empty');

        // Ensure empty string is only in WITH_EMPTY variant
        $this->assertNotContains('', Item::ALLOWED_SUGGESTIONS_COLUMNS,
            'Empty string should not be in required columns list');
    }

    public function testGetTopSellers_RanksCompletedRecentSalesByQuantity(): void
    {
        $popular = $this->createTestItem();
        $modest  = $this->createTestItem();
        $this->recordSale($modest, 3);
        $this->recordSale($popular, 9);

        $ids = $this->topSellerIds();

        $this->assertContains($popular, $ids);
        $this->assertContains($modest, $ids);
        $this->assertLessThan(array_search($modest, $ids, true), array_search($popular, $ids, true));
    }

    public function testGetTopSellers_IgnoresSuspendedStaleAndDeletedItems(): void
    {
        $suspended = $this->createTestItem();
        $stale     = $this->createTestItem();
        $removed   = $this->createTestItem();
        $this->recordSale($suspended, 500, SUSPENDED);
        $this->recordSale($stale, 500, COMPLETED, '200');
        $this->recordSale($removed, 500);
        Database::connect()->table('items')->where('item_id', $removed)->update(['deleted' => 1]);

        $ids = $this->topSellerIds();

        $this->assertNotContains($suspended, $ids);
        $this->assertNotContains($stale, $ids);
        $this->assertNotContains($removed, $ids);
    }

    public function testGetTopSellers_SubtractsReturnedQuantity(): void
    {
        $returned = $this->createTestItem();
        $this->recordSale($returned, 4);
        $this->recordSale($returned, -4);

        $this->assertNotContains($returned, $this->topSellerIds());
    }

    public function testGetTopSellers_RespectsLimit(): void
    {
        $this->recordSale($this->createTestItem(), 2);
        $this->recordSale($this->createTestItem(), 2);

        $this->assertCount(1, model(Item::class)->get_top_sellers(1));
    }
}
