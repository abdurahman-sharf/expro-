<?php

namespace Tests\Models;

use App\Models\Item_quantity;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\ConcurrentDbRaceTrait;
use Tests\Support\ItemFixtureTrait;

/**
 * Regression tests: changeQuantity() must apply
 * its write in a single atomic upsert, so that two concurrent sales of the
 * same item/location can never both read the same stale quantity and
 * oversell stock. Unlike the gift card and reward point spends, there is
 * deliberately no floor guard here (see the fix's scope note) — negative
 * stock is allowed today and this fix does not change that.
 */
class ItemQuantityTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use ConcurrentDbRaceTrait;
    use ItemFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private const LOCATION_ID = 1;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function createItemQuantityRow(int $itemId, float $quantity): void
    {
        \Config\Database::connect()->table('item_quantities')->insert([
            'item_id'     => $itemId,
            'location_id' => self::LOCATION_ID,
            'quantity'    => $quantity,
        ]);
    }

    public function testDecrementQuantitySucceeds(): void
    {
        $itemId = $this->createTestItem();
        $this->createItemQuantityRow($itemId, 10);
        $itemQuantityModel = model(Item_quantity::class);

        $result = $itemQuantityModel->changeQuantity($itemId, self::LOCATION_ID, -3);

        $this->assertTrue($result);
        $this->assertEqualsWithDelta(7.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
    }

    public function testDecrementQuantityAllowsGoingNegative(): void
    {
        $itemId = $this->createTestItem();
        $this->createItemQuantityRow($itemId, 5);
        $itemQuantityModel = model(Item_quantity::class);

        $result = $itemQuantityModel->changeQuantity($itemId, self::LOCATION_ID, -8);

        $this->assertTrue($result);
        $this->assertEqualsWithDelta(-3.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
    }

    public function testDecrementQuantityConcurrentDecrementsBothApply(): void
    {
        $itemId = $this->createTestItem();
        $this->createItemQuantityRow($itemId, 10);

        [$result1, $result2] = $this->raceTwoProcesses(
            'itemQuantity',
            [$itemId, self::LOCATION_ID, -3.0],
            [$itemId, self::LOCATION_ID, -3.0]
        );

        $this->assertTrue($result1);
        $this->assertTrue($result2);
        $this->assertEqualsWithDelta(4.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
    }

    public function testCreateMissingRows_GivesAnEmptyShelfInEveryLocationInUseAndLeavesTheOnesThatExist(): void
    {
        $db      = Database::connect();
        $itemId  = $this->createTestItem();
        $stock   = model(Item_quantity::class);
        $db->table('stock_locations')->insert(['location_name' => 'Missing rows ' . uniqid(), 'deleted' => 0]);
        $inUse   = (int) $db->insertID();
        $db->table('stock_locations')->insert(['location_name' => 'Missing rows gone ' . uniqid(), 'deleted' => 1]);
        $deleted = (int) $db->insertID();

        try {
            $this->createItemQuantityRow($itemId, 5);

            $this->assertTrue($stock->createMissingRows($itemId));
            $this->assertTrue($stock->createMissingRows($itemId));

            $this->assertTrue($stock->exists($itemId, $inUse));
            $this->assertEqualsWithDelta(0.0, $this->getItemQuantity($itemId, $inUse), 0.001);
            $this->assertEqualsWithDelta(5.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
            $this->assertFalse($stock->exists($itemId, $deleted));
        } finally {
            $db->table('item_quantities')->where('item_id', $itemId)->delete();
            $db->table('stock_locations')->whereIn('location_id', [$inUse, $deleted])->delete();
        }
    }
}
