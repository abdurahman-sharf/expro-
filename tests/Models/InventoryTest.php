<?php

namespace Tests\Models;

use App\Models\Inventory;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\ItemFixtureTrait;

/**
 * The log of what moved in and out of a stock location, and which locations of it are read.
 */
class InventoryTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use ItemFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    /**
     * An item with one entry in the log of the first location and one in each of two others.
     *
     * @return array{item: int, locations: list<int>}
     */
    private function itemLoggedInThreeLocations(): array
    {
        $db        = Database::connect();
        $itemId    = $this->createTestItem();
        $locations = [1];

        foreach (['A', 'B'] as $name) {
            $db->table('stock_locations')->insert(['location_name' => "Log {$name} " . uniqid(), 'deleted' => 0]);
            $locations[] = (int) $db->insertID();
        }

        foreach ($locations as $locationId) {
            $db->table('inventory')->insert(['trans_items' => $itemId, 'trans_user' => 1, 'trans_location' => $locationId, 'trans_comment' => "Entry of {$locationId}", 'trans_inventory' => 1, 'trans_date' => date('Y-m-d H:i:s')]);
        }

        return ['item' => $itemId, 'locations' => $locations];
    }

    private function forgetTheLog(int $itemId, array $locations): void
    {
        $db = Database::connect();

        $db->table('inventory')->where('trans_items', $itemId)->delete();
        $db->table('stock_locations')->whereIn('location_id', array_slice($locations, 1))->delete();
    }

    /**
     * @return list<int>
     */
    private function locationsOfTheLog(int $itemId, array|int|false $asked): array
    {
        $locations = array_map('intval', array_column(model(Inventory::class)->get_inventory_data_for_item($itemId, $asked)->getResultArray(), 'trans_location'));

        sort($locations);

        return $locations;
    }

    public function testGetInventoryDataForItem_ReturnsTheLogOfEveryLocationWhenNoneIsGiven(): void
    {
        ['item' => $itemId, 'locations' => $locations] = $this->itemLoggedInThreeLocations();

        try {
            $this->assertSame($locations, $this->locationsOfTheLog($itemId, false));
        } finally {
            $this->forgetTheLog($itemId, $locations);
        }
    }

    public function testGetInventoryDataForItem_LimitsTheLogToTheLocationGiven(): void
    {
        ['item' => $itemId, 'locations' => $locations] = $this->itemLoggedInThreeLocations();

        try {
            $this->assertSame([$locations[2]], $this->locationsOfTheLog($itemId, $locations[2]));
        } finally {
            $this->forgetTheLog($itemId, $locations);
        }
    }

    public function testGetInventoryDataForItem_LimitsTheLogToTheListOfLocationsGiven(): void
    {
        ['item' => $itemId, 'locations' => $locations] = $this->itemLoggedInThreeLocations();

        try {
            $this->assertSame([$locations[0], $locations[1]], $this->locationsOfTheLog($itemId, [$locations[1], $locations[0]]));
        } finally {
            $this->forgetTheLog($itemId, $locations);
        }
    }

    public function testGetInventoryDataForItem_MatchesNothingForAnEmptyList(): void
    {
        ['item' => $itemId, 'locations' => $locations] = $this->itemLoggedInThreeLocations();

        try {
            $this->assertSame([], $this->locationsOfTheLog($itemId, []));
        } finally {
            $this->forgetTheLog($itemId, $locations);
        }
    }
}
