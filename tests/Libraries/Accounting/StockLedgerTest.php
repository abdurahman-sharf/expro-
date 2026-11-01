<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\StockLedger;
use App\Libraries\Accounting\StockMovement;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\PreviewJournalFixtureTrait;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\ParityFixture;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * What stock is worth, from the movements that reached the books: that a movement is written
 * once and taken back once, that what the movements add up to ties to the account of the stock
 * location, and where the cost of an item carried between purchases and the movements part, which
 * is the whole of the difference between 1,314 and 1,320 in the reference sequence.
 */
class StockLedgerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use PreviewJournalFixtureTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private StockLedger $ledger;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBuying();
        $this->ledger = new StockLedger();
    }

    protected function tearDown(): void
    {
        $this->stopBuying();

        parent::tearDown();
    }

    private function movement(int $itemId, string $quantity, string $value, int $locationId = self::LOCATION_ID): StockMovement
    {
        return new StockMovement($itemId, $locationId, $quantity, $value);
    }

    private function shelf(int $itemId, string $quantity, int $locationId = self::LOCATION_ID): void
    {
        $this->accountingDb()->table('item_quantities')->where('item_id', $itemId)->where('location_id', $locationId)->delete();
        $this->accountingDb()->table('item_quantities')->insert(['item_id' => $itemId, 'location_id' => $locationId, 'quantity' => $quantity]);
    }

    public function testRecord_WritesTheMovementsOnceForASourceAndARevision(): void
    {
        $itemId = $this->item('10.00');

        $this->ledger->record('purchase', 9001, JournalEntryType::General, 1, '2026-10-24', 1, [$this->movement($itemId, '5', '50.00')], null);
        $this->ledger->record('purchase', 9001, JournalEntryType::General, 1, '2026-10-24', 1, [$this->movement($itemId, '5', '50.00')], null);

        $this->assertSame(['quantity' => '5.000', 'value' => '50.0000'], $this->ledger->position($itemId, self::LOCATION_ID));
        $this->assertSame(1, $this->countRows('accounting_stock_entries'));
    }

    public function testRecord_KeepsMovementsOfGoodsThatCostNothing(): void
    {
        $itemId = $this->item('0.00');

        $this->ledger->record('purchase', 9002, JournalEntryType::General, 1, '2026-10-24', 1, [$this->movement($itemId, '4', '0.00')], null);

        $this->assertSame(['quantity' => '4.000', 'value' => '0.0000'], $this->ledger->position($itemId, self::LOCATION_ID));
    }

    public function testRecord_RefusesAMovementOfAnItemThatDoesNotExist(): void
    {
        $this->expectException(\Throwable::class);

        $this->ledger->record('purchase', 9003, JournalEntryType::General, 1, '2026-10-24', 1, [$this->movement(987654, '1', '1.00')], null);
    }

    public function testReverseSource_TakesBackEveryMovementOnceWhicheverRevisionWroteIt(): void
    {
        $itemId = $this->item('10.00');

        $this->ledger->record('sale', 9004, JournalEntryType::Cogs, 1, '2026-10-24', 1, [$this->movement($itemId, '-2', '-20.00')], null);
        $this->ledger->record('sale', 9004, JournalEntryType::Cogs, 2, '2026-10-24', 1, [$this->movement($itemId, '-3', '-33.00')], null);

        $this->ledger->reverseSource('sale', 9004, JournalEntryType::Cogs, '2026-10-25', null);
        $this->ledger->reverseSource('sale', 9004, JournalEntryType::Cogs, '2026-10-25', null);

        $this->assertSame(['quantity' => '0.000', 'value' => '0.0000'], $this->ledger->position($itemId, self::LOCATION_ID));
        $this->assertSame(4, $this->countRows('accounting_stock_entries'), 'Two movements and one reversal for each, however many times it was asked');
    }

    public function testReverseSource_LeavesTheMovementsOfAnotherSourceAlone(): void
    {
        $itemId = $this->item('10.00');

        $this->ledger->record('purchase', 9005, JournalEntryType::General, 1, '2026-10-24', 1, [$this->movement($itemId, '5', '50.00')], null);
        $this->ledger->record('purchase', 9006, JournalEntryType::General, 1, '2026-10-24', 1, [$this->movement($itemId, '7', '70.00')], null);

        $this->ledger->reverseSource('purchase', 9005, JournalEntryType::General, '2026-10-24', null);

        $this->assertSame(['quantity' => '7.000', 'value' => '70.0000'], $this->ledger->position($itemId, self::LOCATION_ID));
    }

    public function testPosition_AddsTheMovementsOfAnItemInALocationAndNoOther(): void
    {
        $itemId  = $this->item('10.00');
        $other   = $this->anotherLocation();

        $this->ledger->record('purchase', 9007, JournalEntryType::General, 1, '2026-10-24', 1, [$this->movement($itemId, '5', '50.00'), $this->movement($itemId, '2', '30.00', $other)], null);

        $this->assertSame(['quantity' => '5.000', 'value' => '50.0000'], $this->ledger->position($itemId, self::LOCATION_ID));
        $this->assertSame(['quantity' => '2.000', 'value' => '30.0000'], $this->ledger->position($itemId, $other));
    }

    /**
     * Fixture T-11: the seven movements of the reference sequence, each at the value its journal
     * had, add up to what the reference system's report and its account held.
     */
    public function testPosition_FollowsTheMovementsOfTheReferenceSequence(): void
    {
        $case   = ParityFixture::cases('T-11', 'movement_valuation')['valuation-from-movements'][0];
        $itemId = $this->item($case['expected']['item_cost']);

        foreach ($case['inputs']['movements'] as $index => $step) {
            $this->ledger->record('purchase', 9100 + $index, JournalEntryType::General, 1, '2026-10-24', 1, [$this->movement($itemId, $step['quantity'], $step['value'])], null);
        }

        $position = $this->ledger->position($itemId, self::LOCATION_ID);

        $this->assertSame('exact', $case['classification']);
        $this->assertSame(bcadd($case['expected']['quantity'], '0', 3), $position['quantity']);
        $this->assertSame(bcadd($case['expected']['value'], '0', 4), $position['value']);
        $this->assertSame(bcadd($case['reference']['value'], '0', 4), $position['value'], 'The movements must add up to what the reference system held');
    }

    /**
     * Fixture T-11: the cost an item carries times its quantity is 1,320 and the movements say
     * 1,314. The difference is one unit that went back at 60.00 while the item carries 66.00,
     * and nothing else: not rounding, and not a cost that was wrong.
     */
    public function testPosition_DiffersFromTheCostTheItemCarriesByTheReturnAtTheCostOfTheSaleAndByNothingElse(): void
    {
        $movements = ParityFixture::cases('T-11', 'movement_valuation')['valuation-from-movements'][0];
        $case      = ParityFixture::cases('T-11', 'valuation_difference')['item-cost-times-quantity-is-not-the-valuation'][0];
        $itemId    = $this->item($case['inputs']['item_cost']);

        foreach ($movements['inputs']['movements'] as $index => $step) {
            $this->ledger->record('purchase', 9200 + $index, JournalEntryType::General, 1, '2026-10-24', 1, [$this->movement($itemId, $step['quantity'], $step['value'])], null);
        }

        $this->shelf($itemId, $case['inputs']['quantity']);

        $position = $this->ledger->positions(null, $itemId)[0];

        $this->assertSame($case['expected']['value_at_item_cost'], bcadd($position->valueAtItemCost(), '0', 2));
        $this->assertSame($case['expected']['movements_value'], bcadd($position->value, '0', 2));
        $this->assertSame($case['expected']['difference'], bcadd($position->differenceFromItemCost(), '0', 2));

        $returnOfTheSale = bcmul($case['inputs']['units_returned_at_that_cost'], bcsub($case['inputs']['item_cost'], $case['inputs']['returned_at'], 4), 2);

        $this->assertSame($case['expected']['difference_of_the_return'], $returnOfTheSale, 'The whole difference is the return, as the return came back at the cost of its sale');
        $this->assertSame($position->differenceFromItemCost(), bcadd($returnOfTheSale, '0', 4));
    }

    public function testUnitCost_IsTheAverageOfTheMovementsWhenTheyAccountForEveryUnit(): void
    {
        $itemId = $this->item('66.00');

        $this->ledger->record('purchase', 9300, JournalEntryType::General, 1, '2026-10-24', 1, [$this->movement($itemId, '20', '1314.00')], null);
        $this->shelf($itemId, '20');

        $this->assertSame('65.70000000', $this->ledger->unitCost($itemId, self::LOCATION_ID, '66.00'));
    }

    public function testUnitCost_IsTheCostTheItemCarriesWhenTheMovementsDoNotAccountForTheShelves(): void
    {
        $itemId = $this->item('66.00');

        $this->ledger->record('purchase', 9301, JournalEntryType::General, 1, '2026-10-24', 1, [$this->movement($itemId, '5', '250.00')], null);
        $this->shelf($itemId, '20');

        $this->assertSame('66.00000000', $this->ledger->unitCost($itemId, self::LOCATION_ID, '66.00'));
    }

    public function testUnitCost_IsTheCostTheItemCarriesWhenNothingIsLeftToDivideBy(): void
    {
        $itemId = $this->item('66.00');

        $this->ledger->record('purchase', 9302, JournalEntryType::General, 1, '2026-10-24', 1, [$this->movement($itemId, '3', '150.00'), $this->movement($itemId, '-3', '-150.00')], null);
        $this->shelf($itemId, '0');

        $this->assertSame('66.00000000', $this->ledger->unitCost($itemId, self::LOCATION_ID, '66.00'));
        $this->assertSame('12.50000000', $this->ledger->unitCost($this->item('12.50'), self::LOCATION_ID, '12.50'), 'An item with no movements at all carries its own cost');
    }

    public function testUnitCost_IsPerLocation(): void
    {
        $itemId = $this->item('50.00');
        $other  = $this->anotherLocation();

        $this->ledger->record('purchase', 9303, JournalEntryType::General, 1, '2026-10-24', 1, [$this->movement($itemId, '10', '500.00'), $this->movement($itemId, '10', '800.00', $other)], null);
        $this->shelf($itemId, '10');
        $this->shelf($itemId, '10', $other);

        $this->assertSame('50.00000000', $this->ledger->unitCost($itemId, self::LOCATION_ID, '65.00'));
        $this->assertSame('80.00000000', $this->ledger->unitCost($itemId, $other, '65.00'));
    }

    public function testPositions_ShowsStockOnTheShelvesThatNoPostedMovementAccountsFor(): void
    {
        $itemId = $this->item('10.00');

        $this->ledger->record('purchase', 9304, JournalEntryType::General, 1, '2026-10-24', 1, [$this->movement($itemId, '5', '50.00')], null);
        $this->shelf($itemId, '8');

        $position = $this->ledger->positions(null, $itemId)[0];

        $this->assertSame('3.000', $position->unposted());
        $this->assertFalse($position->covered());
        $this->assertSame('10.0000000', bcadd((string) $position->averageCost(), '0', 7));
    }

    public function testPositions_LeavesOutWhatIsNotStockAndWhatIsOutsideTheLocationsAsked(): void
    {
        $service = $this->item('0.00', false);
        $itemId  = $this->item('10.00');
        $other   = $this->anotherLocation();

        $this->shelf($service, '5');
        $this->shelf($itemId, '2', $other);

        $this->assertSame([], $this->ledger->positions([], $itemId));
        $this->assertSame([], $this->ledger->positions([self::LOCATION_ID], $service));
        $this->assertSame([], $this->ledger->positions([self::LOCATION_ID], $itemId));
        $this->assertCount(1, $this->ledger->positions([$other], $itemId));
    }

    public function testReconcile_ShowsWhatReachedTheAccountWithoutAMovement(): void
    {
        $itemId = $this->item('10.00');

        $this->ledger->record('purchase', 9305, JournalEntryType::General, 1, '2026-10-24', 1, [$this->movement($itemId, '5', '50.00')], null);

        (new PostingService())->post(new JournalDraft(
            date: '2026-10-24',
            lines: [new JournalLineDraft($this->stockAccount(), debit: '30.00'), new JournalLineDraft($this->accountId('opening_balance'), credit: '30.00')],
            sourceType: JournalDraft::SOURCE_MANUAL,
            employeeId: $this->employeeId,
            currencyCode: 'SAR',
        ));

        $row = array_values(array_filter($this->ledger->reconcile(), static fn (array $location): bool => $location['location_id'] === self::LOCATION_ID))[0];

        $this->assertSame('30.0000', $row['account_balance']);
        $this->assertSame('50.0000', $row['movements_value']);
        $this->assertSame('-20.0000', $row['unattributed']);
    }

    public function testMismatchedJournals_FindsAJournalWhoseMovementsDisagreeWithItsLines(): void
    {
        $itemId = $this->item('10.00');
        $result = (new PostingService())->post(new JournalDraft(
            date: '2026-10-24',
            lines: [new JournalLineDraft($this->stockAccount(), debit: '50.00'), new JournalLineDraft($this->supplierAccount(), credit: '50.00')],
            sourceType: 'purchase',
            sourceId: 9306,
            employeeId: $this->employeeId,
            currencyCode: 'SAR',
        ));

        $this->ledger->record('purchase', 9306, JournalEntryType::General, 1, '2026-10-24', 1, [$this->movement($itemId, '5', '40.00')], $result->journalId);

        $this->assertSame([['journal_id' => $result->journalId, 'location_id' => self::LOCATION_ID, 'journal' => '50.0000', 'movements' => '40.0000']], $this->ledger->mismatchedJournals());
    }

    public function testMismatchedJournals_FindsNothingWhenEveryJournalHoldsWhatItsMovementsDo(): void
    {
        $itemId = $this->item('10.00');
        $result = (new PostingService())->post(new JournalDraft(
            date: '2026-10-24',
            lines: [new JournalLineDraft($this->stockAccount(), debit: '50.00'), new JournalLineDraft($this->supplierAccount(), credit: '50.00')],
            sourceType: 'purchase',
            sourceId: 9307,
            employeeId: $this->employeeId,
            currencyCode: 'SAR',
        ));

        $this->ledger->record('purchase', 9307, JournalEntryType::General, 1, '2026-10-24', 1, [$this->movement($itemId, '3', '20.00'), $this->movement($itemId, '2', '30.00')], $result->journalId);

        $this->assertSame([], $this->ledger->mismatchedJournals());
    }

    /**
     * Fixture T-05 and T-11 through the real models: three purchases, two sales and two credit
     * notes in the order the reference system saw them. The movements and the account of the
     * stock location end at the 1,314 its report held, and the cost the item carries ends at 66,
     * so the 1,320 of cost times quantity is 6.00 above what the stock is worth.
     */
    public function testPosition_EndsAtTheValueTheReferenceReportHeldWhenTheSequenceRunsThroughTheApplication(): void
    {
        $sequence = ParityFixture::cases('T-05', 'cost_sequence')['sequence'][0]['steps'];
        $itemId   = $this->item('0.00');
        $price    = '100';
        $sale     = null;

        foreach ($sequence as $step) {
            $cost = $this->itemCostOf($itemId);

            switch ($step['op']) {
                case 'purchase':
                    $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, $step['quantity'], $step['unit_cost'])]), $step['name']);

                    break;

                case 'sale':
                    $saleId = $this->save([$this->line($itemId, 0, $step['quantity'], $price, $cost)]);
                    $this->assertGreaterThan(0, $saleId, $step['name']);
                    $sale ??= $saleId;

                    break;

                case 'return':
                    $this->assertGreaterThan(0, $this->save([$this->line($itemId, 0, '-' . $step['quantity'], $price, $cost)], [], [], SALE_TYPE_RETURN, -1, true, $sale), $step['name']);

                    break;
            }

            $this->assertSame(bcadd($step['expected']['cost'], '0', 4), $this->itemCostOf($itemId), "{$step['name']}: the cost the item carries");
            $this->assertSame(bcadd((string) $step['stock_after'], '0', 3), $this->onHand($itemId), "{$step['name']}: the stock");
        }

        $valuation = ParityFixture::cases('T-11', 'movement_valuation')['valuation-from-movements'][0];
        $position  = $this->ledger->positions(null, $itemId)[0];

        $this->assertSame(bcadd($valuation['reference']['quantity'], '0', 3), $position->quantity);
        $this->assertSame(bcadd($valuation['reference']['value'], '0', 4), $position->value, 'The movements of the application add up to what the reference system held');
        $this->assertTrue($position->covered());
        $this->assertSame(bcadd($valuation['reference']['value'], '0', 2), $this->balance($this->stockAccount()), 'The account of the stock location holds the same');
        $this->assertSame('6.0000', $position->differenceFromItemCost());
        $this->assertStockTies();
    }

    public function testReconcile_LeavesOutThePreviewsOfABatch(): void
    {
        $receivingId = $this->purchaseOf('500');

        $before = $this->ledger->reconcile();

        $this->previewEverything();
        $this->assertPreviewsStand();

        $this->assertSame($before, $this->ledger->reconcile());
        $this->assertSame([], $this->ledger->mismatchedJournals());
        $this->assertGreaterThan(0, $receivingId);
    }
}
