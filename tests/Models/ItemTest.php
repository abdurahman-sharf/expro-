<?php

namespace Tests\Models;

use App\Libraries\Organization\BranchException;
use App\Models\Item;
use App\Models\Item_quantity;
use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use ReflectionMethod;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\ParityFixture;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

class ItemTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;

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

    protected function tearDown(): void
    {
        $this->stopBuying();
        session()->remove('person_id');

        parent::tearDown();
    }

    /**
     * Two items with ten units each, bought at 50 and 20, in the books of a logged-in employee.
     *
     * @return array{int, int}
     */
    private function twoItemsInTheBooks(): array
    {
        $this->startBuying();
        $this->workInEveryBranch();
        session()->set('person_id', $this->employeeId);

        $first  = $this->item('0.00');
        $second = $this->item('0.00');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($first, 0, '10', '50'), $this->purchaseLine($second, 1, '10', '20')]));

        return [$first, $second];
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
     * Every step compares the cost an item carries with the cost the reference system used for
     * the next sale. Only a purchase moves it: a sale and a return leave it as it is.
     */
    public function testChangeCostPrice_FollowsTheWeightedAverageOfTheReferenceSystem(): void
    {
        bcscale(4);

        $items  = model(Item::class);
        $stock  = model(Item_quantity::class);
        $itemId = $this->createTestItem();
        $case   = ParityFixture::cases('T-05', 'cost_sequence')['sequence'][0];

        foreach ($case['steps'] as $step) {
            if ($step['op'] === 'purchase') {
                $items->change_cost_price($itemId, (float) $step['quantity'], (float) $step['unit_cost'], isset($step['old_cost']) ? (float) $step['old_cost'] : null);
            }

            $stock->save_value(['item_id' => $itemId, 'location_id' => 1, 'quantity' => $step['stock_after']], $itemId, 1);

            $cost = bcadd((string) $items->get_info($itemId)->cost_price, '0', 4);

            $this->assertSame('exact', $step['classification'], "{$step['name']} must match the reference system");
            $this->assertSame(bcadd($step['expected']['cost'], '0', 4), $cost, "{$step['name']}: the cost this carries");
            $this->assertSame(bcadd($step['reference']['cost'], '0', 4), $cost, "{$step['name']} must match the reference system");
        }

        $database = Database::connect();

        $database->table('inventory')->where('trans_items', $itemId)->delete();
        $database->table('item_quantities')->where('item_id', $itemId)->delete();
        $database->table('items')->where('item_id', $itemId)->delete();
    }

    public function testApplyPurchaseCost_WeighsTheStockOnHandAndKeepsTheRoundingToTheEnd(): void
    {
        $items  = model(Item::class);
        $stock  = model(Item_quantity::class);
        $itemId = $this->createTestItem();

        $stock->save_value(['item_id' => $itemId, 'location_id' => 1, 'quantity' => 3], $itemId, 1);
        $items->applyPurchaseCost($itemId, '4', '10.00', '1.00');

        $this->assertSame('6.142857', (string) $items->get_info($itemId)->cost_price, 'Three units at 1.00 and four at 10.00 average 43/7 = 6.142857142, kept at six decimals');
    }

    public function testApplyPurchaseCost_TakesTheCostOfTheNewUnitsWhenNoStockIsOnHand(): void
    {
        $items  = model(Item::class);
        $stock  = model(Item_quantity::class);
        $itemId = $this->createTestItem();

        $stock->save_value(['item_id' => $itemId, 'location_id' => 1, 'quantity' => -4], $itemId, 1);
        $items->applyPurchaseCost($itemId, '10', '7.50', '99.00');

        $this->assertSame('7.50', bcadd((string) $items->get_info($itemId)->cost_price, '0', 2));

        $stock->save_value(['item_id' => $itemId, 'location_id' => 1, 'quantity' => 0], $itemId, 1);
        $items->applyPurchaseCost($itemId, '2', '9.00', '7.50');

        $this->assertSame('9.00', bcadd((string) $items->get_info($itemId)->cost_price, '0', 2));
    }

    /**
     * @return array{Item, int}
     */
    private function itemWithStockAndCost(string $onHand, string $cost): array
    {
        $items  = model(Item::class);
        $itemId = $this->createTestItem();
        $data   = ['cost_price' => $cost];

        model(Item_quantity::class)->save_value(['item_id' => $itemId, 'location_id' => 1, 'quantity' => $onHand], $itemId, 1);
        $items->save_value($data, $itemId);

        return [$items, $itemId];
    }

    public function testCorrectPurchaseCost_TakesTheUnitsOutAtTheirValueAndPutsTheNewOnesInAsTheReferenceSystemDoes(): void
    {
        [$items, $itemId] = $this->itemWithStockAndCost('18', '108.888889');

        $this->assertTrue($items->correctPurchaseCost($itemId, '8', '960', '8', '1120'));
        $this->assertSame('117.777778', (string) $items->get_info($itemId)->cost_price, '1,000 and 1,120 of 18 units: the average the reference system reached after the price edit');
    }

    public function testCorrectPurchaseCost_GoesBackToTheAverageOfTheStockThatIsLeftWhenAPurchaseIsTakenOut(): void
    {
        [$items, $itemId] = $this->itemWithStockAndCost('18', '117.777778');

        $this->assertTrue($items->correctPurchaseCost($itemId, '8', '1120', '0', '0'));
        $this->assertSame('100.000000', (string) $items->get_info($itemId)->cost_price);
    }

    public function testCorrectPurchaseCost_WeighsTheStockBelowTheUnitsTakenOutInOneStep(): void
    {
        [$items, $itemId] = $this->itemWithStockAndCost('5', '112.5');

        $this->assertTrue($items->correctPurchaseCost($itemId, '10', '1200', '8', '960'));
        $this->assertSame('107.500000', (string) $items->get_info($itemId)->cost_price, 'Five units of 112.5 less ten of 120 and plus eight of 120 are three units worth 322.5');
    }

    public function testCorrectPurchaseCost_LeavesTheCostWhenNoStockIsLeftToWeigh(): void
    {
        [$items, $itemId] = $this->itemWithStockAndCost('8', '100');

        $this->assertTrue($items->correctPurchaseCost($itemId, '8', '800', '0', '0'));
        $this->assertSame('100.000000', (string) $items->get_info($itemId)->cost_price);
    }

    public function testCorrectPurchaseCost_LeavesTheCostWhenTheValueTakenOutIsMoreThanTheStockIsWorth(): void
    {
        [$items, $itemId] = $this->itemWithStockAndCost('10', '1');

        $this->assertTrue($items->correctPurchaseCost($itemId, '2', '100', '0', '0'));
        $this->assertSame('1.000000', (string) $items->get_info($itemId)->cost_price);
    }

    public function testCorrectPurchaseCost_DoesNotWeighTheStockOfADeletedLocation(): void
    {
        [$items, $itemId] = $this->itemWithStockAndCost('10', '100');

        $db = Database::connect();
        $db->table('stock_locations')->insert(['location_name' => 'Closed depot', 'deleted' => 1, 'branch_id' => 1]);

        $locationId = (int) $db->insertID();

        try {
            $db->table('item_quantities')->insert(['item_id' => $itemId, 'location_id' => $locationId, 'quantity' => '40']);

            $this->assertTrue($items->correctPurchaseCost($itemId, '10', '1000', '10', '1200'));
            $this->assertSame('120.000000', (string) $items->get_info($itemId)->cost_price, 'Ten units worth 1,200 remain in the locations that are kept');
        } finally {
            $db->table('item_quantities')->where('location_id', $locationId)->delete();
            $db->table('stock_locations')->where('location_id', $locationId)->delete();
        }
    }

    public function testCorrectPurchaseCost_WeighsTheCostAndTheStockAnotherPurchaseCommittedAfterTheSnapshotWasTaken(): void
    {
        [$items, $itemId] = $this->itemWithStockAndCost('20', '100');

        $correcting = $this->openSecondConnection();

        try {
            $this->startWithSnapshot($correcting);

            model(Item_quantity::class)->save_value(['item_id' => $itemId, 'location_id' => 1, 'quantity' => '30'], $itemId, 1);
            $committed = ['cost_price' => '133.333333'];
            $items->save_value($committed, $itemId);

            $this->assertTrue((new Item($correcting))->correctPurchaseCost($itemId, '10', '1000', '8', '800'));
            $correcting->transComplete();
        } finally {
            $correcting->close();
        }

        $this->assertSame('135.714285', (string) $items->get_info($itemId)->cost_price, 'Thirty units at 133.333333 less ten worth 1,000 plus eight worth 800 are 28 units worth 3,800');
    }

    public function testCorrectPurchaseCost_ChangesNothingWhenWhatIsTakenOutIsPutBackIn(): void
    {
        [$items, $itemId] = $this->itemWithStockAndCost('20', '71.428571');

        $this->assertTrue($items->correctPurchaseCost($itemId, '4', '200', '4', '200'));
        $this->assertSame('71.428571', (string) $items->get_info($itemId)->cost_price);
    }

    public function testDelete_TakesTheStockOffTheBooksAndLogsItForTheLocation(): void
    {
        [$itemId] = $this->twoItemsInTheBooks();

        $this->assertTrue(model(Item::class)->delete($itemId));

        $log = $this->accountingDb()->table('inventory')->where('trans_items', $itemId)->where('trans_comment', lang('Items.is_deleted'))->get()->getResult();

        $this->assertCount(1, $log);
        $this->assertSame('-10.000', (string) $log[0]->trans_inventory);
        $this->assertSame('0.00', $this->stockOf($itemId)['value']);
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('source_type', 'stock_adjustment')->where('source_id', $log[0]->trans_id)->countAllResults());
        $this->assertStockTies();
    }

    public function testDelete_RefusedByTheBooksLeavesTheItemItsStockAndItsLog(): void
    {
        [$itemId] = $this->twoItemsInTheBooks();
        $this->closeThrough(date('Y-m-d'));
        $items = model(Item::class);

        $this->assertFalse($items->delete($itemId));

        $this->assertSame(0, (int) $items->get_info($itemId)->deleted);
        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertSame(0, $this->accountingDb()->table('inventory')->where('trans_items', $itemId)->where('trans_comment', lang('Items.is_deleted'))->countAllResults());
        $this->assertNotNull($items->accountingFailure());
    }

    private function stockOfAnotherBranchOutOfReachOfTheEmployee(int $itemId): int
    {
        $branch = $this->createBranch('B' . substr(md5(uniqid('', true)), 0, 6), 'Second branch');
        $store  = $this->locationOfBranch($branch, 'Store ' . substr(md5(uniqid('', true)), 0, 6));

        $this->assertTrue(model(Item_quantity::class)->changeQuantity($itemId, $store, 4.0));
        session()->set('person_id', $this->employeeWithGrants([], [1]));

        return $store;
    }

    public function testDelete_RejectsAnItemWithStockInABranchTheEmployeeDoesNotWorkInAndChangesNothing(): void
    {
        [$itemId] = $this->twoItemsInTheBooks();
        $store    = $this->stockOfAnotherBranchOutOfReachOfTheEmployee($itemId);
        $items    = model(Item::class);

        $this->assertFalse($items->delete($itemId));

        $this->assertSame(0, (int) $items->get_info($itemId)->deleted);
        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertSame('4.000', $this->onHand($itemId, $store));
        $this->assertInstanceOf(BranchException::class, $items->accountingFailure());
        $this->assertSame(0, $this->accountingDb()->table('inventory')->where('trans_items', $itemId)->where('trans_comment', lang('Items.is_deleted'))->countAllResults());
    }

    public function testDelete_RejectsAnItemWithStockInABranchTheEmployeeDoesNotWorkInWhileAccountingIsOff(): void
    {
        [$itemId] = $this->twoItemsInTheBooks();
        $store    = $this->stockOfAnotherBranchOutOfReachOfTheEmployee($itemId);
        $this->setConfigValue('accounting_enabled', '0');
        $items = model(Item::class);

        $this->assertFalse($items->delete($itemId));

        $this->assertSame(0, (int) $items->get_info($itemId)->deleted);
        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertSame('4.000', $this->onHand($itemId, $store));
    }

    public function testDelete_DeletesAnItemWithStockInEveryBranchForAnEmployeeOfEveryBranch(): void
    {
        [$itemId] = $this->twoItemsInTheBooks();
        $store    = $this->stockOfAnotherBranchOutOfReachOfTheEmployee($itemId);
        session()->set('person_id', $this->employeeWithGrants([]));

        $this->assertTrue(model(Item::class)->delete($itemId));

        $this->assertSame('0.000', $this->onHand($itemId));
        $this->assertSame('0.000', $this->onHand($itemId, $store));
    }

    public function testHoldsStockOutsideTheEmployeesBranches_AcceptsTheSystemWithNobodyLoggedIn(): void
    {
        [$itemId] = $this->twoItemsInTheBooks();
        $this->stockOfAnotherBranchOutOfReachOfTheEmployee($itemId);
        $items = model(Item::class);

        $this->assertTrue($items->holdsStockOutsideTheEmployeesBranches([$itemId]));

        session()->remove('person_id');

        $this->assertFalse($items->holdsStockOutsideTheEmployeesBranches([$itemId]));
    }

    public function testDeleteList_RejectsEveryItemWhenOneHasStockInABranchTheEmployeeDoesNotWorkInAndChangesNothing(): void
    {
        [$first, $second] = $this->twoItemsInTheBooks();
        $store            = $this->stockOfAnotherBranchOutOfReachOfTheEmployee($second);
        $items            = model(Item::class);

        $this->assertFalse($items->delete_list([$first, $second]));

        $this->assertSame(0, (int) $items->get_info($first)->deleted);
        $this->assertSame(0, (int) $items->get_info($second)->deleted);
        $this->assertSame('10.000', $this->onHand($first));
        $this->assertSame('4.000', $this->onHand($second, $store));
        $this->assertInstanceOf(BranchException::class, $items->accountingFailure());
    }

    public function testDeleteList_RejectsEveryItemWhenOneHasStockInABranchTheEmployeeDoesNotWorkInWhileAccountingIsOff(): void
    {
        [$first, $second] = $this->twoItemsInTheBooks();
        $store            = $this->stockOfAnotherBranchOutOfReachOfTheEmployee($second);
        $this->setConfigValue('accounting_enabled', '0');
        $items = model(Item::class);

        $this->assertFalse($items->delete_list([$first, $second]));

        $this->assertSame(0, (int) $items->get_info($first)->deleted);
        $this->assertSame(0, (int) $items->get_info($second)->deleted);
        $this->assertSame('10.000', $this->onHand($first));
        $this->assertSame('4.000', $this->onHand($second, $store));
    }

    public function testDelete_DeletesAnItemWhoseStockIsAllInTheBranchesOfTheEmployee(): void
    {
        [$itemId] = $this->twoItemsInTheBooks();
        session()->set('person_id', $this->employeeWithGrants([], [1]));

        $this->assertTrue(model(Item::class)->delete($itemId));

        $this->assertSame(1, (int) model(Item::class)->get_info($itemId)->deleted);
        $this->assertSame('0.000', $this->onHand($itemId));
        $this->assertStockTies();
    }

    public function testDelete_RejectsAnItemWithStockToAnEmployeeWhoWorksInNoBranch(): void
    {
        [$itemId] = $this->twoItemsInTheBooks();
        session()->set('person_id', $this->employeeWithGrants([], []));
        $items = model(Item::class);

        $this->assertFalse($items->delete($itemId));

        $this->assertSame(0, (int) $items->get_info($itemId)->deleted);
        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertInstanceOf(BranchException::class, $items->accountingFailure());
    }

    public function testDeleteList_DeletesItemsWhoseStockIsAllInTheBranchesOfTheEmployee(): void
    {
        [$first, $second] = $this->twoItemsInTheBooks();
        session()->set('person_id', $this->employeeWithGrants([], [1]));

        $this->assertTrue(model(Item::class)->delete_list([$first, $second]));

        $this->assertSame('0.000', $this->onHand($first));
        $this->assertSame('0.000', $this->onHand($second));
        $this->assertStockTies();
    }

    public function testDeleteList_RejectsItemsWithStockToAnEmployeeWhoWorksInNoBranch(): void
    {
        [$first, $second] = $this->twoItemsInTheBooks();
        session()->set('person_id', $this->employeeWithGrants([], []));
        $items = model(Item::class);

        $this->assertFalse($items->delete_list([$first, $second]));

        $this->assertSame('10.000', $this->onHand($first));
        $this->assertSame('10.000', $this->onHand($second));
        $this->assertInstanceOf(BranchException::class, $items->accountingFailure());
    }

    public function testDelete_DeletesAnItemWhoseShelfInAnotherBranchIsEmpty(): void
    {
        [$itemId] = $this->twoItemsInTheBooks();
        $store    = $this->stockOfAnotherBranchOutOfReachOfTheEmployee($itemId);
        $this->accountingDb()->table('item_quantities')->where('item_id', $itemId)->where('location_id', $store)->update(['quantity' => 0]);

        $this->assertTrue(model(Item::class)->delete($itemId));

        $this->assertSame(1, (int) model(Item::class)->get_info($itemId)->deleted);
        $this->assertSame('0.000', $this->onHand($itemId));
    }

    public function testDelete_DeletesAnItemWhoseOnlyStockInAnotherBranchSitsInADeletedLocation(): void
    {
        [$itemId] = $this->twoItemsInTheBooks();
        $store    = $this->stockOfAnotherBranchOutOfReachOfTheEmployee($itemId);
        $this->accountingDb()->table('stock_locations')->where('location_id', $store)->update(['deleted' => 1]);

        $this->assertTrue(model(Item::class)->delete($itemId));

        $this->assertSame(1, (int) model(Item::class)->get_info($itemId)->deleted);
        $this->assertSame([1], array_map(static fn (object $journal): int => (int) $journal->branch_id, $this->accountingDb()->table('accounting_journals')->where('source_type', 'stock_adjustment')->get()->getResult()));
    }

    public function testDelete_ForgetsTheRefusalOfAnEarlierDelete(): void
    {
        [$first, $second] = $this->twoItemsInTheBooks();
        $this->stockOfAnotherBranchOutOfReachOfTheEmployee($first);
        $items = model(Item::class);

        $this->assertFalse($items->delete($first));
        $this->assertInstanceOf(BranchException::class, $items->accountingFailure());

        $this->assertTrue($items->delete($second));
        $this->assertNull($items->accountingFailure());
    }

    public function testDeleteList_ForgetsTheRefusalOfAnEarlierDelete(): void
    {
        [$first, $second] = $this->twoItemsInTheBooks();
        $this->stockOfAnotherBranchOutOfReachOfTheEmployee($first);
        $items = model(Item::class);

        $this->assertFalse($items->delete_list([$first]));
        $this->assertInstanceOf(BranchException::class, $items->accountingFailure());

        $this->assertTrue($items->delete_list([$second]));
        $this->assertNull($items->accountingFailure());
    }

    public function testPostStockRemoved_ReportsAJournalOfABranchTheEmployeeDoesNotWorkInInsteadOfThrowing(): void
    {
        [$itemId] = $this->twoItemsInTheBooks();
        $store    = $this->stockOfAnotherBranchOutOfReachOfTheEmployee($itemId);
        $items    = model(Item::class);

        $removed = (new ReflectionMethod(Item::class, 'postStockRemoved'))->invoke($items, $itemId, [['inventory_id' => 1, 'location_id' => $store, 'change' => '-4.000']]);

        $this->assertFalse($removed);
        $this->assertInstanceOf(BranchException::class, $items->accountingFailure());
        $this->assertSame([], $this->accountingDb()->table('accounting_journals')->where('source_type', 'stock_adjustment')->get()->getResult());
    }

    public function testDeleteList_PostsEveryItemAndTiesToTheLedger(): void
    {
        [$first, $second] = $this->twoItemsInTheBooks();

        $this->assertTrue(model(Item::class)->delete_list([$first, $second]));

        $this->assertSame('0.000', $this->onHand($first));
        $this->assertSame('0.000', $this->onHand($second));
        $this->assertSame(2, $this->accountingDb()->table('accounting_journals')->where('source_type', 'stock_adjustment')->countAllResults());
        $this->assertStockTies();
    }

    public function testDeleteList_RefusedByTheBooksTakesNoItemAndLeavesNoLog(): void
    {
        [$first, $second] = $this->twoItemsInTheBooks();
        $this->closeThrough(date('Y-m-d'));
        $items = model(Item::class);

        $this->assertFalse($items->delete_list([$first, $second]));

        $this->assertSame(0, (int) $items->get_info($first)->deleted);
        $this->assertSame(0, (int) $items->get_info($second)->deleted);
        $this->assertSame('10.000', $this->onHand($first));
        $this->assertSame('10.000', $this->onHand($second));
        $this->assertSame(0, $this->accountingDb()->table('inventory')->where('trans_comment', lang('Items.is_deleted'))->whereIn('trans_items', [$first, $second])->countAllResults());
    }

    public function testDeleteList_KeepsNoBooksWhileAccountingIsOff(): void
    {
        $this->startBuying();
        $this->workInEveryBranch();
        session()->set('person_id', $this->employeeId);
        $itemId = $this->item('5.00');
        $this->setConfigValue('accounting_enabled', '0');
        model(Item_quantity::class)->save_value(['item_id' => $itemId, 'location_id' => 1, 'quantity' => 3], $itemId, 1);

        $this->assertTrue(model(Item::class)->delete_list([$itemId]));

        $this->assertSame(0, $this->countRows('accounting_journals'));
        $this->assertSame('0.000', $this->onHand($itemId));
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

    /**
     * @return array<string, array{0: string, 1: string, 2: int, 3: string}>
     */
    public static function priceAfterDiscountProvider(): array
    {
        return [
            'a percent of the price'     => ['100', '20', PERCENT, '80.0000'],
            'ten percent'                => ['100', '10', PERCENT, '90.0000'],
            'no discount'                => ['100', '0', PERCENT, '100.0000'],
            'an amount a unit'           => ['100', '5', FIXED, '95.0000'],
            'a third of the price'       => ['10', '33.3333', PERCENT, '6.6666'],
            'an amount that takes it all' => ['20', '20', FIXED, '0.0000'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('priceAfterDiscountProvider')]
    public function testPriceAfterDiscount_TakesAPercentOrAnAmountPerUnitOffThePrice(string $price, string $discount, int $type, string $expected): void
    {
        $this->assertSame($expected, Item::priceAfterDiscount($price, $discount, $type));
    }

    public function testPriceAfterDiscount_MatchesTheExamplesOfTheReferenceSystemForTheMinimumPriceOfAProduct(): void
    {
        $this->assertSame(-1, bccomp(Item::priceAfterDiscount('100', '20', PERCENT), '90', 2), '100 less 20% is 80 and is under a minimum of 90');
        $this->assertSame(1, bccomp(Item::priceAfterDiscount('100', '5', FIXED), '90', 2), '100 less 5 is 95 and passes');
        $this->assertSame(0, bccomp(Item::priceAfterDiscount('100', '10', PERCENT), '90', 2), 'a final price equal to the minimum passes');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: ?string}>
     */
    public static function marginProvider(): array
    {
        return [
            'a fifth of the price'   => ['100', '80', '20.00'],
            'a loss'                 => ['100', '120', '-20.00'],
            'no cost'                => ['50', '0', '100.00'],
            'a price of nothing'     => ['0', '5', null],
            'a negative price'       => ['-5', '1', null],
            'cost with six decimals' => ['3', '2.123456', '29.21'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('marginProvider')]
    public function testMarginPercent_IsTheShareOfThePriceThatIsProfitAndNothingWithoutAPrice(string $price, string $cost, ?string $expected): void
    {
        $this->assertSame($expected, Item::marginPercent($price, $cost));
    }

    public function testGetInfo_ReadsTheMinimumPriceAndTheHighestDiscountOfAnItemAsNoControlForOneThatHasNone(): void
    {
        $itemId = $this->createTestItem();
        $info   = model(Item::class)->get_info($itemId);

        $this->assertSame('0.0000', $info->minimum_price);
        $this->assertNull($info->max_discount_percent);

        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['minimum_price' => '12.5', 'max_discount_percent' => '7.5']);

        $info = model(Item::class)->get_info($itemId);

        $this->assertSame('12.5000', $info->minimum_price);
        $this->assertSame('7.50', $info->max_discount_percent);
    }
}
