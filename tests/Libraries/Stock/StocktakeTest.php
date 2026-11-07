<?php

namespace Tests\Libraries\Stock;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchException;
use App\Libraries\Stock\StockPermit;
use App\Libraries\Stock\StockPermissions;
use App\Libraries\Stock\StockPermitSources;
use App\Libraries\Stock\Stocktake;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ConcurrentDbRaceTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;
use Tests\Support\ShelfFixtureTrait;
use Tests\Support\StockDocumentFixtureTrait;

/**
 * The stocktake: the quantities it freezes when the count starts, the counts entered against them, and
 * the approval that posts what was found over and short as stock adjustments, with the shelves, the
 * books, the permissions, the branches and the closed periods that go with it, and its cancellation.
 */
class StocktakeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ConcurrentDbRaceTrait;
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

    private Stocktake $takes;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startStockDocuments();
        $this->takes = new Stocktake();
    }

    protected function tearDown(): void
    {
        $this->stopStockDocuments();
        $this->stopBuying();

        parent::tearDown();
    }

    /**
     * @param list<int> $itemIds
     */
    private function start(array $itemIds, array $more = []): int
    {
        return $this->takes->create(['location_id' => self::LOCATION_ID, 'date' => date('Y-m-d'), 'item_ids' => $itemIds, ...$more], $this->keeperId);
    }

    /**
     * @param array<int, string> $counted
     */
    private function countAll(int $stocktakeId, array $counted): void
    {
        $this->takes->count($stocktakeId, $counted, $this->keeperId);
    }

    private function refusedWith(AccountingError $error, callable $action): void
    {
        try {
            $action();
            $this->fail('The change should have been refused with ' . $error->value . '.');
        } catch (AccountingException $exception) {
            $this->assertSame($error, $exception->error);
        }
    }

    public function testCreate_FreezesWhatTheSystemHoldsOfEachItemListedAtTheMomentTheCountStarts(): void
    {
        $first  = $this->stocked('10', '50');
        $second = $this->stocked('4', '20');

        $stocktakeId = $this->start([$first, $second]);

        $this->assertSame(['10.000', '4.000'], array_map(static fn (object $line): string => bcadd((string) $line->system_quantity, '0', 3), $this->orderedLines($stocktakeId, [$first, $second])));

        $this->assertGreaterThan(0, (new StockPermit())->create(['type' => 'issue', 'date' => date('Y-m-d'), 'location_id' => self::LOCATION_ID, 'lines' => [['item_id' => $first, 'quantity' => '3']]], $this->keeperId));

        $this->assertSame('10.000', bcadd((string) $this->orderedLines($stocktakeId, [$first])[0]->system_quantity, '0', 3), 'what was frozen stays frozen when the shelf moves');
        $this->assertSame('counting', $this->takes->find($stocktakeId)->status);
        $this->assertSame($this->keeperId, (int) $this->takes->find($stocktakeId)->created_by);
    }

    private function itemName(int $itemId): string
    {
        return (string) $this->accountingDb()->table('items')->select('name')->where('item_id', $itemId)->get()->getRow()->name;
    }

    /**
     * @param list<int> $itemIds
     *
     * @return list<object>
     */
    private function orderedLines(int $stocktakeId, array $itemIds): array
    {
        $lines = [];

        foreach ($this->takes->lines($stocktakeId) as $line) {
            $lines[(int) $line->item_id] = $line;
        }

        return array_map(static fn (int $itemId): object => $lines[$itemId], $itemIds);
    }

    public function testCreate_ListsEveryStockItemOfTheLocationWhenAskedToCountThemAll(): void
    {
        $this->emptyShelves();

        try {
            $first   = $this->stocked('10', '50');
            $second  = $this->item('5.00');
            $service = $this->item('1.00', false);
            $this->putOnShelf($second, '3');
            $this->putOnShelf($service, '9');

            $stocktakeId = $this->takes->create(['location_id' => self::LOCATION_ID, 'all_items' => true], $this->keeperId);
            $listed      = array_map(static fn (object $line): int => (int) $line->item_id, $this->takes->lines($stocktakeId));

            $this->assertEqualsCanonicalizing([$first, $second], $listed, 'every stock item with a shelf here, and no item that keeps no stock');
        } finally {
            $this->restoreShelves();
        }
    }

    public function testCreate_AllowsAnEmptySheetAndAddItemsFreezesWhatTheSystemHoldsWhenTheyAreAdded(): void
    {
        $first  = $this->stocked('10', '50');
        $second = $this->stocked('4', '20');

        $stocktakeId = $this->start([]);

        $this->assertSame([], $this->takes->lines($stocktakeId));

        $this->takes->addItems($stocktakeId, [$first], $this->keeperId);
        $this->assertGreaterThan(0, (new StockPermit())->create(['type' => 'issue', 'date' => date('Y-m-d'), 'location_id' => self::LOCATION_ID, 'lines' => [['item_id' => $second, 'quantity' => '1']]], $this->keeperId));
        $this->takes->addItems($stocktakeId, [$first, $second], $this->keeperId);

        $this->assertSame(['10.000', '3.000'], array_map(static fn (object $line): string => bcadd((string) $line->system_quantity, '0', 3), $this->orderedLines($stocktakeId, [$first, $second])), 'an item listed already keeps what it froze, a new one freezes what is there now');
    }

    public function testCreate_GivesOneStocktakeToALocationForADayWhileItStands(): void
    {
        $itemId = $this->stocked('10', '50');
        $second = $this->openLocation();

        $first = $this->start([$itemId]);

        $this->refusedWith(AccountingError::StocktakeExists, fn () => $this->start([$itemId]));
        $this->assertGreaterThan(0, $this->takes->create(['location_id' => $second, 'date' => date('Y-m-d')], $this->keeperId), 'another location may be counted the same day');
        $this->assertGreaterThan(0, $this->takes->create(['location_id' => self::LOCATION_ID, 'date' => date('Y-m-d', strtotime('-1 day'))], $this->keeperId), 'the same location another day');

        $this->takes->cancel($first, 'started by mistake', $this->keeperId);

        $this->assertGreaterThan(0, $this->start([$itemId]), 'a cancelled stocktake frees its day');
    }

    public function testCreate_KeepsTheFirstStocktakeAsItWasWhenASecondIsRejected(): void
    {
        $itemId = $this->stocked('10', '50');
        $first  = $this->start([$itemId]);

        $this->refusedWith(AccountingError::StocktakeExists, fn () => $this->start([$itemId]));

        $this->assertSame(1, $this->countRows('stocktakes'));
        $this->assertSame(1, $this->countRows('stocktake_lines'));
        $this->assertSame('counting', $this->takes->find($first)->status);
    }

    public function testCreate_RequiresThePermissionTheLocationAndTheBranch(): void
    {
        $other  = $this->createBranch();
        $itemId = $this->stocked('10', '50');

        $this->refusedWith(AccountingError::PermissionDenied, fn () => $this->takes->create(['location_id' => self::LOCATION_ID, 'item_ids' => [$itemId]], $this->keeper(null, [StockPermissions::MODULE, 'items_stock'])));
        $this->refusedWith(AccountingError::StockLocationDenied, fn () => $this->takes->create(['location_id' => self::LOCATION_ID, 'item_ids' => [$itemId]], $this->keeper(null, [StockPermissions::STOCKTAKE_MANAGE])));

        try {
            $this->takes->create(['location_id' => self::LOCATION_ID, 'item_ids' => [$itemId]], $this->keeper([$other]));
            $this->fail('An employee of another branch must be refused.');
        } catch (BranchException) {
            $this->assertSame(0, $this->countRows('stocktakes'));
        }
    }

    public function testCreate_WritesTheAuditLog(): void
    {
        $itemId = $this->stocked('10', '50');

        $stocktakeId = $this->start([$itemId]);

        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'stocktake', 'entity_id' => $stocktakeId, 'action' => 'stocktake_started'])->countAllResults());
    }

    public function testCreate_RejectsMoreItemsThanAStocktakeHoldsBeforeLookingAtAnyOfThem(): void
    {
        try {
            $this->start(range(1, Stocktake::ITEMS_MAX + 1));
            $this->fail('A stocktake with more than 5000 items must be refused.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::StocktakeItemsInvalid, $exception->error);
            $this->assertSame(Stocktake::ITEMS_MAX, $exception->context['max'], 'refused for how many there are, not for one of them');
        }

        $this->assertSame(0, $this->countRows('stocktakes'));
    }

    public function testCreate_RejectsItemsThatAreNotAListAndNamesTheLimit(): void
    {
        foreach (['7', 7] as $notAList) {
            try {
                $this->takes->create(['location_id' => self::LOCATION_ID, 'item_ids' => $notAList], $this->keeperId);
                $this->fail('Items that are not a list must be refused.');
            } catch (AccountingException $exception) {
                $this->assertSame(AccountingError::StocktakeItemsInvalid, $exception->error);
                $this->assertSame(Stocktake::ITEMS_MAX, $exception->context['max']);
            }
        }

        $this->assertSame(0, $this->countRows('stocktakes'));
    }

    public function testAddItems_RequiresThePermissionToManageStocktakes(): void
    {
        $itemId      = $this->stocked('10', '50');
        $stocktakeId = $this->start([]);

        $this->refusedWith(AccountingError::PermissionDenied, fn () => $this->takes->addItems($stocktakeId, [$itemId], $this->keeper(null, [StockPermissions::MODULE, 'items_stock'])));

        $this->assertSame([], $this->takes->lines($stocktakeId));
    }

    public function testRemoveItem_RequiresThePermissionToManageStocktakes(): void
    {
        $itemId      = $this->stocked('10', '50');
        $stocktakeId = $this->start([$itemId]);

        $this->refusedWith(AccountingError::PermissionDenied, fn () => $this->takes->removeItem($stocktakeId, $itemId, $this->keeper(null, [StockPermissions::MODULE, 'items_stock'])));

        $this->assertCount(1, $this->takes->lines($stocktakeId));
    }

    public function testCount_RequiresThePermissionToManageStocktakes(): void
    {
        $itemId      = $this->stocked('10', '50');
        $stocktakeId = $this->start([$itemId]);

        $this->refusedWith(AccountingError::PermissionDenied, fn () => $this->takes->count($stocktakeId, [$itemId => '5'], $this->keeper(null, [StockPermissions::MODULE, 'items_stock'])));

        $this->assertNull($this->takes->lines($stocktakeId)[0]->counted_quantity);
    }

    public function testCreate_RejectsWhatIsNotAValidCount(): void
    {
        $itemId  = $this->stocked('10', '50');
        $service = $this->item('1.00', false);

        $this->refusedWith(AccountingError::InvalidDate, fn () => $this->start([$itemId], ['date' => '2026-02-31']));
        $this->refusedWith(AccountingError::InvalidDate, fn () => $this->start([$itemId], ['date' => date('Y-m-d', strtotime('+1 day'))]));
        $this->refusedWith(AccountingError::StockPermitNoteTooLong, fn () => $this->start([$itemId], ['note' => str_repeat('x', 501)]));
        $this->refusedWith(AccountingError::StocktakeLinesInvalid, fn () => $this->start([$service]));
        $this->refusedWith(AccountingError::StocktakeLinesInvalid, fn () => $this->start([999999]));
        $this->refusedWith(AccountingError::StockPermitLocationInvalid, fn () => $this->takes->create(['location_id' => 999999], $this->keeperId));
        $this->assertSame(0, $this->countRows('stocktakes'));
    }

    public function testCount_EntersWhatWasCountedAndTakesBackAQuantityWhenItIsEmptied(): void
    {
        $first  = $this->stocked('10', '50');
        $second = $this->stocked('4', '20');

        $stocktakeId = $this->start([$first, $second]);

        $this->countAll($stocktakeId, [$first => '8', $second => '4.5']);

        $this->assertSame(['8.000', '4.500'], array_map(static fn (object $line): string => bcadd((string) $line->counted_quantity, '0', 3), $this->orderedLines($stocktakeId, [$first, $second])));

        $this->countAll($stocktakeId, [$first => '']);

        $this->assertNull($this->orderedLines($stocktakeId, [$first])[0]->counted_quantity);
    }

    public function testCount_RejectsAQuantityThatIsNotANumberOfZeroOrMoreAndAnItemNotOnTheSheet(): void
    {
        $first  = $this->stocked('10', '50');
        $second = $this->stocked('4', '20');

        $stocktakeId = $this->start([$first]);

        foreach (['-1', 'abc', '1.0001', '1e3'] as $bad) {
            $this->refusedWith(AccountingError::StocktakeLinesInvalid, fn () => $this->countAll($stocktakeId, [$first => $bad]));
        }

        $this->refusedWith(AccountingError::StocktakeLinesInvalid, fn () => $this->countAll($stocktakeId, [$second => '1']));
        $this->assertNull($this->orderedLines($stocktakeId, [$first])[0]->counted_quantity);
        $this->countAll($stocktakeId, [$first => '0']);
        $this->assertSame('0.000', bcadd((string) $this->orderedLines($stocktakeId, [$first])[0]->counted_quantity, '0', 3), 'a count of nothing is a count');
    }

    public function testRemoveItem_TakesAnItemOffTheSheetWhileItIsBeingCounted(): void
    {
        $first  = $this->stocked('10', '50');
        $second = $this->stocked('4', '20');

        $stocktakeId = $this->start([$first, $second]);

        $this->takes->removeItem($stocktakeId, $second, $this->keeperId);

        $this->assertSame([$first], array_map(static fn (object $line): int => (int) $line->item_id, $this->takes->lines($stocktakeId)));
    }

    public function testAddItems_RejectsAnApprovedOrCancelledStocktakeLikeCountAndRemoveItem(): void
    {
        $itemId = $this->stocked('10', '50');

        $approved = $this->start([$itemId]);
        $this->countAll($approved, [$itemId => '10']);
        $this->takes->approve($approved, $this->keeperId);

        $this->refusedWith(AccountingError::StocktakeNotCounting, fn () => $this->takes->addItems($approved, [$itemId], $this->keeperId));
        $this->refusedWith(AccountingError::StocktakeNotCounting, fn () => $this->countAll($approved, [$itemId => '1']));
        $this->refusedWith(AccountingError::StocktakeNotCounting, fn () => $this->takes->removeItem($approved, $itemId, $this->keeperId));
        $this->refusedWith(AccountingError::StocktakeNotCounting, fn () => $this->takes->approve($approved, $this->keeperId));
        $this->refusedWith(AccountingError::StocktakeNotFound, fn () => $this->countAll(999999, [$itemId => '1']));
    }

    public function testApprove_PostsWhatWasFoundOverAndShortAsOneJournalAtTheAverageAndMovesTheShelves(): void
    {
        $short = $this->stocked('10', '50');
        $over  = $this->stocked('5', '20');
        $other = $this->stocked('7', '30');

        $stocktakeId = $this->start([$short, $over]);

        $this->countAll($stocktakeId, [$short => '8', $over => '7']);
        $this->takes->approve($stocktakeId, $this->keeperId);

        $journals = $this->journalsOfSource(StockPermitSources::STOCKTAKE, $stocktakeId);

        $this->assertCount(1, $journals);
        $this->assertSame([$this->stockAccount() . ':40.00:0.00', $this->accountId('cogs') . ':0.00:40.00', $this->accountId('cogs') . ':100.00:0.00', $this->stockAccount() . ':0.00:100.00'], $this->linesOfJournal((int) $journals[0]->journal_id));
        $this->assertSame('Stocktake #STKT ' . $stocktakeId, $journals[0]->description);
        $this->assertSame(date('Y-m-d'), $journals[0]->journal_date);
        $this->assertSame(['8.000', '7.000', '7.000'], [$this->onHand($short), $this->onHand($over), $this->onHand($other)], 'only the items listed change');
        $this->assertSame(['quantity' => '8.000', 'value' => '400.00'], $this->stockOf($short));
        $this->assertSame(['quantity' => '7.000', 'value' => '140.00'], $this->stockOf($over));
        $this->assertSame(['quantity' => '7.000', 'value' => '210.00'], $this->stockOf($other));
        $this->assertCount(2, $this->movementsOfSource(StockPermitSources::STOCKTAKE, $stocktakeId));
        $this->assertBooksTie();

        $row = $this->takes->find($stocktakeId);

        $this->assertSame('approved', $row->status);
        $this->assertSame($this->keeperId, (int) $row->approved_by);
        $this->assertSame(1, (int) $row->booked);

        $lines = $this->orderedLines($stocktakeId, [$short, $over]);

        $this->assertSame(['-2.000', '2.000'], array_map(static fn (object $line): string => bcadd((string) $line->difference, '0', 3), $lines));
        $this->assertSame(['50.000000', '20.000000'], array_map(static fn (object $line): string => bcadd((string) $line->unit_cost, '0', 6), $lines), 'what a unit was worth is kept on the line');
    }

    public function testApprove_ValuesBothTheGainAndTheLossAtTheAverageOfTheLocationNowAndLeavesTheAverageAlone(): void
    {
        $itemId = $this->stocked('10', '50');
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '10', '70')]));

        $loss = $this->start([$itemId]);
        $this->countAll($loss, [$itemId => '18']);
        $this->takes->approve($loss, $this->keeperId);

        $this->assertSame(['quantity' => '18.000', 'value' => '1080.00'], $this->stockOf($itemId), 'two units short at the average of 60');

        $gain = $this->takes->create(['location_id' => self::LOCATION_ID, 'date' => date('Y-m-d', strtotime('-1 day')), 'item_ids' => [$itemId]], $this->keeperId);
        $this->countAll($gain, [$itemId => '20']);
        $this->takes->approve($gain, $this->keeperId);

        $this->assertSame(['quantity' => '20.000', 'value' => '1200.00'], $this->stockOf($itemId), 'two units over at the same average');
        $this->assertBooksTie();
    }

    public function testApprove_KeepsWhatWasSoldOrBoughtAfterTheCountStartedCounted(): void
    {
        $itemId = $this->stocked('10', '50');

        $stocktakeId = $this->start([$itemId]);

        $this->assertGreaterThan(0, (new StockPermit())->create(['type' => 'issue', 'date' => date('Y-m-d'), 'location_id' => self::LOCATION_ID, 'lines' => [['item_id' => $itemId, 'quantity' => '3']]], $this->keeperId));

        $this->countAll($stocktakeId, [$itemId => '8']);
        $this->takes->approve($stocktakeId, $this->keeperId);

        $this->assertSame('5.000', $this->onHand($itemId), 'ten at the start, two short, three issued since: five');
        $this->assertBooksTie();
    }

    public function testApprove_RejectsAShortfallOfMoreThanTheShelfHoldsNowAndChangesNothing(): void
    {
        $itemId = $this->stocked('10', '50');

        $stocktakeId = $this->start([$itemId]);

        $this->assertGreaterThan(0, (new StockPermit())->create(['type' => 'issue', 'date' => date('Y-m-d'), 'location_id' => self::LOCATION_ID, 'lines' => [['item_id' => $itemId, 'quantity' => '7']]], $this->keeperId));

        $this->countAll($stocktakeId, [$itemId => '1']);

        $journals = $this->countRows('accounting_journals');

        $this->refusedWith(AccountingError::PurchaseStockWouldGoNegative, fn () => $this->takes->approve($stocktakeId, $this->keeperId));

        $this->assertSame('3.000', $this->onHand($itemId));
        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertSame('counting', $this->takes->find($stocktakeId)->status);
    }

    public function testApprove_PostsNoJournalAndMovesNoStockForAFindingOfNothing(): void
    {
        $itemId = $this->stocked('10', '50');

        $stocktakeId = $this->start([$itemId]);

        $this->countAll($stocktakeId, [$itemId => '10']);

        $journals = $this->countRows('accounting_journals');

        $this->takes->approve($stocktakeId, $this->keeperId);

        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertSame([], $this->movementsOfSource(StockPermitSources::STOCKTAKE, $stocktakeId));
        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertSame('approved', $this->takes->find($stocktakeId)->status);
        $this->assertSame('0.000', bcadd((string) $this->orderedLines($stocktakeId, [$itemId])[0]->difference, '0', 3));
    }

    public function testApprove_MovesTheShelfAndPostsNoJournalForAnItemThatCostsNothing(): void
    {
        $itemId = $this->item('0.00');
        $this->putOnShelf($itemId, '5');

        $stocktakeId = $this->start([$itemId]);

        $this->countAll($stocktakeId, [$itemId => '3']);
        $this->takes->approve($stocktakeId, $this->keeperId);

        $this->assertSame([], $this->journalsOfSource(StockPermitSources::STOCKTAKE, $stocktakeId));
        $this->assertSame('3.000', $this->onHand($itemId));
        $this->assertCount(1, $this->movementsOfSource(StockPermitSources::STOCKTAKE, $stocktakeId));
    }

    public function testApprove_MovesTheShelvesAndPostsNothingWithAccountingOff(): void
    {
        $itemId = $this->stocked('10', '50');

        $stocktakeId = $this->start([$itemId]);

        $this->countAll($stocktakeId, [$itemId => '8']);
        $this->setConfigValue('accounting_enabled', '0');

        $journals = $this->countRows('accounting_journals');

        $this->takes->approve($stocktakeId, $this->keeperId);

        $this->assertSame('8.000', $this->onHand($itemId));
        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertSame(0, (int) $this->takes->find($stocktakeId)->booked);

        $this->takes->cancel($stocktakeId, 'recount', $this->keeperId);

        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertSame($journals, $this->countRows('accounting_journals'));
    }

    public function testApprove_RejectsAStocktakeThatFoundNothingWorthAnythingInAClosedPeriodToo(): void
    {
        $itemId = $this->item('0.00');
        $this->putOnShelf($itemId, '10');

        $stocktakeId = $this->start([$itemId]);

        $this->countAll($stocktakeId, [$itemId => '8']);
        $this->closeThrough(date('Y-m-d'));

        try {
            $this->takes->approve($stocktakeId, $this->keeperId);
            $this->fail('A stocktake is not approved into a closed period even when it posts no journal.');
        } catch (PostingException $exception) {
            $this->assertSame(PostingError::PeriodClosed, $exception->error);
        }

        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertSame('counting', $this->takes->find($stocktakeId)->status);
    }

    public function testApprove_MovesTheShelvesAndPostsNothingForADayBeforeTheBooksStart(): void
    {
        $itemId = $this->stocked('10', '50');

        $stocktakeId = $this->start([$itemId]);

        $this->countAll($stocktakeId, [$itemId => '8']);
        $this->setConfigValue('accounting_start_date', date('Y-m-d', strtotime('+1 day')));
        $this->takes->approve($stocktakeId, $this->keeperId);

        $this->assertSame('8.000', $this->onHand($itemId));
        $this->assertSame([], $this->journalsOfSource(StockPermitSources::STOCKTAKE, $stocktakeId));
        $this->assertSame([], $this->movementsOfSource(StockPermitSources::STOCKTAKE, $stocktakeId));
        $this->assertSame(0, (int) $this->takes->find($stocktakeId)->booked);
    }

    public function testApprove_RejectsAClosedPeriodAndTheStocktakeKeepsCounting(): void
    {
        $itemId = $this->stocked('10', '50');

        $stocktakeId = $this->start([$itemId]);

        $this->countAll($stocktakeId, [$itemId => '8']);
        $this->closeThrough(date('Y-m-d'));

        try {
            $this->takes->approve($stocktakeId, $this->keeperId);
            $this->fail('A stocktake cannot be posted into a closed period.');
        } catch (PostingException $exception) {
            $this->assertSame(PostingError::PeriodClosed, $exception->error);
        }

        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertSame('counting', $this->takes->find($stocktakeId)->status);
        $this->assertSame([], $this->movementsOfSource(StockPermitSources::STOCKTAKE, $stocktakeId));
        $this->assertSame(1, $this->countRows('accounting_posting_failures'));
    }

    public function testApprove_RequiresThePermissionToApproveAndTheLocationAndTheBranch(): void
    {
        $other  = $this->createBranch();
        $itemId = $this->stocked('10', '50');

        $stocktakeId = $this->start([$itemId]);

        $this->countAll($stocktakeId, [$itemId => '8']);

        $this->refusedWith(AccountingError::PermissionDenied, fn () => $this->takes->approve($stocktakeId, $this->keeper(null, [StockPermissions::MODULE, StockPermissions::STOCKTAKE_MANAGE, 'items_stock'])));
        $this->refusedWith(AccountingError::StockLocationDenied, fn () => $this->takes->approve($stocktakeId, $this->keeper(null, [StockPermissions::STOCKTAKE_APPROVE])));

        try {
            $this->takes->approve($stocktakeId, $this->keeper([$other]));
            $this->fail('An employee of another branch must be refused.');
        } catch (BranchException) {
            $this->assertSame('10.000', $this->onHand($itemId));
            $this->assertSame('counting', $this->takes->find($stocktakeId)->status);
        }
    }

    public function testApprove_NeedsEveryListedItemCountedAndAtLeastOneItem(): void
    {
        $first  = $this->stocked('10', '50');
        $second = $this->stocked('4', '20');

        $stocktakeId = $this->start([$first, $second]);

        $this->countAll($stocktakeId, [$first => '8']);
        $this->refusedWith(AccountingError::StocktakeNotCounted, fn () => $this->takes->approve($stocktakeId, $this->keeperId));

        $this->assertSame('10.000', $this->onHand($first), 'nothing was changed by the refusal');
        $this->refusedWith(AccountingError::StocktakeNothingToApprove, fn () => $this->takes->approve($this->takes->create(['location_id' => self::LOCATION_ID, 'date' => date('Y-m-d', strtotime('-1 day'))], $this->keeperId), $this->keeperId));
    }

    public function testApprove_WritesTheAuditLogAndTwoApprovalsAtTheSameMomentApproveOnce(): void
    {
        $itemId = $this->stocked('10', '50');

        $stocktakeId = $this->start([$itemId]);

        $this->countAll($stocktakeId, [$itemId => '8']);

        $answers = $this->raceStockWorkers('approveStocktake', [(string) $stocktakeId, (string) $this->keeperId], [(string) $stocktakeId, (string) $this->keeperId]);

        $this->assertSame(['done', AccountingError::StocktakeNotCounting->value], $answers);
        $this->assertSame('8.000', $this->onHand($itemId));
        $this->assertCount(1, $this->journalsOfSource(StockPermitSources::STOCKTAKE, $stocktakeId));
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'stocktake', 'entity_id' => $stocktakeId, 'action' => 'stocktake_approved'])->countAllResults());
        $this->assertBooksTie();
    }

    public function testCancel_WritesTheAuditLogOfADiscardedCountAndOfAReversedOne(): void
    {
        $itemId = $this->stocked('10', '50');

        $approved = $this->start([$itemId], ['date' => date('Y-m-d', strtotime('-1 day'))]);

        $this->countAll($approved, [$itemId => '8']);
        $this->takes->approve($approved, $this->keeperId);

        $discarded = $this->start([$itemId]);

        $this->takes->cancel($discarded, 'wrong day', $this->keeperId);
        $this->takes->cancel($approved, 'wrong shelf', $this->keeperId);

        foreach ([$discarded, $approved] as $stocktakeId) {
            $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'stocktake', 'entity_id' => $stocktakeId, 'action' => 'stocktake_cancelled'])->countAllResults());
        }
    }

    public function testCancel_DiscardsACountThatIsStillBeingMadeWithoutAnyEffect(): void
    {
        $itemId = $this->stocked('10', '50');

        $stocktakeId = $this->start([$itemId]);

        $this->countAll($stocktakeId, [$itemId => '2']);

        $journals = $this->countRows('accounting_journals');

        $this->takes->cancel($stocktakeId, 'wrong day', $this->keeperId);

        $row = $this->takes->find($stocktakeId);

        $this->assertSame('cancelled', $row->status);
        $this->assertSame('wrong day', $row->cancel_reason);
        $this->assertNull($row->slot);
        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertCount(1, $this->takes->lines($stocktakeId), 'nothing is deleted');
    }

    public function testCancel_ReversesAnApprovedStocktakeItsJournalItsMovementsAndTheShelves(): void
    {
        $short = $this->stocked('10', '50');
        $over  = $this->stocked('5', '20');

        $stocktakeId = $this->start([$short, $over]);

        $this->countAll($stocktakeId, [$short => '8', $over => '7']);
        $this->takes->approve($stocktakeId, $this->keeperId);
        $this->takes->cancel($stocktakeId, 'counted the wrong shelf', $this->keeperId);

        $journal  = $this->journalsOfSource(StockPermitSources::STOCKTAKE, $stocktakeId)[0];
        $reversal = $this->accountingDb()->table('accounting_journals')->where('reverses_journal_id', $journal->journal_id)->get()->getRow();

        $this->assertSame('reversed', $journal->status);
        $this->assertNotNull($reversal);
        $this->assertSame(['10.000', '5.000'], [$this->onHand($short), $this->onHand($over)]);
        $this->assertSame(['quantity' => '10.000', 'value' => '500.00'], $this->stockOf($short));
        $this->assertSame(['quantity' => '5.000', 'value' => '100.00'], $this->stockOf($over));
        $this->assertSame(['posting', 'posting', 'reversal', 'reversal'], array_map(static fn (object $entry): string => $entry->kind, $this->movementsOfSource(StockPermitSources::STOCKTAKE, $stocktakeId)));
        $this->assertSame('cancelled', $this->takes->find($stocktakeId)->status);
        $this->assertBooksTie();
    }

    public function testCancel_RejectsWhenTheUnitsFoundOverHaveGoneSinceAndChangesNothing(): void
    {
        $itemId = $this->stocked('10', '50');

        $stocktakeId = $this->start([$itemId]);

        $this->countAll($stocktakeId, [$itemId => '12']);
        $this->takes->approve($stocktakeId, $this->keeperId);

        $this->assertGreaterThan(0, (new StockPermit())->create(['type' => 'issue', 'date' => date('Y-m-d'), 'location_id' => self::LOCATION_ID, 'lines' => [['item_id' => $itemId, 'quantity' => '11']]], $this->keeperId));

        $journals = $this->countRows('accounting_journals');

        $this->refusedWith(AccountingError::PurchaseStockWouldGoNegative, fn () => $this->takes->cancel($stocktakeId, 'too late', $this->keeperId));

        $this->assertSame('1.000', $this->onHand($itemId));
        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertSame('approved', $this->takes->find($stocktakeId)->status);
    }

    public function testCancel_RejectsWhenTheDayOfTheReversalIsClosedAndChangesNothing(): void
    {
        $itemId = $this->stocked('10', '50');

        $stocktakeId = $this->start([$itemId]);

        $this->countAll($stocktakeId, [$itemId => '8']);
        $this->takes->approve($stocktakeId, $this->keeperId);
        $this->closeThrough(date('Y-m-d'));

        try {
            $this->takes->cancel($stocktakeId, 'late', $this->keeperId);
            $this->fail('A stocktake cannot be reversed into a closed period.');
        } catch (PostingException $exception) {
            $this->assertSame(PostingError::PeriodClosed, $exception->error);
        }

        $this->assertSame('posted', $this->journalsOfSource(StockPermitSources::STOCKTAKE, $stocktakeId)[0]->status);
        $this->assertSame('8.000', $this->onHand($itemId));
        $this->assertSame('approved', $this->takes->find($stocktakeId)->status);
        $this->assertBooksTie();
    }

    public function testCancel_RequiresThePermissionThatFitsTheStateAndRejectsACancelledOrMissingOneOrNoReason(): void
    {
        $itemId = $this->stocked('10', '50');

        $approved = $this->takes->create(['location_id' => self::LOCATION_ID, 'date' => date('Y-m-d', strtotime('-1 day')), 'item_ids' => [$itemId]], $this->keeperId);

        $this->countAll($approved, [$itemId => '9']);
        $this->takes->approve($approved, $this->keeperId);

        $counting = $this->start([$itemId]);

        $manager  = $this->keeper(null, [StockPermissions::STOCKTAKE_MANAGE, 'items_stock']);
        $approver = $this->keeper(null, [StockPermissions::STOCKTAKE_APPROVE, 'items_stock']);

        $this->refusedWith(AccountingError::PermissionDenied, fn () => $this->takes->cancel($approved, 'no', $manager));
        $this->refusedWith(AccountingError::PermissionDenied, fn () => $this->takes->cancel($counting, 'no', $approver));
        $this->refusedWith(AccountingError::ReasonRequired, fn () => $this->takes->cancel($counting, ' ', $this->keeperId));

        $this->takes->cancel($counting, 'by the manager', $manager);
        $this->takes->cancel($approved, 'by the approver', $approver);

        $this->refusedWith(AccountingError::StocktakeCancelled, fn () => $this->takes->cancel($counting, 'again', $this->keeperId));
        $this->refusedWith(AccountingError::StocktakeNotFound, fn () => $this->takes->cancel(999999, 'none', $this->keeperId));
        $this->assertSame('10.000', $this->onHand($itemId));
    }

    public function testNumberOf_NumbersAStocktakeWithItsOwnSequenceAndShowsTheKindAndTheIdWhileItIsOff(): void
    {
        $itemId = $this->stocked('10', '50');

        $plain = $this->start([$itemId]);

        $this->assertSame('STKT ' . $plain, $this->takes->numberOf($plain));
        $this->assertNull($this->takes->peekNumber());

        $this->numberWith(DocumentType::Stocktake, 'CNT-', 4);

        $numbered = $this->takes->create(['location_id' => self::LOCATION_ID, 'date' => date('Y-m-d', strtotime('-1 day'))], $this->keeperId);

        $this->assertSame('CNT-0001', $this->takes->numberOf($numbered));
        $this->assertSame('CNT-0002', $this->takes->peekNumber());
        $this->assertSame('CNT-0001', $this->takes->find($numbered)->document_number);
    }

    public function testSearch_FiltersByStatusLocationDateAndNumberAndShowsOnlyTheBranchesOfTheEmployee(): void
    {
        $other  = $this->createBranch();
        $store  = $this->locationOfBranch($other, 'Branch store');
        $this->allowLocation($store, $this->keeperId);
        $itemId = $this->stocked('10', '50');
        $this->numberWith(DocumentType::Stocktake, 'CNT-', 3);

        $old       = $this->takes->create(['location_id' => self::LOCATION_ID, 'date' => date('Y-m-d', strtotime('-5 days')), 'note' => 'year end'], $this->keeperId);
        $current   = $this->start([$itemId]);
        $ofOther   = $this->takes->create(['location_id' => $store, 'date' => date('Y-m-d')], $this->keeperId);
        $this->takes->cancel($old, 'x', $this->keeperId);

        $ids = static fn (array $result): array => array_map(static fn (object $row): int => (int) $row->stocktake_id, $result['rows']);

        $this->assertSame([$ofOther, $current, $old], $ids($this->takes->search([], $this->keeperId)));
        $this->assertSame([$old], $ids($this->takes->search(['status' => 'cancelled'], $this->keeperId)));
        $this->assertSame([$ofOther], $ids($this->takes->search(['location_id' => $store], $this->keeperId)));
        $this->assertSame([$old], $ids($this->takes->search(['to' => date('Y-m-d', strtotime('-4 days'))], $this->keeperId)));
        $this->assertSame([$old], $ids($this->takes->search(['search' => 'year'], $this->keeperId)));
        $this->assertSame([$current], $ids($this->takes->search(['search' => 'CNT-002'], $this->keeperId)));
        $this->assertSame([$current, $old], $ids($this->takes->search([], $this->keeper([1]))));
        $this->assertSame([$ofOther], $ids($this->takes->search([], $this->keeper([$other]))));
        $this->assertSame([], $this->takes->search([], $this->keeper([]))['rows']);
        $this->assertSame(3, $this->takes->search([], $this->keeperId)['total']);
    }

    public function testSearch_AcceptsTheLabelAndTheIdOfAStocktakeWhileNumberingIsOff(): void
    {
        $itemId = $this->stocked('10', '50');

        $stocktakeId = $this->start([$itemId]);
        $other       = $this->takes->create(['location_id' => self::LOCATION_ID, 'date' => date('Y-m-d', strtotime('-1 day'))], $this->keeperId);

        $ids = static fn (array $result): array => array_map(static fn (object $row): int => (int) $row->stocktake_id, $result['rows']);

        $this->assertSame(['STKT ' . $stocktakeId, 'STKT ' . $other], array_map(static fn (object $row): string => $row->label, $this->takes->search([], $this->keeperId)['rows']));
        $this->assertSame([$stocktakeId], $ids($this->takes->search(['search' => 'STKT ' . $stocktakeId], $this->keeperId)));
        $this->assertSame([$other], $ids($this->takes->search(['search' => '#' . $other], $this->keeperId)));
    }

    public function testApprove_LeavesTheQuantityAdjustmentsOfTheItemScreensAlone(): void
    {
        $itemId = $this->stocked('10', '50');

        $stocktakeId = $this->start([$itemId]);

        $this->countAll($stocktakeId, [$itemId => '8']);
        $this->takes->approve($stocktakeId, $this->keeperId);

        $this->assertSame(0, $this->accountingDb()->table('accounting_journals')->where('source_type', 'stock_adjustment')->countAllResults());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditOf(int $stocktakeId, string $action): array
    {
        return array_map(
            static fn (object $row): array => (array) json_decode((string) $row->details, true),
            $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'stocktake', 'entity_id' => $stocktakeId, 'action' => $action])->orderBy('audit_id', 'ASC')->get()->getResult(),
        );
    }

    private function countedOf(int $stocktakeId, int $itemId): ?string
    {
        $count = $this->orderedLines($stocktakeId, [$itemId])[0]->counted_quantity;

        return $count === null ? null : bcadd((string) $count, '0', 3);
    }

    public function testCreate_RejectsAnItemThatIsOnAnotherStocktakeOfTheLocationStillBeingCounted(): void
    {
        $itemId = $this->stocked('10', '50');
        $other  = $this->stocked('6', '20');

        $first = $this->start([$itemId]);

        try {
            $this->start([$other, $itemId], ['date' => date('Y-m-d', strtotime('-1 day'))]);
            $this->fail('An item on an open count must be refused on a second one.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::StocktakeItemOpen, $exception->error);
            $this->assertSame($this->takes->numberOf($first), $exception->context['code']);
            $this->assertSame($this->itemName($itemId), $exception->context['name']);
        }

        $this->assertSame(1, $this->countRows('stocktakes'), 'the refused count leaves no stocktake behind');
        $this->assertSame(1, $this->countRows('stocktake_lines'));
    }

    public function testCreate_RejectsEveryItemOfTheLocationWhenOneOfThemIsOnAnOpenCount(): void
    {
        $itemId = $this->stocked('10', '50');

        $this->start([$itemId]);

        $this->refusedWith(AccountingError::StocktakeItemOpen, fn () => $this->takes->create(['location_id' => self::LOCATION_ID, 'date' => date('Y-m-d', strtotime('-1 day')), 'all_items' => true], $this->keeperId));
    }

    public function testAddItems_RejectsAnItemThatIsOnAnotherStocktakeOfTheLocationStillBeingCounted(): void
    {
        $itemId = $this->stocked('10', '50');

        $this->start([$itemId]);

        $second = $this->takes->create(['location_id' => self::LOCATION_ID, 'date' => date('Y-m-d', strtotime('-1 day'))], $this->keeperId);

        $this->refusedWith(AccountingError::StocktakeItemOpen, fn () => $this->takes->addItems($second, [$itemId], $this->keeperId));
        $this->assertSame([], $this->takes->lines($second));
    }

    public function testCreate_AcceptsAnItemOnceTheOtherCountIsApprovedOrCancelledAndInAnotherLocation(): void
    {
        $itemId = $this->stocked('10', '50');
        $second = $this->openLocation();

        $first = $this->start([$itemId]);

        $this->assertGreaterThan(0, $this->takes->create(['location_id' => $second, 'date' => date('Y-m-d'), 'item_ids' => [$itemId]], $this->keeperId), 'another location counts its own shelf of the item');

        $this->countAll($first, [$itemId => '8']);
        $this->takes->approve($first, $this->keeperId);

        $afterApproval = $this->start([$itemId], ['date' => date('Y-m-d', strtotime('-1 day'))]);

        $this->assertSame('8.000', bcadd((string) $this->orderedLines($afterApproval, [$itemId])[0]->system_quantity, '0', 3), 'the next count freezes the shelf the approval left');

        $this->takes->cancel($afterApproval, 'not needed', $this->keeperId);

        $this->assertGreaterThan(0, $this->start([$itemId], ['date' => date('Y-m-d', strtotime('-2 days'))]), 'a cancelled count lets go of its items');
    }

    public function testCreate_ListsAnItemOnceWhenTwoCountsOfItComeAtTheSameMoment(): void
    {
        $itemId = $this->stocked('10', '50');

        $answers = $this->raceStockWorkers(
            'createStocktake',
            [(string) self::LOCATION_ID, date('Y-m-d'), (string) $itemId, (string) $this->keeperId],
            [(string) self::LOCATION_ID, date('Y-m-d', strtotime('-1 day')), (string) $itemId, (string) $this->keeperId],
        );

        $this->assertSame(['done', AccountingError::StocktakeItemOpen->value], $answers);
        $this->assertSame(1, $this->countRows('stocktake_lines'));
    }

    public function testAddItems_ListsAnItemOnceWhenTwoCountsOfItComeAtTheSameMoment(): void
    {
        $itemId = $this->stocked('10', '50');
        $first  = $this->start([]);
        $second = $this->start([], ['date' => date('Y-m-d', strtotime('-1 day'))]);

        $answers = $this->raceStockWorkers(
            'addStocktakeItems',
            [(string) $first, (string) $itemId, (string) $this->keeperId],
            [(string) $second, (string) $itemId, (string) $this->keeperId],
        );

        $this->assertSame(['done', AccountingError::StocktakeItemOpen->value], $answers);
        $this->assertSame(1, $this->countRows('stocktake_lines'));
    }

    public function testCount_AcceptsATypedFigureOverTheCountTheSheetShowed(): void
    {
        $first  = $this->stocked('10', '50');
        $second = $this->stocked('4', '20');

        $stocktakeId = $this->start([$first, $second]);

        $this->countAll($stocktakeId, [$first => '8']);
        $this->takes->count($stocktakeId, [$first => '7', $second => '3'], $this->keeperId, [$first => '8', $second => '']);

        $this->assertSame(['7.000', '3.000'], [$this->countedOf($stocktakeId, $first), $this->countedOf($stocktakeId, $second)]);

        $this->takes->count($stocktakeId, [$first => ''], $this->keeperId, [$first => '7']);

        $this->assertNull($this->countedOf($stocktakeId, $first), 'a figure typed away takes the count back when the sheet showed it');
    }

    public function testCount_AcceptsASheetThatShowedACountAnotherPersonSavedLaterWithoutBlankingIt(): void
    {
        $first  = $this->stocked('10', '50');
        $second = $this->stocked('4', '20');

        $stocktakeId = $this->start([$first, $second]);

        $this->countAll($stocktakeId, [$first => '8']);
        $this->takes->count($stocktakeId, [$first => '', $second => '3'], $this->keeperId, [$first => '', $second => '']);

        $this->assertSame(['8.000', '3.000'], [$this->countedOf($stocktakeId, $first), $this->countedOf($stocktakeId, $second)], 'the row the saver left alone keeps what another person counted');
    }

    public function testCount_RejectsAFigureTypedOverACountThatChangedAfterTheSheetWasOpened(): void
    {
        $first  = $this->stocked('10', '50');
        $second = $this->stocked('4', '20');

        $stocktakeId = $this->start([$first, $second]);

        $this->countAll($stocktakeId, [$first => '8']);

        try {
            $this->takes->count($stocktakeId, [$first => '9', $second => '3'], $this->keeperId, [$first => '', $second => '']);
            $this->fail('A figure typed over a count that changed since must be refused.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::StocktakeCountChanged, $exception->error);
            $this->assertSame($this->itemName($first), $exception->context['name']);
        }

        $this->assertSame(['8.000', null], [$this->countedOf($stocktakeId, $first), $this->countedOf($stocktakeId, $second)], 'the whole save is refused, the rows that were fine too');
        $this->assertCount(1, $this->auditOf($stocktakeId, 'stocktake_counted'));
    }

    public function testCount_AcceptsTheSameFigureAnotherPersonSavedAlreadyWithoutWritingItAgain(): void
    {
        $itemId = $this->stocked('10', '50');

        $stocktakeId = $this->start([$itemId]);

        $this->countAll($stocktakeId, [$itemId => '8']);
        $this->takes->count($stocktakeId, [$itemId => '8.000'], $this->keeperId, [$itemId => '']);

        $this->assertSame('8.000', $this->countedOf($stocktakeId, $itemId));
        $this->assertCount(1, $this->auditOf($stocktakeId, 'stocktake_counted'), 'a figure that is the stored one changes nothing and is not logged');
    }

    public function testCount_RejectsAQuantityThatIsAList(): void
    {
        $itemId = $this->stocked('10', '50');

        $stocktakeId = $this->start([$itemId]);

        $this->refusedWith(AccountingError::StocktakeLinesInvalid, fn () => $this->takes->count($stocktakeId, [$itemId => ['1']], $this->keeperId));
        $this->refusedWith(AccountingError::StocktakeLinesInvalid, fn () => $this->takes->count($stocktakeId, [$itemId => '1'], $this->keeperId, [$itemId => ['1']]));
        $this->assertNull($this->countedOf($stocktakeId, $itemId));
    }

    public function testCount_WritesTheAuditLogOfEachChangedCountWithTheFigureBeforeAndAfter(): void
    {
        $first  = $this->stocked('10', '50');
        $second = $this->stocked('4', '20');

        $stocktakeId = $this->start([$first, $second]);

        $this->countAll($stocktakeId, [$first => '8', $second => '']);
        $this->countAll($stocktakeId, [$first => '7', $second => '']);

        $this->assertSame(
            [
                ['counts' => [(string) $first => ['from' => null, 'to' => '8.000']]],
                ['counts' => [(string) $first => ['from' => '8.000', 'to' => '7.000']]],
            ],
            $this->auditOf($stocktakeId, 'stocktake_counted'),
            'only the rows that changed are logged, with what they were and what they became',
        );
    }

    public function testAddItems_WritesTheAuditLogOfTheItemsThatWereListed(): void
    {
        $first  = $this->stocked('10', '50');
        $second = $this->stocked('4', '20');

        $stocktakeId = $this->start([$first]);

        $this->takes->addItems($stocktakeId, [$first, $second], $this->keeperId);
        $this->takes->addItems($stocktakeId, [$first], $this->keeperId);

        $this->assertSame([['items' => [$second]]], $this->auditOf($stocktakeId, 'stocktake_items_added'), 'an item listed already is not logged again');
    }

    public function testRemoveItem_WritesTheAuditLogOfTheLineWithWhatWasFrozenAndCounted(): void
    {
        $first  = $this->stocked('10', '50');
        $second = $this->stocked('4', '20');

        $stocktakeId = $this->start([$first, $second]);

        $this->countAll($stocktakeId, [$second => '2']);
        $this->takes->removeItem($stocktakeId, $second, $this->keeperId);
        $this->takes->removeItem($stocktakeId, $second, $this->keeperId);

        $this->assertSame([['item_id' => $second, 'system_quantity' => '4.000', 'counted' => '2.000']], $this->auditOf($stocktakeId, 'stocktake_item_removed'), 'a line that was not there leaves no entry');
    }

    public function testApprove_AcceptsLinesAsTheApproverReviewedThemAndLogsTheirDigest(): void
    {
        $itemId = $this->stocked('10', '50');

        $stocktakeId = $this->start([$itemId]);

        $this->countAll($stocktakeId, [$itemId => '8']);

        $digest = $this->takes->digest($stocktakeId);

        $this->takes->approve($stocktakeId, $this->keeperId, $digest);

        $this->assertSame('approved', $this->takes->find($stocktakeId)->status);
        $this->assertSame([$digest], array_column($this->auditOf($stocktakeId, 'stocktake_approved'), 'digest'));
    }

    public function testApprove_RejectsLinesThatChangedAfterTheApproverReviewedThem(): void
    {
        $first  = $this->stocked('10', '50');
        $second = $this->stocked('4', '20');

        $stocktakeId = $this->start([$first]);

        $this->countAll($stocktakeId, [$first => '8']);

        $reviewed = $this->takes->digest($stocktakeId);

        $this->countAll($stocktakeId, [$first => '0']);
        $this->refusedWith(AccountingError::StocktakeChanged, fn () => $this->takes->approve($stocktakeId, $this->keeperId, $reviewed));

        $afterCount = $this->takes->digest($stocktakeId);

        $this->assertNotSame($reviewed, $afterCount);

        $this->takes->addItems($stocktakeId, [$second], $this->keeperId);
        $this->refusedWith(AccountingError::StocktakeChanged, fn () => $this->takes->approve($stocktakeId, $this->keeperId, $afterCount));

        $afterAdd = $this->takes->digest($stocktakeId);

        $this->takes->removeItem($stocktakeId, $second, $this->keeperId);
        $this->refusedWith(AccountingError::StocktakeChanged, fn () => $this->takes->approve($stocktakeId, $this->keeperId, $afterAdd));
        $this->refusedWith(AccountingError::StocktakeChanged, fn () => $this->takes->approve($stocktakeId, $this->keeperId, ''));

        $this->assertSame('10.000', $this->onHand($first), 'nothing was posted by the refusals');
        $this->assertSame('counting', $this->takes->find($stocktakeId)->status);
    }

    public function testDigest_ChangesWhenTheFrozenQuantityOrTheCountOfALineChanges(): void
    {
        $itemId = $this->stocked('10', '50');

        $stocktakeId = $this->start([$itemId]);

        $uncounted = $this->takes->digest($stocktakeId);

        $this->countAll($stocktakeId, [$itemId => '8']);

        $counted = $this->takes->digest($stocktakeId);

        $this->assertNotSame($uncounted, $counted, 'the count is part of what was reviewed');
        $this->assertSame($counted, $this->takes->digest($stocktakeId), 'the same lines give the same digest');

        $this->accountingDb()->table('stocktake_lines')->where(['stocktake_id' => $stocktakeId, 'item_id' => $itemId])->update(['system_quantity' => '9']);

        $this->assertNotSame($counted, $this->takes->digest($stocktakeId), 'the quantity frozen is part of what was reviewed');
    }

    /**
     * Runs the action and, the moment it has locked its items, adds a line to the stocktake over a second
     * connection that commits at once, as a request that lists one more item would.
     */
    private function whileLockedAnotherRequestListsOneMoreItem(int $stocktakeId, int $itemId, callable $action): void
    {
        $link     = $this->openSecondConnection(false);
        $fired    = false;
        $listener = static function ($query) use (&$fired, $link, $stocktakeId, $itemId): void {
            $sql = (string) $query->getQuery();

            if (! $fired && str_contains($sql, 'ospos_items WHERE item_id IN') && str_contains($sql, 'FOR UPDATE')) {
                $fired = true;
                $link->query('INSERT INTO ' . $link->prefixTable('stocktake_lines') . ' (stocktake_id, item_id, system_quantity, counted_quantity) VALUES (?, ?, 1, 1)', [$stocktakeId, $itemId]);
            }
        };

        Events::on('DBQuery', $listener);

        try {
            $action();
            $this->fail('A change prepared on what the stocktake listed before must be asked to be made again.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::WriteFailed, $exception->error);
            $this->assertTrue($fired);
        } finally {
            Events::removeListener('DBQuery', $listener);
        }
    }

    public function testApprove_AsksToBeMadeAgainWhenAnotherRequestListedMoreItemsAfterTheyWereRead(): void
    {
        $first  = $this->stocked('10', '50');
        $second = $this->stocked('4', '20');

        $stocktakeId = $this->start([$first]);

        $this->countAll($stocktakeId, [$first => '8']);

        $this->whileLockedAnotherRequestListsOneMoreItem($stocktakeId, $second, fn () => $this->takes->approve($stocktakeId, $this->keeperId));

        $this->assertSame('counting', $this->takes->find($stocktakeId)->status);
        $this->assertSame('10.000', $this->onHand($first));
    }

    public function testCancel_AsksToBeMadeAgainWhenMoreItemsWereListedAfterTheyWereRead(): void
    {
        $first  = $this->stocked('10', '50');
        $second = $this->stocked('4', '20');

        $stocktakeId = $this->start([$first]);

        $this->countAll($stocktakeId, [$first => '8']);
        $this->takes->approve($stocktakeId, $this->keeperId);

        $this->whileLockedAnotherRequestListsOneMoreItem($stocktakeId, $second, fn () => $this->takes->cancel($stocktakeId, 'x', $this->keeperId));

        $this->assertSame('approved', $this->takes->find($stocktakeId)->status);
        $this->assertSame('8.000', $this->onHand($first));
    }

    public function testCreate_ReportsASecondStocktakeOfTheDayEvenWhenTheConnectionReturnsFalse(): void
    {
        $itemId = $this->stocked('10', '50');

        $this->start([$itemId]);

        $quiet = new Stocktake($this->openSecondConnection(false));

        $this->refusedWith(AccountingError::StocktakeExists, fn () => $quiet->create(['location_id' => self::LOCATION_ID, 'date' => date('Y-m-d'), 'item_ids' => [$itemId]], $this->keeperId));
        $this->assertSame(1, $this->countRows('stocktakes'));
        $this->assertSame(1, $this->countRows('stocktake_lines'));
    }

    public function testApprove_RejectsAReversalByHandOfTheJournalOfAStocktake(): void
    {
        $itemId = $this->stocked('10', '50');

        $stocktakeId = $this->start([$itemId]);

        $this->countAll($stocktakeId, [$itemId => '8']);
        $this->takes->approve($stocktakeId, $this->keeperId);

        $reverser = $this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_DRAFT, AccountingGuard::JOURNALS_POST, AccountingGuard::JOURNALS_REVERSE, AccountingGuard::JOURNALS_ALL]);
        $journal  = $this->journalsOfSource(StockPermitSources::STOCKTAKE, $stocktakeId)[0];

        $this->refusedWith(AccountingError::DocumentJournalLocked, fn () => (new JournalService())->reverse((int) $journal->journal_id, date('Y-m-d'), 'By hand', $reverser));
        $this->assertSame('posted', $this->journalsOfSource(StockPermitSources::STOCKTAKE, $stocktakeId)[0]->status);
    }

    public function testCancel_ReversesTodayWhenTheDayOfTheJournalIsInAClosedPeriodAndTheJournalStaysWhereItWas(): void
    {
        $itemId = $this->stocked('10', '50');

        $stocktakeId = $this->start([$itemId]);

        $this->countAll($stocktakeId, [$itemId => '8']);
        $this->takes->approve($stocktakeId, $this->keeperId);

        $day = date('Y-m-d', strtotime('-3 days'));

        $this->accountingDb()->table('accounting_journals')->where(['source_type' => StockPermitSources::STOCKTAKE, 'source_id' => $stocktakeId])->update(['journal_date' => $day]);
        $this->closeThrough(date('Y-m-d', strtotime('-1 day')));
        $this->takes->cancel($stocktakeId, 'late', $this->keeperId);

        $journal  = $this->journalsOfSource(StockPermitSources::STOCKTAKE, $stocktakeId)[0];
        $reversal = $this->accountingDb()->table('accounting_journals')->where('reverses_journal_id', $journal->journal_id)->get()->getRow();

        $this->assertSame($day, $journal->journal_date);
        $this->assertSame(date('Y-m-d'), $reversal->journal_date);
        $this->assertSame('10.000', $this->onHand($itemId));
    }

    public function testCancel_RequiresTheItemsPermissionOfTheLocationAsWellAsThePermission(): void
    {
        $itemId = $this->stocked('10', '50');

        $stocktakeId = $this->start([$itemId]);

        $this->refusedWith(AccountingError::StockLocationDenied, fn () => $this->takes->cancel($stocktakeId, 'no', $this->keeper(null, [StockPermissions::STOCKTAKE_MANAGE])));
        $this->assertSame('counting', $this->takes->find($stocktakeId)->status);
    }
}
