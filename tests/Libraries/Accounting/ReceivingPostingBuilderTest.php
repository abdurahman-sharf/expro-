<?php

namespace Tests\Libraries\Accounting;

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

    private function line(string $quantity, string $price, string $tax = '0', string $discount = '0', int $discountType = PERCENT, ?int $location = null, bool $stock = true, string $cost = '0', int $itemId = 1, string $perPack = '1'): ReceivingLine
    {
        return new ReceivingLine($itemId, $itemId, $quantity, $perPack, $price, $discount, $discountType, $tax, $location ?? $this->warehouse, $stock, $cost);
    }

    /**
     * @param list<ReceivingLine> $lines
     */
    private function purchase(array $lines, ReceivingType $type = ReceivingType::Receive, ?string $payment = null, ?int $supplierId = -1, ?ReceivingDocument $origin = null): ReceivingDocument
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
        );
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

    public function testPurchase_TakesAFixedDiscountOffTheLineAndNotOffEachUnit(): void
    {
        $plan = $this->builder->purchase($this->purchase([$this->line('10', '100', '0', '50', FIXED)]), 1);

        $this->assertSame(['1:' . $this->warehouse . ':10.000:950.0000'], $this->movements($plan->movements));
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
}
