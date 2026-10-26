<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\StockAdjustment;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use App\Models\Item_quantity;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * A change to the stock of an item that no purchase or sale accounts for, in the books: a count
 * that found more or less, a quantity entered, or the stock an item took with it when it was deleted.
 */
class StockAdjustmentTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private StockAdjustment $adjustment;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBuying();
        $this->workInEveryBranch();
        $this->adjustment = new StockAdjustment();
    }

    protected function tearDown(): void
    {
        $this->stopBuying();

        parent::tearDown();
    }

    /**
     * Ten units bought at 50, so the books hold them at 500.00.
     */
    private function tenAtFifty(string $itemCost = '50.00'): int
    {
        $itemId = $this->item('0.00');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]));
        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['cost_price' => $itemCost]);

        return $itemId;
    }

    /**
     * The shelves change, as the screen does, and then the change is posted.
     */
    private function change(int $itemId, string $quantity, int $inventoryId, int $locationId = self::LOCATION_ID): void
    {
        $this->assertTrue(model(Item_quantity::class)->changeQuantity($itemId, $locationId, (float) $quantity));

        $this->adjustment->post($itemId, $locationId, $quantity, $this->employeeId, $inventoryId);
    }

    /**
     * @return list<object>
     */
    private function adjustments(int $inventoryId): array
    {
        return $this->accountingDb()->table('accounting_journals')->where('source_type', 'stock_adjustment')->where('source_id', $inventoryId)->get()->getResult();
    }

    public function testPost_AddsStockAtWhatAUnitIsWorthAndCreditsTheStockCountAccount(): void
    {
        $itemId = $this->tenAtFifty();

        $this->change($itemId, '2', 9001);

        $journal = $this->accountingDb()->table('accounting_journals')->where('source_type', 'stock_adjustment')->where('source_id', 9001)->get()->getRow();
        $lines   = $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $journal->journal_id)->orderBy('line_no', 'ASC')->get()->getResult();

        $this->assertSame($this->stockAccount(), (int) $lines[0]->account_id);
        $this->assertSame('100.0000', $lines[0]->debit);
        $this->assertSame($this->accountId('cogs'), (int) $lines[1]->account_id);
        $this->assertSame('100.0000', $lines[1]->credit);
        $this->assertSame(['quantity' => '12.000', 'value' => '600.00'], $this->stockOf($itemId));
        $this->assertStockTies();
    }

    public function testPost_TakesStockOffAtWhatAUnitIsWorthWhereItLeaves(): void
    {
        $itemId = $this->tenAtFifty();

        $this->change($itemId, '-3', 9002);

        $journal = $this->accountingDb()->table('accounting_journals')->where('source_type', 'stock_adjustment')->where('source_id', 9002)->get()->getRow();
        $lines   = $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $journal->journal_id)->orderBy('line_no', 'ASC')->get()->getResult();

        $this->assertSame($this->accountId('cogs'), (int) $lines[0]->account_id);
        $this->assertSame('150.0000', $lines[0]->debit);
        $this->assertSame($this->stockAccount(), (int) $lines[1]->account_id);
        $this->assertSame('150.0000', $lines[1]->credit);
        $this->assertSame(['quantity' => '7.000', 'value' => '350.00'], $this->stockOf($itemId));
        $this->assertStockTies();
    }

    public function testPost_ValuesTheUnitsAtTheAverageOfTheMovementsAndNotAtTheCostTheItemCarries(): void
    {
        $itemId = $this->tenAtFifty('60.00');

        $this->change($itemId, '-4', 9003);

        $this->assertSame(['quantity' => '6.000', 'value' => '300.00'], $this->stockOf($itemId), 'Four units at 50.00, though the item carries 60.00');
        $this->assertStockTies();
    }

    public function testPost_ValuesTheUnitsAtTheCostTheItemCarriesWhenTheMovementsDoNotAccountForTheShelves(): void
    {
        $itemId = $this->tenAtFifty('60.00');

        $this->accountingDb()->table('item_quantities')->where('item_id', $itemId)->update(['quantity' => '15']);
        $this->change($itemId, '-4', 9004);

        $this->assertSame(['quantity' => '6.000', 'value' => '260.00'], $this->stockOf($itemId), 'Four units at 60.00: five units stood on the shelves that no movement had');
    }

    public function testPost_KeepsTheMovementOfGoodsThatCostNothingAndPostsNoJournal(): void
    {
        $itemId = $this->item('0.00');

        $this->change($itemId, '5', 9005);

        $this->assertSame([], $this->adjustments(9005));
        $this->assertSame(['quantity' => '5.000', 'value' => '0.00'], $this->stockOf($itemId));
    }

    public function testPost_PostsOnceForEachRowOfTheInventoryLog(): void
    {
        $itemId = $this->tenAtFifty();

        $this->change($itemId, '2', 9006);
        $journals = $this->countRows('accounting_journals');
        $entries  = $this->countRows('accounting_stock_entries');

        $this->adjustment->post($itemId, self::LOCATION_ID, '2', $this->employeeId, 9006);

        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertSame($entries, $this->countRows('accounting_stock_entries'));
        $this->assertSame(['quantity' => '12.000', 'value' => '600.00'], $this->stockOf($itemId));
    }

    public function testPost_DoesNothingForAServiceForNoChangeAndWhileTheBooksAreOffOrNotStarted(): void
    {
        $service = $this->item('10.00', false);
        $itemId  = $this->tenAtFifty();
        $journals = $this->countRows('accounting_journals');
        $entries  = $this->countRows('accounting_stock_entries');

        $this->adjustment->post($service, self::LOCATION_ID, '4', $this->employeeId, 9007);
        $this->adjustment->post($itemId, self::LOCATION_ID, '0', $this->employeeId, 9008);
        $this->adjustment->post($itemId, self::LOCATION_ID, '4', $this->employeeId, 0);

        $this->setConfigValue('accounting_start_date', '2035-01-01');
        $this->adjustment->post($itemId, self::LOCATION_ID, '4', $this->employeeId, 9009);

        $this->setConfigValue('accounting_start_date', '2020-01-01');
        $this->setConfigValue('accounting_enabled', '0');
        $this->adjustment->post($itemId, self::LOCATION_ID, '4', $this->employeeId, 9010);

        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertSame($entries, $this->countRows('accounting_stock_entries'));
    }

    public function testPost_IsRefusedInAClosedPeriodAndLeavesNothingOfIt(): void
    {
        $itemId = $this->tenAtFifty();
        $entries = $this->countRows('accounting_stock_entries');

        $this->closeThrough(date('Y-m-d'));

        try {
            $this->adjustment->post($itemId, self::LOCATION_ID, '2', $this->employeeId, 9011);
            $this->fail('A change of stock in a closed period must be refused.');
        } catch (PostingException $failure) {
            $this->assertSame(PostingError::PeriodClosed, $failure->error);
        }

        $this->assertSame($entries, $this->countRows('accounting_stock_entries'));
        $this->assertSame([], $this->adjustments(9011));
    }

    public function testPost_IsRefusedWhenTheAccountOfTheStockCountIsMissing(): void
    {
        $itemId = $this->tenAtFifty();

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'stock_permits.count_in')->update(['account_id' => null]);

        $this->expectException(PostingException::class);

        $this->adjustment->post($itemId, self::LOCATION_ID, '2', $this->employeeId, 9012);
    }

    public function testPost_PostsInTheBranchOfTheStockLocation(): void
    {
        $itemId = $this->item('0.00');
        $other  = $this->anotherLocation('Branch store');
        $branch = $this->createBranch('B4', 'Fourth branch');

        $this->accountingDb()->table('stock_locations')->where('location_id', $other)->update(['branch_id' => $branch]);
        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['cost_price' => '10.00']);

        $this->change($itemId, '3', 9013, $other);

        $this->assertSame($branch, (int) $this->accountingDb()->table('accounting_journals')->where('source_type', 'stock_adjustment')->get()->getRow()->branch_id);
        $this->assertSame($branch, (int) $this->accountingDb()->table('accounting_stock_entries')->where('source_type', 'stock_adjustment')->get()->getRow()->branch_id);
        $this->assertStockTies();
    }

    /**
     * An item that costs 10, with a location in a branch of its own.
     *
     * @return array{item: int, location: int, branch: int}
     */
    private function itemInAnotherBranch(): array
    {
        $itemId   = $this->item('0.00');
        $branchId = $this->createBranch('B' . substr(md5(uniqid('', true)), 0, 6), 'Branch of the stock');
        $location = $this->locationOfBranch($branchId, 'Branch store ' . uniqid());

        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['cost_price' => '10.00']);

        return ['item' => $itemId, 'location' => $location, 'branch' => $branchId];
    }

    public function testPost_RefusesAnEmployeeWhoDoesNotWorkInTheBranchOfTheLocationAndPostsNothing(): void
    {
        ['item' => $itemId, 'location' => $location] = $this->itemInAnotherBranch();
        $journals = $this->countRows('accounting_journals');
        $entries  = $this->countRows('accounting_stock_entries');

        try {
            $this->adjustment->post($itemId, $location, '3', $this->employeeWithGrants([], [1]), 9014);
            $this->fail('An employee of another branch must not post a change of stock.');
        } catch (BranchException $refusal) {
            $this->assertSame(BranchError::NotAllowed, $refusal->error);
        }

        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertSame($entries, $this->countRows('accounting_stock_entries'));
        $this->assertSame([], $this->adjustments(9014));
    }

    public function testPost_RefusesAnEmployeeWithNoBranchAtAll(): void
    {
        ['item' => $itemId, 'location' => $location] = $this->itemInAnotherBranch();

        $this->expectException(BranchException::class);

        $this->adjustment->post($itemId, $location, '3', $this->employeeWithGrants([], []), 9015);
    }

    public function testPost_PostsInTheBranchOfTheLocationForAnEmployeeWhoWorksThereAndTheStockTies(): void
    {
        ['item' => $itemId, 'location' => $location, 'branch' => $branch] = $this->itemInAnotherBranch();
        $this->workInBranches($branch);

        $this->change($itemId, '3', 9016, $location);

        $journal = $this->accountingDb()->table('accounting_journals')->where('source_type', 'stock_adjustment')->where('source_id', 9016)->get()->getRow();

        $this->assertSame($branch, (int) $journal->branch_id);
        $this->assertSame($branch, (int) $this->accountingDb()->table('accounting_stock_entries')->where('source_type', 'stock_adjustment')->where('source_id', 9016)->get()->getRow()->branch_id);
        $this->assertStockTies();
    }

    public function testPost_PostsInTheDefaultBranchForAnEmployeeConfinedToIt(): void
    {
        $itemId = $this->tenAtFifty();
        $this->workInBranches(1);

        $this->change($itemId, '2', 9017);

        $this->assertSame(1, (int) $this->adjustments(9017)[0]->branch_id);
        $this->assertSame(['quantity' => '12.000', 'value' => '600.00'], $this->stockOf($itemId));
        $this->assertStockTies();
    }

    public function testPost_IsNotLimitedForTheSystemItself(): void
    {
        ['item' => $itemId, 'location' => $location, 'branch' => $branch] = $this->itemInAnotherBranch();

        $this->assertTrue(model(Item_quantity::class)->changeQuantity($itemId, $location, 3.0));
        $this->adjustment->post($itemId, $location, '3', null, 9018);

        $this->assertSame($branch, (int) $this->adjustments(9018)[0]->branch_id);
    }
}
