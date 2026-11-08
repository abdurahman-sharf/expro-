<?php

namespace Tests\Libraries\Stock;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use App\Libraries\Stock\StockAccess;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;
use Tests\Support\StockDocumentFixtureTrait;

/**
 * Who may change the stock of a location: whoever works in its branch and holds the items permission
 * of that location.
 */
class StockAccessTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use SaleAccountingFixtureTrait;
    use StockDocumentFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private StockAccess $access;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startStockDocuments();
        $this->access = new StockAccess();
    }

    protected function tearDown(): void
    {
        $this->stopStockDocuments();
        $this->stopBuying();

        parent::tearDown();
    }

    public function testLocation_ReturnsTheLocationWithItsBranchAndRejectsOneThatIsMissingOrDeleted(): void
    {
        $location = $this->access->location(self::LOCATION_ID);

        $this->assertSame(self::LOCATION_ID, (int) $location->location_id);
        $this->assertSame(1, (int) $location->branch_id);

        $deleted = $this->openLocation('Closed warehouse');
        $this->accountingDb()->table('stock_locations')->where('location_id', $deleted)->update(['deleted' => 1]);

        foreach ([0, -1, 999999, $deleted] as $locationId) {
            try {
                $this->access->location($locationId);
                $this->fail('Location ' . $locationId . ' must be refused.');
            } catch (AccountingException $exception) {
                $this->assertSame(AccountingError::StockPermitLocationInvalid, $exception->error);
            }
        }
    }

    public function testAssertActive_RejectsAnInactiveLocationAndNamesItAndAcceptsOnceItIsActiveAgain(): void
    {
        $quiet = $this->openLocation('Quiet warehouse');
        $db    = $this->accountingDb();

        $this->access->assertActive($quiet);

        $db->table('stock_locations')->where('location_id', $quiet)->update(['status' => 0]);

        try {
            $this->access->assertActive($quiet);
            $this->fail('An inactive location takes no new stock document.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::StockLocationNotActive, $exception->error);
            $this->assertSame('Quiet warehouse', $exception->context['name']);
        }

        $this->assertSame($quiet, (int) $this->access->assertMayChange($quiet, $this->keeperId)->location_id, 'a standing document may still be changed at it');

        $db->table('stock_locations')->where('location_id', $quiet)->update(['status' => 1]);

        $this->access->assertActive($quiet);
    }

    public function testAssertMayChange_AllowsWhoWorksInTheBranchAndHoldsTheItemsPermissionOfTheLocation(): void
    {
        $location = $this->access->assertMayChange(self::LOCATION_ID, $this->keeperId);

        $this->assertSame(self::LOCATION_ID, (int) $location->location_id);

        $second = $this->openLocation('Second warehouse');

        $this->assertSame($second, (int) $this->access->assertMayChange($second, $this->keeperId)->location_id);
    }

    public function testAssertMayChange_RejectsWhoLacksTheItemsPermissionOfThatLocationAndNamesIt(): void
    {
        $second = $this->openLocation('Second warehouse');
        $clerk  = $this->keeper(null, ['items_stock']);

        try {
            $this->access->assertMayChange($second, $clerk);
            $this->fail('The permission of another location does not do.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::StockLocationDenied, $exception->error);
            $this->assertSame('Second warehouse', $exception->context['name']);
        }
    }

    public function testAssertMayChange_RejectsALocationWithoutAnItemsPermissionRowAtAll(): void
    {
        $bare = $this->anotherLocation('Bare warehouse');

        $this->expectException(AccountingException::class);

        $this->access->assertMayChange($bare, $this->keeperId);
    }

    public function testAssertMayChange_RejectsWhoDoesNotWorkInTheBranchOfTheLocationWhateverTheyHold(): void
    {
        $other = $this->createBranch();
        $clerk = $this->keeper([$other]);

        try {
            $this->access->assertMayChange(self::LOCATION_ID, $clerk);
            $this->fail('An employee of another branch must be refused.');
        } catch (BranchException $exception) {
            $this->assertSame(BranchError::NotAllowed, $exception->error);
        }

        $store = $this->locationOfBranch($other, 'Branch store');
        $this->allowLocation($store, $clerk);

        $this->assertSame($store, (int) $this->access->assertMayChange($store, $clerk)->location_id);
    }
}
