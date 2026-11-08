<?php

namespace Tests\Libraries\PurchaseCycle;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use App\Libraries\PurchaseCycle\PurchaseDocumentKind;
use App\Libraries\PurchaseCycle\PurchaseDocuments;
use App\Libraries\PurchaseCycle\PurchaseDocumentStatus;
use App\Libraries\PurchaseCycle\PurchaseOrderReceipts;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ConcurrentDbRaceTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\PurchaseCycleFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * What the receivings took from the lines of a purchase order: the units of each line, the refusal of more than the order
 * holds (unless the settings allow it), the supplier, branch and status of the order, the receiving that is taken back or
 * saved again, and the status read from the receivings that stand.
 */
class PurchaseOrderReceiptsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ConcurrentDbRaceTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use PurchaseCycleFixtureTrait;
    use SaleAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private PurchaseOrderReceipts $receipts;
    private PurchaseDocuments $orders;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBuying();
        $this->startCycle();
        $this->allowEmployeeEverywhere();

        $this->receipts = new PurchaseOrderReceipts($this->accountingDb());
        $this->orders   = $this->documentsOf(PurchaseDocumentKind::Order);
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->stopCycle();
        $this->stopBuying();

        parent::tearDown();
    }

    /**
     * @param list<array{item_id?: int, quantity: string, unit_price?: string}> $lines
     */
    private function order(array $lines = [['quantity' => '10']], ?int $supplierId = null, int $location = self::LOCATION_ID): int
    {
        return $this->orders->create($this->documentInput([
            'supplier_id' => $supplierId ?? $this->supplierId,
            'location_id' => $location,
            'lines'       => array_map(fn (array $line): array => ['item_id' => $this->goods, 'unit_price' => '4.00', 'description' => '', ...$line], $lines),
        ]), $this->clerk);
    }

    private function receivingOfStock(): int
    {
        $receivingId = $this->buy([$this->purchaseLine($this->goods, 0, '1', '4.00')]);

        $this->assertGreaterThan(0, $receivingId);

        return $receivingId;
    }

    /**
     * Takes units from the order for a receiving that exists, the way the model does it inside the transaction that saves it.
     *
     * @param list<array{item_id?: int, quantity: string, receiving_quantity?: string}> $items
     */
    private function take(int $orderId, array $items, ?int $receivingId = null, ?int $supplierId = null, ?int $by = null, int $branchId = 1): int
    {
        $receivingId ??= $this->receivingOfStock();

        $this->receipts->recordReceiving($receivingId, $orderId, array_map(fn (array $item): array => ['item_id' => $this->goods, 'receiving_quantity' => '1', ...$item], $items), $supplierId ?? $this->supplierId, $by ?? $this->clerk, $branchId);

        return $receivingId;
    }

    private function refusal(callable $action): AccountingException|BranchException
    {
        try {
            $action();
        } catch (AccountingException|BranchException $refusal) {
            return $refusal;
        }

        $this->fail('The action should have been refused.');
    }

    private function assertRefused(AccountingError $error, callable $action): AccountingException
    {
        $refusal = $this->refusal($action);

        $this->assertInstanceOf(AccountingException::class, $refusal);
        $this->assertSame($error, $refusal->error);

        return $refusal;
    }

    private function received(int $orderId): array
    {
        return array_map(static fn (object $line): string => $line->received, $this->orders->lines($orderId));
    }

    public function testRecordReceiving_TakesTheUnitsOfTheLineAndTheOrderIsReceivedInPartThenInFull(): void
    {
        $orderId = $this->order([['quantity' => '10']]);

        $this->assertSame(PurchaseDocumentStatus::Open, $this->orders->statusOf($orderId));

        $this->take($orderId, [['quantity' => '4']]);

        $this->assertSame(['4.000'], $this->received($orderId));
        $this->assertSame('6.000', $this->orders->lines($orderId)[0]->remaining);
        $this->assertSame(PurchaseDocumentStatus::PartlyReceived, $this->orders->statusOf($orderId));

        $this->take($orderId, [['quantity' => '6']]);

        $this->assertSame(['10.000'], $this->received($orderId));
        $this->assertSame(PurchaseDocumentStatus::Received, $this->orders->statusOf($orderId));
        $this->assertSame([], $this->orders->remainingLines($orderId));
    }

    public function testRecordReceiving_FillsTheLinesOfAnItemInTheOrderTheyAreIn(): void
    {
        $orderId = $this->order([['quantity' => '3'], ['quantity' => '2']]);

        $this->take($orderId, [['quantity' => '4']]);

        $this->assertSame(['3.000', '1.000'], $this->received($orderId));
        $this->assertSame(['0.000', '1.000'], array_map(static fn (object $line): string => $line->remaining, $this->orders->lines($orderId)));
    }

    public function testRecordReceiving_CountsTheUnitsOfAPackAndNotThePacks(): void
    {
        $orderId = $this->order([['quantity' => '30']]);

        $this->take($orderId, [['quantity' => '2', 'receiving_quantity' => '12']]);

        $this->assertSame(['24.000'], $this->received($orderId));
    }

    public function testRecordReceiving_CountsTheUnitsOfSeveralLinesOfTheSameItemInTheReceivingTogether(): void
    {
        $orderId = $this->order([['quantity' => '5']]);

        $this->take($orderId, [['quantity' => '2'], ['quantity' => '3']]);

        $this->assertSame(['5.000'], $this->received($orderId));
        $this->assertSame(PurchaseDocumentStatus::Received, $this->orders->statusOf($orderId));
    }

    public function testRecordReceiving_TakesNothingForALineOfZeroUnits(): void
    {
        $orderId = $this->order([['quantity' => '5']]);

        $this->take($orderId, [['quantity' => '0'], ['quantity' => '1']]);

        $this->assertSame(['1.000'], $this->received($orderId));
    }

    public function testRecordReceiving_RefusesMoreThanTheOrderHoldsAndStoresNothing(): void
    {
        $orderId     = $this->order([['quantity' => '10']]);
        $receivingId = $this->receivingOfStock();

        $refusal = $this->assertRefused(AccountingError::PurchaseOrderQuantityExceeded, fn () => $this->take($orderId, [['quantity' => '10.001']], $receivingId));

        $this->assertSame('10.000', $refusal->context['max']);
        $this->assertSame(0, $this->countRows('purchase_order_receivings'));
        $this->assertSame(0, $this->countRows('purchase_order_receiving_lines'));
        $this->assertSame(PurchaseDocumentStatus::Open, $this->orders->statusOf($orderId));
    }

    public function testRecordReceiving_AcceptsExactlyTheQuantityTheOrderHolds(): void
    {
        $orderId = $this->order([['quantity' => '10.5']]);

        $this->take($orderId, [['quantity' => '10.5']]);

        $this->assertSame(PurchaseDocumentStatus::Received, $this->orders->statusOf($orderId));
    }

    public function testRecordReceiving_RefusesMoreThanWhatIsLeftAfterAnEarlierReceiving(): void
    {
        $orderId = $this->order([['quantity' => '10']]);

        $this->take($orderId, [['quantity' => '6']]);

        $refusal = $this->assertRefused(AccountingError::PurchaseOrderQuantityExceeded, fn () => $this->take($orderId, [['quantity' => '4.5']]));

        $this->assertSame('4.000', $refusal->context['max']);
        $this->assertSame(['6.000'], $this->received($orderId));
    }

    public function testRecordReceiving_LetsTheExcessLandOnTheLastLineOfTheItemWhenTheSettingsAllowIt(): void
    {
        $this->setConfigValue('purchase_order_allow_excess', '1');

        $orderId = $this->order([['quantity' => '3'], ['quantity' => '2']]);

        $this->take($orderId, [['quantity' => '7']]);

        $this->assertSame(['3.000', '4.000'], $this->received($orderId));
        $this->assertSame(PurchaseDocumentStatus::Received, $this->orders->statusOf($orderId));
    }

    public function testRecordReceiving_RefusesAnItemTheOrderDoesNotHoldUnlessTheSettingsAllowIt(): void
    {
        $orderId = $this->order([['quantity' => '5']]);
        $other   = $this->item('3.00');

        $this->assertRefused(AccountingError::PurchaseOrderQuantityExceeded, fn () => $this->take($orderId, [['item_id' => $other, 'quantity' => '1']]));

        $this->setConfigValue('purchase_order_allow_excess', '1');

        $this->take($orderId, [['quantity' => '2'], ['item_id' => $other, 'quantity' => '1']]);

        $this->assertSame(['2.000'], $this->received($orderId), 'the item the order does not hold is left out of what it tracks');
    }

    public function testRecordReceiving_RefusesAnotherSupplierThanTheOrder(): void
    {
        $orderId = $this->order();

        $this->assertRefused(AccountingError::PurchaseOrderSupplierMismatch, fn () => $this->take($orderId, [['quantity' => '1']], null, $this->anotherSupplier()));
        $this->assertRefused(AccountingError::PurchaseOrderSupplierMismatch, fn () => $this->take($orderId, [['quantity' => '1']], null, -1));
        $this->assertSame(0, $this->countRows('purchase_order_receivings'));
    }

    public function testRecordReceiving_RefusesAnotherBranchThanTheOneOfTheOrder(): void
    {
        $branchB = $this->createBranch('B2', 'Second branch');
        $orderId = $this->order();

        $this->assertRefused(AccountingError::PurchaseOrderBranchMismatch, fn () => $this->take($orderId, [['quantity' => '1']], null, null, null, $branchB));
        $this->assertSame(0, $this->countRows('purchase_order_receivings'));
    }

    public function testRecordReceiving_RefusesAnEmployeeWhoDoesNotWorkInTheBranchOfTheOrder(): void
    {
        $branchB  = $this->createBranch('B2', 'Second branch');
        $orderId  = $this->order();
        $outsider = $this->employeeWithGrants([], [$branchB]);

        $refusal = $this->refusal(fn () => $this->take($orderId, [['quantity' => '1']], null, null, $outsider));

        $this->assertInstanceOf(BranchException::class, $refusal);
        $this->assertSame(BranchError::NotAllowed, $refusal->error);
        $this->assertSame(0, $this->countRows('purchase_order_receivings'));
    }

    public function testRecordReceiving_RefusesACancelledOrderAndAnOrderReceivedInFull(): void
    {
        $cancelled = $this->order();
        $full      = $this->order([['quantity' => '2']]);

        $this->orders->cancel($cancelled, 'no', $this->clerk);
        $this->take($full, [['quantity' => '2']]);

        $this->assertRefused(AccountingError::PurchaseOrderNotReceivable, fn () => $this->take($cancelled, [['quantity' => '1']]));
        $this->assertRefused(AccountingError::PurchaseOrderNotReceivable, fn () => $this->take($full, [['quantity' => '1']]));
    }

    public function testRecordReceiving_ReceivesAnOrderThatWasReceivedInPartAndThenCancelledNoMore(): void
    {
        $orderId = $this->order([['quantity' => '10']]);

        $this->take($orderId, [['quantity' => '4']]);
        $this->orders->cancel($orderId, 'the rest is not coming', $this->clerk);

        $this->assertSame(PurchaseDocumentStatus::Cancelled, $this->orders->statusOf($orderId));
        $this->assertRefused(AccountingError::PurchaseOrderNotReceivable, fn () => $this->take($orderId, [['quantity' => '1']]));
        $this->assertSame(['4.000'], $this->received($orderId), 'what was received stays recorded');
    }

    public function testRecordReceiving_RefusesAnOrderThatDoesNotExist(): void
    {
        $this->assertRefused(AccountingError::PurchaseDocumentNotFound, fn () => $this->take(999999, [['quantity' => '1']]));
    }

    public function testRecordReceiving_TakesTheSameReceivingAgainWithoutCountingItsOwnUnitsTwice(): void
    {
        $orderId     = $this->order([['quantity' => '10']]);
        $receivingId = $this->take($orderId, [['quantity' => '8']]);

        $this->take($orderId, [['quantity' => '9']], $receivingId, null, null, 1);

        $this->assertSame(['9.000'], $this->received($orderId));
        $this->assertSame(1, $this->countRows('purchase_order_receivings'));
        $this->assertRefused(AccountingError::PurchaseOrderQuantityExceeded, fn () => $this->take($orderId, [['quantity' => '11']], $receivingId));
        $this->assertSame(['9.000'], $this->received($orderId));
    }

    public function testRecordReceiving_WritesAnAuditEntryOnTheOrder(): void
    {
        $orderId     = $this->order();
        $receivingId = $this->take($orderId, [['quantity' => '2']]);

        $this->take($orderId, [['quantity' => '3']], $receivingId);

        $this->assertSame(['created', 'received', 'received'], $this->auditActions(PurchaseDocumentKind::Order, $orderId));
    }

    public function testRecordReceiving_TakesNothingWhenTheTransactionOfTheReceivingIsRolledBack(): void
    {
        $orderId     = $this->order();
        $receivingId = $this->receivingOfStock();
        $db          = $this->accountingDb();

        $db->transStart();
        $this->take($orderId, [['quantity' => '5']], $receivingId);
        $db->transRollback();
        $db->resetTransStatus();

        $this->assertSame([], $this->receipts->receivedQuantities($orderId));
        $this->assertSame(PurchaseDocumentStatus::Open, $this->orders->statusOf($orderId));
    }

    public function testReleaseReceiving_GivesTheUnitsBackAndSaysSoInTheAuditLog(): void
    {
        $orderId = $this->order([['quantity' => '10']]);
        $first   = $this->take($orderId, [['quantity' => '4']]);

        $this->take($orderId, [['quantity' => '6']]);

        $this->assertSame(PurchaseDocumentStatus::Received, $this->orders->statusOf($orderId));

        $this->receipts->releaseReceiving($first, $this->clerk);

        $this->assertSame(['6.000'], $this->received($orderId));
        $this->assertSame(PurchaseDocumentStatus::PartlyReceived, $this->orders->statusOf($orderId));
        $this->assertNull($this->receipts->orderOfReceiving($first));
        $this->assertSame(['created', 'received', 'received', 'receipt_released'], $this->auditActions(PurchaseDocumentKind::Order, $orderId));
    }

    public function testReleaseReceiving_DoesNothingForAReceivingThatWasNotMadeFromAnOrder(): void
    {
        $receivingId = $this->receivingOfStock();

        $this->receipts->releaseReceiving($receivingId, $this->clerk);

        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'purchase_order')->countAllResults());
    }

    public function testStatusOf_ReadsTheReceivingsThatStandAndKeepsACancelledOrderCancelled(): void
    {
        $orderId = $this->order([['quantity' => '10']]);

        $this->assertSame(PurchaseDocumentStatus::Approved, $this->receipts->statusOf('approved', $orderId));

        $this->take($orderId, [['quantity' => '1']]);

        $this->assertSame(PurchaseDocumentStatus::PartlyReceived, $this->receipts->statusOf('approved', $orderId));
        $this->assertSame(PurchaseDocumentStatus::Cancelled, $this->receipts->statusOf('cancelled', $orderId));
    }

    public function testReceivedQuantities_CanLeaveOutOneReceiving(): void
    {
        $orderId = $this->order([['quantity' => '10']]);
        $first   = $this->take($orderId, [['quantity' => '4']]);

        $this->take($orderId, [['quantity' => '3']]);

        $line = (int) $this->orders->lines($orderId)[0]->line_id;

        $this->assertSame([$line => '7.000'], $this->receipts->receivedQuantities($orderId));
        $this->assertSame([$line => '3.000'], $this->receipts->receivedQuantities($orderId, $first));
    }

    public function testReceivingsOf_ListsTheReceivingsThatStandOldestFirstWithTheirUnits(): void
    {
        $orderId = $this->order([['quantity' => '10']]);
        $first   = $this->take($orderId, [['quantity' => '4']]);
        $second  = $this->take($orderId, [['quantity' => '3']]);

        $rows = $this->receipts->receivingsOf($orderId);

        $this->assertSame([$first, $second], array_map(static fn (object $row): int => (int) $row->receiving_id, $rows));
        $this->assertSame(['4.000', '3.000'], array_map(static fn (object $row): string => bcadd((string) $row->units, '0', 3), $rows));
        $this->assertSame([], $this->receipts->receivingsOf($this->order()));
    }

    public function testOrderOfReceiving_NamesTheOrderAReceivingWasMadeFrom(): void
    {
        $orderId     = $this->order();
        $receivingId = $this->take($orderId, [['quantity' => '1']]);

        $this->assertSame($orderId, $this->receipts->orderOfReceiving($receivingId));
        $this->assertNull($this->receipts->orderOfReceiving($this->receivingOfStock()));
    }

    public function testRecordReceiving_LetsOneOfTwoReceivingsAtTheSameMomentTakeTheUnitsTheOrderHolds(): void
    {
        $orderId = $this->order([['quantity' => '10']]);
        $before  = $this->countRows('receivings');

        [$first, $second] = $this->raceTwoProcesses('orderReceive', [$orderId, '7', $this->goods, $this->supplierId, $this->employeeId], [$orderId, '7', $this->goods, $this->supplierId, $this->employeeId]);

        $this->receivingIds = [...$this->receivingIds, ...array_map(static fn (array $row): int => (int) $row['receiving_id'], $this->accountingDb()->table('receivings')->select('receiving_id')->where('supplier_id', $this->supplierId)->get()->getResultArray())];

        $this->assertSame(1, (int) $first + (int) $second, 'of two receivings of 7 from an order of 10, one is saved and the other is refused');
        $this->assertSame(['7.000'], $this->received($orderId));
        $this->assertSame($before + 1, $this->countRows('receivings'));
        $this->assertSame(1, $this->countRows('purchase_order_receiving_lines'));
    }

    public function testRecordReceiving_LetsOneOfTwoReceivingsTakeTheUnitsOfTheOrderWhenTheyAreTakenAtTheSameMomentWithoutTheLockOfTheSupplierBetweenThem(): void
    {
        $orderId = $this->order([['quantity' => '10']]);
        $first   = $this->receivingOfStock();
        $second  = $this->receivingOfStock();

        [$one, $two] = $this->raceTwoProcesses('orderRecord', [$first, $orderId, '7', $this->goods, $this->supplierId, $this->clerk], [$second, $orderId, '7', $this->goods, $this->supplierId, $this->clerk]);

        $this->assertSame(1, (int) $one + (int) $two, 'the lock of the order lets only one of two takings of 7 from an order of 10 through');
        $this->assertSame(['7.000'], $this->received($orderId));
        $this->assertSame(1, $this->countRows('purchase_order_receivings'));
    }

    public function testRecordReceiving_RefusesANewReceivingWhileThePurchaseCycleIsOffButStillCorrectsOneThatWasMade(): void
    {
        $orderId     = $this->order([['quantity' => '10']]);
        $receivingId = $this->take($orderId, [['quantity' => '4']]);

        $this->setConfigValue('enable_purchase_cycle', '0');

        $this->assertRefused(AccountingError::PurchaseDocumentModuleOff, fn () => $this->take($orderId, [['quantity' => '1']]));

        $this->receipts->recordReceiving($receivingId, $orderId, [['item_id' => $this->goods, 'quantity' => '5', 'receiving_quantity' => '1']], $this->supplierId, $this->clerk, 1, 'receipt_changed');

        $this->assertSame(['5.000'], $this->received($orderId));
    }
}
