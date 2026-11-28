<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\DocumentCurrency;
use App\Libraries\Accounting\Engine\PostingEngine;
use App\Libraries\Accounting\Engine\ShadowComparison;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\PurchaseFacts;
use App\Libraries\Accounting\ReceivingDocument;
use App\Libraries\Accounting\ReceivingLine;
use App\Libraries\Accounting\ReceivingPostingBuilder;
use App\Libraries\Accounting\ReceivingType;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\RoutingResolver;
use App\Libraries\Accounting\StockLedger;
use App\Libraries\Accounting\TreasuryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use Tests\Support\AccountingFixtureTrait;

class PurchaseFactsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private int $supplierId;
    private int $treasuryId;

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
        $this->supplierId = $this->insertSupplier();
        $this->treasuryId = (new TreasuryService())->create(['name' => 'Purchase drawer ' . uniqid(), 'kind' => 'cash', 'branch_id' => 0]);
        $this->locations  = [$this->insertStockLocation('Main store'), $this->insertStockLocation('Back store')];

        $entities->ensure(EntityAccountService::SUPPLIER, $this->supplierId, 'Gulf Supplies');

        foreach ($this->locations as $location) {
            $entities->ensure(EntityAccountService::STOCK_LOCATION, $location, 'Store ' . $location);
        }
    }

    protected function tearDown(): void
    {
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function line(int $item, string $quantity, string $price, string $tax = '15', bool $stock = true, int $location = 0, string $cost = '0', string $discount = '0', bool $saudi = false): ReceivingLine
    {
        return new ReceivingLine($item, $item, $quantity, '1', $price, $discount, 0, $tax, $this->locations[$location], $stock, $cost, vatWorkedOnce: $saudi);
    }

    /**
     * @param list<ReceivingLine> $lines
     */
    private function purchase(array $lines, ReceivingType $type = ReceivingType::Receive, ?int $supplierId = -1, ?string $paymentType = null, ?int $treasuryId = null, ?DocumentCurrency $currency = null, ?ReceivingDocument $origin = null): ReceivingDocument
    {
        return new ReceivingDocument(41, '2026-10-01', $type, $supplierId === -1 ? $this->supplierId : $supplierId, 1, $paymentType, $origin?->receivingId, $lines, $origin, [], $treasuryId, null, [], '2026-10-01', $currency);
    }

    private static function outcome(callable $build): mixed
    {
        try {
            return $build();
        } catch (PostingException $failure) {
            return $failure->error->value;
        }
    }

    private function assertSameOutcome(ReceivingDocument $purchase, string $label): void
    {
        $builder = new ReceivingPostingBuilder(new RoutingResolver(), new StockLedger(), static fn (): int => 2);
        $plan    = self::outcome(static fn () => $builder->purchase($purchase, 1, 1));
        $legacy  = is_string($plan) ? $plan : $plan->journal;
        $engine  = is_string($plan) ? $plan : self::outcome(static fn () => (new PostingEngine())->build($purchase->type === ReceivingType::Receive
            ? PurchaseFacts::receive($purchase, 1, 1, 2)
            : PurchaseFacts::giveBack($purchase, $plan->movements, 1, 1, 2)));

        if (! $legacy instanceof JournalDraft || ! $engine instanceof JournalDraft) {
            $this->assertSame($legacy instanceof JournalDraft ? 'a journal' : $legacy, $engine instanceof JournalDraft ? 'a journal' : $engine, $label);

            return;
        }

        $this->assertSame(['only_legacy' => [], 'only_engine' => []], ShadowComparison::differences($legacy, $engine), $label);
        $this->assertSame([$legacy->description, $legacy->date, $legacy->sourceType, $legacy->sourceId], [$engine->description, $engine->date, $engine->sourceType, $engine->sourceId], $label);
    }

    public function testReceive_GivesTheEngineWhatMakesTheJournalOfTheCodeForEveryKindOfPurchase(): void
    {
        $cases = [
            'a stock product'                         => [$this->line(1, '3', '40.00')],
            'a line that is not stocked'              => [$this->line(2, '1', '250.00', stock: false)],
            'stock and a line that is not stocked'    => [$this->line(1, '3', '40.00'), $this->line(2, '1', '250.00', '0', false)],
            'two taxes'                               => [$this->line(1, '3', '33.33', '15'), $this->line(3, '7', '9.99', '5', location: 1)],
            'two taxes worked once per rate'          => [$this->line(1, '3', '33.33', '15', saudi: true), $this->line(3, '7', '9.99', '5', location: 1, saudi: true)],
            'two stores and a discount'               => [$this->line(1, '2', '100.00', discount: '10'), $this->line(3, '4', '12.50', location: 1)],
            'no tax'                                  => [$this->line(1, '2', '100.00', '0')],
            'nothing'                                 => [$this->line(1, '0', '100.00')],
        ];

        foreach ($cases as $label => $lines) {
            $this->assertSameOutcome($this->purchase($lines), $label);
            $this->assertSameOutcome($this->purchase($lines, supplierId: null, paymentType: lang('Sales.cash')), "{$label} paid in cash with no supplier");
            $this->assertSameOutcome($this->purchase($lines, supplierId: null, paymentType: lang('Sales.cash'), treasuryId: $this->treasuryId), "{$label} paid from a treasury with no supplier");
            $this->assertSameOutcome($this->purchase($lines, supplierId: null, paymentType: lang('Sales.due')), "{$label} on credit with no supplier");
            $this->assertSameOutcome($this->purchase($lines, supplierId: null), "{$label} with no supplier and no way of paying");
            $this->assertSameOutcome($this->purchase($lines, supplierId: null, paymentType: 'Barter'), "{$label} with no supplier in a way nobody knows");
        }
    }

    public function testReceive_RequiresAForeignPurchaseOfManyLinesInTheBaseWithTheRoundingWhereTheCodePutsIt(): void
    {
        $random = new Randomizer(new Xoshiro256StarStar(20261129));

        foreach (['3.7512', '0.2667', '1.0050', '3.7500'] as $rate) {
            $currency = new DocumentCurrency('USD', $rate, 2);

            $this->assertSameOutcome($this->purchase([$this->line(1, '3', '33.33'), $this->line(3, '3', '33.33', '5', location: 1), $this->line(2, '1', '33.34', '0', false)], currency: $currency), "three thirds at {$rate}");
            $this->assertSameOutcome($this->purchase([$this->line(1, '1', '50.00'), $this->line(3, '1', '50.00', location: 1)], currency: $currency), "two as large at {$rate}");

            for ($case = 0; $case < 40; $case++) {
                $lines = [];

                for ($index = 0, $size = $random->getInt(2, 8); $index < $size; $index++) {
                    $lines[] = $this->line($index + 1, (string) $random->getInt(1, 12), number_format($random->getInt(1, 50000) / 100, 2, '.', ''), ['0', '5', '15'][$random->getInt(0, 2)], $random->getInt(0, 4) > 0, $random->getInt(0, 1), saudi: $case % 3 === 0);
                }

                $this->assertSameOutcome($this->purchase($lines, currency: $currency), "random {$case} at {$rate}");
            }
        }
    }

    public function testGiveBack_GivesTheEngineWhatMakesTheJournalOfTheCodeWithTheDifferenceOfPriceToTheCost(): void
    {
        $origin = $this->purchase([$this->line(1, '10', '40.00'), $this->line(3, '10', '9.99', '5', location: 1)]);

        foreach ([
            'at the cost it was bought at'      => [$this->line(1, '-2', '40.00', cost: '40.00')],
            'below the cost it stands at'       => [$this->line(1, '-2', '35.00', cost: '40.00')],
            'above the cost it stands at'       => [$this->line(1, '-2', '45.00', cost: '40.00')],
            'two taxes over two stores'         => [$this->line(1, '-2', '40.00', cost: '38.75'), $this->line(3, '-3', '9.99', '5', location: 1, cost: '9.50')],
            'with a line that is not stocked'   => [$this->line(1, '-1', '40.00', cost: '41.00'), $this->line(2, '-1', '100.00', stock: false)],
            'only a line that is not stocked'   => [$this->line(2, '-1', '100.00', stock: false)],
            'no tax and a cost of nothing'      => [$this->line(1, '-1', '40.00', '0', cost: '0')],
        ] as $label => $lines) {
            $this->assertSameOutcome($this->purchase($lines, ReceivingType::Return, origin: $origin), $label);
            $this->assertSameOutcome($this->purchase($lines, ReceivingType::Return, origin: $origin, currency: new DocumentCurrency('USD', '3.7512', 2)), "{$label} in a foreign currency");
            $this->assertSameOutcome($this->purchase($lines, ReceivingType::Return, null, lang('Sales.cash'), origin: $this->purchase($origin->lines, supplierId: null)), "{$label} paid back in cash with no supplier");
        }
    }

    public function testReceive_GivesTheEngineWhatMakesTheJournalOfTheCodeWhenARoutingRowItNeedsCannotBeUsed(): void
    {
        $lines  = [$this->line(1, '3', '40.00'), $this->line(2, '1', '250.00', stock: false)];
        $origin = $this->purchase([$this->line(1, '10', '40.00')]);

        foreach ([RoutingKey::Purchases, RoutingKey::TaxInput, RoutingKey::Suppliers, RoutingKey::StockLocations, RoutingKey::PurchasePriceVariance] as $key) {
            foreach (['cancelled', 'per_item', 'main_per_entity'] as $type) {
                $this->reinstallChart();
                $this->reopenEntityAccounts(['supplier' => [$this->supplierId], 'treasury' => [$this->treasuryId], 'stock_location' => $this->locations]);
                $this->accountingDb()->table('accounting_routing')->where('routing_key', $key->value)->update(['routing_type' => $type, 'account_id' => null]);

                $this->assertSameOutcome($this->purchase($lines), "{$key->value} {$type}");
                $this->assertSameOutcome($this->purchase([$this->line(1, '-2', '35.00', cost: '40.00'), $this->line(2, '-1', '100.00', stock: false)], ReceivingType::Return, origin: $origin), "return {$key->value} {$type}");
            }
        }
    }
}
