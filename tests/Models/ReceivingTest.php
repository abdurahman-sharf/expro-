<?php

namespace Tests\Models;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\Accounting\InterBranchPosition;
use App\Libraries\Accounting\InvoiceDiscount;
use App\Libraries\Accounting\ReceivingLineEdit;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\PurchaseCycle\PurchaseDocumentKind;
use App\Libraries\PurchaseCycle\PurchaseDocumentStatus;
use App\Libraries\PurchaseCycle\PurchaseOrderReceipts;
use App\Models\Receiving;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ConcurrentDbRaceTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\PurchaseCycleFixtureTrait;
use Tests\Support\RoleFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * Regression tests: Receiving::delete_value() must
 * correctly reverse the stock quantity change it applied via
 * Item_quantity::changeQuantity(), using the same atomic upsert as the
 * sale-checkout and sale-cancel paths.
 */
class ReceivingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ConcurrentDbRaceTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use PurchaseCycleFixtureTrait;
    use RoleFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;
    protected $namespace   = null;

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->stopCycle();
        $this->stopBuying();

        $db = Database::connect();
        $db->table('document_numbers')->truncate();
        $db->table('document_sequences')->truncate();
        $db->table('accounting_audit_log')->where('entity_type', 'document_numbering')->delete();

        parent::tearDown();
    }

    protected function createSupplier(): int
    {
        $db = Database::connect();

        $db->table('people')->insert([
            'first_name'   => 'Test',
            'last_name'    => 'Supplier',
            'phone_number' => '555-0400',
            'email'        => 'supplier-' . uniqid() . '@test.com',
            'address_1'    => '',
            'address_2'    => '',
            'city'         => '',
            'state'        => '',
            'zip'          => '',
            'country'      => '',
            'comments'     => '',
        ]);
        $personId = (int) $db->insertID();

        $db->table('suppliers')->insert([
            'person_id' => $personId,
            'deleted'   => 0,
        ]);

        return $personId;
    }

    protected function buildReceivingLine(int $itemId, float $quantity, float $costPrice): array
    {
        return [
            0 => [
                'item_id'            => $itemId,
                'line'               => 0,
                'description'        => 'Test Item',
                'serialnumber'       => '',
                'quantity'           => $quantity,
                'receiving_quantity' => 1,
                'discount'           => 0,
                'discount_type'      => 0,
                'price'              => $costPrice,
                'item_location'      => self::LOCATION_ID,
            ],
        ];
    }

    public function testDeleteValueRestoresStockAfterReceiving(): void
    {
        $employeeId = $this->createEmployee();
        $supplierId = $this->createSupplier();
        $itemId     = $this->createTestItem(HAS_STOCK);

        // Deliberately no pre-existing item_quantities row for this item/location.

        $receivingModel = model(Receiving::class);
        $items          = $this->buildReceivingLine($itemId, 5, 1.00);

        $receivingId = $receivingModel->save_value(
            $items,
            $supplierId,
            $employeeId,
            'test receiving',
            'REF-' . uniqid(),
            'Cash'
        );

        $this->assertGreaterThan(0, $receivingId);
        $this->assertEqualsWithDelta(5.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);

        $deleteResult = $receivingModel->delete_value($receivingId, $employeeId, true);

        $this->assertTrue($deleteResult);
        $this->assertEqualsWithDelta(0.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
    }
    public function testBranchIdsOfItems_NamesEachBranchOfTheLocationsOfTheLinesOnce(): void
    {
        $this->startBuying();
        $branch = $this->createBranch('R9', 'Ninth branch');
        $depot  = $this->anotherLocation('Depot');
        $this->accountingDb()->table('stock_locations')->where('location_id', $depot)->update(['branch_id' => $branch]);
        $second = $this->anotherLocation('Second depot');
        $this->accountingDb()->table('stock_locations')->where('location_id', $second)->update(['branch_id' => $branch]);

        $receivings = model(Receiving::class);

        $this->assertSame([], $receivings->branchIdsOfItems([]));
        $this->assertSame([1], $receivings->branchIdsOfItems([['item_location' => self::LOCATION_ID], ['item_location' => self::LOCATION_ID]]));
        $this->assertSame([1, $branch], $receivings->branchIdsOfItems([['item_location' => $depot], ['item_location' => $second], ['item_location' => self::LOCATION_ID]]));
    }

    public function testGetBranchId_IsTheBranchOfThePurchaseAndNullWhenThereIsNone(): void
    {
        $this->startBuying();
        $branch = $this->createBranch('R8', 'Eighth branch');
        $depot  = $this->anotherLocation('Depot');
        $this->accountingDb()->table('stock_locations')->where('location_id', $depot)->update(['branch_id' => $branch]);
        $itemId = $this->item('0.00');

        $here  = $this->buy([$this->purchaseLine($itemId, 0, '1', '10')]);
        $there = $this->buy([$this->purchaseLine($itemId, 0, '1', '10', locationId: $depot)]);

        $this->assertSame(1, $this->purchases->getBranchId($here));
        $this->assertSame($branch, $this->purchases->getBranchId($there));
        $this->assertNull($this->purchases->getBranchId(0));
    }

    /**
     * @return array{branch: int, depot: int, second: int}
     */
    private function twoBranchesOfLocations(): array
    {
        $branch = $this->createBranch('R7', 'Seventh branch');
        $depot  = $this->locationOfBranch($branch, 'Depot');
        $second = $this->locationOfBranch($branch, 'Second depot');

        return ['branch' => $branch, 'depot' => $depot, 'second' => $second];
    }

    /**
     * @return array<string, mixed>
     */
    private function lineAt(int $location, string $quantity, string $perPack = '1'): array
    {
        return ['item_location' => $location, 'quantity' => $quantity, 'receiving_quantity' => $perPack];
    }

    public function testTransferBetweenBranches_NamesTheBranchTheStockLeavesAndTheOneItGoesTo(): void
    {
        $this->startBuying();
        ['branch' => $branch, 'depot' => $depot, 'second' => $second] = $this->twoBranchesOfLocations();
        $receivings = model(Receiving::class);

        $this->assertSame(['from' => 1, 'to' => $branch], $receivings->transferBetweenBranches([$this->lineAt($depot, '4'), $this->lineAt(self::LOCATION_ID, '-4')]));
        $this->assertSame(['from' => $branch, 'to' => 1], $receivings->transferBetweenBranches([$this->lineAt($depot, '-4'), $this->lineAt(self::LOCATION_ID, '4')]));
        $this->assertSame(['from' => 1, 'to' => $branch], $receivings->transferBetweenBranches([$this->lineAt($depot, '1'), $this->lineAt($second, '1'), $this->lineAt(self::LOCATION_ID, '-2')]), 'Two locations of the branch it goes to are one branch');
        $this->assertSame(['from' => 1, 'to' => $branch], $receivings->transferBetweenBranches([$this->lineAt($depot, '1', '6'), $this->lineAt(self::LOCATION_ID, '-6')]), 'The units of a pack count');
    }

    public function testTransferBetweenBranches_IsNullForEverythingThatIsNotOneBranchToAnother(): void
    {
        $this->startBuying();
        ['depot' => $depot] = $this->twoBranchesOfLocations();
        $third      = $this->locationOfBranch($this->createBranch('R6', 'Sixth branch'), 'Third depot');
        $near       = $this->anotherLocation('Near depot');
        $receivings = model(Receiving::class);

        $this->assertNull($receivings->transferBetweenBranches([]));
        $this->assertNull($receivings->transferBetweenBranches([$this->lineAt($depot, '4'), $this->lineAt(self::LOCATION_ID, '4')]), 'A purchase across branches');
        $this->assertNull($receivings->transferBetweenBranches([$this->lineAt($depot, '-4'), $this->lineAt(self::LOCATION_ID, '-4')]), 'A return across branches');
        $this->assertNull($receivings->transferBetweenBranches([$this->lineAt($near, '4'), $this->lineAt(self::LOCATION_ID, '-4')]), 'Within one branch');
        $this->assertNull($receivings->transferBetweenBranches([$this->lineAt($depot, '2'), $this->lineAt($third, '2'), $this->lineAt(self::LOCATION_ID, '-4')]), 'Into two other branches');
        $this->assertNull($receivings->transferBetweenBranches([$this->lineAt($depot, '4'), $this->lineAt(self::LOCATION_ID, '-3'), $this->lineAt($depot, '-1')]), 'Out of the branch it goes into as well');
        $this->assertNull($receivings->transferBetweenBranches([$this->lineAt(987654, '4'), $this->lineAt(self::LOCATION_ID, '-4')]), 'A location that does not exist');
    }

    public function testGetBranchIds_AreTheBranchOfThePurchaseAndTheOneItsStockGoesTo(): void
    {
        $this->startBuying();
        ['branch' => $branch, 'depot' => $depot] = $this->twoBranchesOfLocations();
        $itemId = $this->item('0.00');
        $this->workInEveryBranch();

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]));

        $within  = $this->buy([$this->purchaseLine($itemId, 0, '1', '10')]);
        $between = $this->buy([$this->purchaseLine($itemId, 0, '4', '0', '0', '0', PERCENT, $depot), $this->purchaseLine($itemId, 1, '-4', '0')], null, null);

        $this->assertSame([1], $this->purchases->getBranchIds($within));
        $this->assertSame([1, $branch], $this->purchases->getBranchIds($between));
        $this->assertSame([], $this->purchases->getBranchIds(0));
    }

    public function testBranchesBetweenLocations_NamesTheCodesOfTheBranchesOnlyWhenTheyDiffer(): void
    {
        $this->startBuying();
        ['depot' => $depot, 'second' => $second] = $this->twoBranchesOfLocations();
        $receivings = model(Receiving::class);

        $first = (string) $this->accountingDb()->table('branches')->where('branch_id', 1)->get()->getRow()->code;

        $this->assertSame(['from' => $first, 'to' => 'R7'], $receivings->branchesBetweenLocations(self::LOCATION_ID, $depot));
        $this->assertSame(['from' => 'R7', 'to' => $first], $receivings->branchesBetweenLocations($depot, self::LOCATION_ID));
        $this->assertNull($receivings->branchesBetweenLocations($depot, $second));
        $this->assertNull($receivings->branchesBetweenLocations(self::LOCATION_ID, 987654));
    }

    public function testHasReturns_IsTrueOnlyForAPurchaseGoodsWentBackFrom(): void
    {
        $this->startBuying();
        $itemId   = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]);
        $other    = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]);

        $this->assertFalse($this->purchases->hasReturns($purchase));

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($itemId, 0, '-2', '50')], -1, null, $purchase));

        $this->assertTrue($this->purchases->hasReturns($purchase));
        $this->assertFalse($this->purchases->hasReturns($other));
    }

    public function testGetReturnableQuantities_SaysWhatEachItemBroughtAndWhatEarlierReturnsGaveBack(): void
    {
        $this->startBuying();
        $first    = $this->item('0.00');
        $second   = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($first, 0, '10', '50'), $this->purchaseLine($second, 1, '2', '20', perPack: '6')]);

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($first, 0, '-3', '50')], -1, null, $purchase));
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($first, 0, '-1', '50'), $this->purchaseLine($second, 1, '-5', '20')], -1, null, $purchase));

        $this->assertEqualsWithDelta(['bought' => 10.0, 'returned' => 4.0], $this->purchases->getReturnableQuantities($purchase)[$first], 0.0001);
        $this->assertEqualsWithDelta(['bought' => 12.0, 'returned' => 5.0], $this->purchases->getReturnableQuantities($purchase)[$second], 0.0001);
        $this->assertSame([], $this->purchases->getReturnableQuantities(0));
    }

    public function testUpdate_RefusesAnotherSupplierOrDateForAPostedPurchaseAndKeepsTheRow(): void
    {
        $this->startBuying();
        $itemId   = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]);
        $other    = $this->supplier('Another supplier');
        $before   = $this->accountingDb()->table('receivings')->where('receiving_id', $purchase)->get()->getRow();

        $this->assertFalse($this->purchases->update($purchase, ['supplier_id' => $other]));
        $this->assertFalse($this->purchases->update($purchase, ['receiving_time' => '2020-02-02 10:00:00']));

        $failure = $this->purchases->accountingFailure();

        $this->assertInstanceOf(AccountingException::class, $failure);
        $this->assertSame(AccountingError::PurchaseLocked, $failure->error);

        $after = $this->accountingDb()->table('receivings')->where('receiving_id', $purchase)->get()->getRow();

        $this->assertSame($before->supplier_id, $after->supplier_id);
        $this->assertSame($before->receiving_time, $after->receiving_time);
    }

    public function testUpdate_StillTakesTheCommentAndTheSameSupplierAndDateOfAPostedPurchase(): void
    {
        $this->startBuying();
        $itemId   = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($itemId, 0, '10', '50')]);
        $time     = (string) $this->accountingDb()->table('receivings')->where('receiving_id', $purchase)->get()->getRow()->receiving_time;

        $this->assertTrue($this->purchases->update($purchase, ['comment' => 'changed', 'supplier_id' => $this->supplierId, 'receiving_time' => $time]));
        $this->assertNull($this->purchases->accountingFailure());
        $this->assertSame('changed', $this->accountingDb()->table('receivings')->where('receiving_id', $purchase)->get()->getRow()->comment);
    }

    public function testUpdate_ChangesTheSupplierOfAPurchaseWhileAccountingIsOff(): void
    {
        $itemId     = $this->createTestItem(HAS_STOCK);
        $employeeId = $this->createEmployee();
        $supplierId = $this->createSupplier();
        $other      = $this->createSupplier();
        $receivings = model(Receiving::class);

        $purchase = $receivings->save_value($this->buildReceivingLine($itemId, 2, 3.00), $supplierId, $employeeId, 'test', 'REF-' . uniqid(), 'Cash');

        $this->assertTrue($receivings->update($purchase, ['supplier_id' => $other]));
        $this->assertSame($other, (int) Database::connect()->table('receivings')->where('receiving_id', $purchase)->get()->getRow()->supplier_id);
    }

    private function switchOn(DocumentType $type, string $prefix, int $padding = 3): void
    {
        (new DocumentNumbering())->configure($type, ['enabled' => true, 'prefix' => $prefix, 'padding' => $padding]);
    }

    private function numberOf(DocumentType $type, string $table, int $id): ?string
    {
        return (new DocumentNumbering())->numbers($type, $table, [$id])[$id] ?? null;
    }

    public function testSaveValue_GivesNoNumberWhileTheSequencesAreOff(): void
    {
        $this->startBuying();
        $purchase = $this->purchaseOf('100.00');

        $this->debitNoteOf($purchase, '10.00');
        $this->payPurchase($purchase, '20.00');

        $this->assertSame(0, $this->countRows('document_numbers'));
        $this->assertSame(0, $this->countRows('document_sequences'));
    }

    public function testSaveValue_NumbersAPurchaseAndAReturnToTheSupplierEachInItsOwnSequence(): void
    {
        $this->startBuying();
        $this->switchOn(DocumentType::Purchase, 'PUR-');
        $this->switchOn(DocumentType::DebitNote, 'DN-');

        $first  = $this->purchaseOf('100.00');
        $note   = $this->debitNoteOf($first, '10.00');
        $second = $this->purchaseOf('50.00');

        $this->assertSame('PUR-001', $this->numberOf(DocumentType::Purchase, 'receivings', $first));
        $this->assertSame('PUR-002', $this->numberOf(DocumentType::Purchase, 'receivings', $second));
        $this->assertSame('DN-001', $this->numberOf(DocumentType::DebitNote, 'receivings', $note));
        $this->assertNull($this->numberOf(DocumentType::Purchase, 'receivings', $note));
        $this->assertNull($this->numberOf(DocumentType::DebitNote, 'receivings', $first));
    }

    public function testSaveValue_NumbersAPurchaseWhileAccountingIsOffToo(): void
    {
        $this->startBuying();
        $this->setConfigValue('accounting_enabled', '0');
        $this->switchOn(DocumentType::Purchase, 'PUR-');

        $purchase = $this->purchaseOf('100.00');

        $this->assertSame('PUR-001', $this->numberOf(DocumentType::Purchase, 'receivings', $purchase));
    }

    public function testSaveValue_DoesNotNumberATransferBetweenLocations(): void
    {
        $this->startBuying();
        $this->switchOn(DocumentType::Purchase, 'PUR-');
        $this->switchOn(DocumentType::DebitNote, 'DN-');

        $item  = $this->item('0.00');
        $depot = $this->anotherLocation('Depot');
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '5', '10')]));

        $before   = $this->countRows('document_numbers');
        $transfer = $this->buy([$this->purchaseLine($item, 0, '-2', '10'), $this->purchaseLine($item, 1, '2', '10', locationId: $depot)]);

        $this->assertGreaterThan(0, $transfer);
        $this->assertSame($before, $this->countRows('document_numbers'));
        $this->assertNull($this->numberOf(DocumentType::Purchase, 'receivings', $transfer));
        $this->assertNull($this->numberOf(DocumentType::DebitNote, 'receivings', $transfer));
    }

    public function testSaveValue_LeavesNoNumberBehindWhenTheReturnIsRefused(): void
    {
        $this->startBuying();
        $this->switchOn(DocumentType::DebitNote, 'DN-');

        $purchase = $this->purchaseOf('100.00');
        $line     = $this->accountingDb()->table('receivings_items')->where('receiving_id', $purchase)->get()->getRow();
        $result   = $this->buy([$this->purchaseLine((int) $line->item_id, 0, '-2', (string) $line->item_unit_price)], -1, null, $purchase);

        $this->assertSame(ACCOUNTING_FAILED, $result);
        $this->assertSame(0, $this->countRows('document_numbers'));
        $this->assertSame(1, (new DocumentNumbering())->sequence(DocumentType::DebitNote)->nextNumber);
    }

    public function testSaveValue_LeavesNoNumberBehindWhenTheBooksRefuseAPurchaseThatWasNumbered(): void
    {
        $this->startBuying();
        $this->switchOn(DocumentType::Purchase, 'PUR-');
        $this->purchaseOf('100.00');
        $this->closeThrough(date('Y-m-d'));
        $numbers = $this->countRows('document_numbers');

        $result = $this->buy([$this->purchaseLine($this->item('0.00'), 0, '1', '50')]);

        $this->assertSame(ACCOUNTING_FAILED, $result);
        $this->assertNotNull($this->purchases->accountingFailure());
        $this->assertSame($numbers, $this->countRows('document_numbers'));
        $this->assertSame(2, (new DocumentNumbering())->sequence(DocumentType::Purchase)->nextNumber);

        $this->accountingDb()->table('accounting_closed_periods')->truncate();

        $second = $this->purchaseOf('50.00');

        $this->assertSame('PUR-002', $this->numberOf(DocumentType::Purchase, 'receivings', $second));
    }

    public function testSaveValue_RefusesThePurchaseWhenNoNumberCanBeGiven(): void
    {
        $this->startBuying();
        $this->switchOn(DocumentType::Purchase, 'PUR-', 0);
        $db = $this->accountingDb();
        $db->table('document_sequences')->where('doc_type', 'purchase')->update(['next_number' => 4294967294]);
        $db->table('document_numbers')->insert(['company_id' => 1, 'doc_type' => 'purchase', 'source_table' => 'receivings', 'source_id' => 999999999, 'sequence_number' => 4294967294, 'number' => 'PUR-4294967294']);

        $before = $this->countRows('receivings');
        $item   = $this->item('0.00');
        $result = $this->buy([$this->purchaseLine($item, 0, '1', '10')]);

        $this->assertSame(ACCOUNTING_FAILED, $result);
        $this->assertSame($before, $this->countRows('receivings'));
        $this->assertSame(AccountingError::NumberingExhausted, $this->purchases->accountingFailure()?->error);
    }

    public function testUpdate_NumbersEachNewSupplierPaymentAndKeepsTheNumberWhenItIsChanged(): void
    {
        $this->startBuying();
        $this->switchOn(DocumentType::SupplierPayment, 'SP-');

        $purchase = $this->purchaseOf('100.00');
        $first    = $this->payPurchase($purchase, '20.00');
        $second   = $this->payPurchase($purchase, '30.00');

        $this->assertSame('SP-001', $this->numberOf(DocumentType::SupplierPayment, 'receivings_payments', $first));
        $this->assertSame('SP-002', $this->numberOf(DocumentType::SupplierPayment, 'receivings_payments', $second));

        $this->assertTrue($this->changePayment($purchase, $first, ['payment_amount' => '25.00']));
        $this->assertSame('SP-001', $this->numberOf(DocumentType::SupplierPayment, 'receivings_payments', $first));
        $this->assertSame(3, (new DocumentNumbering())->sequence(DocumentType::SupplierPayment)->nextNumber);
    }

    public function testUpdate_DoesNotGiveTheNumberOfARemovedPaymentToAnotherOne(): void
    {
        $this->startBuying();
        $this->switchOn(DocumentType::SupplierPayment, 'SP-');

        $purchase = $this->purchaseOf('100.00');
        $first    = $this->payPurchase($purchase, '20.00');

        $this->assertTrue($this->changePayment($purchase, $first, ['payment_amount' => '0']));

        $second = $this->payPurchase($purchase, '30.00');

        $this->assertSame('SP-002', $this->numberOf(DocumentType::SupplierPayment, 'receivings_payments', $second));
    }

    public function testUpdate_GivesNoNumberToPaymentsWhileTheSequenceIsOff(): void
    {
        $this->startBuying();
        $purchase = $this->purchaseOf('100.00');

        $this->payPurchase($purchase, '20.00');

        $this->assertSame(0, $this->countRows('document_numbers'));
    }

    public function testSaveValue_NumbersAPurchaseThatTakesADiscountOnTheWholePurchaseAndPostsItDiscounted(): void
    {
        $this->startBuying();
        $this->switchOn(DocumentType::Purchase, 'PUR-');
        $item = $this->item('0.00');

        $purchase = $this->buy([$this->purchaseLine($item, 0, '10', '100', '15')], -1, null, null, InvoiceDiscount::of(PERCENT, '10'));

        $this->assertGreaterThan(0, $purchase);
        $this->assertSame('PUR-001', $this->numberOf(DocumentType::Purchase, 'receivings', $purchase));

        $header = $this->accountingDb()->table('receivings')->where('receiving_id', $purchase)->get()->getRow();
        $line   = $this->accountingDb()->table('receivings_items')->where('receiving_id', $purchase)->get()->getRow();

        $this->assertSame([PERCENT, '10.0000'], [(int) $header->invoice_discount_type, bcadd((string) $header->invoice_discount, '0', 4)]);
        $this->assertSame('100.0000', bcadd((string) $line->invoice_discount_share, '0', 4));
        $this->assertSame('1035.00', $this->owed());
        $this->assertSame('135.00', $this->balance($this->accountId('tax_input')));
        $this->assertSame(['quantity' => '10.000', 'value' => '900.00'], $this->stockOf($item));
        $this->assertCount(1, $this->standingPurchase($purchase));
        $this->assertStockTies();
        $this->assertSupplierTies();
    }

    public function testSaveValue_NumbersAPurchaseAndKeepsItsDiscountWhileAccountingIsOff(): void
    {
        $this->startBuying();
        $this->setConfigValue('accounting_enabled', '0');
        $this->switchOn(DocumentType::Purchase, 'PUR-');
        $item = $this->item('0.00');

        $purchase = $this->buy([$this->purchaseLine($item, 0, '10', '100')], -1, null, null, InvoiceDiscount::of(FIXED, '100'));

        $this->assertGreaterThan(0, $purchase);
        $this->assertSame('PUR-001', $this->numberOf(DocumentType::Purchase, 'receivings', $purchase));
        $this->assertSame('100.0000', bcadd((string) $this->accountingDb()->table('receivings_items')->where('receiving_id', $purchase)->get()->getRow()->invoice_discount_share, '0', 4));
        $this->assertSame(0, $this->countRows('accounting_journals'));
    }

    public function testSaveValue_ConsumesNoNumberWhenTheDiscountOnTheWholePurchaseIsRefused(): void
    {
        $this->startBuying();
        $this->switchOn(DocumentType::Purchase, 'PUR-');
        $item  = $this->item('0.00');
        $first = $this->buy([$this->purchaseLine($item, 0, '2', '10')]);

        $receivings = $this->countRows('receivings');
        $lines      = $this->countRows('receivings_items');
        $numbers    = $this->countRows('document_numbers');

        $result = $this->buy([$this->purchaseLine($item, 0, '10', '100')], -1, null, null, InvoiceDiscount::of(FIXED, '1000.01'));

        $failure = $this->purchases->accountingFailure();

        $this->assertSame(ACCOUNTING_FAILED, $result);
        $this->assertInstanceOf(PostingException::class, $failure);
        $this->assertSame(PostingError::PurchaseDiscountInvalid, $failure->error);
        $this->assertSame([$receivings, $lines, $numbers], [$this->countRows('receivings'), $this->countRows('receivings_items'), $this->countRows('document_numbers')]);
        $this->assertSame('2.000', $this->onHand($item));
        $this->assertSame(2, (new DocumentNumbering())->sequence(DocumentType::Purchase)->nextNumber);
        $this->assertSame('PUR-001', $this->numberOf(DocumentType::Purchase, 'receivings', $first));

        $second = $this->buy([$this->purchaseLine($item, 0, '1', '10')]);

        $this->assertSame('PUR-002', $this->numberOf(DocumentType::Purchase, 'receivings', $second));
    }

    public function testSaveValue_StoresNoDiscountWhenNoNumberCanBeGivenToThePurchase(): void
    {
        $this->startBuying();
        $this->switchOn(DocumentType::Purchase, 'PUR-', 0);
        $db = $this->accountingDb();
        $db->table('document_sequences')->where('doc_type', 'purchase')->update(['next_number' => 4294967294]);
        $db->table('document_numbers')->insert(['company_id' => 1, 'doc_type' => 'purchase', 'source_table' => 'receivings', 'source_id' => 999999999, 'sequence_number' => 4294967294, 'number' => 'PUR-4294967294']);

        $receivings = $this->countRows('receivings');
        $lines      = $this->countRows('receivings_items');
        $item       = $this->item('0.00');

        $result = $this->buy([$this->purchaseLine($item, 0, '10', '100')], -1, null, null, InvoiceDiscount::of(PERCENT, '10'));

        $this->assertSame(ACCOUNTING_FAILED, $result);
        $this->assertSame(AccountingError::NumberingExhausted, $this->purchases->accountingFailure()?->error);
        $this->assertSame([$receivings, $lines], [$this->countRows('receivings'), $this->countRows('receivings_items')]);
        $this->assertSame(0, $db->table('receivings_items')->where('invoice_discount_share IS NOT NULL')->countAllResults());
        $this->assertSame('0.000', $this->onHand($item));
    }

    public function testSaveValue_NumbersAReturnOfADiscountedAndNumberedPurchaseAndTakesItsShare(): void
    {
        $this->startBuying();
        $this->switchOn(DocumentType::Purchase, 'PUR-');
        $this->switchOn(DocumentType::DebitNote, 'DN-');
        $item     = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($item, 0, '10', '100', '15')], -1, null, null, InvoiceDiscount::of(FIXED, '100'));

        $return = $this->buy([$this->purchaseLine($item, 0, '-4', '100', '15')], -1, null, $purchase);

        $this->assertGreaterThan(0, $return);
        $this->assertSame('PUR-001', $this->numberOf(DocumentType::Purchase, 'receivings', $purchase));
        $this->assertSame('DN-001', $this->numberOf(DocumentType::DebitNote, 'receivings', $return));
        $this->assertNull($this->numberOf(DocumentType::Purchase, 'receivings', $return));
        $this->assertNull($this->numberOf(DocumentType::DebitNote, 'receivings', $purchase));

        $header = $this->accountingDb()->table('receivings')->where('receiving_id', $return)->get()->getRow();
        $line   = $this->accountingDb()->table('receivings_items')->where('receiving_id', $return)->get()->getRow();

        $this->assertNull($header->invoice_discount_type);
        $this->assertSame('40.0000', bcadd((string) $line->invoice_discount_share, '0', 4));
        $this->assertSame('621.00', $this->owed());
        $this->assertSame(['quantity' => '6.000', 'value' => '540.00'], $this->stockOf($item));
        $this->assertStockTies();
        $this->assertSupplierTies();
    }

    public function testSaveValue_NumbersTheReturnThatTakesWhatIsLeftOfTheDiscountOfANumberedPurchase(): void
    {
        $this->startBuying();
        $this->switchOn(DocumentType::DebitNote, 'DN-');
        $item     = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($item, 0, '10', '100', '15')], -1, null, null, InvoiceDiscount::of(FIXED, '100'));

        $first  = $this->buy([$this->purchaseLine($item, 0, '-4', '100', '15')], -1, null, $purchase);
        $second = $this->buy([$this->purchaseLine($item, 0, '-6', '100', '15')], -1, null, $purchase);

        $this->assertSame('DN-001', $this->numberOf(DocumentType::DebitNote, 'receivings', $first));
        $this->assertSame('DN-002', $this->numberOf(DocumentType::DebitNote, 'receivings', $second));
        $this->assertSame('60.0000', bcadd((string) $this->accountingDb()->table('receivings_items')->where('receiving_id', $second)->get()->getRow()->invoice_discount_share, '0', 4));
        $this->assertSame('0.00', $this->owed());
        $this->assertSupplierTies();
    }

    /**
     * @return list<object>
     */
    private function paymentJournals(int $paymentId): array
    {
        return $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'supplier_payment', 'source_id' => $paymentId])->orderBy('revision')->get()->getResult();
    }

    private function lastPaymentOf(int $receivingId): object
    {
        return $this->accountingDb()->table('receivings_payments')->where('receiving_id', $receivingId)->orderBy('payment_id', 'DESC')->get()->getRow();
    }

    /**
     * @param array<string, mixed> $more
     */
    private function payPurchaseFrom(int $receivingId, string $amount, int $treasury, array $more = []): bool
    {
        return $this->purchases->update($receivingId, ['comment' => 'paid', 'payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.cash'), 'payment_amount' => $amount, 'employee_id' => $this->employeeId, 'payment_status' => 'completed', 'reference_code' => '', 'treasury_id' => $treasury] + $more,
        ]]);
    }

    /**
     * A treasury of the buyer's branch that only the employees given may withdraw from.
     *
     * @param list<int> $employees
     */
    private function drawerOpenOnlyTo(array $employees, string $direction = 'withdraw'): int
    {
        $this->accountingDb()->table('grants')->insert(['person_id' => $this->employeeId, 'permission_id' => BranchGuard::ALL_BRANCHES, 'menu_group' => 'home']);

        return (new TreasuryService())->create(['name' => 'Scoped drawer', 'kind' => 'cash', $direction . '_scope' => 'staff', $direction . '_members' => $employees]);
    }

    /**
     * A second branch with a treasury of its own, and a buyer who works in both branches.
     *
     * @return array{branch: int, treasury: int, account: int}
     */
    private function treasuryOfAnotherBranch(): array
    {
        $branch   = $this->createBranch('B2');
        $treasury = (new TreasuryService())->create(['name' => 'Branch drawer', 'kind' => 'cash', 'branch_id' => $branch]);

        $this->workInBranches(1, $branch);

        return ['branch' => $branch, 'treasury' => $treasury, 'account' => (int) (new TreasuryService())->accountOf($treasury)];
    }

    private function assertEveryBranchBalancedAndTheAccountsBetweenBranchesOffset(): void
    {
        foreach ($this->accountingDb()->table('branches')->get()->getResult() as $branch) {
            $sums = $this->accountingDb()->table('accounting_journal_lines l')->select('SUM(l.debit) AS debit, SUM(l.credit) AS credit', false)->join('accounting_journals j', 'j.journal_id = l.journal_id')->where('j.branch_id', $branch->branch_id)->get()->getRow();

            $this->assertSame(bcadd((string) $sums->debit, '0', 4), bcadd((string) $sums->credit, '0', 4), 'The trial balance of branch ' . $branch->code . ' balances');
        }

        $this->assertSame('0.0000', (new InterBranchPosition())->difference());
    }



    private function daysAgo(int $days): string
    {
        return date('Y-m-d', strtotime("-{$days} days"));
    }

    /**
     * Adds a payment the way the edit form does, with the date it names when it names one.
     *
     * @param array<string, mixed> $more
     */
    private function payOn(int $receivingId, string $amount, ?string $date, array $more = []): bool
    {
        return $this->purchases->update($receivingId, ['comment' => 'dated', 'payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.cash'), 'payment_amount' => $amount, 'employee_id' => $this->employeeId, 'payment_status' => 'completed', 'reference_code' => ''] + ($date === null ? [] : ['payment_date' => $date]) + $more,
        ]]);
    }

    /**
     * Makes a payment look as one saved before dates could be entered: no date, and the time it was
     * saved on the day it was posted.
     */
    private function makeLegacy(int $paymentId, string $day): void
    {
        $this->accountingDb()->table('receivings_payments')->where('payment_id', $paymentId)->update(['payment_date' => null, 'payment_time' => $day . ' 09:30:00']);
    }

    public function testUpdate_PostsAPaymentOnTheDateEnteredAndKeepsTheTimeItWasSaved(): void
    {
        $this->startBuying();
        $purchase = $this->purchaseOf('100.00');
        $date     = $this->daysAgo(5);

        $this->assertTrue($this->payOn($purchase, '40.00', $date));

        $payment  = $this->lastPaymentOf($purchase);
        $journals = $this->paymentJournals((int) $payment->payment_id);

        $this->assertCount(1, $journals);
        $this->assertSame($date, $journals[0]->journal_date);
        $this->assertSame($date, $payment->payment_date);
        $this->assertSame(date('Y-m-d'), substr((string) $payment->payment_time, 0, 10), 'The time it was saved is kept as it is');
        $this->assertSame('60.00', $this->owed());
        $this->assertSupplierTies();
    }

    public function testUpdate_DatesAPaymentTodayWhenNoDateIsGiven(): void
    {
        $this->startBuying();
        $purchase = $this->purchaseOf('100.00');

        $this->assertTrue($this->payOn($purchase, '10.00', null));
        $this->assertTrue($this->payOn($purchase, '20.00', ''));

        foreach ($this->accountingDb()->table('receivings_payments')->where('receiving_id', $purchase)->get()->getResult() as $payment) {
            $this->assertSame(date('Y-m-d'), $payment->payment_date);
            $this->assertSame(date('Y-m-d'), $this->paymentJournals((int) $payment->payment_id)[0]->journal_date);
        }
    }

    public function testUpdate_AcceptsAPaymentDatedToday(): void
    {
        $this->startBuying();
        $purchase = $this->purchaseOf('100.00');

        $this->assertTrue($this->payOn($purchase, '10.00', date('Y-m-d')));
        $this->assertSame(1, $this->countRows('receivings_payments'));
    }

    public function testUpdate_RefusesAPaymentDatedAfterTodayAndSavesNothing(): void
    {
        $this->startBuying();
        $purchase = $this->purchaseOf('100.00');
        $journals = $this->countRows('accounting_journals');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        $this->assertFalse($this->payOn($purchase, '40.00', $tomorrow));

        $failure = $this->purchases->accountingFailure();

        $this->assertInstanceOf(AccountingException::class, $failure);
        $this->assertSame(AccountingError::PaymentDateInFuture, $failure->error);
        $this->assertStringContainsString($tomorrow, AccountingMessage::of($failure));
        $this->assertSame(0, $this->countRows('receivings_payments'));
        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertSame('test', $this->accountingDb()->table('receivings')->where('receiving_id', $purchase)->get()->getRow()->comment, 'Nothing of the same save stayed');
        $this->assertSame('100.00', $this->owed());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notADateProvider(): array
    {
        return ['a day that does not exist' => ['2026-02-30'], 'a month that does not exist' => ['2026-13-01'], 'words' => ['tomorrow'], 'another order' => ['28/10/2026'], 'with a time' => ['2026-10-20 10:00:00']];
    }

    #[DataProvider('notADateProvider')]
    public function testUpdate_RefusesAPaymentDateThatIsNotADayAndSavesNothing(string $date): void
    {
        $this->startBuying();
        $purchase = $this->purchaseOf('100.00');

        $this->assertFalse($this->payOn($purchase, '40.00', $date));
        $this->assertSame(AccountingError::InvalidDate, $this->purchases->accountingFailure()->error);
        $this->assertSame(0, $this->countRows('receivings_payments'));
    }

    public function testUpdate_RefusesAPaymentDateThatIsNotText(): void
    {
        $this->startBuying();
        $purchase = $this->purchaseOf('100.00');

        $this->assertFalse($this->purchases->update($purchase, ['payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '40.00', 'employee_id' => $this->employeeId, 'payment_status' => 'completed', 'reference_code' => '', 'payment_date' => ['2026-10-20']],
        ]]));
        $this->assertSame(AccountingError::InvalidDate, $this->purchases->accountingFailure()->error);
        $this->assertSame(0, $this->countRows('receivings_payments'));
    }

    public function testUpdate_RefusesAPaymentDatedInAClosedPeriodAndSavesNothing(): void
    {
        $this->startBuying();
        $purchase = $this->purchaseOf('100.00');
        $journals = $this->countRows('accounting_journals');
        $through  = $this->daysAgo(10);

        $this->closeThrough($through);

        $this->assertFalse($this->payOn($purchase, '40.00', $this->daysAgo(12)));
        $this->assertSame(PostingError::PeriodClosed, $this->purchases->accountingFailure()->error);
        $this->assertStringContainsString($through, AccountingMessage::of($this->purchases->accountingFailure()));
        $this->assertSame(0, $this->countRows('receivings_payments'));
        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertSame(1, $this->accountingDb()->table('accounting_posting_failures')->where('source_type', 'purchase')->where('source_id', $purchase)->where('error_code', 'period_closed')->countAllResults());

        $this->assertFalse($this->payOn($purchase, '40.00', $through), 'The last day of a closed period is closed');
        $this->assertTrue($this->payOn($purchase, '40.00', $this->daysAgo(9)));
        $this->assertSame($this->daysAgo(9), $this->paymentJournals((int) $this->lastPaymentOf($purchase)->payment_id)[0]->journal_date);
    }

    public function testUpdate_AsksOnlyTheClosedPeriodOfTheBranchOfThePurchase(): void
    {
        $this->startBuying();
        $branch   = $this->createBranch('B2');
        $location = $this->locationOfBranch($branch);
        $item     = $this->item('0.00');
        $other    = $this->buy([$this->purchaseLine($item, 0, '1', '100.00', '0', '0', PERCENT, $location)]);
        $own      = $this->purchaseOf('100.00');

        $this->closeThrough($this->daysAgo(10), $branch);

        $this->assertFalse($this->payOn($other, '40.00', $this->daysAgo(12)));
        $this->assertSame(PostingError::PeriodClosed, $this->purchases->accountingFailure()->error);
        $this->assertSame(0, $this->accountingDb()->table('receivings_payments')->where('receiving_id', $other)->countAllResults());

        $this->assertTrue($this->payOn($own, '40.00', $this->daysAgo(12)), 'The first branch is not closed');
        $this->assertTrue($this->payOn($other, '40.00', $this->daysAgo(9)));
    }

    public function testUpdate_RefusesAPaymentDatedBeforeTheDayTheBooksStart(): void
    {
        $this->startBuying();
        $purchase = $this->purchaseOf('100.00');
        $start    = $this->daysAgo(30);

        $this->setConfigValue('accounting_start_date', $start);

        $this->assertFalse($this->payOn($purchase, '40.00', $this->daysAgo(31)));
        $this->assertSame(AccountingError::PaymentDateBeforeBooks, $this->purchases->accountingFailure()->error);
        $this->assertStringContainsString($start, AccountingMessage::of($this->purchases->accountingFailure()));
        $this->assertSame(0, $this->countRows('receivings_payments'));

        $this->assertTrue($this->payOn($purchase, '40.00', $start), 'The day the books start is the first day a payment can have');
    }

    public function testUpdate_ChangesTheDateOfAPaymentByReversingItAndPostingItAgain(): void
    {
        $this->startBuying();
        $purchase = $this->purchaseOf('100.00');
        $payment  = $this->payPurchase($purchase, '40.00');
        $date     = $this->daysAgo(7);
        $saved    = (string) $this->lastPaymentOf($purchase)->payment_time;

        $this->assertTrue($this->changePayment($purchase, $payment, ['payment_date' => $date]));

        $journals = $this->paymentJournals($payment);

        $this->assertSame(['reversed', 'posted'], array_map(static fn (object $journal): string => $journal->status, $journals));
        $this->assertSame([date('Y-m-d'), $date], array_map(static fn (object $journal): string => $journal->journal_date, $journals));
        $this->assertSame([1, 2], array_map(static fn (object $journal): int => (int) $journal->revision, $journals));
        $this->assertSame($date, $this->lastPaymentOf($purchase)->payment_date);
        $this->assertSame($saved, (string) $this->lastPaymentOf($purchase)->payment_time, 'The time it was saved does not move with its date');
        $this->assertSame('60.00', $this->owed());
        $this->assertSupplierTies();
    }

    public function testUpdate_LeavesTheJournalOfAPaymentAloneWhenItsDateIsSentAgainUnchanged(): void
    {
        $this->startBuying();
        $purchase = $this->purchaseOf('100.00');

        $this->assertTrue($this->payOn($purchase, '40.00', $this->daysAgo(7)));

        $payment = (int) $this->lastPaymentOf($purchase)->payment_id;
        $journal = $this->paymentJournals($payment)[0]->journal_id;

        $this->assertTrue($this->changePayment($purchase, $payment, ['payment_date' => $this->daysAgo(7)]));

        $journals = $this->paymentJournals($payment);

        $this->assertCount(1, $journals);
        $this->assertSame((string) $journal, (string) $journals[0]->journal_id);
    }

    public function testUpdate_KeepsAPaymentSavedWithoutADateOnTheDayItWasSavedAndPostsItAgainOnlyWhenGivenAnother(): void
    {
        $this->startBuying();
        $purchase = $this->purchaseOf('100.00');
        $day      = $this->daysAgo(6);

        $this->assertTrue($this->payOn($purchase, '40.00', $day));

        $payment = (int) $this->lastPaymentOf($purchase)->payment_id;

        $this->makeLegacy($payment, $day);

        $this->assertTrue($this->changePayment($purchase, $payment, ['payment_date' => $day]), 'The form sends back the day it shows');
        $this->assertNull($this->lastPaymentOf($purchase)->payment_date, 'Saving the form as it was does not give it a date');
        $this->assertCount(1, $this->paymentJournals($payment));
        $this->assertSame($day, $this->paymentJournals($payment)[0]->journal_date);

        $this->assertTrue($this->changePayment($purchase, $payment, ['payment_amount' => '45.00']));
        $this->assertNull($this->lastPaymentOf($purchase)->payment_date, 'Changing something else does not give it a date either');

        $this->assertTrue($this->changePayment($purchase, $payment, ['payment_date' => $this->daysAgo(2)]));
        $this->assertSame($this->daysAgo(2), $this->lastPaymentOf($purchase)->payment_date);
        $this->assertSame('55.00', $this->owed());
        $this->assertSupplierTies();
    }

    public function testUpdate_KeepsALegacyPaymentOnTheDayItWasSavedWhenTheFormSendsNoDate(): void
    {
        $this->startBuying();
        $purchase = $this->purchaseOf('100.00');
        $day      = $this->daysAgo(6);

        $this->assertTrue($this->payOn($purchase, '40.00', $day));

        $payment = (int) $this->lastPaymentOf($purchase)->payment_id;

        $this->makeLegacy($payment, $day);

        $this->assertTrue($this->changePayment($purchase, $payment, ['comment' => 'again']));
        $this->assertNull($this->lastPaymentOf($purchase)->payment_date);
        $this->assertSame([$day], array_map(static fn (object $journal): string => $journal->journal_date, $this->paymentJournals($payment)));
    }

    public function testUpdate_RefusesToMoveTheDateOfAPaymentIntoAClosedPeriodAndLeavesItWhereItWas(): void
    {
        $this->startBuying();
        $purchase = $this->purchaseOf('100.00');
        $day      = $this->daysAgo(3);

        $this->assertTrue($this->payOn($purchase, '40.00', $day));

        $payment = (int) $this->lastPaymentOf($purchase)->payment_id;
        $journal = $this->paymentJournals($payment)[0]->journal_id;

        $this->closeThrough($this->daysAgo(10));

        $this->assertFalse($this->changePayment($purchase, $payment, ['payment_date' => $this->daysAgo(12)]));
        $this->assertSame(PostingError::PeriodClosed, $this->purchases->accountingFailure()->error);
        $this->assertSame($day, $this->lastPaymentOf($purchase)->payment_date);

        $journals = $this->paymentJournals($payment);

        $this->assertCount(1, $journals);
        $this->assertSame((string) $journal, (string) $journals[0]->journal_id);
        $this->assertSame('posted', $journals[0]->status);
        $this->assertSame('60.00', $this->owed());
    }

    public function testUpdate_RefusesToMoveAPaymentOutOfAClosedPeriodToAnOpenDayAndLeavesItWhereItWas(): void
    {
        $this->startBuying();
        $purchase = $this->purchaseOf('100.00');
        $day      = $this->daysAgo(12);

        $this->assertTrue($this->payOn($purchase, '40.00', $day));

        $payment  = (int) $this->lastPaymentOf($purchase)->payment_id;
        $journal  = $this->paymentJournals($payment)[0]->journal_id;
        $journals = $this->countRows('accounting_journals');
        $through  = $this->daysAgo(10);

        $this->closeThrough($through);

        $this->assertFalse($this->changePayment($purchase, $payment, ['payment_date' => $this->daysAgo(5)]));
        $this->assertSame(PostingError::PeriodClosed, $this->purchases->accountingFailure()->error);
        $this->assertStringContainsString($through, AccountingMessage::of($this->purchases->accountingFailure()));
        $this->assertSame($day, $this->lastPaymentOf($purchase)->payment_date);
        $this->assertSame($journals, $this->countRows('accounting_journals'), 'No reversal and no new journal was written');
        $this->assertSame([(string) $journal], array_map(static fn (object $row): string => (string) $row->journal_id, $this->paymentJournals($payment)));
        $this->assertSame('60.00', $this->owed());
        $this->assertSupplierTies();
    }

    public function testUpdate_RefusesToMoveAPaymentPostedOnTheLastDayOfAClosedPeriod(): void
    {
        $this->startBuying();
        $purchase = $this->purchaseOf('100.00');
        $through  = $this->daysAgo(10);

        $this->assertTrue($this->payOn($purchase, '40.00', $through));

        $payment = (int) $this->lastPaymentOf($purchase)->payment_id;

        $this->closeThrough($through);

        $this->assertFalse($this->changePayment($purchase, $payment, ['payment_date' => $this->daysAgo(9)]));
        $this->assertSame(PostingError::PeriodClosed, $this->purchases->accountingFailure()->error);
        $this->assertSame($through, $this->lastPaymentOf($purchase)->payment_date);
    }

    public function testUpdate_MovesAPaymentOfAnOpenPeriodWhileAnEarlierPeriodIsClosed(): void
    {
        $this->startBuying();
        $purchase = $this->purchaseOf('100.00');

        $this->assertTrue($this->payOn($purchase, '40.00', $this->daysAgo(8)));

        $payment = (int) $this->lastPaymentOf($purchase)->payment_id;

        $this->closeThrough($this->daysAgo(10));

        $this->assertTrue($this->changePayment($purchase, $payment, ['payment_date' => $this->daysAgo(3)]));
        $this->assertSame([$this->daysAgo(8), $this->daysAgo(3)], array_map(static fn (object $journal): string => $journal->journal_date, $this->paymentJournals($payment)), 'It is taken back on its own day and posted again on the new one');
        $this->assertSame('60.00', $this->owed());
        $this->assertSupplierTies();
    }

    public function testUpdate_LeavesAPaymentOfAClosedPeriodAloneWhenNothingOfItChanges(): void
    {
        $this->startBuying();
        $purchase = $this->purchaseOf('100.00');

        $this->assertTrue($this->payOn($purchase, '40.00', $this->daysAgo(12)));

        $payment  = (int) $this->lastPaymentOf($purchase)->payment_id;
        $journals = $this->countRows('accounting_journals');

        $this->closeThrough($this->daysAgo(10));

        $this->assertTrue($this->changePayment($purchase, $payment, ['payment_date' => $this->daysAgo(12), 'reference_code' => 'same']), 'The form sends the day it shows');
        $this->assertTrue($this->changePayment($purchase, $payment, []));
        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertCount(1, $this->paymentJournals($payment));
    }

    public function testUpdate_RefusesToMoveAPaymentWhoseJournalInTheBranchOfItsTreasuryIsInAClosedPeriod(): void
    {
        $this->startBuying();
        ['branch' => $branch, 'treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $receivingId = $this->purchaseOf('300.00');

        $this->assertTrue($this->payPurchaseFrom($receivingId, '100.00', $treasury, ['payment_date' => $this->daysAgo(12)]));

        $payment  = (int) $this->lastPaymentOf($receivingId)->payment_id;
        $journals = $this->countRows('accounting_journals');

        $this->closeThrough($this->daysAgo(10), $branch);

        $this->assertFalse($this->changePayment($receivingId, $payment, ['payment_date' => $this->daysAgo(5)]));
        $this->assertSame(PostingError::PeriodClosed, $this->purchases->accountingFailure()->error);
        $this->assertSame($this->daysAgo(12), $this->lastPaymentOf($receivingId)->payment_date);
        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertEveryBranchBalancedAndTheAccountsBetweenBranchesOffset();
    }

    public function testUpdate_AcceptsAPaymentOnAPurchaseSavedBeforeTheBooksStartWhateverItsDate(): void
    {
        $this->startBuying();

        $this->setConfigValue('accounting_start_date', date('Y-m-d', strtotime('+20 days')));

        $purchase = $this->buy([$this->purchaseLine($this->item('0.00'), 0, '10', '50')]);

        $this->assertGreaterThan(0, $purchase);
        $this->assertSame(0, $this->countRows('accounting_journals'));

        $this->assertTrue($this->payOn($purchase, '40.00', null), 'Today, the default of the form, comes before the start');
        $this->assertTrue($this->payOn($purchase, '10.00', $this->daysAgo(40)));

        $payment = (int) $this->lastPaymentOf($purchase)->payment_id;

        $this->assertTrue($this->changePayment($purchase, $payment, ['payment_date' => $this->daysAgo(100)]));
        $this->assertSame($this->daysAgo(100), $this->lastPaymentOf($purchase)->payment_date);
        $this->assertSame(0, $this->countRows('accounting_journals'), 'Nothing of a purchase the books do not hold is posted');
    }

    public function testUpdate_StoresAPaymentWithoutPostingItWhenTheBooksNowStartAfterThePurchase(): void
    {
        $this->startBuying();
        $purchase = $this->purchaseOf('100.00');
        $journals = $this->countRows('accounting_journals');

        $this->setConfigValue('accounting_start_date', date('Y-m-d', strtotime('+20 days')));

        $this->assertTrue($this->payOn($purchase, '40.00', $this->daysAgo(50)));
        $this->assertSame($this->daysAgo(50), $this->lastPaymentOf($purchase)->payment_date);
        $this->assertSame($journals, $this->countRows('accounting_journals'), 'A purchase dated before the books start is not followed, and neither are its payments');
    }

    public function testUpdate_AcceptsADateBeforeTheBooksStartOnAPurchaseSavedWhileAccountingWasOff(): void
    {
        $this->startBuying();
        $this->setConfigValue('accounting_enabled', '0');

        $purchase = $this->buy([$this->purchaseLine($this->item('0.00'), 0, '10', '50')]);

        $this->assertGreaterThan(0, $purchase);

        $this->setConfigValue('accounting_enabled', '1');
        $this->setConfigValue('accounting_start_date', $this->daysAgo(30));

        $this->assertTrue($this->payOn($purchase, '40.00', $this->daysAgo(60)));
        $this->assertSame($this->daysAgo(60), $this->lastPaymentOf($purchase)->payment_date);
        $this->assertSame(0, $this->countRows('accounting_journals'));
    }

    public function testUpdate_RefusesToMoveTheDateOfAPaymentAfterTodayAndLeavesItWhereItWas(): void
    {
        $this->startBuying();
        $purchase = $this->purchaseOf('100.00');
        $payment  = $this->payPurchase($purchase, '40.00');

        $this->assertFalse($this->changePayment($purchase, $payment, ['payment_date' => date('Y-m-d', strtotime('+2 days'))]));
        $this->assertSame(AccountingError::PaymentDateInFuture, $this->purchases->accountingFailure()->error);
        $this->assertSame(date('Y-m-d'), $this->lastPaymentOf($purchase)->payment_date);
        $this->assertCount(1, $this->paymentJournals($payment));
    }

    public function testUpdate_RefusesToMoveTheDateOfAPaymentBeforeTheDayTheBooksStart(): void
    {
        $this->startBuying();
        $purchase = $this->purchaseOf('100.00');
        $payment  = $this->payPurchase($purchase, '40.00');

        $this->setConfigValue('accounting_start_date', $this->daysAgo(30));

        $this->assertFalse($this->changePayment($purchase, $payment, ['payment_date' => $this->daysAgo(31)]));
        $this->assertSame(AccountingError::PaymentDateBeforeBooks, $this->purchases->accountingFailure()->error);
        $this->assertSame(date('Y-m-d'), $this->lastPaymentOf($purchase)->payment_date);
    }

    public function testUpdate_AsksNothingOfTheScopeOfTheTreasuryWhenOnlyTheDateOfAPaymentChangesBecauseTheTreasuryHoldsTheSame(): void
    {
        $this->startBuying();
        $drawer    = $this->drawerOpenOnlyTo([$this->employeeWithGrants([])], 'deposit');
        $itemId    = $this->item('0.00');
        $id        = $this->buy([$this->purchaseLine($itemId, 0, '4', '50')]);
        $paymentId = $this->payPurchase($id, '60.00');

        $this->assertTrue($this->changePayment($id, $paymentId, ['treasury_id' => $drawer]), 'Paying from it was not limited');
        $this->assertTrue($this->changePayment($id, $paymentId, ['payment_date' => $this->daysAgo(3)]));
        $this->assertSame($this->daysAgo(3), $this->lastPaymentOf($id)->payment_date);
        $this->assertSame('-60.0000', (new TreasuryService())->position($drawer)->balance);

        $this->assertFalse($this->changePayment($id, $paymentId, ['payment_date' => $this->daysAgo(2), 'payment_amount' => '30.00']), 'A date that moves less money out is still a deposit');
        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->purchases->accountingFailure()->error);
        $this->assertSame($this->daysAgo(3), $this->lastPaymentOf($id)->payment_date);
    }

    public function testUpdate_ChangesTheDateOfAPaymentOutOfATreasuryTheEditorMayDepositIntoAndWithdrawFrom(): void
    {
        $this->startBuying();
        $drawer    = $this->drawerOpenOnlyTo([$this->employeeId], 'deposit');
        $itemId    = $this->item('0.00');
        $id        = $this->buy([$this->purchaseLine($itemId, 0, '4', '50')]);
        $paymentId = $this->payPurchase($id, '60.00');

        $this->assertTrue($this->changePayment($id, $paymentId, ['treasury_id' => $drawer]));
        $this->assertTrue($this->changePayment($id, $paymentId, ['payment_date' => $this->daysAgo(3)]));
        $this->assertSame($this->daysAgo(3), $this->lastPaymentOf($id)->payment_date);
        $this->assertSame('-60.0000', (new TreasuryService())->position($drawer)->balance);
    }

    public function testUpdate_PostsAPaymentOfATreasuryOfAnotherBranchOnTheDateEnteredInBothBranches(): void
    {
        $this->startBuying();
        ['treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $receivingId = $this->purchaseOf('300.00');
        $date        = $this->daysAgo(4);

        $this->assertTrue($this->payPurchaseFrom($receivingId, '100.00', $treasury, ['payment_date' => $date]));

        $payment = (int) $this->lastPaymentOf($receivingId)->payment_id;

        $this->assertSame([$date, $date], array_map(static fn (object $journal): string => $journal->journal_date, $this->accountingDb()->table('accounting_journals')->whereIn('source_type', ['supplier_payment', 'supplier_payment_branch'])->where('source_id', $payment)->orderBy('journal_id')->get()->getResult()));
        $this->assertEveryBranchBalancedAndTheAccountsBetweenBranchesOffset();
    }

    public function testUpdate_MovesTheDateOfAPaymentOfATreasuryOfAnotherBranchInBothBranches(): void
    {
        $this->startBuying();
        ['treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $receivingId = $this->purchaseOf('300.00');

        $this->assertTrue($this->payPurchaseFrom($receivingId, '100.00', $treasury));

        $payment = (int) $this->lastPaymentOf($receivingId)->payment_id;

        $this->assertTrue($this->changePayment($receivingId, $payment, ['payment_date' => $this->daysAgo(4)]));

        $standing = $this->accountingDb()->table('accounting_journals')->whereIn('source_type', ['supplier_payment', 'supplier_payment_branch'])->where(['source_id' => $payment, 'status' => 'posted'])->get()->getResult();

        $this->assertCount(2, $standing);
        $this->assertSame([$this->daysAgo(4), $this->daysAgo(4)], array_map(static fn (object $journal): string => $journal->journal_date, $standing));
        $this->assertEveryBranchBalancedAndTheAccountsBetweenBranchesOffset();
        $this->assertSupplierTies();
    }

    public function testUpdate_StoresTheEnteredDateAndPostsNothingWhenTheBooksAreOff(): void
    {
        $this->startBuying();
        $purchase = $this->purchaseOf('100.00');
        $this->setConfigValue('accounting_enabled', '0');
        $journals = $this->countRows('accounting_journals');
        $date     = $this->daysAgo(5);

        $this->assertTrue($this->payOn($purchase, '40.00', $date));
        $this->assertSame($date, $this->lastPaymentOf($purchase)->payment_date);
        $this->assertSame($journals, $this->countRows('accounting_journals'));

        $this->assertTrue($this->payOn($purchase, '10.00', $this->daysAgo(4000)), 'No start of the books to keep to while they are off');
    }

    public function testUpdate_RefusesAPaymentDatedAfterTodayWhileTheBooksAreOff(): void
    {
        $this->startBuying();
        $purchase = $this->purchaseOf('100.00');
        $this->setConfigValue('accounting_enabled', '0');

        $this->assertFalse($this->payOn($purchase, '40.00', date('Y-m-d', strtotime('+1 day'))));
        $this->assertSame(AccountingError::PaymentDateInFuture, $this->purchases->accountingFailure()->error);
        $this->assertSame(0, $this->countRows('receivings_payments'));
    }

    public function testDeleteValue_TakesBackABackdatedPaymentOnTheDayItWasPostedOn(): void
    {
        $this->startBuying();
        $purchase = $this->purchaseOf('100.00');
        $date     = $this->daysAgo(5);

        $this->assertTrue($this->payOn($purchase, '40.00', $date));

        $payment = (int) $this->lastPaymentOf($purchase)->payment_id;

        $this->assertTrue($this->purchases->delete_value($purchase, $this->employeeId));

        $journals = $this->paymentJournals($payment);

        $this->assertSame('reversed', $journals[0]->status);
        $this->assertSame('0.00', $this->owed());
        $this->assertSame(0, $this->countRows('receivings_payments'));
        $this->assertSame($date, $this->accountingDb()->table('accounting_journals')->where('reverses_journal_id', $journals[0]->journal_id)->get()->getRow()->journal_date);
    }

    private function ownRecordsBuyer(): int
    {
        return $this->employeeWithRoles([$this->createRole([], 'own')], [BranchGuard::ALL_BRANCHES]);
    }

    private function purchaseBy(int $employeeId, string $total = '50.00'): int
    {
        $previous         = $this->employeeId;
        $this->employeeId = $employeeId;

        try {
            return $this->purchaseOf($total);
        } finally {
            $this->employeeId = $previous;
        }
    }

    private function commentOfPurchase(int $receivingId): string
    {
        return (string) $this->accountingDb()->table('receivings')->select('comment')->where('receiving_id', $receivingId)->get()->getRow()->comment;
    }

    public function testGetInfo_ReturnsNothingForAnotherEmployeesPurchaseAndTheRowForTheirOwnAndForAnEmployeeWhoSeesEverything(): void
    {
        $this->startBuying();

        $own    = $this->ownRecordsBuyer();
        $other  = $this->createEmployee();
        $mine   = $this->purchaseBy($own);
        $theirs = $this->purchaseBy($other);

        $this->assertSame(1, $this->purchases->get_info($mine, $own)->getNumRows());
        $this->assertSame(0, $this->purchases->get_info($theirs, $own)->getNumRows(), 'Another employee purchase reads as one that does not exist');
        $this->assertSame(0, $this->purchases->get_info(999999, $own)->getNumRows());
        $this->assertSame(1, $this->purchases->get_info($theirs, $this->createEmployee())->getNumRows());
    }

    public function testGetInfo_ReadsTheEmployeeOfTheSessionWhenNoViewerIsGiven(): void
    {
        $this->startBuying();

        $own    = $this->ownRecordsBuyer();
        $theirs = $this->purchaseBy($this->createEmployee());

        $_SESSION['person_id'] = $own;

        try {
            $this->assertSame(0, $this->purchases->get_info($theirs)->getNumRows());
        } finally {
            unset($_SESSION['person_id']);
        }

        $this->assertSame(1, $this->purchases->get_info($theirs)->getNumRows());
    }

    public function testGetReceivingByReference_FindsOnlyThePurchasesOfAnOwnRecordsEmployee(): void
    {
        $this->startBuying();

        $own    = $this->ownRecordsBuyer();
        $mine   = $this->purchaseBy($own);
        $theirs = $this->purchaseBy($this->createEmployee());
        $db     = $this->accountingDb();

        $db->table('receivings')->where('receiving_id', $mine)->update(['reference' => 'SCOPE-MINE']);
        $db->table('receivings')->where('receiving_id', $theirs)->update(['reference' => 'SCOPE-THEIRS']);

        $this->assertSame(1, $this->purchases->get_receiving_by_reference('SCOPE-MINE', $own)->getNumRows());
        $this->assertSame(0, $this->purchases->get_receiving_by_reference('SCOPE-THEIRS', $own)->getNumRows());
        $this->assertSame(1, $this->purchases->get_receiving_by_reference('SCOPE-THEIRS', $this->createEmployee())->getNumRows());
    }

    public function testIsValidReceipt_IsFalseForAnotherEmployeesPurchaseOfAnOwnRecordsEmployee(): void
    {
        $this->startBuying();

        $own    = $this->ownRecordsBuyer();
        $theirs = $this->purchaseBy($this->createEmployee());

        $this->assertTrue($this->purchases->is_valid_receipt('RECV ' . $theirs));

        $_SESSION['person_id'] = $own;

        try {
            $this->assertFalse($this->purchases->is_valid_receipt('RECV ' . $theirs));
        } finally {
            unset($_SESSION['person_id']);
        }
    }

    public function testUpdate_RefusesAnotherEmployeesPurchaseAndChangesNothing(): void
    {
        $this->startBuying();

        $own    = $this->ownRecordsBuyer();
        $theirs = $this->purchaseBy($this->createEmployee());

        $this->assertFalse($this->purchases->update($theirs, ['comment' => 'changed by a stranger', 'changed_by' => $own]));
        $this->assertSame('test', $this->commentOfPurchase($theirs));
        $this->assertNull($this->purchases->accountingFailure(), 'The refusal is not told apart from one for a purchase that does not exist');
        $this->assertFalse($this->purchases->update(999999, ['comment' => 'nothing', 'changed_by' => $own]));
    }

    public function testUpdate_ChangesThePurchaseOfTheOwnRecordsEmployeeAndAnyOneForAnEmployeeWhoSeesEverything(): void
    {
        $this->startBuying();

        $own   = $this->ownRecordsBuyer();
        $mine  = $this->purchaseBy($own);
        $other = $this->purchaseBy($this->createEmployee());

        $this->assertTrue($this->purchases->update($mine, ['comment' => 'mine, changed', 'changed_by' => $own]));
        $this->assertTrue($this->purchases->update($other, ['comment' => 'theirs, changed', 'changed_by' => $this->createEmployee()]));
        $this->assertSame('mine, changed', $this->commentOfPurchase($mine));
        $this->assertSame('theirs, changed', $this->commentOfPurchase($other));
    }

    public function testUpdateLines_RefusesAnotherEmployeesPurchaseAndKeepsItsLines(): void
    {
        $this->startBuying();

        $own    = $this->ownRecordsBuyer();
        $theirs = $this->purchaseBy($this->createEmployee());
        $line   = $this->accountingDb()->table('receivings_items')->where('receiving_id', $theirs)->get()->getRow();
        $edit   = ReceivingLineEdit::changing((int) $line->item_id, (int) $line->line, ['quantity' => '3']);

        $this->assertFalse($this->purchases->updateLines($theirs, [$edit], $own));
        $this->assertEquals($line->quantity_purchased, $this->accountingDb()->table('receivings_items')->where('receiving_id', $theirs)->get()->getRow()->quantity_purchased);
        $this->assertTrue($this->purchases->updateLines($theirs, [$edit], $this->employeeWithGrants([])));
    }

    public function testDeleteValue_RefusesAnotherEmployeesPurchaseAndKeepsItsJournalsAndStock(): void
    {
        $this->startBuying();

        $own      = $this->ownRecordsBuyer();
        $theirs   = $this->purchaseBy($this->createEmployee());
        $before   = $this->purchaseJournals($theirs);
        $line     = $this->accountingDb()->table('receivings_items')->where('receiving_id', $theirs)->get()->getRow();
        $onHand   = $this->onHand((int) $line->item_id);

        $this->assertFalse($this->purchases->delete_value($theirs, $own));
        $this->assertSame($before, $this->purchaseJournals($theirs), 'No journal of the purchase changed');
        $this->assertSame($onHand, $this->onHand((int) $line->item_id));
        $this->assertSame(1, $this->accountingDb()->table('receivings')->where('receiving_id', $theirs)->countAllResults());
        $this->assertFalse($this->purchases->delete_value(999999, $own));
    }

    public function testDeleteValue_DeletesThePurchaseOfTheOwnRecordsEmployeeAndAnyOneForAnEmployeeWhoSeesEverything(): void
    {
        $this->startBuying();

        $own    = $this->ownRecordsBuyer();
        $mine   = $this->purchaseBy($own);
        $theirs = $this->purchaseBy($this->createEmployee());

        $this->assertTrue($this->purchases->delete_value($mine, $own));
        $this->assertTrue($this->purchases->delete_value($theirs, $this->createEmployee()));
        $this->assertSame(0, $this->accountingDb()->table('receivings')->whereIn('receiving_id', [$mine, $theirs])->countAllResults());
    }

    public function testDeleteList_RefusesTheWholeListWhenOnePurchaseIsAnotherEmployees(): void
    {
        $this->startBuying();

        $own    = $this->ownRecordsBuyer();
        $mine   = $this->purchaseBy($own);
        $theirs = $this->purchaseBy($this->createEmployee());

        $this->assertFalse($this->purchases->delete_list([$mine, $theirs], $own));
        $this->assertSame(2, $this->accountingDb()->table('receivings')->whereIn('receiving_id', [$mine, $theirs])->countAllResults(), 'Nothing of a refused list is deleted');
    }

    public function testSaveValue_RefusesAReturnOfAnotherEmployeesPurchaseAndSavesNothing(): void
    {
        $this->startBuying();

        $own    = $this->ownRecordsBuyer();
        $theirs = $this->purchaseBy($this->createEmployee(), '100.00');
        $line   = $this->accountingDb()->table('receivings_items')->where('receiving_id', $theirs)->get()->getRow();
        $count  = $this->accountingDb()->table('receivings')->countAllResults();

        $result = $this->purchases->save_value(
            [$this->purchaseLine((int) $line->item_id, 0, '-1', (string) $line->item_unit_price)],
            $this->supplierId,
            $own,
            'return',
            'REF-' . uniqid(),
            lang('Sales.due'),
            NEW_ENTRY,
            $theirs,
        );

        $this->assertSame(-1, $result);
        $this->assertSame($count, $this->accountingDb()->table('receivings')->countAllResults());
    }

    public function testOutsideRecordScope_TreatsAPurchaseThatDoesNotExistLikeAnotherEmployeesForAnOwnRecordsEmployeeOnly(): void
    {
        $this->startBuying();

        $own  = $this->ownRecordsBuyer();
        $mine = $this->purchaseBy($own);

        $this->assertFalse($this->purchases->outsideRecordScope($mine, $own));
        $this->assertTrue($this->purchases->outsideRecordScope(999999, $own));
        $this->assertFalse($this->purchases->outsideRecordScope(999999, $this->createEmployee()));
    }

    private function orderOf(string $quantity = '10', ?int $location = null): int
    {
        return $this->documentsOf(PurchaseDocumentKind::Order)->create($this->documentInput(['location_id' => $location ?? self::LOCATION_ID, 'lines' => [['item_id' => $this->goods, 'quantity' => $quantity, 'unit_price' => '4.00']]]), $this->clerk);
    }

    private function receiveFromOrder(int $orderId, string $units, ?int $by = null): int
    {
        $result = $this->purchases->save_value([$this->purchaseLine($this->goods, 0, $units, '4.00')], $this->supplierId, $by ?? $this->clerk, 'order', 'REF-' . uniqid(), lang('Sales.due'), NEW_ENTRY, null, null, null, null, $orderId);

        if ($result > 0) {
            $this->receivingIds[] = $result;
        }

        return $result;
    }

    private function orderStatus(int $orderId): PurchaseDocumentStatus
    {
        return $this->documentsOf(PurchaseDocumentKind::Order)->statusOf($orderId);
    }

    public function testSaveValue_ReceivesAgainstAPurchaseOrderPostsTheReceivingAsAnyPurchaseAndTakesTheUnitsFromTheOrder(): void
    {
        $this->startBuying();
        $this->startCycle();

        $orderId  = $this->orderOf('10');
        $journals = $this->countRows('accounting_journals');

        $receivingId = $this->receiveFromOrder($orderId, '4');

        $this->assertGreaterThan(0, $receivingId);
        $this->assertSame($journals + 1, $this->countRows('accounting_journals'));
        $this->assertSame('4.000', $this->onHand($this->goods));
        $this->assertSame('-16.00', $this->owed());
        $this->assertSame($orderId, (new PurchaseOrderReceipts($this->accountingDb()))->orderOfReceiving($receivingId));
        $this->assertSame(PurchaseDocumentStatus::PartlyReceived, $this->orderStatus($orderId));
        $this->assertSame(['4.000'], array_map(static fn (object $line): string => $line->received, $this->documentsOf(PurchaseDocumentKind::Order)->lines($orderId)));
        $this->assertStockTies();
        $this->assertSupplierTies();
    }

    public function testSaveValue_RefusesMoreThanThePurchaseOrderHoldsAndRollsTheWholeReceivingBack(): void
    {
        $this->startBuying();
        $this->startCycle();

        $orderId = $this->orderOf('10');
        $before  = $this->bookCounts();

        $this->assertSame(ACCOUNTING_FAILED, $this->receiveFromOrder($orderId, '10.001'));

        $failure = $this->purchases->accountingFailure();

        $this->assertInstanceOf(AccountingException::class, $failure);
        $this->assertSame(AccountingError::PurchaseOrderQuantityExceeded, $failure->error);
        $this->assertSame($before, $this->bookCounts(), 'no purchase, no journal, no stock, no unit taken');
        $this->assertSame(0, $this->countRows('purchase_order_receivings'));
        $this->assertSame(PurchaseDocumentStatus::Open, $this->orderStatus($orderId));
        $this->assertStockTies();
    }

    public function testSaveValue_ReceivesMoreThanTheOrderHoldsWhenTheSettingsAllowIt(): void
    {
        $this->startBuying();
        $this->startCycle();
        $this->setConfigValue('purchase_order_allow_excess', '1');

        $orderId = $this->orderOf('10');

        $this->assertGreaterThan(0, $this->receiveFromOrder($orderId, '12'));
        $this->assertSame(PurchaseDocumentStatus::Received, $this->orderStatus($orderId));
        $this->assertSame('12.000', $this->onHand($this->goods));
    }

    public function testSaveValue_RefusesAReturnAgainstAPurchaseOrder(): void
    {
        $this->startBuying();
        $this->startCycle();

        $orderId = $this->orderOf('10');
        $before  = $this->bookCounts();

        $result = $this->purchases->save_value([$this->purchaseLine($this->goods, 0, '-1', '4.00')], $this->supplierId, $this->clerk, 'return', 'REF-R', lang('Sales.due'), NEW_ENTRY, null, null, null, null, $orderId);

        $this->assertSame(ACCOUNTING_FAILED, $result);
        $this->assertSame(AccountingError::PurchaseOrderReceivingKind, $this->purchases->accountingFailure()->error);
        $this->assertSame($before, $this->bookCounts());
    }

    public function testSaveValue_RefusesAnEmployeeWhoDoesNotWorkInTheBranchOfThePurchaseOrder(): void
    {
        $this->startBuying();
        $this->startCycle();

        $branchB  = $this->createBranch('B2', 'Second branch');
        $orderId  = $this->orderOf('10');
        $outsider = $this->employeeWithGrants([], [$branchB]);
        $before   = $this->bookCounts();

        $this->assertSame(ACCOUNTING_FAILED, $this->receiveFromOrder($orderId, '1', $outsider));
        $this->assertInstanceOf(BranchException::class, $this->purchases->accountingFailure());
        $this->assertSame($before, $this->bookCounts());
    }

    public function testSaveValue_RefusesAnOrderThatWasCancelledAndOneOfAnotherSupplier(): void
    {
        $this->startBuying();
        $this->startCycle();

        $cancelled = $this->orderOf('10');
        $another   = $this->documentsOf(PurchaseDocumentKind::Order)->create($this->documentInput(['supplier_id' => $this->anotherSupplier()]), $this->clerk);

        $this->documentsOf(PurchaseDocumentKind::Order)->cancel($cancelled, 'no', $this->clerk);

        $this->assertSame(ACCOUNTING_FAILED, $this->receiveFromOrder($cancelled, '1'));
        $this->assertSame(AccountingError::PurchaseOrderNotReceivable, $this->purchases->accountingFailure()->error);
        $this->assertSame(ACCOUNTING_FAILED, $this->receiveFromOrder($another, '1'));
        $this->assertSame(AccountingError::PurchaseOrderSupplierMismatch, $this->purchases->accountingFailure()->error);
        $this->assertSame(0, $this->countRows('receivings'));
    }

    public function testSaveValue_ReceivesAgainstAPurchaseOrderWithAccountingOffAndPostsNothing(): void
    {
        $this->startBuying();
        $this->startCycle();

        $orderId = $this->orderOf('10');

        $this->setConfigValue('accounting_enabled', '0');

        $journals = $this->countRows('accounting_journals');

        $this->assertGreaterThan(0, $this->receiveFromOrder($orderId, '10'));
        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertSame('10.000', $this->onHand($this->goods));
        $this->assertSame(PurchaseDocumentStatus::Received, $this->orderStatus($orderId));

        $this->setConfigValue('accounting_enabled', '1');
    }

    public function testSaveValue_LeavesAReceivingWithoutAPurchaseOrderAsItWasWhetherTheCycleIsOnOrOff(): void
    {
        $this->startBuying();
        $this->startCycle();

        $first = $this->buy([$this->purchaseLine($this->goods, 0, '3', '4.00')]);

        $this->setConfigValue('enable_purchase_cycle', '0');

        $second = $this->buy([$this->purchaseLine($this->goods, 0, '2', '4.00')]);

        $this->assertGreaterThan(0, $first);
        $this->assertGreaterThan(0, $second);
        $this->assertSame(0, $this->countRows('purchase_order_receivings'));
        $this->assertSame(0, $this->countRows('purchase_order_receiving_lines'));
        $this->assertSame('5.000', $this->onHand($this->goods));
        $this->assertStockTies();
    }

    public function testUpdateLines_TakesTheUnitsOfAReceivingFromAnOrderAgainAndRefusesWhatTheOrderCannotHold(): void
    {
        $this->startBuying();
        $this->startCycle();

        $orderId     = $this->orderOf('10');
        $receivingId = $this->receiveFromOrder($orderId, '4');
        $line        = $this->orderLineEdit($receivingId);

        $this->assertTrue($this->purchases->updateLines($receivingId, [ReceivingLineEdit::changing($this->goods, $line, ['quantity' => '9'])], $this->clerk));
        $this->assertSame('9.000', $this->documentsOf(PurchaseDocumentKind::Order)->lines($orderId)[0]->received);
        $this->assertSame('9.000', $this->onHand($this->goods));

        $this->assertFalse($this->purchases->updateLines($receivingId, [ReceivingLineEdit::changing($this->goods, $line, ['quantity' => '11'])], $this->clerk));
        $this->assertSame(AccountingError::PurchaseOrderQuantityExceeded, $this->purchases->accountingFailure()->error);
        $this->assertSame('9.000', $this->documentsOf(PurchaseDocumentKind::Order)->lines($orderId)[0]->received, 'the refused correction took nothing');
        $this->assertSame('9.000', $this->onHand($this->goods));

        $this->assertTrue($this->purchases->updateLines($receivingId, [ReceivingLineEdit::changing($this->goods, $line, ['quantity' => '2'])], $this->clerk));
        $this->assertSame('2.000', $this->documentsOf(PurchaseDocumentKind::Order)->lines($orderId)[0]->received);
        $this->assertStockTies();
    }

    public function testUpdateLines_LeavesTheOrderAloneWhenOnlyAPriceChangesEvenAfterTheOrderWasCancelled(): void
    {
        $this->startBuying();
        $this->startCycle();

        $orderId     = $this->orderOf('10');
        $receivingId = $this->receiveFromOrder($orderId, '4');
        $line        = $this->orderLineEdit($receivingId);

        $this->documentsOf(PurchaseDocumentKind::Order)->cancel($orderId, 'the rest is not coming', $this->clerk);

        $this->assertTrue($this->purchases->updateLines($receivingId, [ReceivingLineEdit::changing($this->goods, $line, ['price' => '5.00'])], $this->clerk));
        $this->assertSame('4.000', $this->documentsOf(PurchaseDocumentKind::Order)->lines($orderId)[0]->received);
        $this->assertSame(PurchaseDocumentStatus::Cancelled, $this->orderStatus($orderId));
    }

    private function orderLineEdit(int $receivingId): int
    {
        return (int) $this->accountingDb()->table('receivings_items')->where('receiving_id', $receivingId)->get()->getRow()->line;
    }

    public function testDeleteValue_GivesTheUnitsOfAReceivingBackToItsPurchaseOrder(): void
    {
        $this->startBuying();
        $this->startCycle();

        $orderId = $this->orderOf('10');
        $first   = $this->receiveFromOrder($orderId, '10');

        $this->assertSame(PurchaseDocumentStatus::Received, $this->orderStatus($orderId));
        $this->assertTrue($this->purchases->delete_value($first, $this->clerk));

        $this->assertSame(PurchaseDocumentStatus::Open, $this->orderStatus($orderId));
        $this->assertSame(0, $this->countRows('purchase_order_receivings'));
        $this->assertSame(0, $this->countRows('purchase_order_receiving_lines'));
        $this->assertSame('0.000', $this->onHand($this->goods));
        $this->assertContains('receipt_released', $this->auditActions(PurchaseDocumentKind::Order, $orderId));
        $this->assertGreaterThan(0, $this->receiveFromOrder($orderId, '10'), 'the order can be received again');
    }

    public function testDeleteList_GivesTheUnitsBackOfEveryReceivingItDeletes(): void
    {
        $this->startBuying();
        $this->startCycle();

        $orderId = $this->orderOf('10');
        $first   = $this->receiveFromOrder($orderId, '4');
        $second  = $this->receiveFromOrder($orderId, '6');

        $this->assertTrue($this->purchases->delete_list([$first, $second], $this->clerk));
        $this->assertSame(PurchaseDocumentStatus::Open, $this->orderStatus($orderId));
        $this->assertSame(0, $this->countRows('purchase_order_receiving_lines'));
    }

    public function testSaveValue_LetsOneOfTwoReceivingsAtTheSameMomentTakeTheUnitsOfTheOrder(): void
    {
        $this->startBuying();
        $this->startCycle();

        $orderId = $this->orderOf('10');
        $before  = $this->countRows('receivings');

        [$first, $second] = $this->raceTwoProcesses('orderReceive', [$orderId, '7', $this->goods, $this->supplierId, $this->clerk], [$orderId, '7', $this->goods, $this->supplierId, $this->clerk]);

        $this->receivingIds = [...$this->receivingIds, ...array_map(static fn (array $row): int => (int) $row['receiving_id'], $this->accountingDb()->table('receivings')->select('receiving_id')->where('supplier_id', $this->supplierId)->get()->getResultArray())];

        $this->assertSame(1, (int) $first + (int) $second, 'of two receivings of 7 from an order of 10, one is saved and the other is refused');
        $this->assertSame($before + 1, $this->countRows('receivings'));
        $this->assertSame('7.000', $this->documentsOf(PurchaseDocumentKind::Order)->lines($orderId)[0]->received);
    }
}
