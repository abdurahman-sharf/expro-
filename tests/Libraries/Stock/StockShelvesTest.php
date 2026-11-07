<?php

namespace Tests\Libraries\Stock;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Stock\StockShelves;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;
use Tests\Support\ShelfFixtureTrait;
use Tests\Support\StockDocumentFixtureTrait;

/**
 * What the stock documents do to the shelves: the units of an item in a location change and are logged,
 * what would leave a location with less than nothing is refused as a whole, and the items of a change
 * are locked in the order of their ids.
 */
class StockShelvesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use SaleAccountingFixtureTrait;
    use ShelfFixtureTrait;
    use StockDocumentFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private StockShelves $shelves;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startStockDocuments();
        $this->shelves = new StockShelves();
    }

    protected function tearDown(): void
    {
        $this->stopStockDocuments();
        $this->stopBuying();

        parent::tearDown();
    }

    public function testNet_AddsTheChangesOfOneItemAndLocationAndDropsWhatCancelsOut(): void
    {
        $net = StockShelves::net([
            ['itemId' => 7, 'locationId' => 1, 'delta' => '-5.000'],
            ['itemId' => 7, 'locationId' => 1, 'delta' => '5.000'],
            ['itemId' => 7, 'locationId' => 2, 'delta' => '3.000'],
            ['itemId' => 8, 'locationId' => 1, 'delta' => '-2.000'],
            ['itemId' => 8, 'locationId' => 1, 'delta' => '-1.500'],
        ]);

        $this->assertSame([
            ['itemId' => 7, 'locationId' => 2, 'delta' => '3.000'],
            ['itemId' => 8, 'locationId' => 1, 'delta' => '-3.500'],
        ], $net);
        $this->assertSame([], StockShelves::net([]));
    }

    public function testAssertStays_AcceptsATakeThatLeavesNothingAndAnAddOfAnyQuantity(): void
    {
        $itemId = $this->stocked('10', '50');

        $this->shelves->assertStays([['itemId' => $itemId, 'locationId' => self::LOCATION_ID, 'delta' => '-10.000']]);
        $this->shelves->assertStays([['itemId' => $itemId, 'locationId' => self::LOCATION_ID, 'delta' => '1000.000']]);
        $this->shelves->assertStays([]);

        $this->assertSame('10.000', $this->shelves->onHand($itemId, self::LOCATION_ID));
    }

    public function testAssertStays_AcceptsAnAddEvenWhenTheShelfIsAlreadyBelowZero(): void
    {
        $itemId = $this->stocked('10', '50');
        $this->putOnShelf($itemId, '-4');

        $this->shelves->assertStays([['itemId' => $itemId, 'locationId' => self::LOCATION_ID, 'delta' => '1.000']]);

        $this->expectException(AccountingException::class);

        $this->shelves->assertStays([['itemId' => $itemId, 'locationId' => self::LOCATION_ID, 'delta' => '-1.000']]);
    }

    public function testAssertStays_ReadsTheShelfUnderALockWhenItJudgesATake(): void
    {
        $itemId = $this->stocked('10', '50');
        $reads  = [];

        $listener = static function ($query) use (&$reads): void {
            if (str_contains((string) $query->getQuery(), 'item_quantities')) {
                $reads[] = (string) $query->getQuery();
            }
        };

        Events::on('DBQuery', $listener);

        try {
            $this->shelves->assertStays([['itemId' => $itemId, 'locationId' => self::LOCATION_ID, 'delta' => '-1.000']]);
        } finally {
            Events::removeListener('DBQuery', $listener);
        }

        $this->assertCount(1, $reads);
        $this->assertStringContainsString('FOR UPDATE', $reads[0]);
    }

    public function testAssertStays_RejectsWhatLeavesLessThanNothingAndNamesTheItemAndTheLocation(): void
    {
        $itemId = $this->stocked('10', '50');
        $name   = (string) $this->accountingDb()->table('items')->where('item_id', $itemId)->get()->getRow()->name;

        try {
            $this->shelves->assertStays([['itemId' => $itemId, 'locationId' => self::LOCATION_ID, 'delta' => '-10.001']]);
            $this->fail('Taking more than the shelf holds must be refused.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::PurchaseStockWouldGoNegative, $exception->error);
            $this->assertSame($name, $exception->context['name']);
            $this->assertSame($itemId, $exception->context['item_id']);
            $this->assertSame(self::LOCATION_ID, $exception->context['location_id']);
            $this->assertNotSame('', $exception->context['scope_name']);
        }
    }

    public function testAssertStays_JudgesTheNetChangeAndNotEachChange(): void
    {
        $itemId = $this->stocked('10', '50');

        $this->shelves->assertStays([
            ['itemId' => $itemId, 'locationId' => self::LOCATION_ID, 'delta' => '-15.000'],
            ['itemId' => $itemId, 'locationId' => self::LOCATION_ID, 'delta' => '6.000'],
        ]);

        $this->expectException(AccountingException::class);

        $this->shelves->assertStays([
            ['itemId' => $itemId, 'locationId' => self::LOCATION_ID, 'delta' => '-15.000'],
            ['itemId' => $itemId, 'locationId' => self::LOCATION_ID, 'delta' => '4.000'],
        ]);
    }

    public function testAssertStays_ReadsAnEmptyShelfAsNothingAndTheOtherLocationOfTheSameItemApart(): void
    {
        $itemId = $this->stocked('10', '50');
        $second = $this->openLocation();

        $this->expectException(AccountingException::class);

        $this->shelves->assertStays([
            ['itemId' => $itemId, 'locationId' => $second, 'delta' => '-1.000'],
            ['itemId' => $itemId, 'locationId' => self::LOCATION_ID, 'delta' => '-1.000'],
        ]);
    }

    public function testMove_ChangesTheShelvesAndLogsEachNetChangeWithTheCommentAndTheEmployee(): void
    {
        $first  = $this->stocked('10', '50');
        $second = $this->stocked('4', '20');

        $this->shelves->move([
            ['itemId' => $first, 'locationId' => self::LOCATION_ID, 'delta' => '-3.000'],
            ['itemId' => $first, 'locationId' => self::LOCATION_ID, 'delta' => '1.000'],
            ['itemId' => $second, 'locationId' => self::LOCATION_ID, 'delta' => '5.500'],
        ], 'STKP 9001', $this->keeperId);

        $this->assertSame('8.000', $this->onHand($first));
        $this->assertSame('9.500', $this->onHand($second));

        $log = $this->accountingDb()->table('inventory')->where('trans_comment', 'STKP 9001')->orderBy('trans_items', 'ASC')->get()->getResult();

        $this->assertCount(2, $log);
        $this->assertSame(['-2.000', '5.500'], array_map(static fn (object $row): string => bcadd((string) $row->trans_inventory, '0', 3), $log));
        $this->assertSame([$this->keeperId], array_values(array_unique(array_map(static fn (object $row): int => (int) $row->trans_user, $log))));
        $this->assertSame([self::LOCATION_ID], array_values(array_unique(array_map(static fn (object $row): int => (int) $row->trans_location, $log))));
    }

    public function testMove_WritesNothingForAChangeThatCancelsOut(): void
    {
        $itemId = $this->stocked('10', '50');
        $before = $this->countRows('inventory');

        $this->shelves->move([
            ['itemId' => $itemId, 'locationId' => self::LOCATION_ID, 'delta' => '2.000'],
            ['itemId' => $itemId, 'locationId' => self::LOCATION_ID, 'delta' => '-2.000'],
        ], 'STKP 9002', $this->keeperId);

        $this->assertSame($before, $this->countRows('inventory'));
        $this->assertSame('10.000', $this->onHand($itemId));
    }

    public function testOnHand_ReadsTheShelfAndNothingForAnItemThatHasNone(): void
    {
        $itemId = $this->stocked('10', '50');

        $this->assertSame('10.000', $this->shelves->onHand($itemId, self::LOCATION_ID));
        $this->assertSame('10.000', $this->shelves->onHand($itemId, self::LOCATION_ID, true));
        $this->assertSame('0.000', $this->shelves->onHand($itemId, 999999));
        $this->assertSame('0.000', $this->shelves->onHand(999999, self::LOCATION_ID, true));
    }

    public function testLockItems_HoldsTheRowOfEachItemUntilTheTransactionEnds(): void
    {
        $first  = $this->stocked('10', '50');
        $second = $this->stocked('4', '20');
        $other  = $this->stocked('4', '20');
        $db     = $this->accountingDb();

        $db->transStart();

        try {
            $this->shelves->lockItems([$second, $first, $second]);

            $link = $this->openSecondConnection(false);

            foreach ([$first, $second] as $itemId) {
                $this->assertFalse($link->query('SELECT item_id FROM ' . $link->prefixTable('items') . ' WHERE item_id = ? FOR UPDATE', [$itemId]), 'item ' . $itemId . ' is held');
            }

            $this->assertNotFalse($link->query('SELECT item_id FROM ' . $link->prefixTable('items') . ' WHERE item_id = ? FOR UPDATE', [$other]), 'an item that was not named is free');
        } finally {
            $db->transRollback();
        }
    }

    public function testLockItems_LocksTheItemsInOneStatementInTheOrderOfTheirIdsAndTakesNoLockForNone(): void
    {
        $first  = $this->stocked('10', '50');
        $second = $this->stocked('4', '20');
        $locks  = [];

        $listener = static function ($query) use (&$locks): void {
            if (str_contains((string) $query->getQuery(), 'FOR UPDATE')) {
                $locks[] = (string) $query->getQuery();
            }
        };

        Events::on('DBQuery', $listener);

        try {
            $this->shelves->lockItems([$second, $first, $second, 0]);
            $this->shelves->lockItems([]);
        } finally {
            Events::removeListener('DBQuery', $listener);
        }

        $this->assertCount(1, $locks);
        $this->assertStringContainsString('IN (0,' . min($first, $second) . ',' . max($first, $second) . ') ORDER BY item_id FOR UPDATE', $locks[0]);
    }
}
