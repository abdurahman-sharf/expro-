<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\InvoiceDiscount;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\PaymentStatus;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\ReturnOverride;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\RoutingResolver;
use App\Libraries\Accounting\RoutingType;
use App\Libraries\Accounting\SaleDocument;
use App\Libraries\Accounting\SaleLine;
use App\Libraries\Accounting\SalePayment;
use App\Libraries\Accounting\SalePostingBuilder;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ParityFixture;

class SalePostingBuilderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private SalePostingBuilder $builder;
    private int $customerId;
    private int $locationId;

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
        $this->customerId = $this->insertCustomer();
        $this->locationId = $this->insertStockLocation();
        $entities->ensure(EntityAccountService::CUSTOMER, $this->customerId, 'Omar Khaled');
        $entities->ensure(EntityAccountService::STOCK_LOCATION, $this->locationId, 'Warehouse');

        $this->builder = $this->builder();
    }

    protected function tearDown(): void
    {
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function builder(): SalePostingBuilder
    {
        return new SalePostingBuilder(new RoutingResolver(), 2);
    }

    /**
     * @param array<int, string> $accounts
     */
    private function line(string $quantity, string $price, string $discount = '0', int $discountType = PERCENT, string $cost = '0', bool $stock = true, string $added = '0', string $included = '0', int $itemId = 1, string $share = '0', array $accounts = []): SaleLine
    {
        return new SaleLine($itemId, $itemId, $quantity, $price, $discount, $discountType, $cost, $this->locationId, $stock, $included, $added, $share, $accounts);
    }

    /**
     * @param list<SaleLine>    $lines
     * @param list<SalePayment> $payments
     */
    private function sale(array $lines, array $payments = [], int $type = SALE_TYPE_INVOICE, ?int $customerId = -1, bool $delivered = true, ?SaleDocument $origin = null, bool $override = false, bool $refundInvoice = false): SaleDocument
    {
        return new SaleDocument(
            saleId: 7,
            date: '2026-10-21',
            saleType: $type,
            status: COMPLETED,
            customerId: $customerId === -1 ? $this->customerId : $customerId,
            branchId: 1,
            delivered: $delivered,
            returnOfSaleId: $origin?->saleId,
            lines: $lines,
            payments: $payments,
            origin: $origin,
            returnOverride: $override,
            refundInvoice: $refundInvoice,
        );
    }

    private function payment(string $type, string $amount, string $refund = '0', bool $adjustment = false, int $id = 1, PaymentStatus $status = PaymentStatus::Completed): SalePayment
    {
        return new SalePayment($id, $type, $amount, $refund, $adjustment, '2026-10-21', $status);
    }

    /**
     * @return list<string> "account:debit:credit" of each line, in order.
     */
    private function shape(?JournalDraft $draft, int $scale = 2): array
    {
        $this->assertNotNull($draft);

        return array_map(
            static fn ($line): string => $line->accountId . ':' . bcadd($line->debit, '0', $scale) . ':' . bcadd($line->credit, '0', $scale),
            $draft->lines,
        );
    }

    private function customerAccount(): int
    {
        return (int) (new EntityAccountService())->accountFor(EntityAccountService::CUSTOMER, $this->customerId);
    }

    private function locationAccount(): int
    {
        return (int) (new EntityAccountService())->accountFor(EntityAccountService::STOCK_LOCATION, $this->locationId);
    }

    private function setRouting(RoutingKey $key, RoutingType $type, ?string $systemKey = null): void
    {
        $values = ['routing_type' => $type->value];

        if ($systemKey !== null) {
            $values['account_id'] = $this->accountId($systemKey);
        }

        $this->accountingDb()->table('accounting_routing')->where('routing_key', $key->value)->update($values);
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

    public function testInvoice_DebitsTheCustomerTheWholeAmountAndCreditsRevenueNetOfDiscountAndTheTax(): void
    {
        $sale = $this->sale([
            $this->line('4', '75', '10', PERCENT, '40', added: '40.50'),
            $this->line('2', '220', cost: '120', added: '66.00', itemId: 2),
        ]);

        $draft = $this->builder->invoice($sale, 1, 5);

        $this->assertSame(
            [
                $this->customerAccount() . ':816.50:0.00',
                $this->accountId('sales_revenue') . ':0.00:710.00',
                $this->accountId('tax_output') . ':0.00:106.50',
            ],
            $this->shape($draft),
        );
        $this->assertSame('sale', $draft->sourceType);
        $this->assertSame(7, $draft->sourceId);
        $this->assertSame(JournalEntryType::Revenue, $draft->entryType);
        $this->assertSame('2026-10-21', $draft->date);
        $this->assertSame(1, $draft->branchId);
        $this->assertSame(5, $draft->employeeId);
    }

    public function testInvoice_RoundsTheTotalAndKeepsTheTaxAsItWasCalculated(): void
    {
        $draft = $this->builder->invoice($this->sale([$this->line('1', '0.70', added: '0.105')]), 1);

        $this->assertSame(
            [
                $this->customerAccount() . ':0.8100:0.0000',
                $this->accountId('sales_revenue') . ':0.0000:0.7050',
                $this->accountId('tax_output') . ':0.0000:0.1050',
            ],
            $this->shape($draft, 4),
        );
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function exactRoundingProvider(): array
    {
        return array_filter(ParityFixture::cases('T-04', 'rounding'), static fn (array $set): bool => $set[0]['classification'] === 'exact');
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function oneHalalaRoundingProvider(): array
    {
        return array_filter(ParityFixture::cases('T-04', 'rounding'), static fn (array $set): bool => $set[0]['classification'] === 'one_halala');
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('exactRoundingProvider')]
    public function testInvoice_JournalsTheTotalAndTheRevenueTheReferenceSystemStoredForTheSameInvoice(array $case): void
    {
        $draft = $this->builder->invoice($this->sale([$this->line('1', $case['inputs']['net'], added: $case['inputs']['tax'])]), 1);

        $this->assertSame(
            [
                $this->customerAccount() . ':' . bcadd($case['reference']['total'], '0', 4) . ':0.0000',
                $this->accountId('sales_revenue') . ':0.0000:' . bcadd($case['reference']['revenue'], '0', 4),
                $this->accountId('tax_output') . ':0.0000:' . bcadd($case['inputs']['tax'], '0', 4),
            ],
            $this->shape($draft, 4),
            $case['id'],
        );
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('oneHalalaRoundingProvider')]
    public function testInvoice_JournalsOneHalalaMoreThanTheReferenceSystemWhereTheTotalFallsExactlyBetweenTwoHalalas(array $case): void
    {
        $draft = $this->builder->invoice($this->sale([$this->line('1', $case['inputs']['net'], added: $case['inputs']['tax'])]), 1);
        $lines = $this->shape($draft, 4);

        $this->assertSame(
            [
                $this->customerAccount() . ':' . bcadd($case['expected']['total'], '0', 4) . ':0.0000',
                $this->accountId('sales_revenue') . ':0.0000:' . bcadd($case['expected']['revenue'], '0', 4),
                $this->accountId('tax_output') . ':0.0000:' . bcadd($case['inputs']['tax'], '0', 4),
            ],
            $lines,
            $case['id'],
        );
        $this->assertNotSame($this->customerAccount() . ':' . bcadd($case['reference']['total'], '0', 4) . ':0.0000', $lines[0], "{$case['id']} must not be counted as matching");
        $this->assertSame('0.0100', bcsub($draft->lines[0]->debit, $case['reference']['total'], 4), $case['id']);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function fixedDiscountJournalProvider(): array
    {
        return ParityFixture::cases('T-02', 'journal');
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('fixedDiscountJournalProvider')]
    public function testInvoice_JournalsTheInvoiceWithAFixedDiscountTheReferenceSystemRecorded(array $case): void
    {
        $this->assertSame('exact', $case['classification'], $case['id']);
        $this->assertSame($case['reference'], $case['expected'], $case['id']);

        $lines = array_map(
            fn (array $line): SaleLine => $this->line($line['quantity'], $line['price'], $line['discount'], $line['discount_type'] === 'fixed' ? FIXED : PERCENT, added: $line['tax']),
            $case['inputs']['lines'],
        );
        $accounts = ['customer' => $this->customerAccount(), 'sales_revenue' => $this->accountId('sales_revenue'), 'tax_output' => $this->accountId('tax_output')];

        $this->assertSame(
            array_map(static fn (array $line): string => $accounts[$line[0]] . ':' . $line[1] . ':' . $line[2], $case['expected']['lines']),
            $this->shape($this->builder->invoice($this->sale($lines), 1)),
            $case['id'],
        );
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invoiceDiscountProvider(): array
    {
        return ParityFixture::cases('T-04', 'invoice_discount');
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('invoiceDiscountProvider')]
    public function testInvoice_JournalsTheInvoiceWithADiscountOnTheWholeOfItAsTheReferenceSystemRecordedIt(array $case): void
    {
        $rows = [];

        foreach ($case['inputs']['lines'] as $index => $line) {
            $rows[$index + 1] = ['item_id' => $index + 1, 'quantity' => $line['quantity'], 'price' => $line['price'], 'discount' => $line['discount'], 'discount_type' => PERCENT];
        }

        $discount = $case['inputs']['invoice_discount'];
        $plan     = InvoiceDiscount::forSale($rows, $discount['type'] === 'fixed' ? FIXED : PERCENT, $discount['value'], 2);
        $bases    = InvoiceDiscount::basesOf($rows, 2, false);
        $lines    = [];

        foreach ($case['inputs']['lines'] as $index => $line) {
            $key     = $index + 1;
            $tax     = bcmul(bcsub($bases[$key], $plan->share($key), 4), bcdiv($case['inputs']['tax_rate'], '100', 8), 8);
            $lines[] = $this->line($line['quantity'], $line['price'], $line['discount'], PERCENT, added: $tax, itemId: $key, share: $plan->share($key));
        }

        $draft = $this->builder->invoice($this->sale($lines), 1);

        $this->assertSame(
            [
                $this->customerAccount() . ':' . bcadd($case['expected']['total'], '0', 4) . ':0.0000',
                $this->accountId('sales_revenue') . ':0.0000:' . bcadd($case['expected']['revenue'], '0', 4),
                $this->accountId('tax_output') . ':0.0000:' . bcadd($case['expected']['tax'], '0', 4),
            ],
            $this->shape($draft, 4),
            $case['id'],
        );
        $this->assertSame($case['classification'] === 'exact' ? '0.0000' : '0.0100', bcsub($draft->lines[0]->debit, $case['reference']['total'], 4), "{$case['id']}: only the half-halala case may differ from the reference system");
        $this->assertCount(3, $draft->lines, 'the discount on the whole of the invoice has no line of its own');
    }

    public function testInvoice_PostsNoLineForTheDiscountOnTheWholeInvoiceEvenWhenTheDiscountAccountIsRouted(): void
    {
        $this->setRouting(RoutingKey::SalesDiscount, RoutingType::Auto);

        $draft = $this->builder->invoice($this->sale([$this->line('4', '75', '10', PERCENT, added: '36.45', share: '27')]), 1);

        $this->assertSame(
            [
                $this->customerAccount() . ':310.50:0.00',
                $this->accountId('sales_discount') . ':30.00:0.00',
                $this->accountId('sales_revenue') . ':0.00:300.00',
                $this->accountId('tax_output') . ':0.00:40.50',
            ],
            $this->shape($this->builder->invoice($this->sale([$this->line('4', '75', '10', PERCENT, added: '40.50')]), 1)),
            'without the discount on the whole invoice the line discount goes to its account',
        );
        $this->assertSame(
            [
                $this->customerAccount() . ':279.45:0.00',
                $this->accountId('sales_discount') . ':30.00:0.00',
                $this->accountId('sales_revenue') . ':0.00:273.00',
                $this->accountId('tax_output') . ':0.00:36.45',
            ],
            $this->shape($draft),
            'the discount on the whole invoice only lowers the revenue',
        );
    }

    public function testInvoice_MirrorsTheDiscountOnTheWholeInvoiceInAReturn(): void
    {
        $origin = $this->sale([$this->line('4', '75', '10', PERCENT, '40', added: '36.45', share: '27')]);
        $return = $this->sale([$this->line('-1', '75', '10', PERCENT, '40', added: '-9.1125', share: '-6.75')], [], SALE_TYPE_RETURN, -1, true, $origin);

        $this->assertSame(
            [
                $this->accountId('sales_returns') . ':60.7475:0.0000',
                $this->accountId('tax_output') . ':9.1125:0.0000',
                $this->customerAccount() . ':0.0000:69.8600',
            ],
            $this->shape($this->builder->invoice($return, 1), 4),
        );
    }

    public function testInvoice_TakesTheDiscountOnTheWholeInvoiceOffTheRevenueWhenTheTaxIsInsideThePrices(): void
    {
        $builder = $this->builder;
        $draft   = $builder->invoice($this->sale([$this->line('1', '115', included: '13.50', share: '11.50')]), 1);

        $this->assertSame(
            [
                $this->customerAccount() . ':103.50:0.00',
                $this->accountId('sales_revenue') . ':0.00:90.00',
                $this->accountId('tax_output') . ':0.00:13.50',
            ],
            $this->shape($draft),
        );
    }

    public function testInvoice_JournalsTheSameWhenTheShareIsSpelledAsNothingAsWhenThereIsNone(): void
    {
        foreach ([['0'], ['0.0000'], ['-0.0000']] as [$share]) {
            $draft = $this->builder->invoice($this->sale([
                $this->line('4', '75', '10', PERCENT, '40', added: '40.50', share: $share),
                $this->line('2', '220', cost: '120', added: '66.00', itemId: 2, share: $share),
            ]), 1);

            $this->assertSame(
                [$this->customerAccount() . ':816.50:0.00', $this->accountId('sales_revenue') . ':0.00:710.00', $this->accountId('tax_output') . ':0.00:106.50'],
                $this->shape($draft),
            );
        }
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function everyReferenceInvoiceProvider(): array
    {
        return ParityFixture::cases('T-02', 'journal') + ParityFixture::cases('T-04', 'rounding');
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('everyReferenceInvoiceProvider')]
    public function testInvoice_JournalsEveryInvoiceOfTheReferenceFixturesTheSameWithAndWithoutAnEmptyDiscountOnTheWholeInvoice(array $case): void
    {
        $lines = $case['kind'] === 'journal'
            ? array_map(fn (array $line): array => [$line['quantity'], $line['price'], $line['discount'], $line['discount_type'] === 'fixed' ? FIXED : PERCENT, $line['tax']], $case['inputs']['lines'])
            : [['1', $case['inputs']['net'], '0', PERCENT, $case['inputs']['tax']]];

        $without = $with = [];

        foreach ($lines as $index => [$quantity, $price, $discount, $type, $tax]) {
            $without[] = $this->line($quantity, $price, $discount, $type, added: $tax, itemId: $index + 1);
            $with[]    = $this->line($quantity, $price, $discount, $type, added: $tax, itemId: $index + 1, share: '0.0000');
        }

        $this->assertSame($this->shape($this->builder->invoice($this->sale($without), 1), 4), $this->shape($this->builder->invoice($this->sale($with), 1), 4), $case['id']);
    }

    public function testInvoice_TakesTheTaxThatWasInsideThePricesOutOfTheRevenue(): void
    {
        $builder = $this->builder;
        $draft   = $builder->invoice($this->sale([$this->line('1', '115', included: '15.00')]), 1);

        $this->assertSame(
            [
                $this->customerAccount() . ':115.00:0.00',
                $this->accountId('sales_revenue') . ':0.00:100.00',
                $this->accountId('tax_output') . ':0.00:15.00',
            ],
            $this->shape($draft),
        );
    }

    public function testInvoice_LeavesOutTheTaxLineWhenThereIsNoTax(): void
    {
        $draft = $this->builder->invoice($this->sale([$this->line('3', '100')]), 1);

        $this->assertSame(
            [$this->customerAccount() . ':300.00:0.00', $this->accountId('sales_revenue') . ':0.00:300.00'],
            $this->shape($draft),
        );
    }

    public function testInvoice_TakesAFixedDiscountFromEveryUnit(): void
    {
        $draft = $this->builder->invoice($this->sale([$this->line('3', '75', '10', FIXED, added: '29.25')]), 1);

        $this->assertSame(
            [
                $this->customerAccount() . ':224.25:0.00',
                $this->accountId('sales_revenue') . ':0.00:195.00',
                $this->accountId('tax_output') . ':0.00:29.25',
            ],
            $this->shape($draft),
        );
    }

    public function testInvoice_PostsTheDiscountToItsOwnAccountWhenTheRowIsAutomatic(): void
    {
        $this->setRouting(RoutingKey::SalesDiscount, RoutingType::Auto);

        $draft = $this->builder->invoice($this->sale([$this->line('4', '75', '10', PERCENT, added: '40.50')]), 1);

        $this->assertSame(
            [
                $this->customerAccount() . ':310.50:0.00',
                $this->accountId('sales_discount') . ':30.00:0.00',
                $this->accountId('sales_revenue') . ':0.00:300.00',
                $this->accountId('tax_output') . ':0.00:40.50',
            ],
            $this->shape($draft),
        );
    }

    public function testInvoice_RefusesADiscountAccountWhenThePricesIncludeTheTax(): void
    {
        $this->setRouting(RoutingKey::SalesDiscount, RoutingType::Auto);

        $failure = $this->failureOf(fn () => $this->builder()->invoice($this->sale([$this->line('4', '75', '10', PERCENT, included: '39.13')]), 1));

        $this->assertSame(PostingError::DiscountNeedsAdditiveTax, $failure->error);
    }

    public function testInvoice_ReturnsNothingForASaleThatComesToNothing(): void
    {
        $this->assertNull($this->builder->invoice($this->sale([$this->line('1', '0')]), 1));
    }

    public function testInvoice_MirrorsAReturnOntoTheReturnsAccountAndTheTax(): void
    {
        $draft = $this->builder->invoice($this->sale([$this->line('-1', '220', added: '33.00')], type: SALE_TYPE_RETURN), 1);

        $this->assertSame(
            [
                $this->accountId('sales_returns') . ':220.00:0.00',
                $this->accountId('tax_output') . ':33.00:0.00',
                $this->customerAccount() . ':0.00:253.00',
            ],
            $this->shape($draft),
        );
    }

    public function testInvoice_UsesTheCounterCustomersWhenTheSaleHasNoCustomer(): void
    {
        $draft = $this->builder->invoice($this->sale([$this->line('1', '50')], customerId: null), 1);

        $this->assertSame($this->accountId('counter_customers') . ':50.00:0.00', $this->shape($draft)[0]);
    }

    public function testInvoice_RefusesAQuantityThatDoesNotFitTheKindOfSale(): void
    {
        $sale = $this->failureOf(fn () => $this->builder->invoice($this->sale([$this->line('-1', '50')]), 1));
        $back = $this->failureOf(fn () => $this->builder->invoice($this->sale([$this->line('1', '50')], type: SALE_TYPE_RETURN), 1));

        $this->assertSame(PostingError::SaleNotPostable, $sale->error);
        $this->assertSame(PostingError::SaleNotPostable, $back->error);
    }

    public function testInvoice_RefusesARevenueRowThatIsCancelled(): void
    {
        $this->setRouting(RoutingKey::SalesRevenue, RoutingType::Cancelled);

        $failure = $this->failureOf(fn () => $this->builder->invoice($this->sale([$this->line('1', '50')]), 1));

        $this->assertSame(PostingError::RoutingRowCancelled, $failure->error);
        $this->assertSame('sales.revenue', $failure->context['routing_key']);
    }

    public function testInvoice_RefusesARowSavedWithATypePostingDoesNotApply(): void
    {
        $this->setRouting(RoutingKey::Customers, RoutingType::MainPerEntity);

        $failure = $this->failureOf(fn () => $this->builder->invoice($this->sale([$this->line('1', '50')]), 1));

        $this->assertSame(PostingError::RoutingTypeUnsupported, $failure->error);
    }

    public function testInvoice_RefusesTheSettlementRowWhenItIsAutomaticBecauseNothingFeedsIt(): void
    {
        $this->setRouting(RoutingKey::SalesAdjustment, RoutingType::Auto);

        $failure = $this->failureOf(fn () => $this->builder->invoice($this->sale([$this->line('1', '50')]), 1));

        $this->assertSame(PostingError::RoutingTypeUnsupported, $failure->error);
        $this->assertSame('sales.adjustment', $failure->context['routing_key']);
    }

    public function testInvoice_RefusesACustomerWithoutAnAccount(): void
    {
        $other = $this->insertCustomer('No', 'Account');

        $failure = $this->failureOf(fn () => $this->builder->invoice($this->sale([$this->line('1', '50')], customerId: $other), 1));

        $this->assertSame(PostingError::EntityAccountMissing, $failure->error);
    }

    public function testCost_DebitsCostOfSalesAndCreditsTheStockOfTheLocation(): void
    {
        $sale = $this->sale([
            $this->line('4', '75', cost: '40'),
            $this->line('2', '220', cost: '120', itemId: 2),
        ]);

        $draft = $this->builder->cost($sale, 1, 5);

        $this->assertSame([$this->accountId('cogs') . ':400.00:0.00', $this->locationAccount() . ':0.00:400.00'], $this->shape($draft));
        $this->assertSame(JournalEntryType::Cogs, $draft->entryType);
        $this->assertSame('sale', $draft->sourceType);
    }

    public function testCost_SplitsTheStockCreditByLocation(): void
    {
        $second = $this->insertStockLocation('Second');
        (new EntityAccountService())->ensure(EntityAccountService::STOCK_LOCATION, $second, 'Second');

        $elsewhere = new SaleLine(2, 2, '1', '100', '0', PERCENT, '30', $second, true);
        $draft     = $this->builder->cost($this->sale([$this->line('2', '50', cost: '10'), $elsewhere]), 1);

        $this->assertSame(
            [
                $this->accountId('cogs') . ':50.00:0.00',
                $this->locationAccount() . ':0.00:20.00',
                (int) (new EntityAccountService())->accountFor(EntityAccountService::STOCK_LOCATION, $second) . ':0.00:30.00',
            ],
            $this->shape($draft),
        );
    }

    public function testCost_LeavesOutServicesAndPostsNothingWhenThereIsNoStockCost(): void
    {
        $this->assertNull($this->builder->cost($this->sale([$this->line('1', '100', cost: '0', stock: false)]), 1));
        $this->assertNull($this->builder->cost($this->sale([$this->line('1', '100', cost: '40', stock: false)]), 1));
        $this->assertNull($this->builder->cost($this->sale([$this->line('1', '100', cost: '0')]), 1));
    }

    public function testCost_WaitsForTheDelivery(): void
    {
        $this->assertNull($this->builder->cost($this->sale([$this->line('1', '100', cost: '40')], delivered: false), 1));
    }

    public function testCost_PostsNothingWhenTheCostOfSalesRowIsCancelled(): void
    {
        $this->setRouting(RoutingKey::CostOfGoodsSold, RoutingType::Cancelled);

        $this->assertNull($this->builder->cost($this->sale([$this->line('1', '100', cost: '40')]), 1));
    }

    public function testCost_TakesAReturnedItemBackAtTheCostOfTheOriginalSale(): void
    {
        $origin = $this->sale([$this->line('5', '100', cost: '60')]);
        $return = $this->sale([$this->line('-2', '100', cost: '66')], type: SALE_TYPE_RETURN, origin: $origin);

        $draft = $this->builder->cost($return, 1);

        $this->assertSame([$this->locationAccount() . ':120.00:0.00', $this->accountId('cogs') . ':0.00:120.00'], $this->shape($draft));
    }

    public function testCost_AveragesTheOriginalLinesOfTheItemByQuantity(): void
    {
        $origin = $this->sale([$this->line('1', '100', cost: '50'), $this->line('3', '100', cost: '70')]);
        $return = $this->sale([$this->line('-2', '100')], type: SALE_TYPE_RETURN, origin: $origin);

        $draft = $this->builder->cost($return, 1);

        $this->assertSame([$this->locationAccount() . ':130.00:0.00', $this->accountId('cogs') . ':0.00:130.00'], $this->shape($draft));
    }

    public function testCost_RefusesAReturnOfStockThatNamesNoSale(): void
    {
        $failure = $this->failureOf(fn () => $this->builder->cost($this->sale([$this->line('-1', '100', cost: '66')], type: SALE_TYPE_RETURN), 1));

        $this->assertSame(PostingError::ReturnOriginRequired, $failure->error);
    }

    public function testCost_RefusesAnItemThatWasNotOnTheSaleItComesFrom(): void
    {
        $origin = $this->sale([$this->line('5', '100', cost: '60', itemId: 1)]);

        $failure = $this->failureOf(fn () => $this->builder->cost($this->sale([$this->line('-1', '100', itemId: 9)], type: SALE_TYPE_RETURN, origin: $origin), 1));

        $this->assertSame(PostingError::ReturnLineNotOnOrigin, $failure->error);
        $this->assertSame(9, $failure->context['item_id']);
    }

    public function testCost_RefusesAReturnOfASaleThatIsNotCompleteAndARefundInvoiceOfOneThatWasNotDelivered(): void
    {
        $undelivered = $this->sale([$this->line('5', '100', cost: '60')], delivered: false);
        $cancelled   = new SaleDocument(8, '2026-10-20', SALE_TYPE_INVOICE, CANCELED, $this->customerId, 1, true, null, [$this->line('5', '100', cost: '60')], []);
        $aReturn     = $this->sale([$this->line('-5', '100', cost: '60')], type: SALE_TYPE_RETURN);

        $failure = $this->failureOf(fn () => $this->builder->cost($this->sale([$this->line('-1', '100')], type: SALE_TYPE_RETURN, origin: $undelivered, refundInvoice: true), 1));

        $this->assertSame(PostingError::ReturnOfUndelivered, $failure->error);

        foreach ([$cancelled, $aReturn] as $origin) {
            $failure = $this->failureOf(fn () => $this->builder->cost($this->sale([$this->line('-1', '100')], type: SALE_TYPE_RETURN, origin: $origin), 1));

            $this->assertSame(PostingError::ReturnOriginInvalid, $failure->error);
        }
    }

    public function testCostPlan_PostsNoCostAndMovesNoStockForACreditNoteOnASaleThatWasNotDelivered(): void
    {
        $undelivered = $this->sale([$this->line('5', '100', cost: '60')], delivered: false);

        $plan = $this->builder->costPlan($this->sale([$this->line('-2', '100', cost: '60')], type: SALE_TYPE_RETURN, origin: $undelivered), 1);

        $this->assertNull($plan->journal);
        $this->assertSame([], $plan->movements);
    }

    public function testCostPlan_StillPostsTheCostOfACreditNoteOnASaleThatWasDeliveredAtTheCostItWasSoldAt(): void
    {
        $delivered = $this->sale([$this->line('5', '100', cost: '60')]);

        $plan = $this->builder->costPlan($this->sale([$this->line('-2', '100', cost: '99')], type: SALE_TYPE_RETURN, origin: $delivered), 1);

        $this->assertSame([$this->locationAccount() . ':120.00:0.00', $this->accountId('cogs') . ':0.00:120.00'], $this->shape($plan->journal));
    }

    public function testCostPlan_TakesOutOnlyTheUnitsThatWereNotReturnedBeforeTheDelivery(): void
    {
        $sale = $this->sale([$this->line('5', '100', cost: '60')]);

        $plan = $this->builder->costPlan($sale->withReturnedUnits([1 => '2.000']), 1);

        $this->assertSame([$this->accountId('cogs') . ':180.00:0.00', $this->locationAccount() . ':0.00:180.00'], $this->shape($plan->journal));
        $this->assertSame(['-3.000'], array_map(static fn ($movement): string => $movement->quantity, $plan->movements));
        $this->assertSame(['-180.0000'], array_map(static fn ($movement): string => $movement->value, $plan->movements));
    }

    public function testCostPlan_SharesTheUnitsThatCameBackOverTheLinesOfTheItemInOrder(): void
    {
        $sale = $this->sale([$this->line('2', '100', cost: '60'), $this->line('4', '100', cost: '50')]);

        $plan = $this->builder->costPlan($sale->withReturnedUnits([1 => '3.000']), 1);

        $this->assertSame(['-3.000'], array_map(static fn ($movement): string => $movement->quantity, $plan->movements));
        $this->assertSame([$this->accountId('cogs') . ':150.00:0.00', $this->locationAccount() . ':0.00:150.00'], $this->shape($plan->journal));
    }

    public function testCostPlan_PostsNothingWhenEverythingCameBackBeforeTheDelivery(): void
    {
        $plan = $this->builder->costPlan($this->sale([$this->line('5', '100', cost: '60')])->withReturnedUnits([1 => '5.000']), 1);

        $this->assertNull($plan->journal);
        $this->assertSame([], $plan->movements);
    }

    public function testCostPlan_CostsAReturnLetThroughWithNoSaleAtTheCostItsLinesCarry(): void
    {
        $plan = $this->builder->costPlan($this->sale([$this->line('-3', '100', cost: '66')], type: SALE_TYPE_RETURN, override: true), 1);

        $this->assertSame([$this->locationAccount() . ':198.00:0.00', $this->accountId('cogs') . ':0.00:198.00'], $this->shape($plan->journal));
        $this->assertSame(['3'], array_map(static fn ($movement): string => $movement->quantity, $plan->movements));
    }

    public function testCostPlan_RefusesAReturnWithNoSaleThatNoManagerLetThrough(): void
    {
        $failure = $this->failureOf(fn () => $this->builder->costPlan($this->sale([$this->line('-3', '100', cost: '66')], type: SALE_TYPE_RETURN), 1));

        $this->assertSame(PostingError::ReturnOriginRequired, $failure->error);
    }

    public function testInvoice_MarksTheJournalOfAReturnThatWasLetThroughWithNoSaleAndOnlyThat(): void
    {
        $overridden = $this->builder->invoice($this->sale([$this->line('-1', '100')], type: SALE_TYPE_RETURN, override: true), 1);
        $plain      = $this->builder->invoice($this->sale([$this->line('-1', '100')], type: SALE_TYPE_RETURN), 1);
        $cost       = $this->builder->cost($this->sale([$this->line('-1', '100', cost: '60')], type: SALE_TYPE_RETURN, override: true), 1);

        $this->assertSame('Return #7 ' . ReturnOverride::MARKER, $overridden->description);
        $this->assertSame('Return #7', $plain->description);
        $this->assertSame('Cost of return #7 ' . ReturnOverride::MARKER, $cost->description);
    }

    public function testCost_NeedsNoOriginForAReturnOfServices(): void
    {
        $this->assertNull($this->builder->cost($this->sale([$this->line('-1', '100', stock: false)], type: SALE_TYPE_RETURN), 1));
    }

    public function testPayment_DebitsTheCashAccountAndCreditsTheCustomer(): void
    {
        $draft = $this->builder->payment($this->sale([$this->line('1', '100')]), $this->payment(lang('Sales.cash'), '300.00', id: 3), 1, 5);

        $this->assertSame([$this->accountId('cash') . ':300.00:0.00', $this->customerAccount() . ':0.00:300.00'], $this->shape($draft));
        $this->assertSame('sale_payment', $draft->sourceType);
        $this->assertSame(3, $draft->sourceId);
        $this->assertSame(JournalEntryType::General, $draft->entryType);
        $this->assertSame('2026-10-21', $draft->date);
    }

    public function testPayment_PostsChequesAndTransfersToTheSameAccountAsCash(): void
    {
        foreach ([lang('Sales.check'), lang('Sales.bank_transfer')] as $label) {
            $draft = $this->builder->payment($this->sale([$this->line('1', '100')]), $this->payment($label, '100.00'), 1);

            $this->assertSame([$this->accountId('cash') . ':100.00:0.00', $this->customerAccount() . ':0.00:100.00'], $this->shape($draft), $label);
        }
    }

    public function testPayment_TakesTheChangeGivenBackOffTheAmountReceived(): void
    {
        $draft = $this->builder->payment($this->sale([$this->line('1', '100')]), $this->payment(lang('Sales.cash'), '120.00', '20.00'), 1);

        $this->assertSame([$this->accountId('cash') . ':100.00:0.00', $this->customerAccount() . ':0.00:100.00'], $this->shape($draft));
    }

    public function testPayment_PostsNothingForACreditSaleOrAPaymentThatNetsToZero(): void
    {
        $sale = $this->sale([$this->line('1', '100')]);

        $this->assertNull($this->builder->payment($sale, $this->payment(lang('Sales.due'), '100.00'), 1));
        $this->assertNull($this->builder->payment($sale, $this->payment(lang('Sales.cash'), '20.00', '20.00'), 1));
    }

    public function testPayment_FollowsTheGiftCardLiabilityAndTheRewardsDiscount(): void
    {
        $sale = $this->sale([$this->line('1', '100')]);

        $giftcard = $this->builder->payment($sale, $this->payment(lang('Sales.giftcard') . ':1234', '40.00'), 1);
        $rewards  = $this->builder->payment($sale, $this->payment(lang('Sales.rewards'), '10.00', id: 2), 1);

        $this->assertSame($this->accountId('giftcard_liability') . ':40.00:0.00', $this->shape($giftcard)[0]);
        $this->assertSame($this->accountId('sales_discount') . ':10.00:0.00', $this->shape($rewards)[0]);
    }

    /**
     * @return array<string, array{PaymentStatus}>
     */
    public static function notCompletedProvider(): array
    {
        return [
            'incomplete' => [PaymentStatus::Incomplete],
            'review'     => [PaymentStatus::Review],
            'failed'     => [PaymentStatus::Failed],
            'draft'      => [PaymentStatus::Draft],
        ];
    }

    #[DataProvider('notCompletedProvider')]
    public function testPayment_PostsNothingForAPaymentThatIsNotCompleted(PaymentStatus $status): void
    {
        $sale = $this->sale([$this->line('1', '100')], [$this->payment(lang('Sales.cash'), '100', status: $status)]);

        $this->assertNull($this->builder->payment($sale, $sale->payments[0], 1));
    }

    public function testPayment_PostsAPaymentThatIsCompleted(): void
    {
        $sale = $this->sale([$this->line('1', '100')], [$this->payment(lang('Sales.cash'), '100', status: PaymentStatus::Completed)]);

        $this->assertNotNull($this->builder->payment($sale, $sale->payments[0], 1));
    }

    public function testPayment_DoesNotJudgeTheTypeOfAPaymentThatIsNotCompletedYet(): void
    {
        $sale = $this->sale([$this->line('1', '100')], [$this->payment('Barter', '100', status: PaymentStatus::Draft)]);

        $this->assertNull($this->builder->payment($sale, $sale->payments[0], 1));
    }

    public function testAssertSettled_CountsOnlyThePaymentsThatAreCompleted(): void
    {
        $unpaid = $this->sale([$this->line('1', '100')], [$this->payment(lang('Sales.cash'), '100', status: PaymentStatus::Review)], customerId: null);

        $this->expectException(PostingException::class);

        $this->builder->assertSettled($unpaid);
    }

    public function testPayment_RefusesAPaymentTypeItDoesNotKnow(): void
    {
        $failure = $this->failureOf(fn () => $this->builder->payment($this->sale([$this->line('1', '100')]), $this->payment('Barter', '10.00'), 1));

        $this->assertSame(PostingError::PaymentTypeUnknown, $failure->error);
        $this->assertSame('Barter', $failure->context['payment_type']);
    }

    public function testPayment_MirrorsARefundOntoTheCustomer(): void
    {
        $return = $this->sale([$this->line('-1', '100')], type: SALE_TYPE_RETURN);

        $draft = $this->builder->payment($return, $this->payment(lang('Sales.cash'), '-253.00'), 1);

        $this->assertSame([$this->customerAccount() . ':253.00:0.00', $this->accountId('cash') . ':0.00:253.00'], $this->shape($draft));
    }

    public function testPayment_SendsACashAdjustmentToTheCashDifferencesAccount(): void
    {
        $sale = $this->sale([$this->line('1', '100')]);

        $over  = $this->builder->payment($sale, $this->payment(lang('Sales.cash_adjustment'), '0.02', adjustment: true), 1);
        $under = $this->builder->payment($sale, $this->payment(lang('Sales.cash_adjustment'), '-0.03', adjustment: true, id: 2), 1);

        $this->assertSame([$this->customerAccount() . ':0.02:0.00', $this->accountId('cash_difference') . ':0.00:0.02'], $this->shape($over));
        $this->assertSame([$this->accountId('cash_difference') . ':0.03:0.00', $this->customerAccount() . ':0.00:0.03'], $this->shape($under));
    }

    public function testPayment_UsesTheCounterCustomersForASaleWithoutACustomer(): void
    {
        $draft = $this->builder->payment($this->sale([$this->line('1', '50')], customerId: null), $this->payment(lang('Sales.cash'), '50.00'), 1);

        $this->assertSame([$this->accountId('cash') . ':50.00:0.00', $this->accountId('counter_customers') . ':0.00:50.00'], $this->shape($draft));
    }

    public function testAssertSettled_AcceptsASaleWithoutACustomerThatIsPaidInFullCashRoundingIncluded(): void
    {
        $this->expectNotToPerformAssertions();

        $lines = [$this->line('1', '9.98')];

        $this->builder->assertSettled($this->sale($lines, [$this->payment(lang('Sales.cash'), '9.98')], customerId: null));
        $this->builder->assertSettled($this->sale($lines, [$this->payment(lang('Sales.cash'), '20.00', '10.02')], customerId: null));
        $this->builder->assertSettled($this->sale($lines, [$this->payment(lang('Sales.cash'), '10.00'), $this->payment(lang('Sales.cash_adjustment'), '0.02', adjustment: true, id: 2)], customerId: null));
    }

    public function testAssertSettled_RefusesASaleWithoutACustomerThatIsNotPaidInFull(): void
    {
        $lines = [$this->line('1', '100')];

        foreach ([[], [$this->payment(lang('Sales.cash'), '60.00')], [$this->payment(lang('Sales.due'), '100.00')], [$this->payment(lang('Sales.cash'), '100.00'), $this->payment(lang('Sales.cash'), '5.00', id: 2)]] as $payments) {
            $failure = $this->failureOf(fn () => $this->builder->assertSettled($this->sale($lines, $payments, customerId: null)));

            $this->assertSame(PostingError::CreditNeedsCustomer, $failure->error);
        }
    }

    public function testAssertSettled_LeavesACreditSaleToACustomerAlone(): void
    {
        $this->expectNotToPerformAssertions();

        $this->builder->assertSettled($this->sale([$this->line('1', '100')], [$this->payment(lang('Sales.cash'), '10.00')]));
    }

    public function testAssertSettled_ExpectsAReturnWithoutACustomerToBeRefundedInFull(): void
    {
        $return = fn (string $refund) => $this->sale([$this->line('-1', '100')], [$this->payment(lang('Sales.cash'), $refund)], type: SALE_TYPE_RETURN, customerId: null);

        $this->builder->assertSettled($return('-100.00'));

        $failure = $this->failureOf(fn () => $this->builder->assertSettled($return('-40.00')));

        $this->assertSame(PostingError::CreditNeedsCustomer, $failure->error);
    }

    public function testInvoice_PostsALineWithATaxInsideThePriceAndOneAddedToItAsTheNetAndTheWholeTax(): void
    {
        $draft = $this->builder->invoice($this->sale([$this->line('1', '100', included: '13.0435', added: '4.3478')]), 1);

        $this->assertSame(
            [
                $this->customerAccount() . ':104.3500:0.0000',
                $this->accountId('sales_revenue') . ':0.0000:86.9587',
                $this->accountId('tax_output') . ':0.0000:17.3913',
            ],
            $this->shape($draft, 4),
            'the total is rounded to the cent and the revenue is what is left of it after the tax (E14)',
        );
    }

    public function testInvoice_PostsTheTaxOfATaxWithAnAccountOfItsOwnToThatAccountAndTheRestToTheRoutedOne(): void
    {
        $account = $this->customAccount('215', '215003', 'Tax on services');
        $draft   = $this->builder->invoice($this->sale([$this->line('1', '100', included: '13.0435', added: '4.3478', accounts: [$account => '13.0435'])]), 1);

        $this->assertSame(
            [
                $this->customerAccount() . ':104.3500:0.0000',
                $this->accountId('sales_revenue') . ':0.0000:86.9587',
                $this->accountId('tax_output') . ':0.0000:4.3478',
                $account . ':0.0000:13.0435',
            ],
            $this->shape($draft, 4),
        );
    }

    public function testInvoice_PostsTheSameJournalAsBeforeWhenNoTaxHasAnAccountOfItsOwn(): void
    {
        $draft = $this->builder->invoice($this->sale([$this->line('4', '75', '10', PERCENT, added: '40.50'), $this->line('2', '220', added: '66.00', itemId: 2)]), 1);

        $this->assertSame(
            [
                $this->customerAccount() . ':816.5000:0.0000',
                $this->accountId('sales_revenue') . ':0.0000:710.0000',
                $this->accountId('tax_output') . ':0.0000:106.5000',
            ],
            $this->shape($draft, 4),
        );
    }

    public function testInvoice_PostsNoLineToTheRoutedAccountWhenEveryTaxHasAnAccountOfItsOwn(): void
    {
        $account = $this->customAccount('215', '215003', 'Tax on services');

        $draft = $this->builder->invoice($this->sale([$this->line('1', '115', included: '15.00', accounts: [$account => '15.00'])]), 1);

        $this->assertSame(
            [
                $this->customerAccount() . ':115.0000:0.0000',
                $this->accountId('sales_revenue') . ':0.0000:100.0000',
                $account . ':0.0000:15.0000',
            ],
            $this->shape($draft, 4),
        );
    }

    public function testInvoice_RefusesTheTaxThatTheRoutedAccountMustTakeWhenItsRoutingRowIsSavedWithATypePostingDoesNotApply(): void
    {
        $account = $this->customAccount('215', '215003', 'Tax on services');
        $this->setRouting(RoutingKey::TaxOutput, RoutingType::Cancelled);

        $failure = $this->failureOf(fn () => $this->builder->invoice($this->sale([$this->line('1', '100', included: '13.0435', added: '4.3478', accounts: [$account => '13.0435'])]), 1));

        $this->assertSame(PostingError::RoutingTypeUnsupported, $failure->error);
        $this->assertSame('tax.output', $failure->context['routing_key']);
    }

    public function testInvoice_RefusesTaxRowsWithAccountsThatAddUpToMoreThanTheTaxOfTheSale(): void
    {
        $account = $this->customAccount('215', '215003', 'Tax on services');

        $failure = $this->failureOf(fn () => $this->builder->invoice($this->sale([$this->line('1', '100', added: '15.00', accounts: [$account => '16.00'])]), 1));

        $this->assertSame(PostingError::TaxAccountsExceedTax, $failure->error);
    }

    public function testInvoice_SumsTheTaxOfEveryLineThatWentToTheSameAccount(): void
    {
        $account = $this->customAccount('215', '215003', 'Tax on services');
        $draft   = $this->builder->invoice($this->sale([
            $this->line('1', '100', added: '15.00', accounts: [$account => '15.00']),
            $this->line('1', '200', added: '30.00', itemId: 2, accounts: [$account => '30.00']),
        ]), 1);

        $this->assertSame(
            [
                $this->customerAccount() . ':345.0000:0.0000',
                $this->accountId('sales_revenue') . ':0.0000:300.0000',
                $account . ':0.0000:45.0000',
            ],
            $this->shape($draft, 4),
        );
    }

    public function testInvoice_PostsTheTaxOfSeveralAccountsInTheOrderOfTheAccounts(): void
    {
        $first  = $this->customAccount('215', '215003', 'Tax on services');
        $second = $this->customAccount('215', '215004', 'Tax on food');
        $draft  = $this->builder->invoice($this->sale([$this->line('1', '200', added: '30.00', accounts: [$second => '10.00', $first => '20.00'])]), 1);

        $this->assertSame(
            [
                $this->customerAccount() . ':230.0000:0.0000',
                $this->accountId('sales_revenue') . ':0.0000:200.0000',
                $first . ':0.0000:20.0000',
                $second . ':0.0000:10.0000',
            ],
            $this->shape($draft, 4),
        );
    }

    public function testInvoice_PostsNoLineForATaxOfNothingThatHasAnAccountOfItsOwn(): void
    {
        $account = $this->customAccount('215', '215003', 'Tax on services');
        $draft   = $this->builder->invoice($this->sale([$this->line('1', '100', added: '15.00', accounts: [$account => '0.0000'])]), 1);

        $this->assertSame(
            [$this->customerAccount() . ':115.0000:0.0000', $this->accountId('sales_revenue') . ':0.0000:100.0000', $this->accountId('tax_output') . ':0.0000:15.0000'],
            $this->shape($draft, 4),
        );
    }

    public function testInvoice_MirrorsTheTaxAccountsOfTheSaleInAReturn(): void
    {
        $account = $this->customAccount('215', '215003', 'Tax on services');
        $origin  = $this->sale([$this->line('1', '100', included: '13.0435', added: '4.3478', accounts: [$account => '13.0435'])]);
        $return  = $this->sale([$this->line('-1', '100', included: '-13.0435', added: '-4.3478', accounts: [$account => '13.0435'])], type: SALE_TYPE_RETURN, origin: $origin);

        $this->assertSame(
            [
                $this->accountId('sales_returns') . ':86.9587:0.0000',
                $this->accountId('tax_output') . ':4.3478:0.0000',
                $account . ':13.0435:0.0000',
                $this->customerAccount() . ':0.0000:104.3500',
            ],
            $this->shape($this->builder->invoice($return, 1), 4),
            'a credit note takes back exactly what the invoice posted, to the accounts it posted it to',
        );
    }

    public function testInvoice_AcceptsADiscountAccountWhenOnlyAddedTaxesAreOnTheSale(): void
    {
        $this->setRouting(RoutingKey::SalesDiscount, RoutingType::Auto);

        $draft = $this->builder->invoice($this->sale([$this->line('4', '75', '10', PERCENT, added: '40.50')]), 1);

        $this->assertSame(
            [
                $this->customerAccount() . ':310.5000:0.0000',
                $this->accountId('sales_discount') . ':30.0000:0.0000',
                $this->accountId('sales_revenue') . ':0.0000:300.0000',
                $this->accountId('tax_output') . ':0.0000:40.5000',
            ],
            $this->shape($draft, 4),
        );
    }

    public function testInvoice_RefusesADiscountAccountWhenOneTaxOnTheSaleIsInsideThePrice(): void
    {
        $this->setRouting(RoutingKey::SalesDiscount, RoutingType::Auto);

        $failure = $this->failureOf(fn () => $this->builder->invoice($this->sale([
            $this->line('4', '75', '10', PERCENT, added: '10.00'),
            $this->line('1', '115', included: '15.00', itemId: 2),
        ]), 1));

        $this->assertSame(PostingError::DiscountNeedsAdditiveTax, $failure->error);
    }
}
