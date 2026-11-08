<?php

namespace Tests\Libraries\Stock;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\StockLedger;
use App\Libraries\Sale_lib;
use App\Libraries\Stock\StockOptions;
use App\Libraries\Stock\StockPermit;
use App\Libraries\Stock\StockPermitSources;
use App\Libraries\Stock\Stocktake;
use App\Libraries\Stock\StockTracking;
use App\Libraries\Stock\TrackingType;
use App\Models\Item_quantity;
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
use Tests\Support\StockUnitsFixtureTrait;

/**
 * Serial numbers and batches through the documents that move them: a purchase, a sale, a return, a
 * stock permit and their cancellation, with the shelf of a tracked item always equal to its serial
 * numbers on hand or the sum of its batches.
 *
 * @internal
 */
final class StockTrackingTest extends CIUnitTestCase
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
    use StockUnitsFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;
    private StockTracking $tracking;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startStockDocuments();
        $this->readyStockUnits();
        $this->tracking = new StockTracking();
    }

    protected function tearDown(): void
    {
        $this->stopStockUnits();
        $this->stopStockDocuments();
        $this->stopBuying();

        parent::tearDown();
    }

    /**
     * @param list<string> $serials
     */
    private function receiveSerials(int $itemId, array $serials, string $price = '5.00', int $locationId = self::LOCATION_ID): int
    {
        return $this->buy([[...$this->purchaseLine($itemId, 0, (string) count($serials), $price, locationId: $locationId), 'tracking' => ['serials' => $serials]]]);
    }

    private function receiveBatch(int $itemId, string $batch, string $expiry, string $quantity, string $price = '5.00'): int
    {
        return $this->buy([[...$this->purchaseLine($itemId, 0, $quantity, $price), 'tracking' => ['batches' => [['number' => $batch, 'expiry' => $expiry, 'quantity' => $quantity]]]]]);
    }

    /**
     * @param array<string, mixed> $more
     */
    private function sellPiece(int $itemId, string $serial = '', array $more = [], int $line = 0): int
    {
        return $this->save([[...$this->line($itemId, $line, '1', '10.00', '5.00'), 'serialnumber' => $serial, ...$more]], [], [$this->payment(lang('Sales.cash'), '10.00')], SALE_TYPE_POS);
    }

    private function sellUnits(int $itemId, string $quantity, array $more = []): int
    {
        return $this->save([[...$this->line($itemId, 0, $quantity, '10.00', '5.00'), ...$more]], [], [$this->payment(lang('Sales.cash'), bcmul($quantity, '10.00', 2))], SALE_TYPE_POS);
    }

    private function serialState(int $itemId, string $serial): ?string
    {
        $row = $this->accountingDb()->table('stock_serials')->where(['item_id' => $itemId, 'serial_number' => $serial])->get()->getRow();

        return $row === null ? null : (string) $row->state;
    }

    private function batchQuantity(int $itemId, string $batch, int $locationId = self::LOCATION_ID): string
    {
        $row = $this->accountingDb()->table('stock_batch_quantities q')->select('q.quantity')->join('stock_batches b', 'b.batch_id = q.batch_id')->where(['b.item_id' => $itemId, 'b.batch_number' => $batch, 'q.location_id' => $locationId])->get()->getRow();

        return $row === null ? '0.000' : bcadd((string) $row->quantity, '0', 3);
    }

    private function assertTied(int $itemId, int $locationId = self::LOCATION_ID): void
    {
        $tie = $this->tracking->tie($itemId, $locationId);

        $this->assertSame($tie['shelf'], $tie['tracked'], 'The shelf of a tracked item equals what its serial numbers or batches add up to');
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

    private function failure(object $model): ?AccountingError
    {
        $failure = $model->accountingFailure();

        return $failure instanceof AccountingException ? $failure->error : null;
    }

    private function inDays(int $days): string
    {
        return date('Y-m-d', strtotime("{$days} days"));
    }

    // ---------------------------------------------------------------------------------------
    // Serial numbers
    // ---------------------------------------------------------------------------------------

    public function testReceiveBringsInOneSerialNumberForEachUnitOfAPurchaseAndTiesTheShelf(): void
    {
        $itemId = $this->trackedItem(TrackingType::Serial);

        $receivingId = $this->receiveSerials($itemId, ['S1', 'S2', 'S3']);

        $this->assertGreaterThan(0, $receivingId);
        $this->assertSame('3.000', $this->onHand($itemId));
        $this->assertSame(['in_stock', 'in_stock', 'in_stock'], [$this->serialState($itemId, 'S1'), $this->serialState($itemId, 'S2'), $this->serialState($itemId, 'S3')]);
        $this->assertTied($itemId);
        $this->assertSame(['quantity' => '3.000', 'value' => '15.00'], $this->stockOf($itemId));
        $this->assertBooksTie();
    }

    public function testReceiveWritesOneMovementForEachSerialNumberAndTheJournalStillAddsUp(): void
    {
        $itemId = $this->trackedItem(TrackingType::Serial);

        $receivingId = $this->receiveSerials($itemId, ['M1', 'M2', 'M3'], '33.33');

        $movements = $this->movementsOfSource('purchase', $receivingId);

        $this->assertSame(['1.000', '1.000', '1.000'], array_map(static fn (object $movement): string => bcadd((string) $movement->quantity, '0', 3), $movements));
        $this->assertSame('99.9900', bcadd(array_reduce($movements, static fn (string $sum, object $movement): string => bcadd($sum, (string) $movement->value, 4), '0'), '0', 4));
        $this->assertSame([], (new StockLedger())->mismatchedJournals());
        $this->assertBooksTie();
    }

    public function testReceiveRequiresOneSerialNumberForEachUnit(): void
    {
        $itemId = $this->trackedItem(TrackingType::Serial);

        $this->assertSame(ACCOUNTING_FAILED, $this->buy([[...$this->purchaseLine($itemId, 0, '3', '5.00'), 'tracking' => ['serials' => ['A', 'B']]]]));
        $this->assertSame(AccountingError::TrackedEntryRequired, $this->failure($this->purchases));
        $this->assertSame('0.000', $this->onHand($itemId));
        $this->assertSame(0, $this->accountingDb()->table('stock_serials')->where('item_id', $itemId)->countAllResults());
    }

    public function testReceiveRequiresASerialNumberWhenTheLineHasNoEntryAtAll(): void
    {
        $itemId = $this->trackedItem(TrackingType::Serial);

        $this->assertSame(ACCOUNTING_FAILED, $this->buy([$this->purchaseLine($itemId, 0, '1', '5.00')]));
        $this->assertSame(AccountingError::TrackedEntryRequired, $this->failure($this->purchases));
    }

    public function testReceiveRejectsASerialNumberThatIsAlreadyOnHand(): void
    {
        $itemId = $this->trackedItem(TrackingType::Serial);

        $this->receiveSerials($itemId, ['D1']);

        $this->assertSame(ACCOUNTING_FAILED, $this->receiveSerials($itemId, ['D1']));
        $this->assertSame(AccountingError::SerialDuplicate, $this->failure($this->purchases));
        $this->assertSame('1.000', $this->onHand($itemId));
    }

    public function testReceiveRejectsASerialNumberRepeatedInTheLineOrLongerThanThirtyCharacters(): void
    {
        $itemId = $this->trackedItem(TrackingType::Serial);

        $this->assertSame(ACCOUNTING_FAILED, $this->receiveSerials($itemId, ['R1', 'R1']));
        $this->assertSame(AccountingError::SerialInvalid, $this->failure($this->purchases));
        $this->assertSame(ACCOUNTING_FAILED, $this->receiveSerials($itemId, [str_repeat('x', 31)]));
        $this->assertSame(AccountingError::SerialInvalid, $this->failure($this->purchases));
        $this->assertGreaterThan(0, $this->receiveSerials($itemId, [str_repeat('x', 30)]));
    }

    public function testReceiveRefusesAnEntryForAnItemThatIsNotTracked(): void
    {
        $itemId = $this->item('5.00');

        $this->assertSame(ACCOUNTING_FAILED, $this->buy([[...$this->purchaseLine($itemId, 0, '1', '5.00'), 'tracking' => ['serials' => ['X1']]]]));
        $this->assertSame(AccountingError::TrackedEntryNotAllowed, $this->failure($this->purchases));
    }

    public function testSaveSellsASerialNumberOnceAndTakesItOutOfTheShelf(): void
    {
        $itemId = $this->trackedItem(TrackingType::Serial);

        $this->receiveSerials($itemId, ['P1', 'P2']);

        $saleId = $this->sellPiece($itemId, 'P1');

        $this->assertGreaterThan(0, $saleId);
        $this->assertSame('sold', $this->serialState($itemId, 'P1'));
        $this->assertSame('1.000', $this->onHand($itemId));
        $this->assertTied($itemId);
        $this->assertBooksTie();
    }

    public function testSaveRefusesToSellASerialNumberThatWasSoldAndLeavesNothingBehind(): void
    {
        $itemId = $this->trackedItem(TrackingType::Serial);

        $this->receiveSerials($itemId, ['Q1', 'Q2']);
        $this->sellPiece($itemId, 'Q1');

        $this->assertSame(ACCOUNTING_FAILED, $this->sellPiece($itemId, 'Q1'));
        $this->assertSame(AccountingError::SerialNotAvailable, $this->failure($this->sales));
        $this->assertSame('1.000', $this->onHand($itemId));
        $this->assertTied($itemId);
    }

    public function testSaveRequiresASerialNumberForASaleOfATrackedItem(): void
    {
        $itemId = $this->trackedItem(TrackingType::Serial);

        $this->receiveSerials($itemId, ['N1']);

        $this->assertSame(ACCOUNTING_FAILED, $this->sellPiece($itemId, ''));
        $this->assertSame(AccountingError::TrackedEntryRequired, $this->failure($this->sales));
    }

    public function testSaveRefusesASerialNumberThatIsInAnotherStockLocation(): void
    {
        $itemId   = $this->trackedItem(TrackingType::Serial);
        $location = $this->openLocation();

        $this->receiveSerials($itemId, ['L1'], '5.00', $location);

        $this->assertSame(ACCOUNTING_FAILED, $this->sellPiece($itemId, 'L1'));
        $this->assertSame(AccountingError::SerialNotAvailable, $this->failure($this->sales));
    }

    public function testSavePutsASoldSerialNumberBackInStockOnAReturnAndRefusesOneThatWasNeverSold(): void
    {
        $itemId = $this->trackedItem(TrackingType::Serial);

        $this->receiveSerials($itemId, ['T1', 'T2']);

        $saleId = $this->sellPiece($itemId, 'T1');

        $return = $this->save([[...$this->line($itemId, 0, '-1', '10.00', '5.00'), 'serialnumber' => 'T1']], [], [], SALE_TYPE_RETURN, origin: $saleId);

        $this->assertGreaterThan(0, $return);
        $this->assertSame('returned', $this->serialState($itemId, 'T1'));
        $this->assertSame('2.000', $this->onHand($itemId));
        $this->assertTied($itemId);

        $this->assertSame(ACCOUNTING_FAILED, $this->save([[...$this->line($itemId, 0, '-1', '10.00', '5.00'), 'serialnumber' => 'T2']], [], [], SALE_TYPE_RETURN, origin: $saleId));
        $this->assertSame(AccountingError::SerialNotSold, $this->failure($this->sales));
        $this->assertBooksTie();
    }

    public function testSaveLetsAReturnedSerialNumberBeSoldAgain(): void
    {
        $itemId = $this->trackedItem(TrackingType::Serial);

        $this->receiveSerials($itemId, ['U1']);

        $saleId = $this->sellPiece($itemId, 'U1');

        $this->save([[...$this->line($itemId, 0, '-1', '10.00', '5.00'), 'serialnumber' => 'U1']], [], [], SALE_TYPE_RETURN, origin: $saleId);

        $this->assertGreaterThan(0, $this->sellPiece($itemId, 'U1'));
        $this->assertSame('sold', $this->serialState($itemId, 'U1'));
        $this->assertSame('0.000', $this->onHand($itemId));
    }

    public function testDeleteOfASaleTakesTheSerialNumbersBackIntoStock(): void
    {
        $itemId = $this->trackedItem(TrackingType::Serial);

        $this->receiveSerials($itemId, ['V1', 'V2']);

        $saleId = $this->sellPiece($itemId, 'V1');

        $this->assertTrue($this->sales->delete($saleId, false, true, $this->employeeId));
        $this->assertSame('in_stock', $this->serialState($itemId, 'V1'));
        $this->assertSame('2.000', $this->onHand($itemId));
        $this->assertTied($itemId);
        $this->assertBooksTie();
    }

    public function testDeleteOfAPurchaseTakesItsSerialNumbersOutUnlessOneWasSold(): void
    {
        $itemId = $this->trackedItem(TrackingType::Serial);

        $receivingId = $this->receiveSerials($itemId, ['W1', 'W2']);

        $this->sellPiece($itemId, 'W1');

        $this->assertFalse($this->purchases->delete_value($receivingId, $this->employeeId));
        $this->assertSame('sold', $this->serialState($itemId, 'W1'));
        $this->assertSame('1.000', $this->onHand($itemId));
        $this->assertTied($itemId);
    }

    public function testDeleteOfAPurchaseOfSerialNumbersStillOnHandRemovesThemAndTheyCanComeInAgain(): void
    {
        $itemId = $this->trackedItem(TrackingType::Serial);

        $receivingId = $this->receiveSerials($itemId, ['X1', 'X2']);

        $this->assertTrue($this->purchases->delete_value($receivingId, $this->employeeId));
        $this->assertSame(['removed', 'removed'], [$this->serialState($itemId, 'X1'), $this->serialState($itemId, 'X2')]);
        $this->assertSame('0.000', $this->onHand($itemId));
        $this->assertGreaterThan(0, $this->receiveSerials($itemId, ['X1', 'X2']));
        $this->assertSame('2.000', $this->onHand($itemId));
        $this->assertTied($itemId);
    }

    public function testCreateAddsAndIssuesSerialNumbersWithAPermitAndMovesThemBetweenLocations(): void
    {
        $itemId  = $this->trackedItem(TrackingType::Serial, '0.00');
        $second  = $this->openLocation('Back store');
        $permits = new StockPermit();
        $common  = ['date' => date('Y-m-d'), 'location_id' => self::LOCATION_ID, 'note' => ''];
        $addId   = $permits->create([...$common, 'type' => 'add', 'lines' => [['item_id' => $itemId, 'quantity' => '3', 'unit_cost' => '10', 'tracking' => ['serials' => ['K1', 'K2', 'K3']]]]], $this->keeperId);

        $this->assertSame('3.000', $this->onHand($itemId));
        $this->assertCount(3, $this->movementsOfSource(StockPermitSources::PERMIT, $addId), 'one movement for each serial number, as the permits of a tracked product are observed');

        $issueId = $permits->create([...$common, 'type' => 'issue', 'lines' => [['item_id' => $itemId, 'quantity' => '1', 'tracking' => ['serials' => ['K1']]]]], $this->keeperId);

        $this->assertSame(['removed', '2.000'], [$this->serialState($itemId, 'K1'), $this->onHand($itemId)]);

        $this->switchOnTransferPermit();
        $permits->create([...$common, 'type' => 'transfer', 'to_location_id' => $second, 'lines' => [['item_id' => $itemId, 'quantity' => '1', 'tracking' => ['serials' => ['K2']]]]], $this->keeperId);

        $this->assertSame($second, (int) $this->accountingDb()->table('stock_serials')->where(['item_id' => $itemId, 'serial_number' => 'K2'])->get()->getRow()->location_id);
        $this->assertSame(['1.000', '1.000'], [$this->onHand($itemId), $this->onHand($itemId, $second)]);
        $this->assertTied($itemId);
        $this->assertTied($itemId, $second);

        $permits->cancel($issueId, 'back', $this->keeperId);

        $this->assertSame(['in_stock', '2.000'], [$this->serialState($itemId, 'K1'), $this->onHand($itemId)]);
        $this->assertTied($itemId);
        $this->assertBooksTie();
    }

    public function testCancelOfAnAddPermitRefusesWhileOneOfItsSerialNumbersWasSold(): void
    {
        $itemId  = $this->trackedItem(TrackingType::Serial, '0.00');
        $permits = new StockPermit();
        $addId   = $permits->create(['type' => 'add', 'date' => date('Y-m-d'), 'location_id' => self::LOCATION_ID, 'lines' => [['item_id' => $itemId, 'quantity' => '2', 'unit_cost' => '10', 'tracking' => ['serials' => ['Y1', 'Y2']]]]], $this->keeperId);

        $permits->create(['type' => 'add', 'date' => date('Y-m-d'), 'location_id' => self::LOCATION_ID, 'lines' => [['item_id' => $itemId, 'quantity' => '2', 'unit_cost' => '10', 'tracking' => ['serials' => ['Y3', 'Y4']]]]], $this->keeperId);
        $this->sellPiece($itemId, 'Y1');

        $this->refusedWith(AccountingError::SerialMoved, fn () => $permits->cancel($addId, 'mistake', $this->keeperId));
        $this->assertSame('3.000', $this->onHand($itemId));
        $this->assertSame('sold', $this->serialState($itemId, 'Y1'));
        $this->assertTied($itemId);
        $this->assertBooksTie();
    }

    public function testCreateRequiresTheSerialNumbersOnAnIssuePermitOfATrackedItem(): void
    {
        $itemId  = $this->trackedItem(TrackingType::Serial, '0.00');
        $permits = new StockPermit();

        $permits->create(['type' => 'add', 'date' => date('Y-m-d'), 'location_id' => self::LOCATION_ID, 'lines' => [['item_id' => $itemId, 'quantity' => '1', 'unit_cost' => '10', 'tracking' => ['serials' => ['Z1']]]]], $this->keeperId);

        $this->refusedWith(AccountingError::TrackedEntryRequired, fn () => $permits->create(['type' => 'issue', 'date' => date('Y-m-d'), 'location_id' => self::LOCATION_ID, 'lines' => [['item_id' => $itemId, 'quantity' => '1']]], $this->keeperId));
        $this->assertSame('1.000', $this->onHand($itemId));
    }

    public function testRaceOfTwoPermitsIssuingTheSameSerialNumberLetsExactlyOneThrough(): void
    {
        $itemId  = $this->trackedItem(TrackingType::Serial, '0.00');
        $permits = new StockPermit();

        $permits->create(['type' => 'add', 'date' => date('Y-m-d'), 'location_id' => self::LOCATION_ID, 'lines' => [['item_id' => $itemId, 'quantity' => '2', 'unit_cost' => '10', 'tracking' => ['serials' => ['RACE-1', 'RACE-3']]]]], $this->keeperId);

        $answers = $this->raceStockWorkers('issueSerialPermit', [(string) $itemId, 'RACE-1', (string) $this->keeperId], [(string) $itemId, 'RACE-1', (string) $this->keeperId]);

        $this->assertSame(['done', 'serial_not_available'], $answers);
        $this->assertSame('1.000', $this->onHand($itemId));
        $this->assertSame(['removed', 'in_stock'], [$this->serialState($itemId, 'RACE-1'), $this->serialState($itemId, 'RACE-3')]);
        $this->assertTied($itemId);
    }

    public function testRaceOfTwoSalesOfTheSameSerialNumberLetsExactlyOneThrough(): void
    {
        $itemId = $this->trackedItem(TrackingType::Serial);

        $this->receiveSerials($itemId, ['RACE-2']);

        $answers = $this->raceStockWorkers('sellSerial', [(string) $itemId, 'RACE-2', (string) $this->employeeId], [(string) $itemId, 'RACE-2', (string) $this->employeeId]);

        $this->assertSame(['done', 'refused'], $answers);
        $this->assertSame('sold', $this->serialState($itemId, 'RACE-2'));
        $this->assertSame('0.000', $this->onHand($itemId));
        $this->assertTied($itemId);

        $this->accountingDb()->table('inventory')->like('trans_comment', 'POS ', 'after')->delete();
    }

    // ---------------------------------------------------------------------------------------
    // Batches and expiry
    // ---------------------------------------------------------------------------------------

    public function testReceivePutsTheQuantityOfAPurchaseIntoABatchWithItsExpiryAndTiesTheShelf(): void
    {
        $itemId = $this->trackedItem(TrackingType::Batch);

        $this->assertGreaterThan(0, $this->receiveBatch($itemId, 'B-1', $this->inDays(100), '10'));
        $this->assertSame('10.000', $this->batchQuantity($itemId, 'B-1'));
        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertTied($itemId);
        $this->assertBooksTie();
    }

    public function testReceiveAddsToABatchThatIsAlreadyThereWithTheSameExpiryAndRejectsAnotherExpiry(): void
    {
        $itemId = $this->trackedItem(TrackingType::Batch);
        $expiry = $this->inDays(100);

        $this->receiveBatch($itemId, 'B-2', $expiry, '4');
        $this->receiveBatch($itemId, 'B-2', $expiry, '6');

        $this->assertSame('10.000', $this->batchQuantity($itemId, 'B-2'));
        $this->assertSame(ACCOUNTING_FAILED, $this->receiveBatch($itemId, 'B-2', $this->inDays(200), '1'));
        $this->assertSame(AccountingError::BatchExpiryMismatch, $this->failure($this->purchases));
        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertTied($itemId);
    }

    public function testReceiveRequiresAnExpiryDateAndBatchesThatAddUpToTheQuantity(): void
    {
        $itemId = $this->trackedItem(TrackingType::Batch);

        $this->assertSame(ACCOUNTING_FAILED, $this->buy([[...$this->purchaseLine($itemId, 0, '5', '5.00'), 'tracking' => ['batches' => [['number' => 'B-3', 'quantity' => '5']]]]]));
        $this->assertSame(AccountingError::BatchInvalid, $this->failure($this->purchases));

        $this->assertSame(ACCOUNTING_FAILED, $this->buy([[...$this->purchaseLine($itemId, 0, '5', '5.00'), 'tracking' => ['batches' => [['number' => 'B-3', 'expiry' => $this->inDays(10), 'quantity' => '4']]]]]));
        $this->assertSame(AccountingError::TrackedEntryRequired, $this->failure($this->purchases));

        $this->assertSame(ACCOUNTING_FAILED, $this->buy([$this->purchaseLine($itemId, 0, '5', '5.00')]));
        $this->assertSame(AccountingError::BatchInvalid === $this->failure($this->purchases) ? AccountingError::BatchInvalid : AccountingError::TrackedEntryRequired, $this->failure($this->purchases));
        $this->assertSame('0.000', $this->onHand($itemId));
    }

    public function testReceiveAcceptsSeveralBatchesInOneLineThatAddUpToTheQuantity(): void
    {
        $itemId = $this->trackedItem(TrackingType::Batch);

        $receivingId = $this->buy([[...$this->purchaseLine($itemId, 0, '10', '5.00'), 'tracking' => ['batches' => [['number' => 'M-1', 'expiry' => $this->inDays(30), 'quantity' => '4'], ['number' => 'M-2', 'expiry' => $this->inDays(60), 'quantity' => '6']]]]]);

        $this->assertGreaterThan(0, $receivingId);
        $this->assertSame(['4.000', '6.000'], [$this->batchQuantity($itemId, 'M-1'), $this->batchQuantity($itemId, 'M-2')]);
        $this->assertTied($itemId);
    }

    public function testSaveTakesTheBatchThatExpiresFirstAndThenTheNext(): void
    {
        $itemId = $this->trackedItem(TrackingType::Batch);

        $this->receiveBatch($itemId, 'LATE', $this->inDays(200), '10');
        $this->receiveBatch($itemId, 'SOON', $this->inDays(20), '4');

        $this->assertGreaterThan(0, $this->sellUnits($itemId, '6'));
        $this->assertSame(['0.000', '8.000'], [$this->batchQuantity($itemId, 'SOON'), $this->batchQuantity($itemId, 'LATE')]);
        $this->assertSame('8.000', $this->onHand($itemId));
        $this->assertTied($itemId);
        $this->assertBooksTie();
    }

    public function testSaveRefusesASaleOfMoreThanTheBatchesHoldEvenWhenNegativeStockIsAllowed(): void
    {
        $itemId = $this->trackedItem(TrackingType::Batch);

        $this->receiveBatch($itemId, 'ONLY', $this->inDays(50), '3');

        $this->assertSame(ACCOUNTING_FAILED, $this->sellUnits($itemId, '4'));
        $this->assertSame(AccountingError::BatchInsufficient, $this->failure($this->sales));
        $this->assertSame('3.000', $this->onHand($itemId));
        $this->assertTied($itemId);
    }

    public function testSaveDoesNotSellAnExpiredBatchWhileTheSettingRefusesIt(): void
    {
        $itemId = $this->trackedItem(TrackingType::Batch);

        $this->receiveBatch($itemId, 'OLD', $this->inDays(-5), '5');

        $this->assertSame(ACCOUNTING_FAILED, $this->sellUnits($itemId, '1'));
        $this->assertSame(AccountingError::BatchInsufficient, $this->failure($this->sales));
        $this->assertSame('5.000', $this->batchQuantity($itemId, 'OLD'));
    }

    public function testSaveSkipsAnExpiredBatchAndTakesAnUnexpiredOne(): void
    {
        $itemId = $this->trackedItem(TrackingType::Batch);

        $this->receiveBatch($itemId, 'OLD', $this->inDays(-5), '5');
        $this->receiveBatch($itemId, 'NEW', $this->inDays(90), '5');

        $this->assertGreaterThan(0, $this->sellUnits($itemId, '2'));
        $this->assertSame(['5.000', '3.000'], [$this->batchQuantity($itemId, 'OLD'), $this->batchQuantity($itemId, 'NEW')]);
    }

    public function testSaveSellsAnExpiredBatchWhenTheSettingAllowsIt(): void
    {
        $itemId = $this->trackedItem(TrackingType::Batch);

        $this->receiveBatch($itemId, 'OLD', $this->inDays(-5), '5');
        (new StockOptions())->update(['expired_refused' => false], $this->unitManagerId);

        $this->assertGreaterThan(0, $this->sellUnits($itemId, '2'));
        $this->assertSame('3.000', $this->batchQuantity($itemId, 'OLD'));
    }

    public function testSaveRefusesABatchPickedByHandThatHasExpired(): void
    {
        $itemId = $this->trackedItem(TrackingType::Batch);

        $this->receiveBatch($itemId, 'OLD', $this->inDays(-5), '5');

        $this->employeeId = $this->unitManagerId;

        $this->assertSame(ACCOUNTING_FAILED, $this->sellUnits($itemId, '1', ['tracking' => ['batches' => [['number' => 'OLD', 'quantity' => '1']]]]));
        $this->assertSame(AccountingError::BatchExpired, $this->failure($this->sales));
    }

    public function testSaveTakesABatchPickedByHandWhenTheEmployeeMayPickBatches(): void
    {
        $itemId = $this->trackedItem(TrackingType::Batch);

        $this->receiveBatch($itemId, 'SOON', $this->inDays(20), '5');
        $this->receiveBatch($itemId, 'LATE', $this->inDays(200), '5');

        $this->employeeId = $this->unitManagerId;

        $this->assertGreaterThan(0, $this->sellUnits($itemId, '2', ['tracking' => ['batches' => [['number' => 'LATE', 'quantity' => '2']]]]));
        $this->assertSame(['5.000', '3.000'], [$this->batchQuantity($itemId, 'SOON'), $this->batchQuantity($itemId, 'LATE')]);
        $this->assertTied($itemId);
    }

    public function testSaveRefusesABatchPickedByHandWithoutThePermission(): void
    {
        $itemId = $this->trackedItem(TrackingType::Batch);

        $this->receiveBatch($itemId, 'SOON', $this->inDays(20), '5');

        $this->assertSame(ACCOUNTING_FAILED, $this->sellUnits($itemId, '2', ['tracking' => ['batches' => [['number' => 'SOON', 'quantity' => '2']]]]));
        $this->assertSame(AccountingError::BatchManualPickDenied, $this->failure($this->sales));
        $this->assertSame('5.000', $this->batchQuantity($itemId, 'SOON'));
    }

    public function testSavePutsAReturnBackIntoTheBatchTheSaleTookItFrom(): void
    {
        $itemId = $this->trackedItem(TrackingType::Batch);

        $this->receiveBatch($itemId, 'SOON', $this->inDays(20), '3');
        $this->receiveBatch($itemId, 'LATE', $this->inDays(200), '10');

        $saleId = $this->sellUnits($itemId, '5');

        $this->assertSame(['0.000', '8.000'], [$this->batchQuantity($itemId, 'SOON'), $this->batchQuantity($itemId, 'LATE')]);

        $return = $this->save([$this->line($itemId, 0, '-4', '10.00', '5.00')], [], [], SALE_TYPE_RETURN, origin: $saleId);

        $this->assertGreaterThan(0, $return);
        $this->assertSame(['3.000', '9.000'], [$this->batchQuantity($itemId, 'SOON'), $this->batchQuantity($itemId, 'LATE')]);
        $this->assertSame('12.000', $this->onHand($itemId));
        $this->assertTied($itemId);
        $this->assertBooksTie();
    }

    public function testSaveRequiresTheBatchOfAReturnThatNamesNoSale(): void
    {
        $itemId = $this->trackedItem(TrackingType::Batch);

        $this->receiveBatch($itemId, 'B-9', $this->inDays(20), '3');

        $this->assertSame(ACCOUNTING_FAILED, $this->save([$this->line($itemId, 0, '-1', '10.00', '5.00')], [], [], SALE_TYPE_RETURN));
        $this->assertSame(AccountingError::TrackedEntryRequired, $this->failure($this->sales));
    }

    public function testDeleteOfASaleOfBatchesPutsTheQuantitiesBackIntoTheBatchesItTookThemFrom(): void
    {
        $itemId = $this->trackedItem(TrackingType::Batch);

        $this->receiveBatch($itemId, 'SOON', $this->inDays(20), '3');
        $this->receiveBatch($itemId, 'LATE', $this->inDays(200), '10');

        $saleId = $this->sellUnits($itemId, '5');

        $this->assertTrue($this->sales->delete($saleId, false, true, $this->employeeId));
        $this->assertSame(['3.000', '10.000'], [$this->batchQuantity($itemId, 'SOON'), $this->batchQuantity($itemId, 'LATE')]);
        $this->assertSame('13.000', $this->onHand($itemId));
        $this->assertTied($itemId);
        $this->assertBooksTie();
    }

    public function testDeleteOfAPurchaseOfABatchRefusesWhileTheQuantityWasSold(): void
    {
        $itemId = $this->trackedItem(TrackingType::Batch);

        $receivingId = $this->receiveBatch($itemId, 'B-7', $this->inDays(40), '5');

        $this->sellUnits($itemId, '2');

        $this->assertFalse($this->purchases->delete_value($receivingId, $this->employeeId));
        $this->assertSame('3.000', $this->batchQuantity($itemId, 'B-7'));
        $this->assertTied($itemId);
    }

    public function testCreateMovesABatchBetweenLocationsWithAPermit(): void
    {
        $itemId = $this->trackedItem(TrackingType::Batch, '0.00');
        $second = $this->openLocation('Back store');

        $permits = new StockPermit();

        $permits->create(['type' => 'add', 'date' => date('Y-m-d'), 'location_id' => self::LOCATION_ID, 'lines' => [['item_id' => $itemId, 'quantity' => '10', 'unit_cost' => '4', 'tracking' => ['batches' => [['number' => 'P-1', 'expiry' => $this->inDays(60), 'quantity' => '10']]]]]], $this->keeperId);
        $this->switchOnTransferPermit();
        $permits->create(['type' => 'transfer', 'to_location_id' => $second, 'date' => date('Y-m-d'), 'location_id' => self::LOCATION_ID, 'lines' => [['item_id' => $itemId, 'quantity' => '4']]], $this->keeperId);

        $this->assertSame(['6.000', '4.000'], [$this->batchQuantity($itemId, 'P-1'), $this->batchQuantity($itemId, 'P-1', $second)]);
        $this->assertTied($itemId);
        $this->assertTied($itemId, $second);
        $this->assertBooksTie();
    }

    public function testCreateTakesABatchOutWithAnIssuePermitEvenWhenItHasExpired(): void
    {
        $itemId  = $this->trackedItem(TrackingType::Batch, '0.00');
        $permits = new StockPermit();

        $permits->create(['type' => 'add', 'date' => date('Y-m-d'), 'location_id' => self::LOCATION_ID, 'lines' => [['item_id' => $itemId, 'quantity' => '5', 'unit_cost' => '4', 'tracking' => ['batches' => [['number' => 'P-2', 'expiry' => $this->inDays(-3), 'quantity' => '5']]]]]], $this->keeperId);
        $permits->create(['type' => 'issue', 'date' => date('Y-m-d'), 'location_id' => self::LOCATION_ID, 'lines' => [['item_id' => $itemId, 'quantity' => '5']]], $this->keeperId);

        $this->assertSame('0.000', $this->batchQuantity($itemId, 'P-2'));
        $this->assertTied($itemId);
    }

    // ---------------------------------------------------------------------------------------
    // What cannot be done to a tracked item, and legacy items
    // ---------------------------------------------------------------------------------------

    public function testChangeQuantityRefusesAnyChangeOfTheShelfOfATrackedItemThatIsNotTheTracking(): void
    {
        $itemId = $this->trackedItem(TrackingType::Serial);

        $this->refusedWith(AccountingError::TrackedPathUnsupported, static fn () => model(Item_quantity::class)->changeQuantity($itemId, self::LOCATION_ID, 3.0));
        $this->assertSame('0.000', $this->onHand($itemId));
    }

    public function testSaveValueRefusesToTypeAnotherQuantityForATrackedItemButKeepsTheSameOne(): void
    {
        $itemId = $this->trackedItem(TrackingType::Batch);

        $this->receiveBatch($itemId, 'B-5', $this->inDays(20), '2');

        $this->refusedWith(AccountingError::TrackedPathUnsupported, static fn (): bool => model(Item_quantity::class)->save_value(['item_id' => $itemId, 'location_id' => self::LOCATION_ID, 'quantity' => 9], $itemId, self::LOCATION_ID));
        $this->assertTrue(model(Item_quantity::class)->save_value(['item_id' => $itemId, 'location_id' => self::LOCATION_ID, 'quantity' => 2], $itemId, self::LOCATION_ID));
    }

    public function testSaveRefusesAnInvoiceIssuedBeforeItsGoodsLeaveWhenItHasATrackedItem(): void
    {
        $itemId = $this->trackedItem(TrackingType::Serial);

        $this->receiveSerials($itemId, ['I1']);

        $this->assertSame(ACCOUNTING_FAILED, $this->save([[...$this->line($itemId, 0, '1', '10.00', '5.00'), 'serialnumber' => 'I1']], delivered: false));
        $this->assertSame(AccountingError::TrackedPathUnsupported, $this->failure($this->sales));
        $this->assertSame('in_stock', $this->serialState($itemId, 'I1'));
    }

    public function testSaveRefusesToReturnTrackedGoodsToASupplier(): void
    {
        $itemId = $this->trackedItem(TrackingType::Serial);

        $this->receiveSerials($itemId, ['R1']);

        $this->assertSame(ACCOUNTING_FAILED, $this->buy([$this->purchaseLine($itemId, 0, '-1', '5.00')]));
        $this->assertSame(AccountingError::TrackedPathUnsupported, $this->failure($this->purchases));
        $this->assertSame('1.000', $this->onHand($itemId));
    }

    public function testCreateLeavesATrackedItemOutOfAStocktake(): void
    {
        $itemId = $this->trackedItem(TrackingType::Serial);

        $this->receiveSerials($itemId, ['C1']);

        $this->refusedWith(AccountingError::TrackedPathUnsupported, fn () => (new Stocktake())->create(['location_id' => self::LOCATION_ID, 'date' => date('Y-m-d'), 'item_ids' => [$itemId]], $this->keeperId));
        $this->assertNotContains($itemId, (new Stocktake())->itemsOf(self::LOCATION_ID));
    }

    public function testRequireUntrackedLetsAnUntrackedItemThroughAndRefusesATrackedOne(): void
    {
        $tracked   = $this->trackedItem(TrackingType::Batch);
        $untracked = $this->item('5.00');

        $this->tracking->requireUntracked([$untracked]);
        $this->refusedWith(AccountingError::TrackedPathUnsupported, fn () => $this->tracking->requireUntracked([$untracked, $tracked]));
    }

    public function testChangeQuantityLeavesAnUntrackedItemAloneWhileTrackingIsOn(): void
    {
        $this->switchOn('tracking');

        $itemId = $this->item('5.00');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '4', '5.00')]));
        $this->assertGreaterThan(0, $this->sellUnits($itemId, '1'));
        $this->assertSame('3.000', $this->onHand($itemId));
        $this->assertBooksTie();
    }

    public function testReceiveBehavesAsBeforeWhileTrackingIsOffEvenForAnItemThatHasAProfile(): void
    {
        $itemId = $this->trackedItem(TrackingType::Serial);

        $this->setConfigValue(StockOptions::TRACKING, '0');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '3', '5.00')]));
        $this->assertSame('3.000', $this->onHand($itemId));
        $this->assertSame(0, $this->accountingDb()->table('stock_serials')->where('item_id', $itemId)->countAllResults());
    }

    public function testReverseSourceReturnsTheItemsItChangedBackAndLeavesAnUntouchedSourceAlone(): void
    {
        $itemId = $this->trackedItem(TrackingType::Serial);

        $receivingId = $this->receiveSerials($itemId, ['Q9']);

        $this->assertSame([$itemId], $this->tracking->reverseSource('receiving', $receivingId));
        $this->assertSame([], $this->tracking->reverseSource('receiving', $receivingId), 'taking back twice changes nothing');
        $this->assertSame('0.000', $this->onHand($itemId));
    }

    public function testSaveSellsALineOfTheRegisterFromTheBatchPickedByHand(): void
    {
        $itemId = $this->trackedItem(TrackingType::Batch);

        $this->buy([[...$this->purchaseLine($itemId, 0, '5', '5.00'), 'tracking' => ['batches' => [['number' => 'SOON', 'expiry' => date('Y-m-d', strtotime('+10 days')), 'quantity' => '5']]]]]);
        $this->buy([[...$this->purchaseLine($itemId, 0, '5', '5.00'), 'tracking' => ['batches' => [['number' => 'LATE', 'expiry' => date('Y-m-d', strtotime('+300 days')), 'quantity' => '5']]]]]);

        bcscale(4);

        $register = new Sale_lib();
        $register->clear_all();
        $register->set_mode('sale');
        $register->set_customer($this->customerId);

        $discount = '0';
        $code     = (string) $itemId;

        $register->add_item($code, self::LOCATION_ID, '2', $discount, 0, PRICE_MODE_STANDARD, null, null, '10.00');
        $register->pickBatch((string) array_key_last($register->get_cart()), 'LATE');

        $this->employeeId = $this->unitManagerId;

        $this->assertGreaterThan(0, $this->save(array_values($register->get_cart()), [], [$this->payment(lang('Sales.cash'), '20.00')], SALE_TYPE_POS));
        $this->assertSame('3.000', $this->accountingDb()->table('stock_batch_quantities q')->select('q.quantity')->join('stock_batches b', 'b.batch_id = q.batch_id')->where('b.batch_number', 'LATE')->get()->getRow()->quantity);
    }
}
