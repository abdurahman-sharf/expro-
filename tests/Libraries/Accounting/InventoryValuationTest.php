<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\InventoryValuation;
use App\Libraries\Accounting\InventoryValuationLine;
use App\Libraries\Accounting\InventoryValuationLocation;
use App\Libraries\Accounting\InventoryValuationReport;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\OpeningStock;
use App\Libraries\Accounting\OpeningStockStatus;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\ReportScopeKind;
use App\Libraries\Accounting\StockLedger;
use App\Libraries\Accounting\StockMovement;
use App\Libraries\Accounting\TrialBalance;
use App\Models\Reports\Inventory_summary;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\ParityFixture;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;
use Tests\Support\ShelfFixtureTrait;
use Tests\Support\StockBooksFixtureTrait;

/**
 * What the stock is worth by the books, location by location and item by item: that it comes from
 * the valued movements and not from the cost an item carries times its quantity, that it ties to
 * the account of each location, which branches and which day it adds up, and what it says of units
 * that stand on the shelves with no movement behind them.
 */
class InventoryValuationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use ShelfFixtureTrait;
    use StockBooksFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private InventoryValuation $valuation;
    private StockLedger $ledger;
    private int $second;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBuying();
        $this->emptyShelves();

        $this->second    = $this->createBranch('V2', 'Second branch');
        $this->valuation = new InventoryValuation();
        $this->ledger    = new StockLedger();
    }

    protected function tearDown(): void
    {
        $this->stopBuying();
        $this->restoreShelves();

        parent::tearDown();
    }

    private function company(): ReportScope
    {
        return ReportScope::resolve(null, null);
    }

    private function build(?ReportScope $scope = null, ?string $asOf = null, bool $showEmpty = false): InventoryValuationReport
    {
        return $this->valuation->build($scope ?? $this->company(), $asOf, $showEmpty);
    }

    private function location(InventoryValuationReport $report, int $locationId): ?InventoryValuationLocation
    {
        foreach ($report->locations as $location) {
            if ($location->locationId === $locationId) {
                return $location;
            }
        }

        return null;
    }

    private function lineOf(?InventoryValuationLocation $location, int $itemId): ?InventoryValuationLine
    {
        foreach ($location->lines ?? [] as $line) {
            if ($line->position->itemId === $itemId) {
                return $line;
            }
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private function locationIds(InventoryValuationReport $report): array
    {
        return array_map(static fn (InventoryValuationLocation $location): int => $location->locationId, $report->locations);
    }

    public function testBuild_ShowsTheQuantityAverageCostAndValueOfEachItemInEachLocation(): void
    {
        $first  = $this->item('0.00');
        $second = $this->item('0.00');
        $depot  = $this->anotherLocation('Depot');

        $this->bookStock('2026-10-05', 1, $first, '10', '100.00');
        $this->bookStock('2026-10-06', 1, $first, '5', '75.00');
        $this->bookStock('2026-10-07', 1, $second, '4', '30.00', $depot);

        $report = $this->build();

        $main = $this->location($report, self::LOCATION_ID);
        $line = $this->lineOf($main, $first);

        $this->assertSame('15.000', $line->position->quantity);
        $this->assertSame('175.0000', $line->position->value);
        $this->assertSame('11.66666666', bcadd((string) $line->averageCost(), '0', 8));
        $this->assertSame('175.0000', $main->value);
        $this->assertNull($this->lineOf($main, $second), 'An item is listed in the locations it has stock in only');

        $other = $this->lineOf($this->location($report, $depot), $second);

        $this->assertSame(['4.000', '30.0000', '7.50000000'], [$other->position->quantity, $other->position->value, bcadd((string) $other->averageCost(), '0', 8)]);
    }

    public function testBuild_TotalsEveryLocationAndTheCompany(): void
    {
        $item  = $this->item('0.00');
        $depot = $this->anotherLocation('Depot');

        $this->bookStock('2026-10-05', 1, $item, '10', '100.00');
        $this->bookStock('2026-10-06', 1, $item, '3', '45.50', $depot);

        $report = $this->build();

        $this->assertSame('100.0000', $this->location($report, self::LOCATION_ID)->value);
        $this->assertSame('45.5000', $this->location($report, $depot)->value);
        $this->assertSame('145.5000', $report->value());
        $this->assertSame('145.5000', $report->stockAccountBalance);
        $this->assertSame(2, $report->lineCount());
        $this->assertTrue($report->scope->isCompany());
    }

    public function testBuild_TiesEachLocationToTheAccountOfTheLocationAndTheMainStockAccount(): void
    {
        $item  = $this->item('0.00');
        $depot = $this->anotherLocation('Depot');

        $this->bookStock('2026-10-05', 1, $item, '10', '100.00');
        $this->bookStock('2026-10-06', 1, $item, '3', '45.50', $depot);

        $report = $this->build();

        foreach ([self::LOCATION_ID => '100.0000', $depot => '45.5000'] as $locationId => $value) {
            $location = $this->location($report, $locationId);

            $this->assertSame($value, $location->accountBalance);
            $this->assertSame('0.0000', $location->difference());
            $this->assertTrue($location->ties());
            $this->assertSame($this->stockAccountOf($locationId), $location->accountId);
        }

        $this->assertSame('0.0000', $report->stockAccountDifference());
        $this->assertTrue($report->ties());
        $this->assertSame('1203', $report->stockAccountCode);
    }

    /**
     * Fixtures T-05 and T-11 through the real models, and the reference report: the valuation of
     * the movements is 1,314 and what the legacy report makes of the same stock is 1,320, because
     * it multiplies the cost the item carries by the units, and one unit came back at 60.00.
     */
    public function testBuild_EndsAtTheValueTheReferenceReportHeldWhileTheLegacyReportSaysMore(): void
    {
        $sequence = ParityFixture::cases('T-05', 'cost_sequence')['sequence'][0]['steps'];
        $itemId   = $this->item('0.00');
        $sale     = null;

        foreach ($sequence as $step) {
            $cost = $this->itemCostOf($itemId);

            switch ($step['op']) {
                case 'purchase':
                    $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, $step['quantity'], $step['unit_cost'])]), $step['name']);

                    break;

                case 'sale':
                    $saleId = $this->save([$this->line($itemId, 0, $step['quantity'], '100', $cost)]);
                    $this->assertGreaterThan(0, $saleId, $step['name']);
                    $sale ??= $saleId;

                    break;

                case 'return':
                    $this->assertGreaterThan(0, $this->save([$this->line($itemId, 0, '-' . $step['quantity'], '100', $cost)], [], [], SALE_TYPE_RETURN, -1, true, $sale), $step['name']);

                    break;
            }
        }

        $reference = ParityFixture::cases('T-11', 'movement_valuation')['valuation-from-movements'][0]['reference'];
        $report    = $this->build();
        $location  = $this->location($report, self::LOCATION_ID);
        $line      = $this->lineOf($location, $itemId);

        $this->assertSame(bcadd($reference['quantity'], '0', 3), $line->position->quantity);
        $this->assertSame(bcadd($reference['value'], '0', 4), $line->position->value);
        $this->assertSame('1314.0000', $report->value());
        $this->assertSame('1314.0000', $location->accountBalance);
        $this->assertTrue($location->ties());
        $this->assertFalse($line->hasUnpostedUnits());
        $this->assertSame('1320.0000', $line->valueAtItemCost(), 'Cost times quantity is what the legacy report calls the value');

        $legacy = array_sum(array_column(model(Inventory_summary::class)->getData(['item_count' => 'all', 'location_id' => (string) self::LOCATION_ID]), 'sub_total_value'));

        $this->assertSame('1320.00', bcadd((string) $legacy, '0', 2));
        $this->assertSame('6.0000', bcsub($line->valueAtItemCost(), $line->position->value, 4));
    }

    public function testBuild_AgreesWithWhatTheStockLedgerSaysForEveryLocationAndItem(): void
    {
        $first  = $this->item('0.00');
        $second = $this->item('0.00');
        $depot  = $this->locationOfBranch($this->second, 'Second depot');

        $this->workInEveryBranch();
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($first, 0, '10', '50'), $this->purchaseLine($second, 1, '6', '12.5')]));
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($first, 0, '4', '0', '0', '0', PERCENT, $depot), $this->purchaseLine($first, 1, '-4', '0')], null, null));
        $this->assertGreaterThan(0, $this->save([$this->line($first, 0, '2', '80', '50')]));

        $report = $this->build();

        foreach ($this->ledger->positions() as $position) {
            $line = $this->lineOf($this->location($report, $position->locationId), $position->itemId);

            $this->assertNotNull($line, 'Item ' . $position->itemId . ' in location ' . $position->locationId);
            $this->assertSame($position->quantity, $line->position->quantity);
            $this->assertSame($position->value, $line->position->value);
            $this->assertSame($position->onHand, $line->position->onHand);
            $this->assertSame($position->unposted(), $line->unposted());
        }

        foreach ($this->ledger->reconcile() as $row) {
            $location = $this->location($report, $row['location_id']);

            if ($location === null) {
                $this->assertSame('0.0000', $row['movements_value']);

                continue;
            }

            $this->assertSame($row['account_balance'], $location->accountBalance);
            $this->assertSame($row['movements_value'], $location->value);
            $this->assertSame($row['unattributed'], $location->difference());
        }

        $this->assertSame('475.0000', $report->value(), '500 and 75 bought, 200 moved to the other branch, 100 sold at cost');
        $this->assertTrue($report->ties());
    }

    public function testBuild_FlagsALocationWhoseAccountHoldsWhatNoMovementAccountsFor(): void
    {
        $item = $this->item('0.00');

        $this->bookStock('2026-10-05', 1, $item, '10', '100.00');
        $this->manualToStock('30.00');

        $report   = $this->build();
        $location = $this->location($report, self::LOCATION_ID);

        $this->assertSame('130.0000', $location->accountBalance);
        $this->assertSame('100.0000', $location->value);
        $this->assertSame('30.0000', $location->difference());
        $this->assertFalse($location->ties());
        $this->assertFalse($report->ties());
        $this->assertSame('30.0000', $report->stockAccountDifference());
    }

    public function testBuild_DoesNotTieWhenTwoLocationsDifferInOppositeWaysThoughTheStockAccountsAddUp(): void
    {
        $item  = $this->item('0.00');
        $depot = $this->anotherLocation('Depot');

        $this->bookStock('2026-10-05', 1, $item, '10', '100.00');
        $this->bookStock('2026-10-05', 1, $item, '10', '100.00', $depot);
        $this->manualToStock('30.00');
        $this->ledger->record('purchase', 889003, JournalEntryType::General, 1, '2026-10-06', 1, [new StockMovement($item, $depot, '1', '30.00')], null);

        $report = $this->build();

        $this->assertSame('30.0000', $this->location($report, self::LOCATION_ID)->difference());
        $this->assertSame('-30.0000', $this->location($report, $depot)->difference());
        $this->assertSame('0.0000', $report->stockAccountDifference(), 'The two cancel out in the main stock account');
        $this->assertFalse($report->ties(), 'But each location is wrong');
    }

    public function testBuild_FlagsAMovementThatReachedNoAccount(): void
    {
        $item = $this->item('0.00');

        $this->ledger->record('purchase', 889001, JournalEntryType::General, 1, '2026-10-05', 1, [new StockMovement($item, self::LOCATION_ID, '5', '50.00')], null);

        $report   = $this->build();
        $location = $this->location($report, self::LOCATION_ID);

        $this->assertSame('0.0000', $location->accountBalance);
        $this->assertSame('-50.0000', $location->difference());
        $this->assertFalse($location->ties());
        $this->assertSame('-50.0000', $report->stockAccountDifference());
    }

    public function testBuild_ShowsAMovementOfALocationThatHasNoAccountAsOneThatDoesNotTie(): void
    {
        $item  = $this->item('0.00');
        $depot = $this->insertStockLocation('Depot without an account');

        $this->ledger->record('purchase', 889002, JournalEntryType::General, 1, '2026-10-05', 1, [new StockMovement($item, $depot, '2', '20.00')], null);

        $location = $this->location($this->build(), $depot);

        $this->assertNull($location->accountId);
        $this->assertNull($location->accountCode);
        $this->assertSame('-20.0000', $location->difference());
        $this->assertFalse($location->ties());
    }

    public function testBuild_SeesValuePostedToAnAccountUnderTheMainStockAccountThatIsNoLocation(): void
    {
        $item   = $this->item('0.00');
        $custom = $this->customAccount('1203', '120399', 'Stock outside the locations');

        $this->bookStock('2026-10-05', 1, $item, '10', '100.00');

        (new PostingService())->post(new JournalDraft(
            date: '2026-10-06',
            lines: [new JournalLineDraft($custom, debit: '25.00'), new JournalLineDraft($this->accountId('opening_balance'), credit: '25.00')],
            sourceType: JournalDraft::SOURCE_MANUAL,
            currencyCode: 'SAR',
        ));

        $report = $this->build();

        $this->assertTrue($this->location($report, self::LOCATION_ID)->ties(), 'The location itself is right');
        $this->assertSame('125.0000', $report->stockAccountBalance);
        $this->assertSame('25.0000', $report->stockAccountDifference());
        $this->assertFalse($report->ties());
    }

    public function testBuild_LeavesOutADraftJournalOfTheStockAccount(): void
    {
        $item = $this->item('0.00');

        $this->bookStock('2026-10-05', 1, $item, '10', '100.00');

        (new JournalService())->saveDraft([
            'date'        => '2026-10-06',
            'description' => 'Draft',
            'lines'       => [
                ['account_id' => $this->stockAccountOf(self::LOCATION_ID), 'debit' => '500.00', 'credit' => ''],
                ['account_id' => $this->accountId('opening_balance'), 'debit' => '', 'credit' => '500.00'],
            ],
        ], null);

        $report = $this->build();

        $this->assertSame('100.0000', $this->location($report, self::LOCATION_ID)->accountBalance);
        $this->assertSame('100.0000', $report->stockAccountBalance);
        $this->assertTrue($report->ties());
    }

    public function testBuild_ListsUnitsOnTheShelvesThatNoMovementAccountsForAndDoesNotValueThem(): void
    {
        $item  = $this->item('12.00');
        $other = $this->item('9.00');

        $this->putOnShelf($item, '5');
        $this->bookStock('2026-10-05', 1, $other, '4', '36.00');
        $this->putOnShelf($other, '4');

        $report   = $this->build();
        $location = $this->location($report, self::LOCATION_ID);
        $line     = $this->lineOf($location, $item);

        $this->assertTrue($line->hasUnpostedUnits());
        $this->assertSame('5.000', $line->unposted());
        $this->assertSame('5.000', $line->onHand());
        $this->assertSame('0.000', $line->position->quantity);
        $this->assertSame('0.0000', $line->position->value, 'Units no movement accounts for are not given the cost of the item');
        $this->assertNull($line->averageCost());
        $this->assertFalse($this->lineOf($location, $other)->hasUnpostedUnits());
        $this->assertSame(1, $location->unpostedLines());
        $this->assertSame(1, $report->unpostedLines());
        $this->assertSame('36.0000', $report->value());
    }

    public function testBuild_ShowsALocationWhoseOpeningStockWasPostedAsTiedAndOneThatGrewSinceAsPartiallyPosted(): void
    {
        $first  = $this->item('10.00');
        $second = $this->item('20.00');

        $this->putOnShelf($first, '7');

        $results = (new OpeningStock())->post([self::LOCATION_ID], null);

        $this->assertCount(1, $results);

        $report   = $this->build();
        $location = $this->location($report, self::LOCATION_ID);

        $this->assertSame('70.0000', $location->value);
        $this->assertTrue($location->ties());
        $this->assertSame(0, $location->unpostedLines());
        $this->assertSame('7.000', $this->lineOf($location, $first)->position->quantity);

        $this->putOnShelf($second, '3');
        $this->putOnShelf($first, '9');

        $grown = $this->location($this->build(), self::LOCATION_ID);

        $this->assertSame('70.0000', $grown->value, 'What was not posted is not valued');
        $this->assertTrue($grown->ties());
        $this->assertSame(2, $grown->unpostedLines());
        $this->assertSame('2.000', $this->lineOf($grown, $first)->unposted());
        $this->assertSame('3.000', $this->lineOf($grown, $second)->unposted());
    }

    public function testBuild_FlagsTheItemsThatThePartialOpeningOfALocationLeftOut(): void
    {
        $rice  = $this->item('50.00');
        $owed  = $this->item('3.00');
        $short = $this->item('0.00');

        $this->putOnShelf($rice, '10');
        $this->putOnShelf($owed, '-3');
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($short, 0, '10', '20')]));
        $this->putOnShelf($short, '7');

        $result = (new OpeningStock())->post([self::LOCATION_ID], null)[0];

        $this->assertSame(OpeningStockStatus::PartiallyPosted, $result->plan->status);

        $report   = $this->build();
        $location = $this->location($report, self::LOCATION_ID);

        $this->assertTrue($location->ties(), 'What was posted is in the account');
        $this->assertSame('700.0000', $report->value(), '10 of rice at 50 posted, and 10 bought at 20');
        $this->assertFalse($this->lineOf($location, $rice)->hasUnpostedUnits());
        $this->assertSame(['-3.000', '-3.000'], [$this->lineOf($location, $owed)->unposted(), $this->lineOf($location, $short)->unposted()]);
        $this->assertSame(2, $report->unpostedLines(), 'The two items the opening left out are flagged and not valued');
        $this->assertSame('0.0000', $this->lineOf($location, $owed)->position->value);
    }

    public function testBuild_ShowsStockThatWentNegativeWithoutAnAverageCost(): void
    {
        $item = $this->item('0.00');

        $this->bookStock('2026-10-05', 1, $item, '2', '20.00');
        $this->bookStock('2026-10-06', 1, $item, '-5', '-50.00');
        $this->putOnShelf($item, '-3');

        $line = $this->lineOf($this->location($this->build(), self::LOCATION_ID), $item);

        $this->assertSame(['-3.000', '-30.0000'], [$line->position->quantity, $line->position->value]);
        $this->assertNull($line->averageCost());
        $this->assertFalse($line->hasUnpostedUnits());
    }

    public function testBuild_ShowsAnItemWhoseMovementsLeftValueButNoUnits(): void
    {
        $item = $this->item('0.00');

        $this->bookStock('2026-10-05', 1, $item, '3', '30.00');
        $this->bookStock('2026-10-06', 1, $item, '-3', '-29.95');

        $line = $this->lineOf($this->location($this->build(), self::LOCATION_ID), $item);

        $this->assertNotNull($line);
        $this->assertSame(['0.000', '0.0500'], [$line->position->quantity, $line->position->value]);
    }

    public function testBuild_LeavesOutItemsAndLocationsWithNothingInThemUnlessAsked(): void
    {
        $item  = $this->item('0.00');
        $depot = $this->anotherLocation('Empty depot');

        $this->bookStock('2026-10-05', 1, $item, '3', '30.00');
        $this->bookStock('2026-10-06', 1, $item, '-3', '-30.00');

        $this->assertSame([], $this->build()->locations);

        $shown = $this->build(showEmpty: true);

        $this->assertContains($depot, $this->locationIds($shown));
        $this->assertContains(self::LOCATION_ID, $this->locationIds($shown));
        $this->assertNotNull($this->lineOf($this->location($shown, self::LOCATION_ID), $item));
    }

    public function testBuild_ShowsALocationWithNoStockWhoseAccountHoldsSomething(): void
    {
        $this->manualToStock('40.00');

        $location = $this->location($this->build(), self::LOCATION_ID);

        $this->assertNotNull($location);
        $this->assertSame([], $location->lines);
        $this->assertSame('40.0000', $location->difference());
    }

    public function testBuild_LeavesOutItemsThatAreNotStockOnTheShelves(): void
    {
        $service = $this->item('0.00', false);

        $this->putOnShelf($service, '5');

        $this->assertSame([], $this->build()->locations);
    }

    public function testBuild_CountsTheStockUpToTheDayAsked(): void
    {
        $item = $this->item('0.00');

        $this->bookStock('2026-10-01', 1, $item, '5', '50.00');
        $this->bookStock('2026-10-10', 1, $item, '-2', '-20.00');

        $early = $this->build(asOf: '2026-10-05');
        $later = $this->build(asOf: '2026-10-10');
        $line  = $this->lineOf($this->location($early, self::LOCATION_ID), $item);

        $this->assertSame(['5.000', '50.0000', '50.0000'], [$line->position->quantity, $line->position->value, $this->location($early, self::LOCATION_ID)->accountBalance]);
        $this->assertSame(['3.000', '30.0000', '30.0000'], [
            $this->lineOf($this->location($later, self::LOCATION_ID), $item)->position->quantity,
            $this->location($later, self::LOCATION_ID)->value,
            $this->location($later, self::LOCATION_ID)->accountBalance,
        ]);
        $this->assertSame('50.0000', $early->stockAccountBalance);
        $this->assertTrue($early->ties());
        $this->assertSame('2026-10-05', $early->asOf);
        $this->assertFalse($early->current);
        $this->assertSame([], $this->build(asOf: '2026-09-30')->locations, 'Nothing was in the books before the first day');
    }

    public function testBuild_ReadsTheJournalsOfTheAccountsUpToTheSameDay(): void
    {
        $item = $this->item('0.00');

        $this->bookStock('2026-10-01', 1, $item, '5', '50.00');
        $this->manualToStock('30.00', 1, self::LOCATION_ID, '2026-10-20');

        $this->assertTrue($this->build(asOf: '2026-10-15')->ties(), 'The manual journal of the 20th is not yet in the books on the 15th');
        $this->assertFalse($this->build(asOf: '2026-10-25')->ties());
    }

    public function testBuild_DoesNotCompareTheShelvesForADayThatIsPast(): void
    {
        $item  = $this->item('10.00');
        $other = $this->item('10.00');

        $this->bookStock('2026-10-05', 1, $item, '5', '50.00');
        $this->putOnShelf($item, '8');
        $this->putOnShelf($other, '4');

        $report = $this->build(asOf: '2026-10-10');
        $line   = $this->lineOf($this->location($report, self::LOCATION_ID), $item);

        $this->assertNull($line->onHand());
        $this->assertNull($line->unposted());
        $this->assertNull($line->valueAtItemCost());
        $this->assertFalse($line->hasUnpostedUnits());
        $this->assertNull($this->lineOf($this->location($report, self::LOCATION_ID), $other), 'Stock that is on the shelves now is not what stood there on a past day');
        $this->assertSame(0, $report->unpostedLines());

        $everything = $this->build(asOf: '2026-10-10', showEmpty: true);

        $this->assertNull($this->lineOf($this->location($everything, self::LOCATION_ID), $other), 'Not even when the empty ones are asked for');
        $this->assertNotNull($this->lineOf($this->location($everything, self::LOCATION_ID), $item));
    }

    public function testBuild_TreatsTodayAndLaterDaysAsTheStockAsItStandsNow(): void
    {
        $item = $this->item('10.00');

        $this->bookStock('2026-10-05', 1, $item, '5', '50.00');
        $this->putOnShelf($item, '8');

        foreach ([null, '', date('Y-m-d'), '2099-12-31'] as $day) {
            $report = $this->build(asOf: $day);

            $this->assertTrue($report->current, 'Day ' . json_encode($day));
            $this->assertTrue($this->lineOf($this->location($report, self::LOCATION_ID), $item)->hasUnpostedUnits(), 'Day ' . json_encode($day));
        }

        $this->assertSame('2099-12-31', $this->build(asOf: '2099-12-31')->asOf);
        $this->assertNull($this->build()->asOf);
    }

    public function testBuild_RejectsADayThatIsNotADate(): void
    {
        foreach (['2026-02-31', 'yesterday', '31/10/2026'] as $day) {
            try {
                $this->build(asOf: $day);
                $this->fail("The day {$day} was accepted.");
            } catch (AccountingException $refusal) {
                $this->assertSame(AccountingError::InvalidDate, $refusal->error);
            }
        }
    }

    public function testBuild_AddsUpTheBranchesOfTheScopeAndNoOther(): void
    {
        $item  = $this->item('0.00');
        $depot = $this->locationOfBranch($this->second, 'Second depot');

        $this->bookStock('2026-10-05', 1, $item, '10', '100.00');
        $this->bookStock('2026-10-05', $this->second, $item, '4', '80.00', $depot);

        $company = $this->build();
        $first   = $this->build(ReportScope::resolve([1], null));
        $other   = $this->build(ReportScope::resolve([$this->second], null));

        $this->assertSame('180.0000', $company->value());
        $this->assertSame([self::LOCATION_ID, $depot], $this->sorted($this->locationIds($company)));

        $this->assertSame('100.0000', $first->value());
        $this->assertSame([self::LOCATION_ID], $this->locationIds($first));
        $this->assertSame('100.0000', $first->stockAccountBalance);
        $this->assertTrue($first->ties());

        $this->assertSame('80.0000', $other->value());
        $this->assertSame([$depot], $this->locationIds($other));
        $this->assertSame('80.0000', $other->stockAccountBalance);
        $this->assertTrue($other->ties());
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    public function testBuild_ReadsTheJournalsOfTheAccountsInTheBranchesOfTheScopeOnly(): void
    {
        $item = $this->item('0.00');

        $this->bookStock('2026-10-05', 1, $item, '10', '100.00');
        $this->manualToStock('30.00', $this->second);

        $first   = $this->build(ReportScope::resolve([1], null));
        $company = $this->build();

        $this->assertSame('100.0000', $this->location($first, self::LOCATION_ID)->accountBalance);
        $this->assertTrue($first->ties(), 'The journal of the other branch is not in the books of this one');
        $this->assertSame('130.0000', $this->location($company, self::LOCATION_ID)->accountBalance);
        $this->assertFalse($company->ties());
    }

    public function testBuild_LabelsAScopeOfSomeBranchesPartialAndTheWholeCompanyAsCompany(): void
    {
        $item = $this->item('0.00');

        $this->bookStock('2026-10-05', 1, $item, '10', '100.00');

        $this->assertSame(ReportScopeKind::Company, $this->build()->scope->kind);
        $this->assertSame(ReportScopeKind::Partial, $this->build(ReportScope::resolve(null, [1]))->scope->kind);
        $this->assertSame(ReportScopeKind::Selected, $this->build(ReportScope::resolve([1], null))->scope->kind);
    }

    public function testBuild_ShowsNothingForAScopeWithNoBranch(): void
    {
        $item = $this->item('0.00');

        $this->bookStock('2026-10-05', 1, $item, '10', '100.00');

        $report = $this->build(ReportScope::resolve([987654], null));

        $this->assertTrue($report->scope->isEmpty());
        $this->assertSame([], $report->locations);
        $this->assertSame('0.0000', $report->value());
        $this->assertSame('0.0000', $report->stockAccountBalance);
    }

    public function testBuild_DoesNotListTheShelvesOfALocationOfABranchOutsideTheScope(): void
    {
        $item  = $this->item('10.00');
        $depot = $this->locationOfBranch($this->second, 'Second depot');

        $this->putOnShelf($item, '6', $depot);

        $this->assertSame([], $this->build(ReportScope::resolve([1], null))->locations);
        $this->assertSame([$depot], $this->locationIds($this->build(ReportScope::resolve([$this->second], null))));
    }

    public function testBuild_LeavesOutALocationThatWasDeletedUnlessItHoldsWhatTheBooksHave(): void
    {
        $item  = $this->item('10.00');
        $empty = $this->anotherLocation('Closed shelf');
        $kept  = $this->anotherLocation('Closed depot');

        $this->putOnShelf($item, '6', $empty);
        $this->bookStock('2026-10-05', 1, $item, '2', '20.00', $kept);
        $this->accountingDb()->table('stock_locations')->whereIn('location_id', [$empty, $kept])->update(['deleted' => 1]);

        $report = $this->build();

        $this->assertNull($this->location($report, $empty), 'Shelves of a location that was deleted are not stock');
        $this->assertSame('20.0000', $this->location($report, $kept)->value, 'What the books hold stays visible');
        $this->assertTrue($this->location($report, $kept)->ties());

        $everything = $this->build(showEmpty: true);

        $this->assertNull($this->location($everything, $empty), 'A location that was deleted is not listed among the empty ones');
        $this->assertNotNull($this->location($everything, $kept));
    }

    public function testBuild_ListsAmongTheEmptyLocationsThoseOfTheBranchesOfTheScopeOnly(): void
    {
        $depot = $this->locationOfBranch($this->second, 'Idle second depot');
        $own   = $this->anotherLocation('Idle first depot');

        $first = $this->build(ReportScope::resolve([1], null), showEmpty: true);
        $other = $this->build(ReportScope::resolve([$this->second], null), showEmpty: true);

        $this->assertContains($own, $this->locationIds($first));
        $this->assertNotContains($depot, $this->locationIds($first));
        $this->assertSame([$depot], $this->locationIds($other));
    }

    public function testBuild_TakesBackTheStockOfASaleThatWasCancelled(): void
    {
        $item = $this->item('0.00');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '10', '50')]));

        $saleId = $this->save([$this->line($item, 0, '3', '80', '50')], [], [$this->payment(lang('Sales.cash'), '240.00')], SALE_TYPE_POS);

        $this->assertGreaterThan(0, $saleId);
        $this->assertSame('350.0000', $this->build()->value());

        $this->assertTrue($this->sales->delete($saleId, false, true, $this->employeeId));

        $report   = $this->build();
        $location = $this->location($report, self::LOCATION_ID);

        $this->assertSame('500.0000', $report->value());
        $this->assertSame('10.000', $this->lineOf($location, $item)->position->quantity);
        $this->assertTrue($report->ties());
        $this->assertSame(0, $report->unpostedLines());
    }

    public function testBuild_TiesTheCompanyAndEveryBranchAfterStockWasMovedBetweenTwoBranches(): void
    {
        $item  = $this->item('0.00');
        $depot = $this->locationOfBranch($this->second, 'Second depot');

        $this->workInEveryBranch();
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '10', '50')]));
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '4', '0', '0', '0', PERCENT, $depot), $this->purchaseLine($item, 1, '-4', '0')], null, null));

        foreach ([[1], [$this->second], [1, $this->second]] as $branches) {
            $report = $this->build(ReportScope::resolve($branches, null));

            $this->assertTrue($report->ties(), 'Branches ' . implode(',', $branches));
        }

        $this->assertSame('300.0000', $this->location($this->build(), self::LOCATION_ID)->value);
        $this->assertSame('200.0000', $this->location($this->build(), $depot)->value);
        $this->assertSame('500.0000', $this->build()->value());
        $this->assertSame('300.0000', $this->build(ReportScope::resolve([1], null))->value());
        $this->assertSame('200.0000', $this->build(ReportScope::resolve([$this->second], null))->value());
    }

    public function testBuild_SortsLocationsAndItemsByName(): void
    {
        $zebra = $this->item('0.00');
        $apple = $this->item('0.00');

        $this->accountingDb()->table('items')->where('item_id', $zebra)->update(['name' => 'Zebra']);
        $this->accountingDb()->table('items')->where('item_id', $apple)->update(['name' => 'Apple']);

        $this->bookStock('2026-10-05', 1, $zebra, '1', '10.00');
        $this->bookStock('2026-10-05', 1, $apple, '1', '10.00');
        $this->bookStock('2026-10-05', 1, $apple, '1', '10.00', $this->anotherLocation('Aardvark depot'));

        $report = $this->build();

        $this->assertSame('Aardvark depot', $report->locations[0]->name);
        $this->assertSame(['Apple', 'Zebra'], array_map(static fn (InventoryValuationLine $line): string => $line->position->itemName, $report->locations[1]->lines));
    }

    public function testBuild_SortsByNameWhateverTheCaseOfTheLetters(): void
    {
        $upper = $this->item('0.00');
        $lower = $this->item('0.00');

        $this->accountingDb()->table('items')->where('item_id', $upper)->update(['name' => 'Zebra']);
        $this->accountingDb()->table('items')->where('item_id', $lower)->update(['name' => 'apple']);

        $this->bookStock('2026-10-05', 1, $upper, '1', '10.00');
        $this->bookStock('2026-10-05', 1, $lower, '1', '10.00');
        $this->bookStock('2026-10-05', 1, $lower, '1', '10.00', $this->anotherLocation('zulu depot'));
        $this->bookStock('2026-10-05', 1, $lower, '1', '10.00', $this->anotherLocation('Alpha depot'));

        $report = $this->build();

        $this->assertSame(['Alpha depot', 'stock', 'zulu depot'], array_map(static fn (InventoryValuationLocation $location): string => $location->name, $report->locations));
        $this->assertSame(['apple', 'Zebra'], array_map(static fn (InventoryValuationLine $line): string => $line->position->itemName, $report->locations[1]->lines));
    }

    public function testBuild_NamesTheBranchOfEachLocationAndItsAccount(): void
    {
        $item  = $this->item('0.00');
        $depot = $this->locationOfBranch($this->second, 'Second depot');

        $this->bookStock('2026-10-05', $this->second, $item, '1', '10.00', $depot);

        $location = $this->location($this->build(), $depot);

        $this->assertSame($this->second, $location->branchId);
        $this->assertSame('V2', $location->branchCode);
        $this->assertSame($this->stockAccountOf($depot), $location->accountId);
        $this->assertStringStartsWith('1203', (string) $location->accountCode);
    }

    public function testBuild_ReadsOneSnapshotWhileAnotherRequestPostsStock(): void
    {
        $item   = $this->item('0.00');
        $reader = $this->openSecondConnection();
        $posted = false;

        $this->bookStock('2026-10-05', 1, $item, '10', '100.00');

        $listener = function (Query $query) use (&$posted, $item): void {
            if ($posted || ! str_contains((string) $query, 'accounting_stock_entries')) {
                return;
            }

            $posted = true;
            $this->bookStock('2026-10-06', 1, $item, '5', '50.00');
        };

        Events::on('DBQuery', $listener);

        try {
            $report = (new InventoryValuation($reader))->build($this->company());
        } finally {
            Events::removeListener('DBQuery', $listener);
        }

        $this->assertTrue($posted, 'Another request posted stock while the report was being read');
        $this->assertSame('100.0000', $report->value());
        $this->assertSame('100.0000', $this->location($report, self::LOCATION_ID)->accountBalance, 'What was read of the movements and of the journals is of one moment');
        $this->assertTrue($report->ties());
        $this->assertSame('150.0000', $this->build()->value(), 'The next report has the new stock, in the movements and the account alike');
        $this->assertTrue($this->build()->ties());
    }

    public function testBuild_IgnoresWhatBelongsToAnotherCompany(): void
    {
        $db   = $this->accountingDb();
        $item = $this->item('0.00');

        $this->bookStock('2026-10-05', 1, $item, '10', '100.00');

        $db->table('companies')->insert(['name' => 'Another company']);
        $other = (int) $db->insertID();

        try {
            $db->table('accounting_stock_entries')->update(['company_id' => $other]);
            $db->table('accounting_journals')->update(['company_id' => $other]);

            $report = $this->build();

            $this->assertSame([], $report->locations);
            $this->assertSame('0.0000', $report->value());
            $this->assertSame('0.0000', $report->stockAccountBalance);
        } finally {
            $db->table('accounting_stock_entries')->update(['company_id' => 1]);
            $db->table('accounting_journals')->update(['company_id' => 1]);
            $db->table('companies')->where('company_id', $other)->delete();
        }
    }

    public function testBuild_ReadsOnlyTheAccountsAndTheRoutingOfTheCompany(): void
    {
        $db     = $this->accountingDb();
        $item   = $this->item('0.00');
        $custom = $this->customAccount('1203', '120399', 'Stock outside the locations');

        $this->bookStock('2026-10-05', 1, $item, '10', '100.00');
        $this->manualToStock('25.00');

        (new PostingService())->post(new JournalDraft(
            date: '2026-10-06',
            lines: [new JournalLineDraft($custom, debit: '40.00'), new JournalLineDraft($this->accountId('opening_balance'), credit: '40.00')],
            sourceType: JournalDraft::SOURCE_MANUAL,
            currencyCode: 'SAR',
        ));

        $db->table('companies')->insert(['name' => 'Another company']);
        $other = (int) $db->insertID();

        try {
            $db->table('accounting_accounts')->where('account_id', $custom)->update(['company_id' => $other]);

            $withoutIt = $this->build();

            $this->assertSame('125.0000', $withoutIt->stockAccountBalance, 'An account of another company is not under the stock account of this one');

            $db->table('accounting_accounts')->where('entity_type', 'stock_location')->update(['company_id' => $other]);

            $location = $this->location($this->build(), self::LOCATION_ID);

            $this->assertNull($location->accountId, 'The account of a location of another company is not the account of this one');

            $db->table('accounting_routing')->update(['company_id' => $other]);

            $this->assertNull($this->build()->stockAccountCode);
            $this->assertNull($this->build()->stockAccountBalance);
        } finally {
            $db->table('accounting_routing')->update(['company_id' => 1]);
            $db->table('accounting_accounts')->where('company_id', $other)->update(['company_id' => 1]);
            $db->table('companies')->where('company_id', $other)->delete();
        }
    }

    public function testBuild_CountsEveryMovementForTheStockAsItStandsNowEvenOneDatedLater(): void
    {
        $item = $this->item('0.00');

        $this->bookStock('2035-06-01', 1, $item, '10', '100.00');
        $this->putOnShelf($item, '10');

        foreach ([null, date('Y-m-d')] as $day) {
            $report = $this->build(asOf: $day);

            $this->assertSame('100.0000', $report->value(), 'Day ' . json_encode($day));
            $this->assertSame('100.0000', $report->stockAccountBalance, 'Day ' . json_encode($day));
            $this->assertSame(0, $report->unpostedLines(), 'Day ' . json_encode($day));
        }

        $this->assertSame([], $this->build(asOf: '2026-10-05')->locations, 'A day before it is dated, it is not in the books');
    }

    public function testBuild_LeavesOutAnItemWithAnEmptyShelfAndNoMovementEvenWhenAskedForEmptyOnes(): void
    {
        $item = $this->item('0.00');

        $this->putOnShelf($item, '0');

        $location = $this->location($this->build(showEmpty: true), self::LOCATION_ID);

        $this->assertSame([], $location->lines, 'Every item has a row of zero in every location, and listing them all would bury the stock');
    }

    public function testBuild_HoldsForTheStockAccountsWhatTheTrialBalanceHoldsForTheSameBranchesAndDay(): void
    {
        $item  = $this->item('0.00');
        $depot = $this->locationOfBranch($this->second, 'Second depot');

        $this->workInEveryBranch();
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '10', '50')]));
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '4', '0', '0', '0', PERCENT, $depot), $this->purchaseLine($item, 1, '-4', '0')], null, null));
        $this->bookStock('2026-10-05', 1, $item, '2', '16.00');

        foreach ([[1], [$this->second], [1, $this->second]] as $branches) {
            $scope = ReportScope::resolve($branches, null);
            $row   = null;

            foreach ((new TrialBalance())->build($scope, ReportPeriod::of(null, '2099-12-31'))->rows as $candidate) {
                $row = $candidate->code === '1203' ? $candidate : $row;
            }

            $report = $this->build($scope);

            $this->assertNotNull($row, 'Branches ' . implode(',', $branches));
            $this->assertSame($row->closing(), $report->stockAccountBalance, 'Branches ' . implode(',', $branches));
            $this->assertSame($report->value(), $row->closing(), 'Branches ' . implode(',', $branches));
        }

        $this->assertSame('516.0000', $this->build()->value(), '500 bought, which moving 200 of it to the other branch does not change, and 16 booked');
    }

    public function testBuild_ReadsTheSameWhenAPeriodIsClosed(): void
    {
        $item = $this->item('0.00');

        $this->bookStock('2026-10-05', 1, $item, '10', '100.00');

        $before = $this->build();

        $this->closeThrough('2026-12-31');
        $this->closeThrough('2026-12-31', $this->second);

        $after = $this->build();

        $this->assertSame($before->value(), $after->value());
        $this->assertSame($before->stockAccountBalance, $after->stockAccountBalance);
        $this->assertTrue($after->ties());
        $this->assertSame('100.0000', $this->build(asOf: '2026-10-06')->value(), 'A day inside a closed period is read as it was posted');
    }

    public function testBuild_ChangesNothingInTheBooks(): void
    {
        $item = $this->item('0.00');

        $this->bookStock('2026-10-05', 1, $item, '10', '100.00');
        $this->manualToStock('30.00');
        $this->putOnShelf($item, '12');

        $before = [$this->countRows('accounting_journals'), $this->countRows('accounting_journal_lines'), $this->countRows('accounting_stock_entries'), $this->countRows('accounting_audit_log'), $this->countRows('inventory'), $this->shelfSum()];

        $this->build();
        $this->build(asOf: '2026-10-05', showEmpty: true);

        $this->assertSame($before, [$this->countRows('accounting_journals'), $this->countRows('accounting_journal_lines'), $this->countRows('accounting_stock_entries'), $this->countRows('accounting_audit_log'), $this->countRows('inventory'), $this->shelfSum()]);
    }

    private function shelfSum(): string
    {
        return (string) $this->accountingDb()->table('item_quantities')->selectSum('quantity', 'total')->get()->getRow()->total;
    }
}
