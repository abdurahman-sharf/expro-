<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\DocumentCurrency;
use App\Libraries\Accounting\Engine\PostingEngine;
use App\Libraries\Accounting\Engine\ShadowComparison;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\RoutingResolver;
use App\Libraries\Accounting\RoutingType;
use App\Libraries\Accounting\SaleDocument;
use App\Libraries\Accounting\SaleFacts;
use App\Libraries\Accounting\SaleLine;
use App\Libraries\Accounting\SalePostingBuilder;
use App\Libraries\ResolvedCharges;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ItemFixtureTrait;

class SaleFactsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ItemFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private int $customerId;

    /**
     * @var list<int>
     */
    private array $locations;

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
        $this->locations  = [$this->insertStockLocation('Front store'), $this->insertStockLocation('Back store')];

        $entities->ensure(EntityAccountService::CUSTOMER, $this->customerId, 'Omar Khaled');

        foreach ($this->locations as $location) {
            $entities->ensure(EntityAccountService::STOCK_LOCATION, $location, 'Store ' . $location);
        }
    }

    protected function tearDown(): void
    {
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    /**
     * @param array<int, string> $accounts
     */
    private function line(int $item, string $quantity, string $price, string $discount = '0', string $added = '0', string $included = '0', string $cost = '0', bool $stock = true, int $location = 0, string $share = '0', array $accounts = []): SaleLine
    {
        return new SaleLine($item, $item, $quantity, $price, $discount, PERCENT, $cost, $this->locations[$location], $stock, $included, $added, $share, $accounts);
    }

    /**
     * @param list<SaleLine> $lines
     */
    private function sale(array $lines, ?int $customerId = -1, ?ResolvedCharges $charges = null, ?DocumentCurrency $currency = null): SaleDocument
    {
        return new SaleDocument(saleId: 8, date: '2026-10-21', saleType: SALE_TYPE_INVOICE, status: COMPLETED, customerId: $customerId === -1 ? $this->customerId : $customerId, branchId: 1, delivered: true, returnOfSaleId: null, lines: $lines, payments: [], charges: $charges, currency: $currency);
    }

    private static function outcome(callable $build): mixed
    {
        try {
            return $build();
        } catch (PostingException $failure) {
            return $failure->error->value;
        }
    }

    /**
     * @return bool Whether the two came to a journal.
     */
    private function assertSameJournal(mixed $legacy, mixed $engine, string $label): bool
    {
        if (! $legacy instanceof JournalDraft || ! $engine instanceof JournalDraft) {
            $this->assertSame($legacy instanceof JournalDraft ? 'a journal' : $legacy, $engine instanceof JournalDraft ? 'a journal' : $engine, $label);

            return false;
        }

        $this->assertSame(['only_legacy' => [], 'only_engine' => []], ShadowComparison::differences($legacy, $engine), $label);
        $this->assertSame([$legacy->description, $legacy->date, $legacy->sourceType, $legacy->entryType], [$engine->description, $engine->date, $engine->sourceType, $engine->entryType], $label);

        return true;
    }

    /**
     * The invoice and the cost of the sale from the code and from the engine.
     */
    /**
     * @return bool Whether the invoice came to a journal.
     */
    private function assertSameOutcome(SaleDocument $sale, string $label): bool
    {
        $builder = new SalePostingBuilder(new RoutingResolver(), 2, static fn (): int => 2);
        $engine  = new PostingEngine();

        $journal = $this->assertSameJournal(self::outcome(static fn () => $builder->invoice($sale, 1, 1)), self::outcome(static fn () => $engine->build(SaleFacts::invoice($sale, 1, 1, 2, 2, new RoutingResolver()))), "{$label}: invoice");

        $plan = self::outcome(static fn () => $builder->costPlan($sale, 1, 1));

        if (! is_string($plan)) {
            $this->assertSameJournal($plan->journal, self::outcome(static fn () => $engine->build(SaleFacts::cost($sale, $plan->movements, 1, 1))), "{$label}: cost");
        }

        return $journal;
    }

    public function testInvoice_GivesTheEngineWhatMakesTheJournalsOfTheCodeForEveryKindOfSale(): void
    {
        $company = $this->customAccount('21', '2197', 'Shipping company');

        $cases = [
            'a service'                               => [[$this->line(1, '1', '100.00', added: '15.00', stock: false)], null],
            'two at 100 of a product that cost 60'    => [[$this->line(1, '2', '100.00', added: '30.00', cost: '60.00')], null],
            'a discount of a tenth on a line'         => [[$this->line(1, '1', '100.00', '10', '13.50', cost: '40.00')], null],
            'a discount on the whole of two lines'    => [[$this->line(1, '1', '100.00', added: '13.50', cost: '40.00', share: '10.00'), $this->line(2, '1', '50.00', added: '6.75', stock: false, share: '5.00')], null],
            'a price that holds the tax'              => [[$this->line(1, '1', '115.00', included: '15.00', cost: '50.00')], null],
            'two taxes with accounts of their own'    => [[$this->line(1, '2', '100.00', added: '40.00', cost: '60.00', accounts: [$company => '10.00'])], null],
            'shipping to the routed account'          => [[$this->line(1, '1', '100.00', added: '15.00', cost: '60.00')], new ResolvedCharges(shipping: '20.0000', shippingTax: '3.0000')],
            'shipping on the account of a company'    => [[$this->line(1, '1', '100.00', added: '15.00', cost: '60.00')], new ResolvedCharges(shipping: '20.0000', shippingAccountId: $company)],
            'an adjustment up'                        => [[$this->line(1, '1', '100.00', added: '15.00', stock: false)], new ResolvedCharges(adjustmentLabel: 'Round', adjustment: '0.5000')],
            'an adjustment down'                      => [[$this->line(1, '1', '100.00', added: '15.00', stock: false)], new ResolvedCharges(adjustmentLabel: 'Round', adjustment: '-0.5000')],
            'only shipping'                           => [[$this->line(1, '0', '100.00')], new ResolvedCharges(shipping: '20.0000')],
            'nothing'                                 => [[$this->line(1, '0', '100.00')], null],
            'two stores'                              => [[$this->line(1, '2', '10.00', added: '3.00', cost: '6.00'), $this->line(2, '3', '20.00', added: '9.00', cost: '7.50', location: 1)], null],
            'a quantity that does not fit a sale'     => [[$this->line(1, '-1', '10.00')], null],
        ];

        foreach ($cases as $label => [$lines, $charges]) {
            $this->assertSameOutcome($this->sale($lines, charges: $charges), $label);
            $this->assertSameOutcome($this->sale($lines, null, $charges), "{$label} at the counter");
            $this->assertSameOutcome($this->sale($lines, charges: $charges, currency: new DocumentCurrency('USD', '3.7512', 2)), "{$label} in dollars");
        }

        $this->accountingDb()->table('accounting_routing')->where('routing_key', RoutingKey::SalesDiscount->value)->update(['routing_type' => 'auto', 'account_id' => $this->accountId('sales_discount')]);

        foreach (['a discount of a tenth on a line', 'a discount on the whole of two lines', 'a price that holds the tax'] as $label) {
            $this->assertSameOutcome($this->sale($cases[$label][0]), "{$label} with a discount account");
            $this->assertSameOutcome($this->sale($cases[$label][0], currency: new DocumentCurrency('USD', '0.2667', 2)), "{$label} with a discount account in dollars");
        }
    }

    public function testInvoice_GivesTheEngineWhatMakesTheJournalOfTheCodeWhenTheItemsNameAccountsOfRevenue(): void
    {
        $first  = $this->createTestItem();
        $second = $this->createTestItem();
        $plain  = $this->createTestItem();

        foreach ([[$first, '4101'], [$second, '4102']] as [$item, $code]) {
            $this->accountingDb()->table('item_routing_accounts')->replace(['item_id' => $item, 'routing_key' => RoutingKey::SalesRevenue->value, 'account_id' => $this->customAccount('41', $code, 'Revenue ' . $code)]);
        }

        $this->accountingDb()->table('accounting_routing')->where('routing_key', RoutingKey::SalesRevenue->value)->update(['routing_type' => RoutingType::PerItem->value]);

        $lines = [$this->line($first, '1', '33.33', added: '5.00'), $this->line($second, '2', '33.33', added: '10.00', location: 1), $this->line($plain, '1', '33.34', added: '5.00', stock: false)];

        $this->assertSameOutcome($this->sale($lines), 'three items of two accounts and one of none');
        $this->assertSameOutcome($this->sale($lines, currency: new DocumentCurrency('USD', '3.7512', 2)), 'the same in dollars');
        $this->assertSameOutcome($this->sale([$this->line($plain, '1', '50.00', added: '7.50')]), 'an item that names none');
    }

    public function testInvoice_GivesTheEngineWhatMakesTheJournalOfTheCodeWhenARoutingRowItNeedsCannotBeUsed(): void
    {
        $lines   = [$this->line(1, '1', '100.00', '10', '13.50', cost: '40.00'), $this->line(2, '1', '50.00', added: '7.50', stock: false)];
        $charges = new ResolvedCharges(adjustmentLabel: 'Round', adjustment: '0.2500', shipping: '10.0000');

        $journals = 0;

        foreach ([RoutingKey::Customers, RoutingKey::CounterCustomers, RoutingKey::SalesRevenue, RoutingKey::SalesDiscount, RoutingKey::TaxOutput, RoutingKey::SalesShipping, RoutingKey::SalesAdjustment, RoutingKey::ProductSales, RoutingKey::CostOfGoodsSold, RoutingKey::StockLocations] as $key) {
            foreach (['cancelled', 'per_item', 'auto', 'main_per_entity'] as $type) {
                $this->reinstallChart();
                $this->reopenEntityAccounts(['customer' => [$this->customerId], 'stock_location' => $this->locations]);
                $this->accountingDb()->table('accounting_routing')->where('routing_key', $key->value)->update(['routing_type' => $type, 'account_id' => null]);

                $journals += (int) $this->assertSameOutcome($this->sale($lines, charges: $charges), "{$key->value} {$type}");
                $journals += (int) $this->assertSameOutcome($this->sale($lines, null, $charges), "{$key->value} {$type} at the counter");
            }
        }

        $this->assertGreaterThanOrEqual(20, $journals, 'Most rows the sale can do without still give a journal');
    }

    public function testInvoice_GivesTheEngineWhatMakesTheJournalsOfTheCodeForRandomSales(): void
    {
        $random = new Randomizer(new Xoshiro256StarStar(20261130));
        $first  = $this->createTestItem();
        $second = $this->createTestItem();
        $plain  = $this->createTestItem();
        $items  = [$first, $second, $plain];
        $tax    = $this->customAccount('21', '2198', 'A tax of its own');

        $this->accountingDb()->table('item_routing_accounts')->replace(['item_id' => $first, 'routing_key' => RoutingKey::SalesRevenue->value, 'account_id' => $this->customAccount('41', '4103', 'Item revenue')]);

        for ($case = 0; $case < 240; $case++) {
            $this->accountingDb()->table('accounting_routing')->where('routing_key', RoutingKey::SalesRevenue->value)->update(['routing_type' => $case % 4 === 0 ? RoutingType::PerItem->value : RoutingType::Auto->value]);

            $lines = [];

            for ($index = 0, $size = $random->getInt(1, 6); $index < $size; $index++) {
                $quantity = (string) $random->getInt(0, 7);
                $price    = number_format($random->getInt(1, 40000) / 100, 2, '.', '');
                $discount = (string) [0, 0, 5, 10, 12.5][$random->getInt(0, 4)];
                $net      = bcmul(bcmul($quantity, $price, 4), bcsub('1', bcdiv($discount, '100', 4), 4), 2);
                $amount   = bcmul($net, ['0', '0.05', '0.15'][$random->getInt(0, 2)], 2);
                $included = $random->getInt(0, 3) === 0;
                $accounts = $random->getInt(0, 5) === 0 && bccomp($amount, '0', 2) > 0 ? [$tax => $amount] : [];

                $lines[] = $this->line($items[$random->getInt(0, 2)], $quantity, $price, $discount, $included ? '0' : $amount, $included ? $amount : '0', number_format($random->getInt(0, 30000) / 100, 2, '.', ''), $random->getInt(0, 3) > 0, $random->getInt(0, 1), accounts: $accounts);
            }

            $charges  = $random->getInt(0, 2) === 0 ? new ResolvedCharges(adjustmentLabel: 'Round', adjustment: number_format($random->getInt(-99, 99) / 100, 4, '.', ''), shipping: number_format($random->getInt(0, 3000) / 100, 4, '.', '')) : null;
            $currency = $case % 3 === 0 ? new DocumentCurrency('USD', number_format($random->getInt(1, 99999) / 10000, 4, '.', ''), 2) : null;

            $this->assertSameOutcome($this->sale($lines, $case % 5 === 0 ? null : -1, $charges, $currency), "random {$case}");
        }
    }
}
