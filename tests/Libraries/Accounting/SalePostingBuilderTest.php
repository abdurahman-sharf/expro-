<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\CurrencyCatalog;
use App\Libraries\Accounting\DocumentCurrency;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\InvoiceDiscount;
use App\Libraries\ResolvedCharges;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\PaymentStatus;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\ReturnOverride;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\RoutingResolver;
use App\Libraries\Accounting\RoutingType;
use App\Libraries\Accounting\SaleDocument;
use App\Libraries\Accounting\SaleLine;
use App\Libraries\Accounting\SalePayment;
use App\Libraries\Accounting\SalePostingBuilder;
use App\Libraries\Tax\VatCategoryTotal;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\ParityCaseTrait;
use Tests\Support\ParityFixture;

class SalePostingBuilderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ParityCaseTrait;
use ItemFixtureTrait;

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
     * @param list<SaleLine>                  $lines
     * @param list<SalePayment>               $payments
     * @param array<string, VatCategoryTotal> $vat
     */
    private function sale(array $lines, array $payments = [], int $type = SALE_TYPE_INVOICE, ?int $customerId = -1, bool $delivered = true, ?SaleDocument $origin = null, bool $override = false, bool $refundInvoice = false, ?ResolvedCharges $charges = null, ?DocumentCurrency $currency = null, array $vat = []): SaleDocument
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
            charges: $charges,
            currency: $currency,
            vat: $vat,
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

    public function testInvoice_PostsTheVatOfASaudiSaleOnItsCategoriesAndWhatThePricesLeaveAsRounding(): void
    {
        $lines = array_map(fn (int $item): SaleLine => $this->line('1', '0.99', included: '0.129', itemId: $item), range(1, 10));
        $draft = $this->builder->invoice($this->sale($lines, vat: ['S|15.000|' => new VatCategoryTotal('S', '15.000', null, null, '8.60', '1.29', true)]), 1);

        $this->assertSame(
            [
                $this->customerAccount() . ':9.90:0.00',
                $this->accountId('sales_revenue') . ':0.00:8.60',
                $this->accountId('tax_output') . ':0.00:1.29',
                $this->accountId('tax_rounding') . ':0.00:0.01',
            ],
            $this->shape($draft),
        );
    }

    public function testInvoice_TakesARoundingOfASaudiSaleAboveWhatThePricesComeToFromTheIncome(): void
    {
        $lines = array_map(fn (int $item): SaleLine => $this->line('1', '0.01', included: '0.0015', itemId: $item), range(1, 4));
        $draft = $this->builder->invoice($this->sale($lines, vat: ['S|15.000|' => new VatCategoryTotal('S', '15.000', null, null, '0.04', '0.01', true)]), 1);

        $this->assertSame(
            [
                $this->customerAccount() . ':0.04:0.00',
                $this->accountId('tax_rounding') . ':0.01:0.00',
                $this->accountId('sales_revenue') . ':0.00:0.04',
                $this->accountId('tax_output') . ':0.00:0.01',
            ],
            $this->shape($draft),
        );
    }

    public function testInvoice_PostsTheVatOfEachCategoryOfASaudiSaleToTheHalala(): void
    {
        $lines = array_map(fn (int $item): SaleLine => $this->line('1', '0.10', added: '0.015', itemId: $item), range(1, 3));
        $draft = $this->builder->invoice($this->sale($lines, vat: ['S|15.000|' => new VatCategoryTotal('S', '15.000', null, null, '0.30', '0.05', false)]), 1);

        $this->assertSame(
            [
                $this->customerAccount() . ':0.3500:0.0000',
                $this->accountId('sales_revenue') . ':0.0000:0.3000',
                $this->accountId('tax_output') . ':0.0000:0.0500',
            ],
            $this->shape($draft, 4),
        );
    }

    public function testInvoice_GivesBackOnASaudiReturnWhatItsCategoriesWereHeldTo(): void
    {
        $origin = $this->sale([$this->line('3', '0.10', added: '0.045')], vat: ['S|15.000|' => new VatCategoryTotal('S', '15.000', null, null, '0.30', '0.05', false)]);
        $draft  = $this->builder->invoice($this->sale([$this->line('-1', '0.10', added: '-0.015')], type: SALE_TYPE_RETURN, origin: $origin, vat: ['S|15.000|' => new VatCategoryTotal('S', '15.000', null, null, '-0.10', '-0.01', false)]), 1);

        $this->assertSame(
            [
                $this->accountId('sales_returns') . ':0.10:0.00',
                $this->accountId('tax_output') . ':0.01:0.00',
                $this->customerAccount() . ':0.00:0.11',
            ],
            $this->shape($draft),
        );
    }

    public function testInvoice_LeavesASaleWithoutItsVatWorkedOnceAsItWasPosted(): void
    {
        $lines = array_map(fn (int $item): SaleLine => $this->line('1', '0.10', added: '0.015', itemId: $item), range(1, 3));

        $this->assertSame(
            [
                $this->customerAccount() . ':0.3500:0.0000',
                $this->accountId('sales_revenue') . ':0.0000:0.3050',
                $this->accountId('tax_output') . ':0.0000:0.0450',
            ],
            $this->shape($this->builder->invoice($this->sale($lines), 1), 4),
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
        $this->setRouting(RoutingKey::Customers, RoutingType::PerItem);

        $failure = $this->failureOf(fn () => $this->builder->invoice($this->sale([$this->line('1', '50')]), 1));

        $this->assertSame(PostingError::RoutingTypeUnsupported, $failure->error);
    }

    public function testInvoice_RefusesTheProductSalesRowWhenItIsInUseBecausePostingDoesNotApplyIt(): void
    {
        $this->setRouting(RoutingKey::ProductSales, RoutingType::PerItem, 'sales_revenue');

        $failure = $this->failureOf(fn () => $this->builder->invoice($this->sale([$this->line('1', '50')]), 1));

        $this->assertSame(PostingError::RoutingTypeUnsupported, $failure->error);
    }

    public function testInvoice_RefusesACustomerWithoutAnAccount(): void
    {
        $other = $this->insertCustomer('No', 'Account');

        $failure = $this->failureOf(fn () => $this->builder->invoice($this->sale([$this->line('1', '50')], customerId: $other), 1));

        $this->assertSame(PostingError::EntityAccountMissing, $failure->error);
    }

    /**
     * @return array{int, int, int} Two items that name an account of their own, and one that names none, as sale lines of an invoice.
     */
    private function itemsOfTwoAccountsAndOneWithout(): array
    {
        $first  = $this->createTestItem();
        $second = $this->createTestItem();
        $plain  = $this->createTestItem();

        $this->nameAccount($first, RoutingKey::SalesRevenue, $this->customAccount('41', '4101', 'First revenue'));
        $this->nameAccount($second, RoutingKey::SalesRevenue, $this->customAccount('41', '4102', 'Second revenue'));

        return [$first, $second, $plain];
    }

    private function nameAccount(int $itemId, RoutingKey $key, int $accountId): void
    {
        $this->accountingDb()->table('item_routing_accounts')->replace(['item_id' => $itemId, 'routing_key' => $key->value, 'account_id' => $accountId]);
    }

    /**
     * @return list<string> The descriptions of the lines, in order.
     */
    private function notes(JournalDraft $draft): array
    {
        return array_map(static fn ($line): string => (string) $line->description, $draft->lines);
    }

    public function testInvoice_PostsTheRevenueOfEachItemToTheAccountItNames(): void
    {
        [$first, $second, $plain] = $this->itemsOfTwoAccountsAndOneWithout();
        $this->setRouting(RoutingKey::SalesRevenue, RoutingType::PerItem);

        $sale = $this->sale([
            $this->line('4', '75', '10', PERCENT, added: '40.50', itemId: $first),
            $this->line('2', '220', added: '66.00', itemId: $second),
            $this->line('1', '100', itemId: $plain),
        ]);

        $draft = $this->builder->invoice($sale, 1);

        $this->assertSame(
            [
                $this->customerAccount() . ':916.50:0.00',
                $this->accountIdByCode('4101') . ':0.00:270.00',
                $this->accountIdByCode('4102') . ':0.00:440.00',
                $this->accountId('sales_revenue') . ':0.00:100.00',
                $this->accountId('tax_output') . ':0.00:106.50',
            ],
            $this->shape($draft),
        );
    }

    public function testInvoice_PostsAPerItemRowWithNoItemAccountsAsTheAutomaticRowWouldAndSaysSo(): void
    {
        $plain = $this->createTestItem();
        $sale  = $this->sale([$this->line('4', '75', '10', PERCENT, added: '40.50', itemId: $plain), $this->line('2', '220', added: '66.00', itemId: $plain)]);

        $automatic = $this->builder->invoice($sale, 1);
        $this->setRouting(RoutingKey::SalesRevenue, RoutingType::PerItem);
        $perItem = $this->builder->invoice($sale, 1);

        $this->assertSame($this->shape($automatic), $this->shape($perItem));
        $this->assertSame(['', '', ''], $this->notes($automatic));
        $this->assertSame(['', SalePostingBuilder::FALLBACK_NOTE, ''], $this->notes($perItem));
    }

    public function testInvoice_MarksOnlyTheLinesOfTheItemsThatNameNoAccountAsTheAccountOfTheRow(): void
    {
        [$first, , $plain] = $this->itemsOfTwoAccountsAndOneWithout();
        $this->setRouting(RoutingKey::SalesRevenue, RoutingType::PerItem);

        $draft = $this->builder->invoice($this->sale([$this->line('1', '50', itemId: $first), $this->line('1', '30', itemId: $plain)]), 1);

        $this->assertSame(['', '', SalePostingBuilder::FALLBACK_NOTE], $this->notes($draft));
    }

    public function testInvoice_PutsTheItemsOfOneAccountInOneLine(): void
    {
        [$first, , $plain] = $this->itemsOfTwoAccountsAndOneWithout();
        $other             = $this->createTestItem();
        $this->nameAccount($other, RoutingKey::SalesRevenue, $this->accountIdByCode('4101'));
        $this->setRouting(RoutingKey::SalesRevenue, RoutingType::PerItem);

        $draft = $this->builder->invoice($this->sale([
            $this->line('1', '50', itemId: $first),
            $this->line('1', '30', itemId: $plain),
            $this->line('2', '10', itemId: $other),
            $this->line('1', '5', itemId: $plain),
        ]), 1);

        $this->assertSame(
            [
                $this->customerAccount() . ':105.00:0.00',
                $this->accountIdByCode('4101') . ':0.00:70.00',
                $this->accountId('sales_revenue') . ':0.00:35.00',
            ],
            $this->shape($draft),
        );
    }

    public function testInvoice_KeepsTheLinesOfAnItemThatNamesTheAccountOfTheRowApartFromTheOnesThatNamedNothing(): void
    {
        $named = $this->createTestItem();
        $plain = $this->createTestItem();
        $this->nameAccount($named, RoutingKey::SalesRevenue, $this->accountId('sales_revenue'));
        $this->setRouting(RoutingKey::SalesRevenue, RoutingType::PerItem);

        $draft = $this->builder->invoice($this->sale([$this->line('1', '50', itemId: $named), $this->line('1', '30', itemId: $plain)]), 1);

        $this->assertSame(
            [
                $this->customerAccount() . ':80.00:0.00',
                $this->accountId('sales_revenue') . ':0.00:50.00',
                $this->accountId('sales_revenue') . ':0.00:30.00',
            ],
            $this->shape($draft),
        );
        $this->assertSame(['', '', SalePostingBuilder::FALLBACK_NOTE], $this->notes($draft));
    }

    public function testInvoice_PutsWhatTheRoundingOfTheTotalLeavesOnTheLargestPartSoThePartsAddUpToTheGoods(): void
    {
        $first  = $this->createTestItem();
        $second = $this->createTestItem();
        $this->nameAccount($first, RoutingKey::SalesRevenue, $this->customAccount('41', '4101', 'First revenue'));
        $this->nameAccount($second, RoutingKey::SalesRevenue, $this->customAccount('41', '4102', 'Second revenue'));
        $this->setRouting(RoutingKey::SalesRevenue, RoutingType::PerItem);

        $draft = $this->builder->invoice($this->sale([$this->line('1', '0.35', added: '0.05', itemId: $first), $this->line('1', '0.35', added: '0.055', itemId: $second)]), 1);

        $this->assertSame(
            [
                $this->customerAccount() . ':0.8100:0.0000',
                $this->accountIdByCode('4101') . ':0.0000:0.3550',
                $this->accountIdByCode('4102') . ':0.0000:0.3500',
                $this->accountId('tax_output') . ':0.0000:0.1050',
            ],
            $this->shape($draft, 4),
        );
    }

    public function testInvoice_SharesTheGoodsThatTheDiscountAccountLeavesWholeAndPostsTheDiscountApart(): void
    {
        [$first, $second] = $this->itemsOfTwoAccountsAndOneWithout();
        $this->setRouting(RoutingKey::SalesRevenue, RoutingType::PerItem);
        $this->setRouting(RoutingKey::SalesDiscount, RoutingType::Auto);

        $draft = $this->builder->invoice($this->sale([$this->line('4', '75', '10', PERCENT, itemId: $first), $this->line('2', '220', itemId: $second)]), 1);

        $this->assertSame(
            [
                $this->customerAccount() . ':710.00:0.00',
                $this->accountId('sales_discount') . ':30.00:0.00',
                $this->accountIdByCode('4101') . ':0.00:300.00',
                $this->accountIdByCode('4102') . ':0.00:440.00',
            ],
            $this->shape($draft),
        );
    }

    public function testInvoice_TakesTheShareOfTheDiscountOnTheWholeInvoiceOffTheLineThatCarriedIt(): void
    {
        [$first, $second] = $this->itemsOfTwoAccountsAndOneWithout();
        $this->setRouting(RoutingKey::SalesRevenue, RoutingType::PerItem);

        $draft = $this->builder->invoice($this->sale([$this->line('1', '100', itemId: $first, share: '10'), $this->line('1', '100', itemId: $second, share: '30')]), 1);

        $this->assertSame(
            [
                $this->customerAccount() . ':160.00:0.00',
                $this->accountIdByCode('4101') . ':0.00:90.00',
                $this->accountIdByCode('4102') . ':0.00:70.00',
            ],
            $this->shape($draft),
        );
    }

    public function testInvoice_TakesTheTaxThatWasInsideThePriceOffTheLineBeforeItIsPosted(): void
    {
        [$first, $second] = $this->itemsOfTwoAccountsAndOneWithout();
        $this->setRouting(RoutingKey::SalesRevenue, RoutingType::PerItem);

        $draft = $this->builder->invoice($this->sale([$this->line('1', '115', included: '15', itemId: $first), $this->line('1', '230', included: '30', itemId: $second)]), 1);

        $this->assertSame(
            [
                $this->customerAccount() . ':345.00:0.00',
                $this->accountIdByCode('4101') . ':0.00:100.00',
                $this->accountIdByCode('4102') . ':0.00:200.00',
                $this->accountId('tax_output') . ':0.00:45.00',
            ],
            $this->shape($draft),
        );
    }

    public function testInvoice_LeavesOutTheLineOfAnItemThatCostsNothingAndNamesAnAccount(): void
    {
        [$first, $second] = $this->itemsOfTwoAccountsAndOneWithout();
        $this->setRouting(RoutingKey::SalesRevenue, RoutingType::PerItem);

        $draft = $this->builder->invoice($this->sale([$this->line('1', '50', itemId: $first), $this->line('1', '0', itemId: $second)]), 1);

        $this->assertSame([$this->customerAccount() . ':50.00:0.00', $this->accountIdByCode('4101') . ':0.00:50.00'], $this->shape($draft));
    }

    public function testInvoice_PostsAReturnToTheReturnsAccountOfTheItemOnTheDebitSide(): void
    {
        [$first, , $plain] = $this->itemsOfTwoAccountsAndOneWithout();
        $this->nameAccount($first, RoutingKey::SalesReturns, $this->customAccount('41', '4103', 'First returns'));
        $this->nameAccount($plain, RoutingKey::SalesRevenue, $this->accountIdByCode('4101'));
        $this->setRouting(RoutingKey::SalesReturns, RoutingType::PerItem);
        $origin = $this->sale([$this->line('2', '50', itemId: $first), $this->line('1', '30', itemId: $plain)]);
        $return = $this->sale([$this->line('-2', '50', itemId: $first), $this->line('-1', '30', itemId: $plain)], type: SALE_TYPE_RETURN, origin: $origin);

        $draft = $this->builder->invoice($return, 1);

        $this->assertSame(
            [
                $this->accountIdByCode('4103') . ':100.00:0.00',
                $this->accountId('sales_returns') . ':30.00:0.00',
                $this->customerAccount() . ':0.00:130.00',
            ],
            $this->shape($draft),
        );
        $this->assertSame(['', SalePostingBuilder::FALLBACK_NOTE, ''], $this->notes($draft));
    }

    public function testInvoice_LeavesAReturnOnTheReturnsRowWhileOnlyTheRevenueRowIsPerItem(): void
    {
        [$first] = $this->itemsOfTwoAccountsAndOneWithout();
        $this->setRouting(RoutingKey::SalesRevenue, RoutingType::PerItem);
        $origin = $this->sale([$this->line('2', '50', itemId: $first)]);
        $return = $this->sale([$this->line('-2', '50', itemId: $first)], type: SALE_TYPE_RETURN, origin: $origin);

        $this->assertSame([$this->accountId('sales_returns') . ':100.00:0.00', $this->customerAccount() . ':0.00:100.00'], $this->shape($this->builder->invoice($return, 1)));
    }

    public function testInvoice_RejectsAnAccountAnItemNamesThatCannotTakeEntriesAnyMore(): void
    {
        [$first] = $this->itemsOfTwoAccountsAndOneWithout();
        $this->setRouting(RoutingKey::SalesRevenue, RoutingType::PerItem);
        $this->accountingDb()->table('accounting_accounts')->where('code', '4101')->update(['active' => 0]);

        $failure = $this->failureOf(fn () => $this->builder->invoice($this->sale([$this->line('1', '50', itemId: $first)]), 1));

        $this->assertSame(PostingError::ItemAccountUnusable, $failure->error);
        $this->assertSame($first, $failure->context['item_id']);
    }

    public function testInvoice_RejectsGoodsTooSmallToBeSharedOverTheAccountsOfItsItems(): void
    {
        [$first, $second, $plain] = $this->itemsOfTwoAccountsAndOneWithout();
        $this->setRouting(RoutingKey::SalesRevenue, RoutingType::PerItem);

        $sale = $this->sale([$this->line('1', '100', itemId: $first), $this->line('1', '100', itemId: $second), $this->line('1', '10', itemId: $plain, share: '200')]);

        $failure = $this->failureOf(fn () => $this->builder->invoice($sale, 1));

        $this->assertSame(PostingError::SaleNotPostable, $failure->error);
        $this->assertSame('sales.revenue', $failure->context['routing_key']);
    }

    public function testInvoice_IgnoresTheAccountsOfItemsWhileTheRowIsAutomatic(): void
    {
        [$first] = $this->itemsOfTwoAccountsAndOneWithout();

        $draft = $this->builder->invoice($this->sale([$this->line('1', '50', itemId: $first)]), 1);

        $this->assertSame([$this->customerAccount() . ':50.00:0.00', $this->accountId('sales_revenue') . ':0.00:50.00'], $this->shape($draft));
        $this->assertSame(['', ''], $this->notes($draft));
    }

    public function testInvoice_RejectsARevenueRowThatIsCancelledWhenItemsNameAccounts(): void
    {
        [$first] = $this->itemsOfTwoAccountsAndOneWithout();
        $this->setRouting(RoutingKey::SalesRevenue, RoutingType::Cancelled);

        $failure = $this->failureOf(fn () => $this->builder->invoice($this->sale([$this->line('1', '50', itemId: $first)]), 1));

        $this->assertSame(PostingError::RoutingRowCancelled, $failure->error);
    }

    public function testInvoice_PostsTheCustomerOfAMainPerEntityRowToTheAccountOfTheCustomer(): void
    {
        $automatic = $this->builder->invoice($this->sale([$this->line('1', '50')]), 1);
        $this->setRouting(RoutingKey::Customers, RoutingType::MainPerEntity);
        $perEntity = $this->builder->invoice($this->sale([$this->line('1', '50')]), 1);

        $this->assertSame($this->shape($automatic), $this->shape($perEntity));
        $this->assertSame($this->customerAccount() . ':50.00:0.00', $this->shape($perEntity)[0]);
    }

    public function testInvoice_RejectsACustomerWithoutAnAccountOnAMainPerEntityRow(): void
    {
        $this->setRouting(RoutingKey::Customers, RoutingType::MainPerEntity);
        $other = $this->insertCustomer('No', 'Account');

        $failure = $this->failureOf(fn () => $this->builder->invoice($this->sale([$this->line('1', '50')], customerId: $other), 1));

        $this->assertSame(PostingError::EntityAccountMissing, $failure->error);
    }

    public function testPayment_PostsTheCustomerOfAMainPerEntityRowToTheAccountOfTheCustomer(): void
    {
        $this->setRouting(RoutingKey::Customers, RoutingType::MainPerEntity);
        $sale = $this->sale([$this->line('1', '50')], [$this->payment('Cash', '50.00')]);

        $draft = $this->builder->payment($sale, $sale->payments[0], 1);

        $this->assertSame([$this->accountId('cash') . ':50.00:0.00', $this->customerAccount() . ':0.00:50.00'], $this->shape($draft));
    }

    public function testCost_IsTheSameWhenTheRevenueRowIsPerItem(): void
    {
        [$first, $second] = $this->itemsOfTwoAccountsAndOneWithout();
        $sale             = $this->sale([$this->line('4', '75', cost: '40', itemId: $first), $this->line('2', '220', cost: '120', itemId: $second)]);

        $automatic = $this->shape($this->builder->cost($sale, 1));
        $this->setRouting(RoutingKey::SalesRevenue, RoutingType::PerItem);

        $this->assertSame($automatic, $this->shape($this->builder->cost($sale, 1)));
        $this->assertSame([$this->accountId('cogs') . ':400.00:0.00', $this->locationAccount() . ':0.00:400.00'], $automatic);
    }

    public function testCost_RejectsTheStockRowSetToPerItemBecauseItsTiesAreKeptByLocation(): void
    {
        [$first] = $this->itemsOfTwoAccountsAndOneWithout();
        $this->setRouting(RoutingKey::StockLocations, RoutingType::PerItem);

        $failure = $this->failureOf(fn () => $this->builder->cost($this->sale([$this->line('1', '100', cost: '40', itemId: $first)]), 1));

        $this->assertSame(PostingError::RoutingTypeUnsupported, $failure->error);
        $this->assertSame('inventory.locations', $failure->context['routing_key']);
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

    public function testInvoice_PostsShippingAndANegativeAdjustmentToTheirOwnAccounts(): void
    {
        $sale = $this->sale(
            [$this->line('4', '75', '10', PERCENT, '40', added: '40.50'), $this->line('2', '220', cost: '120', added: '66.00', itemId: 2)],
            charges: new ResolvedCharges('Rounding', '-10.0000', '20.0000'),
        );

        $this->assertSame(
            [
                $this->customerAccount() . ':826.50:0.00',
                $this->accountId('sales_adjustment') . ':10.00:0.00',
                $this->accountId('sales_revenue') . ':0.00:710.00',
                $this->accountId('tax_output') . ':0.00:106.50',
                $this->accountId('sales_shipping') . ':0.00:20.00',
            ],
            $this->shape($this->builder->invoice($sale, 1)),
        );
    }

    public function testInvoice_CreditsAPositiveAdjustmentToTheAdjustmentAccount(): void
    {
        $sale = $this->sale([$this->line('1', '100')], charges: new ResolvedCharges('Handling', '12.5000'));

        $this->assertSame(
            [
                $this->customerAccount() . ':112.50:0.00',
                $this->accountId('sales_revenue') . ':0.00:100.00',
                $this->accountId('sales_adjustment') . ':0.00:12.50',
            ],
            $this->shape($this->builder->invoice($sale, 1)),
        );
    }

    public function testInvoice_CreditsTheShippingToTheAccountOfTheShippingCompanyTheDocumentNamesAndToTheRoutedRevenueWhenItNamesNone(): void
    {
        $owed = $this->customAccount('2212', '22121', 'Owed to Fast Ship');

        $named = $this->sale([$this->line('1', '100')], charges: new ResolvedCharges(shipping: '20.0000', shippingCompanyId: 7, shippingAccountId: $owed));
        $plain = $this->sale([$this->line('1', '100')], charges: new ResolvedCharges(shipping: '20.0000', shippingCompanyId: 7));

        $this->assertContains($owed . ':0.00:20.00', $this->shape($this->builder->invoice($named, 1)));
        $this->assertNotContains($this->accountId('sales_shipping') . ':0.00:20.00', $this->shape($this->builder->invoice($named, 1)));
        $this->assertContains($this->accountId('sales_shipping') . ':0.00:20.00', $this->shape($this->builder->invoice($plain, 1)));
    }

    public function testInvoice_AddsTheTaxOnShippingToTheOutputTaxWhenItIsNotInTheAmount(): void
    {
        $sale = $this->sale([$this->line('1', '100')], charges: new ResolvedCharges(shipping: '100.0000', shippingTax: '15.0000'));

        $this->assertSame(
            [
                $this->customerAccount() . ':215.00:0.00',
                $this->accountId('sales_revenue') . ':0.00:100.00',
                $this->accountId('tax_output') . ':0.00:15.00',
                $this->accountId('sales_shipping') . ':0.00:100.00',
            ],
            $this->shape($this->builder->invoice($sale, 1)),
        );
    }

    public function testInvoice_TakesTheTaxOnShippingOutOfTheAmountWhenItIsIncluded(): void
    {
        $sale = $this->sale([$this->line('1', '100')], charges: new ResolvedCharges(shipping: '100.0000', shippingTax: '13.0435', shippingTaxIncluded: true));

        $this->assertSame(
            [
                $this->customerAccount() . ':200.0000:0.0000',
                $this->accountId('sales_revenue') . ':0.0000:100.0000',
                $this->accountId('tax_output') . ':0.0000:13.0435',
                $this->accountId('sales_shipping') . ':0.0000:86.9565',
            ],
            $this->shape($this->builder->invoice($sale, 1), 4),
        );
    }

    public function testInvoice_RoundsTheTotalOnTheGoodsAndKeepsTheShippingAndItsTaxUnrounded(): void
    {
        $sale = $this->sale([$this->line('1', '0.70', added: '0.105')], charges: new ResolvedCharges(shipping: '0.0700', shippingTax: '0.0105'));

        $this->assertSame(
            [
                $this->customerAccount() . ':0.8900:0.0000',
                $this->accountId('sales_revenue') . ':0.0000:0.7045',
                $this->accountId('tax_output') . ':0.0000:0.1155',
                $this->accountId('sales_shipping') . ':0.0000:0.0700',
            ],
            $this->shape($this->builder->invoice($sale, 1), 4),
        );
    }

    public function testInvoice_KeepsTheDiscountLineAndAddsTheChargesBesideIt(): void
    {
        $this->setRouting(RoutingKey::SalesDiscount, RoutingType::Auto);

        $sale = $this->sale([$this->line('4', '75', '10', PERCENT, added: '40.50')], charges: new ResolvedCharges('Fee', '5.0000', '20.0000'));

        $this->assertSame(
            [
                $this->customerAccount() . ':335.50:0.00',
                $this->accountId('sales_discount') . ':30.00:0.00',
                $this->accountId('sales_revenue') . ':0.00:300.00',
                $this->accountId('tax_output') . ':0.00:40.50',
                $this->accountId('sales_shipping') . ':0.00:20.00',
                $this->accountId('sales_adjustment') . ':0.00:5.00',
            ],
            $this->shape($this->builder->invoice($sale, 1)),
        );
    }

    public function testInvoice_MirrorsTheChargesOnACreditNote(): void
    {
        $origin = $this->sale([$this->line('2', '50', cost: '10')]);
        $return = $this->sale(
            [$this->line('-1', '50', cost: '10', added: '7.50')],
            type: SALE_TYPE_RETURN,
            origin: $origin,
            charges: new ResolvedCharges('Goodwill', '-5.0000', '20.0000'),
        );

        $this->assertSame(
            [
                $this->accountId('sales_returns') . ':50.00:0.00',
                $this->accountId('tax_output') . ':7.50:0.00',
                $this->accountId('sales_shipping') . ':20.00:0.00',
                $this->accountId('sales_adjustment') . ':0.00:5.00',
                $this->customerAccount() . ':0.00:72.50',
            ],
            $this->shape($this->builder->invoice($return, 1)),
        );
    }

    public function testInvoice_JournalsTheSameWhenTheChargesAreEmptyAsWhenThereAreNone(): void
    {
        $lines = [$this->line('4', '75', '10', PERCENT, '40', added: '40.50')];

        $this->assertSame(
            $this->shape($this->builder->invoice($this->sale($lines), 1), 4),
            $this->shape($this->builder->invoice($this->sale($lines, charges: new ResolvedCharges()), 1), 4),
        );
    }

    public function testInvoice_RefusesShippingWhenItsRoutingRowIsCancelled(): void
    {
        $this->setRouting(RoutingKey::SalesShipping, RoutingType::Cancelled);

        $failure = $this->failureOf(fn () => $this->builder->invoice($this->sale([$this->line('1', '50')], charges: new ResolvedCharges(shipping: '5.0000')), 1));

        $this->assertSame(PostingError::RoutingRowCancelled, $failure->error);
        $this->assertSame('sales.shipping', $failure->context['routing_key']);
    }

    public function testInvoice_RefusesAnAdjustmentWhenItsRoutingRowIsCancelled(): void
    {
        $this->setRouting(RoutingKey::SalesAdjustment, RoutingType::Cancelled);

        $failure = $this->failureOf(fn () => $this->builder->invoice($this->sale([$this->line('1', '50')], charges: new ResolvedCharges('Fee', '5.0000')), 1));

        $this->assertSame(PostingError::RoutingRowCancelled, $failure->error);
        $this->assertSame('sales.adjustment', $failure->context['routing_key']);
    }

    public function testInvoice_NeedsNeitherChargeRowWhenTheSaleHasNoChargesEvenIfBothAreCancelled(): void
    {
        $this->setRouting(RoutingKey::SalesShipping, RoutingType::Cancelled);
        $this->setRouting(RoutingKey::SalesAdjustment, RoutingType::Cancelled);

        $this->assertCount(2, $this->builder->invoice($this->sale([$this->line('1', '50')]), 1)->lines);
    }

    public function testInvoice_PostsOnlyTheShippingWhenTheGoodsAreFree(): void
    {
        $sale = $this->sale([$this->line('1', '0')], charges: new ResolvedCharges(shipping: '20.0000'));

        $draft = $this->builder->invoice($sale, 1);

        $this->assertSame(
            [
                $this->customerAccount() . ':20.00:0.00',
                $this->accountId('sales_shipping') . ':0.00:20.00',
            ],
            $this->shape($draft),
        );
        (new PostingService())->validate($draft);
    }

    public function testInvoice_PostsOnlyTheShippingWhenALineDiscountTakesEverythingAndDiscountsAreNotRouted(): void
    {
        $sale = $this->sale([$this->line('2', '50', '100', PERCENT)], charges: new ResolvedCharges(shipping: '20.0000'));

        $this->assertSame(
            [
                $this->customerAccount() . ':20.00:0.00',
                $this->accountId('sales_shipping') . ':0.00:20.00',
            ],
            $this->shape($this->builder->invoice($sale, 1)),
        );
    }

    public function testInvoice_KeepsTheDiscountLineWhenTheLineDiscountTakesEverythingAndDiscountsAreRouted(): void
    {
        $this->setRouting(RoutingKey::SalesDiscount, RoutingType::Auto);

        $sale = $this->sale([$this->line('2', '50', '100', PERCENT)], charges: new ResolvedCharges(shipping: '20.0000'));

        $this->assertSame(
            [
                $this->customerAccount() . ':20.00:0.00',
                $this->accountId('sales_discount') . ':100.00:0.00',
                $this->accountId('sales_revenue') . ':0.00:100.00',
                $this->accountId('sales_shipping') . ':0.00:20.00',
            ],
            $this->shape($this->builder->invoice($sale, 1)),
        );
    }

    public function testInvoice_LetsTheShippingCarryTheRoundingWhenTheGoodsAreFreeAndItsTaxRoundsDown(): void
    {
        $sale = $this->sale([$this->line('1', '0')], charges: new ResolvedCharges(shipping: '10.0100', shippingTax: '0.4505'));

        $draft = $this->builder->invoice($sale, 1);

        $this->assertSame(
            [
                $this->customerAccount() . ':10.4600:0.0000',
                $this->accountId('tax_output') . ':0.0000:0.4505',
                $this->accountId('sales_shipping') . ':0.0000:10.0095',
            ],
            $this->shape($draft, 4),
        );
        (new PostingService())->validate($draft);
    }

    public function testInvoice_LetsTheShippingCarryTheRoundingWhenTheGoodsAreFreeAndItsTaxRoundsUp(): void
    {
        $sale = $this->sale([$this->line('1', '0')], charges: new ResolvedCharges(shipping: '33.3300', shippingTax: '2.4998'));

        $this->assertSame(
            [
                $this->customerAccount() . ':35.8300:0.0000',
                $this->accountId('tax_output') . ':0.0000:2.4998',
                $this->accountId('sales_shipping') . ':0.0000:33.3302',
            ],
            $this->shape($this->builder->invoice($sale, 1), 4),
        );
    }

    public function testInvoice_PostsTheShippingAndTheNegativeAdjustmentWhenTheGoodsAreFree(): void
    {
        $sale = $this->sale([$this->line('1', '0')], charges: new ResolvedCharges('Goodwill', '-5.0000', '20.0000'));

        $this->assertSame(
            [
                $this->customerAccount() . ':15.00:0.00',
                $this->accountId('sales_adjustment') . ':5.00:0.00',
                $this->accountId('sales_shipping') . ':0.00:20.00',
            ],
            $this->shape($this->builder->invoice($sale, 1)),
        );
    }

    public function testInvoice_MirrorsTheShippingOfACreditNoteWhoseGoodsAreFree(): void
    {
        $origin = $this->sale([$this->line('2', '50', cost: '10')]);
        $return = $this->sale([$this->line('-1', '0')], type: SALE_TYPE_RETURN, origin: $origin, charges: new ResolvedCharges(shipping: '20.0000'));

        $this->assertSame(
            [
                $this->accountId('sales_shipping') . ':20.00:0.00',
                $this->customerAccount() . ':0.00:20.00',
            ],
            $this->shape($this->builder->invoice($return, 1)),
        );
    }

    public function testInvoice_StillReturnsNothingWhenTheGoodsAreFreeAndThereAreNoCharges(): void
    {
        $this->assertNull($this->builder->invoice($this->sale([$this->line('1', '0')]), 1));
    }

    public function testAssertSettled_CountsTheChargesInWhatAFeelessSaleMustPay(): void
    {
        $sale = $this->sale([$this->line('1', '100')], [$this->payment(lang('Sales.cash'), '100.00')], customerId: null, charges: new ResolvedCharges(shipping: '20.0000'));

        $failure = $this->failureOf(fn () => $this->builder->assertSettled($sale));

        $this->assertSame(PostingError::CreditNeedsCustomer, $failure->error);

        $this->builder->assertSettled($this->sale([$this->line('1', '100')], [$this->payment(lang('Sales.cash'), '120.00')], customerId: null, charges: new ResolvedCharges(shipping: '20.0000')));
    }

    private function foreign(string $code = 'USD', string $rate = '3.75000000', int $decimals = 2): DocumentCurrency
    {
        return new DocumentCurrency($code, $rate, $decimals);
    }

    private function foreignPayment(int $id, string $amount, ?string $rate, string $refund = '0', bool $adjustment = false): SalePayment
    {
        return new SalePayment($id, lang('Sales.cash'), $amount, $refund, $adjustment, '2026-10-21', PaymentStatus::Completed, null, null, $rate);
    }

    /**
     * @return list<string> "account:foreign" of each line, in order.
     */
    private function foreignShape(JournalDraft $draft): array
    {
        return array_map(static fn ($line): string => $line->accountId . ':' . $line->foreignAmount . ':' . $line->currencyCode, $draft->lines);
    }

    public function testInvoice_ConvertsTheObservedDollarInvoiceLineByLineToTheBase(): void
    {
        $sale  = $this->sale([$this->line('2', '100', added: '30'), $this->line('1', '33.33', added: '4.9995', itemId: 2)], currency: $this->foreign());
        $draft = $this->builder->invoice($sale, 1, 5);

        $this->assertSame([
            $this->customerAccount() . ':1006.24:0.00',
            $this->accountId('sales_revenue') . ':0.00:874.99',
            $this->accountId('tax_output') . ':0.00:131.25',
        ], $this->shape($draft));
        $this->assertSame(['USD', '3.75000000'], [$draft->currencyCode, $draft->exchangeRate]);
        $this->assertSame([
            $this->customerAccount() . ':268.3300:USD',
            $this->accountId('sales_revenue') . ':233.3305:USD',
            $this->accountId('tax_output') . ':34.9995:USD',
        ], $this->foreignShape($draft));
    }

    public function testInvoice_LeavesTheJournalOfABaseSaleAsItWasWithNoForeignAmounts(): void
    {
        $draft = $this->builder->invoice($this->sale([$this->line('2', '100', added: '30')]), 1);

        $this->assertSame('', $draft->currencyCode);
        $this->assertSame('1', $draft->exchangeRate);
        $this->assertSame([null, null], [$draft->lines[0]->foreignAmount, $draft->lines[0]->currencyCode]);
        $this->assertSame([$this->customerAccount() . ':230.00:0.00', $this->accountId('sales_revenue') . ':0.00:200.00', $this->accountId('tax_output') . ':0.00:30.00'], $this->shape($draft));
    }

    public function testInvoice_RoundsTheTotalOfAZeroDecimalCurrencyToWholeUnits(): void
    {
        $sale  = $this->sale([$this->line('3', '1234.5')], currency: $this->foreign('JPY', '0.02376000', 0));
        $draft = $this->builder->invoice($sale, 1);

        $this->assertSame(['3704.0000', '3704.0000'], [$draft->lines[0]->foreignAmount, $draft->lines[1]->foreignAmount]);
        $this->assertSame([$this->customerAccount() . ':88.01:0.00', $this->accountId('sales_revenue') . ':0.00:88.01'], $this->shape($draft));
        $this->assertSame('JPY', $draft->currencyCode);
    }

    public function testInvoice_KeepsTheThreeDecimalsOfACurrencyThatHasThem(): void
    {
        $sale  = $this->sale([$this->line('3', '10.125')], currency: $this->foreign('KWD', '12.04917000', 3));
        $draft = $this->builder->invoice($sale, 1);

        $this->assertSame('30.3750', $draft->lines[0]->foreignAmount);
        $this->assertSame([$this->customerAccount() . ':365.99:0.00', $this->accountId('sales_revenue') . ':0.00:365.99'], $this->shape($draft));
    }

    public function testInvoice_RoundsTheTotalOfATwoDecimalCurrencyToTheCentAndLeavesTheRoundingOnTheRevenue(): void
    {
        $sale  = $this->sale([$this->line('1', '33.335')], currency: $this->foreign('USD', '2.00000000', 2));
        $draft = $this->builder->invoice($sale, 1);

        $this->assertSame(['33.3400', '33.3400'], [$draft->lines[0]->foreignAmount, $draft->lines[1]->foreignAmount]);
        $this->assertSame([$this->customerAccount() . ':66.68:0.00', $this->accountId('sales_revenue') . ':0.00:66.68'], $this->shape($draft));
    }

    public function testInvoice_PutsTheRoundingDifferenceOfTheConversionOnTheRevenueOfTheGoodsOnly(): void
    {
        $sale  = $this->sale([$this->line('1', '33.33', itemId: 1), $this->line('1', '33.33', itemId: 2), $this->line('1', '33.34', itemId: 3)], currency: $this->foreign('USD', '1.00500000', 2));
        $draft = $this->builder->invoice($sale, 1);

        $this->assertSame([$this->customerAccount() . ':100.50:0.00', $this->accountId('sales_revenue') . ':0.00:100.50'], $this->shape($draft));
    }

    public function testInvoice_PutsTheConversionRoundingOnTheLargestGoodsLineWhenEveryItemNamesAnAccountOtherThanTheRow(): void
    {
        [$first, $second] = $this->itemsOfTwoAccountsAndOneWithout();
        $third            = $this->createTestItem();
        $this->nameAccount($third, RoutingKey::SalesRevenue, $this->customAccount('41', '4103', 'Third revenue'));
        $this->setRouting(RoutingKey::SalesRevenue, RoutingType::PerItem);

        $sale = $this->sale(
            [$this->line('1', '33.33', itemId: $first), $this->line('1', '33.33', itemId: $second), $this->line('1', '33.34', itemId: $third)],
            currency: $this->foreign('USD', '1.00500000', 2),
        );

        $this->assertSame(
            [
                $this->customerAccount() . ':100.50:0.00',
                $this->accountIdByCode('4101') . ':0.00:33.50',
                $this->accountIdByCode('4102') . ':0.00:33.50',
                $this->accountIdByCode('4103') . ':0.00:33.50',
            ],
            $this->shape($this->builder->invoice($sale, 1)),
        );
    }

    public function testInvoice_PutsTheConversionRoundingOnTheLargestGoodsLineEvenWhenTheLineOfTheRowIsSmaller(): void
    {
        [$first, , $plain] = $this->itemsOfTwoAccountsAndOneWithout();
        $this->setRouting(RoutingKey::SalesRevenue, RoutingType::PerItem);

        $sale = $this->sale([$this->line('1', '33.34', itemId: $first), $this->line('1', '33.33', itemId: $plain)], currency: $this->foreign('USD', '1.00500000', 2));

        $this->assertSame(
            [
                $this->customerAccount() . ':67.00:0.00',
                $this->accountIdByCode('4101') . ':0.00:33.50',
                $this->accountId('sales_revenue') . ':0.00:33.50',
            ],
            $this->shape($this->builder->invoice($sale, 1)),
        );
    }

    public function testInvoice_PutsTheConversionRoundingOfAForeignReturnOnTheLargestGoodsLine(): void
    {
        [$first, $second] = $this->itemsOfTwoAccountsAndOneWithout();
        $third            = $this->createTestItem();
        foreach ([$first => '4101', $second => '4102'] as $item => $code) {
            $this->nameAccount($item, RoutingKey::SalesReturns, $this->accountIdByCode($code));
        }
        $this->nameAccount($third, RoutingKey::SalesReturns, $this->customAccount('41', '4103', 'Third returns'));
        $this->setRouting(RoutingKey::SalesReturns, RoutingType::PerItem);
        $currency = $this->foreign('USD', '1.00500000', 2);
        $origin   = $this->sale([$this->line('1', '33.33', itemId: $first), $this->line('1', '33.33', itemId: $second), $this->line('1', '33.34', itemId: $third)], currency: $currency);
        $return   = $this->sale(
            [$this->line('-1', '33.33', itemId: $first), $this->line('-1', '33.33', itemId: $second), $this->line('-1', '33.34', itemId: $third)],
            type: SALE_TYPE_RETURN,
            origin: $origin,
            currency: $currency,
        );

        $this->assertSame(
            [
                $this->accountIdByCode('4101') . ':33.50:0.00',
                $this->accountIdByCode('4102') . ':33.50:0.00',
                $this->accountIdByCode('4103') . ':33.50:0.00',
                $this->customerAccount() . ':0.00:100.50',
            ],
            $this->shape($this->builder->invoice($return, 2)),
        );
    }

    public function testInvoice_PutsTheConversionRoundingOnTheLargestGoodsLineBehindTheDiscountLine(): void
    {
        [$first, $second] = $this->itemsOfTwoAccountsAndOneWithout();
        $third            = $this->createTestItem();
        $this->nameAccount($third, RoutingKey::SalesRevenue, $this->customAccount('41', '4103', 'Third revenue'));
        $this->setRouting(RoutingKey::SalesRevenue, RoutingType::PerItem);
        $this->setRouting(RoutingKey::SalesDiscount, RoutingType::Auto, 'sales_discount');

        $sale = $this->sale(
            [$this->line('1', '33.33', '10', PERCENT, itemId: $first), $this->line('1', '33.33', '10', PERCENT, itemId: $second), $this->line('1', '33.34', '10', PERCENT, itemId: $third)],
            currency: $this->foreign('USD', '1.00500000', 2),
        );

        $this->assertSame(
            [
                $this->customerAccount() . ':90.46:0.00',
                $this->accountId('sales_discount') . ':10.04:0.00',
                $this->accountIdByCode('4101') . ':0.00:33.50',
                $this->accountIdByCode('4102') . ':0.00:33.50',
                $this->accountIdByCode('4103') . ':0.00:33.50',
            ],
            $this->shape($this->builder->invoice($sale, 1)),
        );
    }

    public function testInvoice_PutsTheConversionRoundingOnTheLargestGoodsLineBehindTheLineOfANegativeAdjustment(): void
    {
        [$first, $second] = $this->itemsOfTwoAccountsAndOneWithout();
        $third            = $this->createTestItem();
        $this->nameAccount($third, RoutingKey::SalesRevenue, $this->customAccount('41', '4103', 'Third revenue'));
        $this->setRouting(RoutingKey::SalesRevenue, RoutingType::PerItem);

        $sale = $this->sale(
            [$this->line('1', '33.33', itemId: $first), $this->line('1', '33.33', itemId: $second), $this->line('1', '33.34', itemId: $third)],
            charges: new ResolvedCharges('Fee', '-2.0000'),
            currency: $this->foreign('USD', '1.00500000', 2),
        );

        $this->assertSame(
            [
                $this->customerAccount() . ':98.49:0.00',
                $this->accountId(RoutingKey::SalesAdjustment->defaultSystemKey()) . ':2.01:0.00',
                $this->accountIdByCode('4101') . ':0.00:33.50',
                $this->accountIdByCode('4102') . ':0.00:33.50',
                $this->accountIdByCode('4103') . ':0.00:33.50',
            ],
            $this->shape($this->builder->invoice($sale, 1)),
        );
    }

    public function testInvoice_PutsTheConversionRoundingOnTheFirstOfTwoEqualGoodsLines(): void
    {
        [$first, $second] = $this->itemsOfTwoAccountsAndOneWithout();
        $third            = $this->createTestItem();
        $this->nameAccount($third, RoutingKey::SalesRevenue, $this->customAccount('41', '4103', 'Third revenue'));
        $this->setRouting(RoutingKey::SalesRevenue, RoutingType::PerItem);

        $sale = $this->sale(
            [$this->line('1', '33.33', itemId: $first), $this->line('1', '33.33', itemId: $second), $this->line('1', '33.33', itemId: $third)],
            currency: $this->foreign('USD', '1.00500000', 2),
        );

        $this->assertSame(
            [
                $this->customerAccount() . ':100.49:0.00',
                $this->accountIdByCode('4101') . ':0.00:33.49',
                $this->accountIdByCode('4102') . ':0.00:33.50',
                $this->accountIdByCode('4103') . ':0.00:33.50',
            ],
            $this->shape($this->builder->invoice($sale, 1)),
        );
    }

    public function testInvoice_KeepsTheRoundingOnTheSingleGoodsLineOfARowThatIsNotPerItem(): void
    {
        $sale = $this->sale([$this->line('1', '33.33', itemId: 1), $this->line('1', '33.33', itemId: 2), $this->line('1', '33.34', itemId: 3)], currency: $this->foreign('USD', '1.00500000', 2));

        $this->assertSame(
            [$this->customerAccount() . ':100.50:0.00', $this->accountId('sales_revenue') . ':0.00:100.50'],
            $this->shape($this->builder->invoice($sale, 1)),
        );
    }

    public function testInvoice_MirrorsAReturnOfAForeignSaleAtTheRateOfTheSaleItTakesBack(): void
    {
        $currency = $this->foreign();
        $origin   = $this->sale([$this->line('2', '100', added: '30')], currency: $currency);
        $return   = $this->sale([$this->line('-2', '100', added: '30')], type: SALE_TYPE_RETURN, origin: $origin, currency: $currency);

        $draft = $this->builder->invoice($return, 2);

        $this->assertSame([
            $this->accountId('sales_returns') . ':750.00:0.00',
            $this->accountId('tax_output') . ':112.50:0.00',
            $this->customerAccount() . ':0.00:862.50',
        ], $this->shape($draft));
        $this->assertSame('3.75000000', $draft->exchangeRate);
    }

    public function testInvoice_PostsAShippingOnlyForeignSaleWithTheRoundingOnTheShipping(): void
    {
        $this->setRouting(RoutingKey::SalesShipping, RoutingType::Auto, 'sales_shipping');
        $charges = new ResolvedCharges(adjustmentLabel: '', adjustment: '0.0000', shipping: '10.0000', shippingTax: '0.0000', shippingTaxIncluded: false, shippingOptions: null);
        $sale    = $this->sale([$this->line('1', '0')], charges: $charges, currency: $this->foreign());
        $draft   = $this->builder->invoice($sale, 1);

        $this->assertSame([$this->customerAccount() . ':37.50:0.00', $this->accountId('sales_shipping') . ':0.00:37.50'], $this->shape($draft));
    }

    public function testPayment_PostsTheMoneyAtTheRateOfThePaymentWithTheForeignAmountOnBothLines(): void
    {
        $sale  = $this->sale([$this->line('1', '100')], currency: $this->foreign());
        $draft = $this->builder->payment($sale, $this->foreignPayment(3, '100.00', '3.60000000'), 1, 5);

        $this->assertSame([$this->accountId('cash') . ':360.00:0.00', $this->customerAccount() . ':0.00:360.00'], $this->shape($draft));
        $this->assertSame([$this->accountId('cash') . ':100.0000:USD', $this->customerAccount() . ':100.0000:USD'], $this->foreignShape($draft));
        $this->assertSame(['USD', '3.60000000'], [$draft->currencyCode, $draft->exchangeRate]);
        $this->assertSame('sale_payment', $draft->sourceType);
    }

    public function testPayment_RefusesAPaymentOfAForeignSaleThatHasNoRate(): void
    {
        $failure = $this->failureOf(fn () => $this->builder->payment($this->sale([$this->line('1', '100')], currency: $this->foreign()), $this->foreignPayment(3, '100.00', null), 1));

        $this->assertSame(PostingError::ForeignRateMismatch, $failure->error);
    }

    public function testPayment_RefusesTheRoundingOfCashOnAForeignSale(): void
    {
        $failure = $this->failureOf(fn () => $this->builder->payment($this->sale([$this->line('1', '100')], currency: $this->foreign()), $this->foreignPayment(3, '0.02', '3.75000000', adjustment: true), 1));

        $this->assertSame(PostingError::FxNotSupported, $failure->error);
    }

    public function testPaymentDifference_PostsTheLossWhenTheDollarCostsLessThanItDidOnTheInvoice(): void
    {
        $sale  = $this->sale([$this->line('1', '100')], [$this->foreignPayment(3, '100.00', '3.60000000')], currency: $this->foreign());
        $draft = $this->builder->paymentDifference($sale, $sale->payments[0], 1, 5);

        $this->assertSame([$this->accountId('fx_loss') . ':15.00:0.00', $this->customerAccount() . ':0.00:15.00'], $this->shape($draft));
        $this->assertSame('sale_payment_fx', $draft->sourceType);
        $this->assertSame(3, $draft->sourceId);
        $this->assertSame(JournalEntryType::General, $draft->entryType);
        $this->assertSame('', $draft->currencyCode);
    }

    public function testPaymentDifference_PostsTheGainWhenTheDollarCostsMoreThanItDidOnTheInvoice(): void
    {
        $sale  = $this->sale([$this->line('1', '100')], [$this->foreignPayment(3, '100.00', '3.90000000')], currency: $this->foreign());
        $draft = $this->builder->paymentDifference($sale, $sale->payments[0], 1);

        $this->assertSame([$this->customerAccount() . ':15.00:0.00', $this->accountId('fx_gain') . ':0.00:15.00'], $this->shape($draft));
    }

    public function testPaymentDifference_IsNothingWhenThePaymentIsAtTheRateOfTheInvoice(): void
    {
        $sale = $this->sale([$this->line('1', '100')], [$this->foreignPayment(3, '100.00', '3.75000000')], currency: $this->foreign());

        $this->assertNull($this->builder->paymentDifference($sale, $sale->payments[0], 1));
    }

    public function testPaymentDifference_IsNothingForABaseSale(): void
    {
        $sale = $this->sale([$this->line('1', '100')], [$this->payment(lang('Sales.cash'), '115.00')]);

        $this->assertNull($this->builder->paymentDifference($sale, $sale->payments[0], 1));
    }

    public function testPaymentDifference_SettlesWhatTheEarlierPaymentsLeftAndNotEachPaymentOnItsOwn(): void
    {
        $sale = $this->sale([$this->line('1', '0.20')], [$this->foreignPayment(1, '0.10', '3.75000000'), $this->foreignPayment(2, '0.10', '3.75000000')], currency: $this->foreign());

        $this->assertNull($this->builder->paymentDifference($sale, $sale->payments[0], 1));
        $this->assertSame([$this->customerAccount() . ':0.01:0.00', $this->accountId('fx_gain') . ':0.00:0.01'], $this->shape($this->builder->paymentDifference($sale, $sale->payments[1], 1)));
    }

    public function testPaymentDifference_SettlesTheWholeInvoiceByTheRateOfTheInvoiceAcrossPartPayments(): void
    {
        $sale = $this->sale([$this->line('2', '100', added: '30'), $this->line('1', '33.33', added: '4.9995', itemId: 2)], [
            $this->foreignPayment(1, '100.00', '3.75000000'),
            $this->foreignPayment(2, '100.00', '3.60000000'),
            $this->foreignPayment(3, '68.33', '3.90000000'),
        ], currency: $this->foreign());

        $this->assertNull($this->builder->paymentDifference($sale, $sale->payments[0], 1));
        $this->assertSame([$this->accountId('fx_loss') . ':15.00:0.00', $this->customerAccount() . ':0.00:15.00'], $this->shape($this->builder->paymentDifference($sale, $sale->payments[1], 1)));
        $this->assertSame([$this->customerAccount() . ':10.25:0.00', $this->accountId('fx_gain') . ':0.00:10.25'], $this->shape($this->builder->paymentDifference($sale, $sale->payments[2], 1)));

        $settled  = '0';
        $customer = $this->customerAccount();

        foreach ($sale->payments as $payment) {
            foreach ([$this->builder->payment($sale, $payment, 1), $this->builder->paymentDifference($sale, $payment, 1)] as $draft) {
                foreach ($draft?->lines ?? [] as $line) {
                    if ($line->accountId === $customer) {
                        $settled = bcadd($settled, bcsub($line->credit, $line->debit, 2), 2);
                    }
                }
            }
        }

        $this->assertSame('1006.24', $settled);
    }

    public function testPaymentDifference_MirrorsARefundOfAForeignSale(): void
    {
        $sale = $this->sale([$this->line('1', '100')], [$this->foreignPayment(1, '100.00', '3.75000000'), $this->foreignPayment(2, '-100.00', '3.60000000')], currency: $this->foreign());

        $draft = $this->builder->paymentDifference($sale, $sale->payments[1], 1);

        $this->assertSame([$this->customerAccount() . ':15.00:0.00', $this->accountId('fx_gain') . ':0.00:15.00'], $this->shape($draft));
        $this->assertSame([$this->customerAccount() . ':360.00:0.00', $this->accountId('cash') . ':0.00:360.00'], $this->shape($this->builder->payment($sale, $sale->payments[1], 1)));
    }

    public function testPaymentDifference_SkipsAPaymentThatIsNotCompletedAndOneLeftOnCredit(): void
    {
        $sale = $this->sale([$this->line('1', '100')], [
            new SalePayment(1, lang('Sales.cash'), '40.00', '0', false, '2026-10-21', PaymentStatus::Incomplete, null, null, '3.60000000'),
            new SalePayment(2, lang('Sales.due'), '60.00', '0', false, '2026-10-21', PaymentStatus::Completed, null, null, '3.60000000'),
            $this->foreignPayment(3, '100.00', '3.60000000'),
        ], currency: $this->foreign());

        $this->assertNull($this->builder->paymentDifference($sale, $sale->payments[0], 1));
        $this->assertNull($this->builder->paymentDifference($sale, $sale->payments[1], 1));
        $this->assertSame([$this->accountId('fx_loss') . ':15.00:0.00', $this->customerAccount() . ':0.00:15.00'], $this->shape($this->builder->paymentDifference($sale, $sale->payments[2], 1)));
    }

    public function testPaymentDifference_RefusesWhenTheRoutingOfExchangeLossesHasNoAccount(): void
    {
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'fx.loss')->update(['account_id' => null]);
        $sale = $this->sale([$this->line('1', '100')], [$this->foreignPayment(3, '100.00', '3.60000000')], currency: $this->foreign());

        $failure = $this->failureOf(fn () => $this->builder->paymentDifference($sale, $sale->payments[0], 1));

        $this->assertSame(PostingError::FxAccountsMissing, $failure->error);
    }

    public function testPaymentDifference_RoundsEachPartOnTheDecimalsOfTheBase(): void
    {
        $sale = $this->sale([$this->line('1', '10.125')], [$this->foreignPayment(3, '10.125', '12.00000000')], currency: $this->foreign('KWD', '12.04917000', 3));

        $draft = $this->builder->paymentDifference($sale, $sale->payments[0], 1);

        $this->assertSame([$this->accountId('fx_loss') . ':0.50:0.00', $this->customerAccount() . ':0.00:0.50'], $this->shape($draft));
    }

    public function testInvoice_RoundsTheDiscountOfAZeroDecimalCurrencyToWholeUnitsBeforeTheTotal(): void
    {
        $sale  = $this->sale([$this->line('3', '1234.5', discount: '10')], currency: $this->foreign('JPY', '0.02376000', 0));
        $draft = $this->builder->invoice($sale, 1);

        $this->assertSame('3334.0000', $draft->lines[0]->foreignAmount, 'The discount of 370.35 is 370, so the 3,333.5 left is 3,334');
        $this->assertSame([$this->customerAccount() . ':79.22:0.00', $this->accountId('sales_revenue') . ':0.00:79.22'], $this->shape($draft));
    }

    public function testPaymentDifference_RefusesWhenTheRoutingOfExchangeGainsIsCancelled(): void
    {
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'fx.gain')->update(['routing_type' => 'cancelled']);
        $sale = $this->sale([$this->line('1', '100')], [$this->foreignPayment(3, '100.00', '3.90000000')], currency: $this->foreign());

        $failure = $this->failureOf(fn () => $this->builder->paymentDifference($sale, $sale->payments[0], 1));

        $this->assertSame(PostingError::FxAccountsMissing, $failure->error);
    }

    /**
     * @return array<string, int>
     */
    private function rolesOfASale(): array
    {
        return [
            'customer'      => $this->customerAccount(),
            'sales_revenue' => $this->accountId('sales_revenue'),
            'tax_output'    => $this->accountId('tax_output'),
            'treasury'      => $this->accountId('cash'),
            'fx_loss'       => $this->accountId('fx_loss'),
            'fx_gain'       => $this->accountId('fx_gain'),
        ];
    }

    public function testPaymentDifference_PostsTheJournalsOfTheObservedDollarInvoiceAndItsThreePaymentsWithTheDifferencesInTwoAccounts(): void
    {
        $case  = $this->referenceCase('T-58', 'foreign_currency_invoice', 'an-invoice-in-a-foreign-currency-and-its-payments-at-other-rates', 'differs');
        $sale  = $this->sale([$this->line('2', '100', added: '30'), $this->line('1', '33.33', added: '4.9995', itemId: 2)], [
            $this->foreignPayment(1, '100.00', '3.75000000'),
            $this->foreignPayment(2, '100.00', '3.60000000'),
            $this->foreignPayment(3, '68.33', '3.90000000'),
        ], currency: $this->foreign());
        $roles = $this->rolesOfASale();

        $this->assertSameAsFixture($case['expected']['journals']['invoice'], $this->foreignRoleLines($this->builder->invoice($sale, 1), $roles), 'the invoice');
        $this->assertSameAsFixture($case['expected']['journals']['payment_1_at_3_75'], $this->foreignRoleLines($this->builder->payment($sale, $sale->payments[0], 1), $roles), 'the first payment');
        $this->assertSameAsFixture($case['expected']['journals']['payment_2_at_3_60'], $this->foreignRoleLines($this->builder->payment($sale, $sale->payments[1], 1), $roles), 'the second payment');
        $this->assertSameAsFixture($case['expected']['journals']['difference_of_payment_2_loss'], $this->foreignRoleLines($this->builder->paymentDifference($sale, $sale->payments[1], 1), $roles), 'the loss of the second payment');
        $this->assertSameAsFixture($case['expected']['journals']['payment_3_at_3_90'], $this->foreignRoleLines($this->builder->payment($sale, $sale->payments[2], 1), $roles), 'the third payment');
        $this->assertSameAsFixture($case['expected']['journals']['difference_of_payment_3_gain'], $this->foreignRoleLines($this->builder->paymentDifference($sale, $sale->payments[2], 1), $roles), 'the gain of the third payment');
        $this->assertNull($this->builder->paymentDifference($sale, $sale->payments[0], 1), 'a payment at the rate of the invoice has no difference');
        $this->assertSame($case['reference']['payment_form_has_a_rate_field'], $case['expected']['payment_form_has_a_rate_field']);
        $this->assertSame($case['expected']['one_account_for_gains_and_losses'], $this->accountId('fx_loss') === $this->accountId('fx_gain'));
    }

    public function testInvoice_RoundsTheObservedPurchasesInDinarsAndYenToTheDecimalsOfEachCurrency(): void
    {
        $case = $this->referenceCase('T-61', 'currency_decimals', 'each-currency-has-its-own-decimals', 'differs');

        $dinars = $this->builder->invoice($this->sale([$this->line('3', '10.125')], currency: $this->foreign('KWD', '12.04917000', CurrencyCatalog::decimalsOf('KWD'))), 1);
        $yen    = $this->builder->invoice($this->sale([$this->line('3', '1234.5')], currency: $this->foreign('JPY', '0.02376000', CurrencyCatalog::decimalsOf('JPY'))), 1);

        $this->assertSameAsFixture($case['expected'], [
            'decimals'   => ['USD' => (string) CurrencyCatalog::decimalsOf('USD'), 'KWD' => (string) CurrencyCatalog::decimalsOf('KWD'), 'JPY' => (string) CurrencyCatalog::decimalsOf('JPY')],
            'kwd_total'  => $dinars->lines[0]->foreignAmount,
            'jpy_total'  => $yen->lines[0]->foreignAmount,
            'kwd_in_sar' => bcadd($dinars->lines[0]->debit, '0', 2),
            'jpy_in_sar' => bcadd($yen->lines[0]->debit, '0', 2),
        ], 'dinars and yen');
    }
}
