<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\DocumentCurrency;
use App\Libraries\Accounting\SupplierPayment;
use App\Libraries\Accounting\PaymentStatus;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\ReceivingDocument;
use App\Libraries\Accounting\ReceivingLine;
use App\Libraries\Accounting\ReceivingPostingBuilder;
use App\Libraries\Accounting\ReceivingType;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\RoutingResolver;
use App\Libraries\Accounting\RoutingType;
use App\Libraries\Accounting\StockLedger;
use App\Libraries\Accounting\StockMovement;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

/**
 * The journals and the stock movements a purchase draws, from a purchase handed to the builder
 * as it is, with nothing saved: the amounts of each side, the refusals, and the payment.
 */
class ReceivingPostingBuilderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private ReceivingPostingBuilder $builder;
    private int $supplierId;
    private int $warehouse;
    private int $shop;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');

        $entities         = new EntityAccountService();
        $this->supplierId = $this->insertSupplier();
        $this->warehouse  = $this->insertStockLocation('Warehouse');
        $this->shop       = $this->insertStockLocation('Shop');

        $entities->ensure(EntityAccountService::SUPPLIER, $this->supplierId, 'Gulf Supplies');
        $entities->ensure(EntityAccountService::STOCK_LOCATION, $this->warehouse, 'Warehouse');
        $entities->ensure(EntityAccountService::STOCK_LOCATION, $this->shop, 'Shop');

        $this->builder = new ReceivingPostingBuilder(new RoutingResolver(), new StockLedger());
    }

    protected function tearDown(): void
    {
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function line(string $quantity, string $price, string $tax = '0', string $discount = '0', int $discountType = PERCENT, ?int $location = null, bool $stock = true, string $cost = '0', int $itemId = 1, string $perPack = '1', ?int $branch = null): ReceivingLine
    {
        return new ReceivingLine($itemId, $itemId, $quantity, $perPack, $price, $discount, $discountType, $tax, $location ?? $this->warehouse, $stock, $cost, $branch);
    }

    /**
     * @param list<ReceivingLine> $lines
     */
    private function purchase(array $lines, ReceivingType $type = ReceivingType::Receive, ?string $payment = null, ?int $supplierId = -1, ?ReceivingDocument $origin = null, ?int $toBranchId = null, ?DocumentCurrency $currency = null, array $payments = []): ReceivingDocument
    {
        return new ReceivingDocument(
            receivingId: 7,
            date: '2026-10-24',
            type: $type,
            supplierId: $supplierId === -1 ? $this->supplierId : $supplierId,
            branchId: 1,
            paymentType: $payment,
            returnOfReceivingId: $origin?->receivingId,
            lines: $lines,
            origin: $origin,
            payments: $payments,
            toBranchId: $toBranchId,
            branchCodes: $toBranchId === null ? [] : [1 => 'MAIN', $toBranchId => 'JED'],
            currency: $currency,
        );
    }

    private function dollars(string $rate = '3.75000000'): DocumentCurrency
    {
        return new DocumentCurrency('USD', $rate, 2);
    }

    private function supplierPayment(int $id, string $amount, ?string $rate): SupplierPayment
    {
        return new SupplierPayment($id, lang('Sales.cash'), $amount, '2026-10-24', PaymentStatus::Completed, null, null, $rate);
    }

    /**
     * @param list<ReceivingLine> $lines
     */
    private function origin(array $lines, ?int $supplierId = -1, ReceivingType $type = ReceivingType::Receive): ReceivingDocument
    {
        return new ReceivingDocument(5, '2026-10-01', $type, $supplierId === -1 ? $this->supplierId : $supplierId, 1, null, null, $lines);
    }

    /**
     * @return list<string> "account:debit:credit" of each line, in order, at the four decimals they are kept at.
     */
    private function shape(?JournalDraft $draft): array
    {
        $this->assertNotNull($draft);

        return array_map(static fn ($line): string => $line->accountId . ':' . bcadd($line->debit, '0', 4) . ':' . bcadd($line->credit, '0', 4), $draft->lines);
    }

    /**
     * @param list<StockMovement> $movements
     *
     * @return list<string> "item:location:quantity:value" of each movement.
     */
    private function movements(array $movements): array
    {
        return array_map(static fn (StockMovement $movement): string => $movement->itemId . ':' . $movement->locationId . ':' . $movement->quantity . ':' . bcadd($movement->value, '0', 4), $movements);
    }

    private function supplierAccount(): int
    {
        return (int) (new EntityAccountService())->accountFor(EntityAccountService::SUPPLIER, $this->supplierId);
    }

    private function stockAccount(int $location): int
    {
        return (int) (new EntityAccountService())->accountFor(EntityAccountService::STOCK_LOCATION, $location);
    }

    private function failureOf(callable $work): PostingException
    {
        try {
            $work();
        } catch (PostingException $failure) {
            return $failure;
        }

        $this->fail('A posting failure was expected.');
    }

    public function testPurchase_DebitsStockAndTaxAndCreditsTheSupplierTheTotal(): void
    {
        $plan = $this->builder->purchase($this->purchase([$this->line('20', '40', '15'), $this->line('10', '120', '15', itemId: 2)]), 1, 5);

        $this->assertSame(
            [
                $this->stockAccount($this->warehouse) . ':2000.0000:0.0000',
                $this->accountId('tax_input') . ':300.0000:0.0000',
                $this->supplierAccount() . ':0.0000:2300.0000',
            ],
            $this->shape($plan->journal),
        );
        $this->assertSame(['1:' . $this->warehouse . ':20.000:800.0000', '2:' . $this->warehouse . ':10.000:1200.0000'], $this->movements($plan->movements));
        $this->assertSame('purchase', $plan->journal->sourceType);
        $this->assertSame(7, $plan->journal->sourceId);
        $this->assertSame(1, $plan->journal->revision);
        $this->assertSame(5, $plan->journal->employeeId);
        $this->assertSame('2026-10-24', $plan->journal->date);
    }

    public function testPurchase_DebitsEachStockLocationForItsOwnGoods(): void
    {
        $plan = $this->builder->purchase($this->purchase([$this->line('10', '10'), $this->line('5', '20', location: $this->shop, itemId: 2)]), 1);

        $this->assertSame(
            [
                $this->stockAccount($this->warehouse) . ':100.0000:0.0000',
                $this->stockAccount($this->shop) . ':100.0000:0.0000',
                $this->supplierAccount() . ':0.0000:200.0000',
            ],
            $this->shape($plan->journal),
        );
    }

    public function testPurchase_TakesTheDiscountOffTheCostOfTheGoodsAndTheTax(): void
    {
        $plan = $this->builder->purchase($this->purchase([$this->line('10', '100', '15', '10')]), 1);

        $this->assertSame(
            [
                $this->stockAccount($this->warehouse) . ':900.0000:0.0000',
                $this->accountId('tax_input') . ':135.0000:0.0000',
                $this->supplierAccount() . ':0.0000:1035.0000',
            ],
            $this->shape($plan->journal),
        );
        $this->assertSame(['1:' . $this->warehouse . ':10.000:900.0000'], $this->movements($plan->movements));
    }

    public function testPurchase_TakesAFixedDiscountOffEachUnit(): void
    {
        $plan = $this->builder->purchase($this->purchase([$this->line('10', '100', '0', '50', FIXED)]), 1);

        $this->assertSame(['1:' . $this->warehouse . ':10.000:500.0000'], $this->movements($plan->movements));
    }

    public function testPurchase_CountsThePacksOfALineAsTheUnitsInThem(): void
    {
        $plan = $this->builder->purchase($this->purchase([$this->line('3', '2.50', perPack: '12')]), 1);

        $this->assertSame(['1:' . $this->warehouse . ':36.000:90.0000'], $this->movements($plan->movements));
    }

    public function testPurchase_KeepsTheTaxUnroundedAndTheTotalAtTwoDecimalsWithTheLargestPartTakingTheRounding(): void
    {
        $plan = $this->builder->purchase($this->purchase([$this->line('1', '0.70', '15'), $this->line('1', '9.00', itemId: 2)]), 1);

        $this->assertSame(
            [
                $this->stockAccount($this->warehouse) . ':9.7050:0.0000',
                $this->accountId('tax_input') . ':0.1050:0.0000',
                $this->supplierAccount() . ':0.0000:9.8100',
            ],
            $this->shape($plan->journal),
            'The lines come to 9.70 and the tax to 0.105, so the total 9.805 is kept as 9.81 and the 0.005 goes to the larger line',
        );
    }

    public function testPurchase_PostsAServiceAsACostAndMovesNoStock(): void
    {
        $plan = $this->builder->purchase($this->purchase([$this->line('1', '80', stock: false)]), 1);

        $this->assertSame(
            [
                $this->accountId('cogs') . ':80.0000:0.0000',
                $this->supplierAccount() . ':0.0000:80.0000',
            ],
            $this->shape($plan->journal),
        );
        $this->assertSame([], $plan->movements);
    }

    public function testPurchase_KeepsTheMovementsOfGoodsThatCostNothingAndDrawsNoJournal(): void
    {
        $plan = $this->builder->purchase($this->purchase([$this->line('5', '0')]), 1);

        $this->assertNull($plan->journal);
        $this->assertSame(['1:' . $this->warehouse . ':5.000:0.0000'], $this->movements($plan->movements));
    }

    public function testPurchase_AnEmptyPurchaseDrawsNothing(): void
    {
        $plan = $this->builder->purchase($this->purchase([]), 1);

        $this->assertNull($plan->journal);
        $this->assertSame([], $plan->movements);
    }

    public function testPurchase_OfAPurchaseNotPaidAtOnceNeedsASupplier(): void
    {
        $failure = $this->failureOf(fn () => $this->builder->purchase($this->purchase([$this->line('1', '10')], supplierId: null), 1));

        $this->assertSame(PostingError::SupplierRequired, $failure->error);
    }

    public function testPurchase_OfAPurchasePaidAtOnceWithoutASupplierCreditsTheAccountItIsPaidFrom(): void
    {
        $plan = $this->builder->purchase($this->purchase([$this->line('1', '10')], payment: lang('Sales.cash'), supplierId: null), 1);

        $this->assertSame(
            [
                $this->stockAccount($this->warehouse) . ':10.0000:0.0000',
                $this->accountId('cash') . ':0.0000:10.0000',
            ],
            $this->shape($plan->journal),
        );
    }

    public function testPurchase_RefusesLinesThatDoNotFitTheKindOfThePurchase(): void
    {
        $failure = $this->failureOf(fn () => $this->builder->purchase($this->purchase([$this->line('-1', '10')]), 1));

        $this->assertSame(PostingError::PurchaseNotPostable, $failure->error);
    }

    public function testPurchase_RefusesARoutingThatIsCancelled(): void
    {
        $this->accountingDb()->table('accounting_routing')->where('routing_key', RoutingKey::CostOfGoodsSold->value)->update(['routing_type' => RoutingType::Cancelled->value]);

        $failure = $this->failureOf(fn () => $this->builder->purchase($this->purchase([$this->line('1', '10', stock: false)]), 1));

        $this->assertSame(PostingError::RoutingRowCancelled, $failure->error);
    }

    public function testPurchase_GivesGoodsBackAtTheCostTheItemCarriesAndTheDifferenceGoesToTheCostOfSales(): void
    {
        $origin = $this->origin([$this->line('20', '65.70')]);
        $plan   = $this->builder->purchase($this->purchase([$this->line('-2', '70', '15', cost: '66')], ReceivingType::Return, origin: $origin), 1);

        $this->assertSame(
            [
                $this->supplierAccount() . ':161.0000:0.0000',
                $this->accountId('tax_input') . ':0.0000:21.0000',
                $this->stockAccount($this->warehouse) . ':0.0000:132.0000',
                $this->accountId('cogs') . ':0.0000:8.0000',
            ],
            $this->shape($plan->journal),
        );
        $this->assertSame(['1:' . $this->warehouse . ':-2.000:-132.0000'], $this->movements($plan->movements));
    }

    public function testPurchase_GivesGoodsBackBelowWhatTheyAreWorthAndTheDifferenceIsACost(): void
    {
        $origin = $this->origin([$this->line('20', '65.70')]);
        $plan   = $this->builder->purchase($this->purchase([$this->line('-2', '60', cost: '66')], ReceivingType::Return, origin: $origin), 1);

        $this->assertSame(
            [
                $this->supplierAccount() . ':120.0000:0.0000',
                $this->stockAccount($this->warehouse) . ':0.0000:132.0000',
                $this->accountId('cogs') . ':12.0000:0.0000',
            ],
            $this->shape($plan->journal),
        );
    }

    public function testPurchase_RefusesAReturnThatSaysNoPurchase(): void
    {
        $failure = $this->failureOf(fn () => $this->builder->purchase($this->purchase([$this->line('-2', '70', cost: '66')], ReceivingType::Return), 1));

        $this->assertSame(PostingError::PurchaseReturnOriginRequired, $failure->error);
    }

    public function testPurchase_RefusesAReturnToAnotherSupplierThanThePurchaseCameFrom(): void
    {
        $other   = $this->insertSupplier('Other supplies', 'Huda', 'Said');
        $origin  = $this->origin([$this->line('20', '65.70')], $other);
        $failure = $this->failureOf(fn () => $this->builder->purchase($this->purchase([$this->line('-2', '70', cost: '66')], ReceivingType::Return, origin: $origin), 1));

        $this->assertSame(PostingError::PurchaseReturnOriginInvalid, $failure->error);
    }

    public function testPurchase_RefusesAReturnOfAReturn(): void
    {
        $origin  = $this->origin([$this->line('-20', '65.70')], type: ReceivingType::Return);
        $failure = $this->failureOf(fn () => $this->builder->purchase($this->purchase([$this->line('-2', '70', cost: '66')], ReceivingType::Return, origin: $origin), 1));

        $this->assertSame(PostingError::PurchaseReturnOriginInvalid, $failure->error);
    }

    public function testPurchase_RefusesAReturnOfAnItemThePurchaseDidNotHave(): void
    {
        $origin  = $this->origin([$this->line('20', '65.70')]);
        $failure = $this->failureOf(fn () => $this->builder->purchase($this->purchase([$this->line('-2', '70', cost: '66', itemId: 9)], ReceivingType::Return, origin: $origin), 1));

        $this->assertSame(PostingError::PurchaseReturnLineNotOnOrigin, $failure->error);
        $this->assertSame(9, $failure->context['item_id']);
    }

    public function testPurchase_AReturnOfAServiceNeedsNoPurchaseToTakeItFrom(): void
    {
        $plan = $this->builder->purchase($this->purchase([$this->line('-1', '80', stock: false)], ReceivingType::Return), 1);

        $this->assertSame(
            [
                $this->supplierAccount() . ':80.0000:0.0000',
                $this->accountId('cogs') . ':0.0000:80.0000',
            ],
            $this->shape($plan->journal),
        );
    }

    public function testPurchase_MovesStockBetweenLocationsAtWhatItIsWorthWhereItLeaves(): void
    {
        $plan = $this->builder->purchase($this->purchase([
            $this->line('-4', '0', cost: '25'),
            $this->line('4', '0', location: $this->shop, cost: '25'),
        ], ReceivingType::Requisition, supplierId: null), 1);

        $this->assertSame(
            [
                $this->stockAccount($this->shop) . ':100.0000:0.0000',
                $this->stockAccount($this->warehouse) . ':0.0000:100.0000',
            ],
            $this->shape($plan->journal),
        );
        $this->assertSame(['1:' . $this->warehouse . ':-4.000:-100.0000', '1:' . $this->shop . ':4.000:100.0000'], $this->movements($plan->movements));
    }

    public function testPurchase_SharesWhatLeftAmongTheLocationsThatTookItWithTheLastOneTakingTheRounding(): void
    {
        $plan = $this->builder->purchase($this->purchase([
            $this->line('-3', '0', cost: '10'),
            $this->line('1', '0', location: $this->shop, cost: '10'),
            $this->line('2', '0', location: $this->warehouse, cost: '10'),
        ], ReceivingType::Requisition, supplierId: null), 1);

        $values = array_map(static fn (StockMovement $movement): string => bcadd($movement->value, '0', 4), $plan->movements);

        $this->assertSame(['-30.0000', '10.0000', '20.0000'], $values);
    }

    public function testPurchase_RefusesATransferThatPutsInAnItemItNeverTookOut(): void
    {
        $failure = $this->failureOf(fn () => $this->builder->purchase($this->purchase([
            $this->line('-4', '0', cost: '25'),
            $this->line('4', '0', location: $this->shop, cost: '25', itemId: 2),
        ], ReceivingType::Requisition, supplierId: null), 1));

        $this->assertSame(PostingError::PurchaseNotPostable, $failure->error);
    }

    /**
     * A move from the first branch to the second, with the locations of the second branch given.
     *
     * @param list<ReceivingLine> $lines
     */
    private function betweenBranches(array $lines): ReceivingDocument
    {
        return $this->purchase($lines, ReceivingType::Requisition, supplierId: null, toBranchId: 2);
    }

    public function testPurchase_DrawsAJournalInEachBranchForAMoveBetweenBranches(): void
    {
        $plan = $this->builder->purchase($this->betweenBranches([
            $this->line('-4', '0', cost: '25', branch: 1),
            $this->line('4', '0', location: $this->shop, cost: '25', branch: 2),
        ]), 3, 9);

        $issue   = $plan->journal;
        $receipt = $plan->receipt;

        $this->assertSame([$this->accountId('branches_receivable') . ':100.0000:0.0000', $this->stockAccount($this->warehouse) . ':0.0000:100.0000'], $this->shape($issue));
        $this->assertSame([$this->stockAccount($this->shop) . ':100.0000:0.0000', $this->accountId('branches_payable') . ':0.0000:100.0000'], $this->shape($receipt));
        $this->assertSame(['stock_transfer', 1, 7, 3, 9, 'Stock transfer #7 (to branch JED)'], [$issue->sourceType, $issue->branchId, $issue->sourceId, $issue->revision, $issue->employeeId, $issue->description]);
        $this->assertSame(['stock_transfer_receipt', 2, 7, 3, 9, 'Stock transfer #7 (from branch MAIN)'], [$receipt->sourceType, $receipt->branchId, $receipt->sourceId, $receipt->revision, $receipt->employeeId, $receipt->description]);
        $this->assertSame(['1:' . $this->warehouse . ':-4.000:-100.0000'], $this->movements($plan->movements));
        $this->assertSame(['1:' . $this->shop . ':4.000:100.0000'], $this->movements($plan->receiptMovements));
    }

    public function testPurchase_SharesWhatLeftAmongTheLocationsOfTheOtherBranchWithTheLastOneTakingTheRounding(): void
    {
        $annex = $this->insertStockLocation('Annex');

        (new EntityAccountService())->ensure(EntityAccountService::STOCK_LOCATION, $annex, 'Annex');

        $plan = $this->builder->purchase($this->betweenBranches([
            $this->line('-3', '0', cost: '10', branch: 1),
            $this->line('1', '0', location: $this->shop, cost: '10', branch: 2),
            $this->line('2', '0', location: $annex, cost: '10', branch: 2),
        ]), 1);

        $this->assertSame(
            [$this->stockAccount($this->shop) . ':10.0000:0.0000', $this->stockAccount($annex) . ':20.0000:0.0000', $this->accountId('branches_payable') . ':0.0000:30.0000'],
            $this->shape($plan->receipt),
        );
        $this->assertSame([$this->accountId('branches_receivable') . ':30.0000:0.0000', $this->stockAccount($this->warehouse) . ':0.0000:30.0000'], $this->shape($plan->journal));
    }

    public function testPurchase_OwesWhatTheOtherBranchIsOwedWhateverTheRounding(): void
    {
        $plan = $this->builder->purchase($this->betweenBranches([
            $this->line('-3', '0', cost: '3.3333', branch: 1),
            $this->line('1', '0', location: $this->shop, cost: '3.3333', branch: 2),
            $this->line('2', '0', location: $this->shop, cost: '3.3333', branch: 2),
        ]), 1);

        $owed = $plan->journal->lines[0]->debit;
        $owes = $plan->receipt->lines[array_key_last($plan->receipt->lines)]->credit;

        $this->assertSame(bcadd($owed, '0', 4), bcadd($owes, '0', 4));

        $net = '0';

        foreach ([...$plan->movements, ...$plan->receiptMovements] as $movement) {
            $net = bcadd($net, $movement->value, 4);
        }

        $this->assertSame('0.0000', $net);
    }

    public function testPurchase_DrawsNoJournalForAMoveBetweenBranchesOfStockWorthNothingButKeepsItsMovements(): void
    {
        $plan = $this->builder->purchase($this->betweenBranches([
            $this->line('-4', '0', cost: '0', branch: 1),
            $this->line('4', '0', location: $this->shop, cost: '0', branch: 2),
        ]), 1);

        $this->assertNull($plan->journal);
        $this->assertNull($plan->receipt);
        $this->assertSame(['1:' . $this->warehouse . ':-4.000:0.0000'], $this->movements($plan->movements));
        $this->assertSame(['1:' . $this->shop . ':4.000:0.0000'], $this->movements($plan->receiptMovements));
    }

    public function testPurchase_RefusesAMoveBetweenBranchesThatTakesStockOutOfTheOtherBranch(): void
    {
        $failure = $this->failureOf(fn () => $this->builder->purchase($this->betweenBranches([
            $this->line('-4', '0', cost: '25', branch: 2),
            $this->line('4', '0', location: $this->shop, cost: '25', branch: 2),
        ]), 1));

        $this->assertSame(PostingError::TransferBranchesInvalid, $failure->error);
    }

    public function testPurchase_RefusesAMoveBetweenBranchesThatPutsStockInTheBranchItLeaves(): void
    {
        $failure = $this->failureOf(fn () => $this->builder->purchase($this->betweenBranches([
            $this->line('-4', '0', cost: '25', branch: 1),
            $this->line('4', '0', location: $this->shop, cost: '25', branch: 1),
        ]), 1));

        $this->assertSame(PostingError::TransferBranchesInvalid, $failure->error);
    }

    public function testPurchase_RefusesALocationWhoseBranchIsNotKnown(): void
    {
        $failure = $this->failureOf(fn () => $this->builder->purchase($this->betweenBranches([
            $this->line('-4', '0', cost: '25', branch: 1),
            $this->line('4', '0', location: $this->shop, cost: '25'),
        ]), 1));

        $this->assertSame(PostingError::TransferBranchesInvalid, $failure->error);
    }

    public function testPurchase_RefusesABranchThatGoesToItselfAndDocumentsThatAreNotTransfers(): void
    {
        $toItself = $this->purchase([$this->line('-4', '0', cost: '25', branch: 1), $this->line('4', '0', location: $this->shop, cost: '25', branch: 1)], ReceivingType::Requisition, supplierId: null, toBranchId: 1);
        $received = $this->purchase([$this->line('4', '10', branch: 2)], toBranchId: 2);

        foreach ([$toItself, $received] as $document) {
            $this->assertSame(PostingError::TransferBranchesInvalid, $this->failureOf(fn () => $this->builder->purchase($document, 1))->error);
        }
    }

    public function testPurchase_RefusesAMoveBetweenBranchesThatTakesOutAnItemItDoesNotPutIn(): void
    {
        $failure = $this->failureOf(fn () => $this->builder->purchase($this->betweenBranches([
            $this->line('-4', '0', cost: '25', branch: 1),
            $this->line('-1', '0', cost: '25', itemId: 2, branch: 1),
            $this->line('4', '0', location: $this->shop, cost: '25', branch: 2),
        ]), 1));

        $this->assertSame(PostingError::TransferBranchesInvalid, $failure->error);
        $this->assertSame(['receiving_id' => 7, 'item_id' => 2], $failure->context);
    }

    public function testPurchase_RefusesAMoveBetweenBranchesThatPutsInAnItemItNeverTookOut(): void
    {
        $failure = $this->failureOf(fn () => $this->builder->purchase($this->betweenBranches([
            $this->line('-4', '0', cost: '25', branch: 1),
            $this->line('4', '0', location: $this->shop, cost: '25', itemId: 2, branch: 2),
        ]), 1));

        $this->assertContains($failure->error, [PostingError::TransferBranchesInvalid, PostingError::PurchaseNotPostable]);
    }

    public function testPurchase_RefusesAMoveBetweenBranchesWhenTheRoutingNamesNoAccountBetweenBranches(): void
    {
        foreach (['branches.receivable', 'branches.payable'] as $key) {
            $this->accountingDb()->table('accounting_routing')->where('routing_key', $key)->update(['account_id' => null]);

            $failure = $this->failureOf(fn () => (new ReceivingPostingBuilder(new RoutingResolver(), new StockLedger()))->purchase($this->betweenBranches([
                $this->line('-4', '0', cost: '25', branch: 1),
                $this->line('4', '0', location: $this->shop, cost: '25', branch: 2),
            ]), 1));

            $this->assertSame(PostingError::InterBranchAccountsMissing, $failure->error);

            $this->accountingDb()->table('accounting_routing')->where('routing_key', $key)->update(['account_id' => $this->accountId($key === 'branches.receivable' ? 'branches_receivable' : 'branches_payable')]);
        }
    }

    public function testIssueSourceOf_IsTheStockTransferOnlyForStockThatGoesToAnotherBranch(): void
    {
        $within  = $this->purchase([$this->line('-1', '0', cost: '5'), $this->line('1', '0', location: $this->shop, cost: '5')], ReceivingType::Requisition, supplierId: null);
        $between = $this->betweenBranches([$this->line('-1', '0', cost: '5', branch: 1), $this->line('1', '0', location: $this->shop, cost: '5', branch: 2)]);

        $this->assertSame('purchase', ReceivingPostingBuilder::issueSourceOf($this->purchase([$this->line('1', '10')])));
        $this->assertSame('purchase', ReceivingPostingBuilder::issueSourceOf($within));
        $this->assertSame('stock_transfer', ReceivingPostingBuilder::issueSourceOf($between));
    }

    public function testPayment_DebitsTheSupplierAndCreditsTheTreasuryForAPaidPurchase(): void
    {
        $draft = $this->builder->payment($this->purchase([$this->line('20', '40', '15')], payment: lang('Sales.cash')), 1, 5);

        $this->assertSame(
            [
                $this->supplierAccount() . ':920.0000:0.0000',
                $this->accountId('cash') . ':0.0000:920.0000',
            ],
            $this->shape($draft),
        );
        $this->assertSame('purchase_payment', $draft->sourceType);
        $this->assertSame(7, $draft->sourceId);
    }

    public function testPayment_MirrorsAPaymentForAReturnPaidBackAtOnce(): void
    {
        $origin = $this->origin([$this->line('20', '65.70')]);
        $draft  = $this->builder->payment($this->purchase([$this->line('-2', '70', '15', cost: '66')], ReceivingType::Return, lang('Sales.cash'), origin: $origin), 1);

        $this->assertSame(
            [
                $this->accountId('cash') . ':161.0000:0.0000',
                $this->supplierAccount() . ':0.0000:161.0000',
            ],
            $this->shape($draft),
        );
    }

    public function testPayment_IsNothingForAPurchaseLeftOnCreditWithoutASupplierAndForATransfer(): void
    {
        $this->assertNull($this->builder->payment($this->purchase([$this->line('1', '10')]), 1));
        $this->assertNull($this->builder->payment($this->purchase([$this->line('1', '10')], payment: lang('Sales.cash'), supplierId: null), 1));
        $this->assertNull($this->builder->payment($this->purchase([$this->line('-1', '0', cost: '5'), $this->line('1', '0', location: $this->shop, cost: '5')], ReceivingType::Requisition, lang('Sales.cash')), 1));
    }

    public function testPayment_IsNothingForAPurchaseThatCostsNothing(): void
    {
        $this->assertNull($this->builder->payment($this->purchase([$this->line('5', '0')], payment: lang('Sales.cash')), 1));
    }

    public function testPayment_RefusesATypeAPurchaseCannotBePaidWith(): void
    {
        $failure = $this->failureOf(fn () => $this->builder->payment($this->purchase([$this->line('1', '10')], payment: lang('Sales.giftcard')), 1));

        $this->assertSame(PostingError::PaymentTypeUnknown, $failure->error);
    }

    public function testTotals_KeepTheTaxUnroundedAndTheTotalToTwoDecimals(): void
    {
        $totals = $this->builder->totals($this->purchase([$this->line('1', '0.70', '15'), $this->line('1', '9.00', itemId: 2)]));

        $this->assertSame(['net' => '9.7000', 'tax' => '0.1050', 'total' => '9.81'], $totals);
    }

    public function testPurchase_ConvertsAPurchaseInDollarsToTheBaseAndWorthsTheStockWhatItsLineIsWorth(): void
    {
        $purchase = $this->purchase([$this->line('2', '50.00', itemId: 1), $this->line('3', '33.33', itemId: 2), $this->line('1', '20.00', itemId: 3, stock: false)], currency: $this->dollars());

        $plan = $this->builder->purchase($purchase, 1);

        $this->assertSame([
            $this->stockAccount($this->warehouse) . ':749.9600:0.0000',
            $this->accountId('cogs') . ':75.0000:0.0000',
            $this->supplierAccount() . ':0.0000:824.9600',
        ], array_map(static fn ($line): string => $line->accountId . ':' . bcadd($line->debit, '0', 4) . ':' . bcadd($line->credit, '0', 4), $plan->journal->lines));
        $this->assertSame(['USD', '3.75000000'], [$plan->journal->currencyCode, $plan->journal->exchangeRate]);
    }

    public function testPurchase_KeepsTheMovementsOfALocationAddingUpToItsLineInTheBase(): void
    {
        $purchase = $this->purchase([$this->line('1', '33.33', itemId: 1), $this->line('1', '33.33', itemId: 2), $this->line('1', '33.34', itemId: 3)], currency: $this->dollars('1.00500000'));

        $plan  = $this->builder->purchase($purchase, 1);
        $stock = $this->stockAccount($this->warehouse);
        $line  = '0';

        foreach ($plan->journal->lines as $each) {
            if ($each->accountId === $stock) {
                $line = $each->debit;
            }
        }

        $sum = array_reduce($plan->movements, static fn (string $carry, StockMovement $movement): string => bcadd($carry, $movement->value, 4), '0');

        $this->assertSame(bcadd($line, '0', 4), $sum);
        $this->assertCount(3, $plan->movements);
    }

    public function testPurchase_KeepsThePurchaseOfABaseSupplierWithoutForeignAmounts(): void
    {
        $plan = $this->builder->purchase($this->purchase([$this->line('2', '50.00')]), 1);

        $this->assertSame('', $plan->journal->currencyCode);
        $this->assertSame([null, null], [$plan->journal->lines[0]->foreignAmount, $plan->journal->lines[0]->currencyCode]);
    }

    public function testPurchase_RefusesTheTransferOfStockInAForeignCurrency(): void
    {
        $failure = $this->failureOf(fn () => $this->builder->purchase($this->purchase([$this->line('-1', '10.00', location: $this->warehouse), $this->line('1', '10.00', location: $this->shop)], ReceivingType::Requisition, currency: $this->dollars()), 1));

        $this->assertSame(PostingError::FxNotSupported, $failure->error);
    }

    public function testPurchase_GivesBackTheGoodsAtTheirStockValueAndTheSupplierBalanceAtTheRateOfThePurchase(): void
    {
        $origin = $this->origin([$this->line('4', '25.00')]);
        $return = $this->purchase([$this->line('-2', '25.00')], ReceivingType::Return, origin: $origin, currency: $this->dollars());

        $plan = $this->builder->purchase($return, 1);

        $this->assertSame($this->supplierAccount(), $plan->journal->lines[0]->accountId);
        $this->assertSame('187.5000', bcadd($plan->journal->lines[0]->debit, '0', 4));
        $this->assertSame(['50.0000', 'USD'], [$plan->journal->lines[0]->foreignAmount, $plan->journal->lines[0]->currencyCode]);
        $this->assertSame('', $plan->journal->currencyCode, 'The journal of the return is kept in the base: the goods leave at their cost');
    }

    public function testPayment_PostsThePaymentAtOnceAtTheRateOfThePurchase(): void
    {
        $purchase = $this->purchase([$this->line('2', '50.00')], payment: lang('Sales.cash'), currency: $this->dollars());

        $draft = $this->builder->payment($purchase, 1, 5);

        $this->assertSame([$this->supplierAccount() . ':375.0000:0.0000', $this->accountId('cash') . ':0.0000:375.0000'], $this->shape($draft));
        $this->assertSame(['USD', '3.75000000'], [$draft->currencyCode, $draft->exchangeRate]);
    }

    public function testSupplierPayment_PostsTheMoneyAtTheRateOfThePaymentWithTheForeignAmountOnBothLines(): void
    {
        $payment  = $this->supplierPayment(3, '200.00', '3.60000000');
        $purchase = $this->purchase([$this->line('4', '50.00')], currency: $this->dollars(), payments: [$payment]);

        $draft = $this->builder->supplierPayment($purchase, $payment, 1);

        $this->assertSame([$this->supplierAccount() . ':720.0000:0.0000', $this->accountId('cash') . ':0.0000:720.0000'], $this->shape($draft));
        $this->assertSame(['200.0000', 'USD'], [$draft->lines[0]->foreignAmount, $draft->lines[0]->currencyCode]);
        $this->assertSame('3.60000000', $draft->exchangeRate);
    }

    public function testSupplierPaymentDifference_PostsTheObservedGainOfThirtyWhenTheDollarCostsLess(): void
    {
        $payment  = $this->supplierPayment(3, '200.00', '3.60000000');
        $purchase = $this->purchase([$this->line('4', '50.00')], currency: $this->dollars(), payments: [$payment]);

        $draft = $this->builder->supplierPaymentDifference($purchase, $payment, 1, 5);

        $this->assertSame([$this->supplierAccount() . ':30.0000:0.0000', $this->accountId('fx_gain') . ':0.0000:30.0000'], $this->shape($draft));
        $this->assertSame('supplier_payment_fx', $draft->sourceType);
        $this->assertSame(3, $draft->sourceId);
    }

    public function testSupplierPaymentDifference_PostsTheLossWhenTheDollarCostsMore(): void
    {
        $payment  = $this->supplierPayment(3, '200.00', '3.90000000');
        $purchase = $this->purchase([$this->line('4', '50.00')], currency: $this->dollars(), payments: [$payment]);

        $draft = $this->builder->supplierPaymentDifference($purchase, $payment, 1);

        $this->assertSame([$this->accountId('fx_loss') . ':30.0000:0.0000', $this->supplierAccount() . ':0.0000:30.0000'], $this->shape($draft));
    }

    public function testSupplierPaymentDifference_IsNothingForABasePurchaseOrAPaymentAtTheRateOfThePurchase(): void
    {
        $payment = $this->supplierPayment(3, '200.00', '3.75000000');
        $foreign = $this->purchase([$this->line('4', '50.00')], currency: $this->dollars(), payments: [$payment]);
        $base    = $this->purchase([$this->line('4', '50.00')], payments: [$this->supplierPayment(4, '200.00', null)]);

        $this->assertNull($this->builder->supplierPaymentDifference($foreign, $payment, 1));
        $this->assertNull($this->builder->supplierPaymentDifference($base, $base->payments[0], 1));
    }

    public function testSupplierPaymentDifference_SettlesThePurchaseToTheCentAcrossPartPayments(): void
    {
        $payments = [$this->supplierPayment(1, '100.00', '3.75000000'), $this->supplierPayment(2, '100.00', '3.60000000'), $this->supplierPayment(3, '68.33', '3.90000000')];
        $purchase = $this->purchase([$this->line('1', '268.33')], currency: $this->dollars(), payments: $payments);

        $this->assertNull($this->builder->supplierPaymentDifference($purchase, $payments[0], 1));
        $this->assertSame([$this->supplierAccount() . ':15.0000:0.0000', $this->accountId('fx_gain') . ':0.0000:15.0000'], $this->shape($this->builder->supplierPaymentDifference($purchase, $payments[1], 1)));
        $this->assertSame([$this->accountId('fx_loss') . ':10.2500:0.0000', $this->supplierAccount() . ':0.0000:10.2500'], $this->shape($this->builder->supplierPaymentDifference($purchase, $payments[2], 1)));
    }

    public function testSupplierPayment_RefusesAPaymentThatHasNoRate(): void
    {
        $payment  = $this->supplierPayment(3, '200.00', null);
        $purchase = $this->purchase([$this->line('4', '50.00')], currency: $this->dollars(), payments: [$payment]);

        $failure = $this->failureOf(fn () => $this->builder->supplierPayment($purchase, $payment, 1));

        $this->assertSame(PostingError::ForeignRateMismatch, $failure->error);
    }

    public function testSupplierPaymentDifference_CountsWhatWasPaidAtOnceAmongThePaymentsOfThePurchase(): void
    {
        $refund   = $this->supplierPayment(3, '-50.00', '3.60000000');
        $purchase = $this->purchase([$this->line('4', '50.00')], payment: lang('Sales.cash'), currency: $this->dollars(), payments: [$refund]);

        $draft = $this->builder->supplierPaymentDifference($purchase, $refund, 1);

        $this->assertSame([$this->accountId('fx_loss') . ':7.5000:0.0000', $this->supplierAccount() . ':0.0000:7.5000'], $this->shape($draft));
    }
}
