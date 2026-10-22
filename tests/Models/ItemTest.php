<?php

namespace Tests\Models;

use App\Models\Item;
use App\Models\Item_quantity;
use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\ParityFixture;

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

    /**
     * Fixture T-05: a purchase, a sale and a return in the order the reference system saw them.
     * Every step compares the cost an item carries with the cost the reference system used. The
     * return of a unit of an earlier sale is the one step that differs, and it is pinned as such.
     */
    public function testChangeCostPrice_FollowsTheWeightedAverageOfTheReferenceSystem(): void
    {
        bcscale(4);

        $items   = model(Item::class);
        $stock   = model(Item_quantity::class);
        $itemId  = $this->createTestItem();
        $case    = ParityFixture::cases('T-05', 'cost_sequence')['sequence'][0];
        $differs = 0;

        foreach ($case['steps'] as $step) {
            if ($step['op'] === 'purchase') {
                $items->change_cost_price($itemId, (float) $step['quantity'], (float) $step['unit_cost'], isset($step['old_cost']) ? (float) $step['old_cost'] : null);
            }

            $stock->save_value(['item_id' => $itemId, 'location_id' => 1, 'quantity' => $step['stock_after']], $itemId, 1);

            $cost = bcadd((string) $items->get_info($itemId)->cost_price, '0', 4);

            $this->assertSame(bcadd($step['expected']['cost'], '0', 4), $cost, "{$step['name']}: the cost this carries");

            if ($step['classification'] === 'exact') {
                $this->assertSame(bcadd($step['reference']['cost'], '0', 4), $cost, "{$step['name']} must match the reference system");

                continue;
            }

            $differs++;
            $this->assertNotSame(bcadd($step['reference']['cost'], '0', 4), $cost, "{$step['name']} is a known difference and must not be counted as matching");
        }

        $this->assertSame(1, $differs);

        $database = Database::connect();

        $database->table('inventory')->where('trans_items', $itemId)->delete();
        $database->table('item_quantities')->where('item_id', $itemId)->delete();
        $database->table('items')->where('item_id', $itemId)->delete();
    }

    public function testChangeCostPrice_ValuesStockAtAverageCostWhileTheReferenceSystemValuesItFromMovements(): void
    {
        bcscale(4);

        $case = ParityFixture::cases('T-05', 'valuation')['valuation-after-p3'][0];

        $value = bcmul((string) $case['inputs']['stock'], $case['inputs']['average_cost']);

        $this->assertSame(bcadd($case['expected']['value'], '0', 4), $value);
        $this->assertSame('6.0000', bcsub($value, $case['reference']['value']));
        $this->assertSame('differs', $case['classification']);
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
