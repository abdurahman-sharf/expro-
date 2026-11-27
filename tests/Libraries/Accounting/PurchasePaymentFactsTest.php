<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\DocumentCurrency;
use App\Libraries\Accounting\Engine\PostingEngine;
use App\Libraries\Accounting\Engine\ShadowComparison;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\PurchasePaymentFacts;
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
use Tests\Support\AccountingFixtureTrait;

class PurchasePaymentFactsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private int $treasuryId;
    private int $supplierId;

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
        $this->treasuryId = (new TreasuryService())->create(['name' => 'Purchase drawer ' . uniqid(), 'kind' => 'cash', 'branch_id' => 0]);
        $this->supplierId = $this->insertSupplier();
        (new EntityAccountService())->ensure(EntityAccountService::SUPPLIER, $this->supplierId, 'Gulf Supplies');
    }

    protected function tearDown(): void
    {
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    /**
     * @return list<ReceivingLine>
     */
    private static function lines(string $quantity, string $price): array
    {
        return [new ReceivingLine(1, 1, $quantity, '1', $price, '0', 0, '15', 1, true, '0'), new ReceivingLine(2, 2, $quantity, '1', '3.33', '0', 0, '0', 1, false, '0')];
    }

    private function purchase(ReceivingType $type, ?int $supplierId, ?string $paymentType, ?int $treasuryId, string $quantity = '2', string $price = '40.00', ?DocumentCurrency $currency = null): ReceivingDocument
    {
        return new ReceivingDocument(15, '2026-10-01', $type, $supplierId, 1, $paymentType, null, self::lines($quantity, $price), null, [], $treasuryId, null, [], '2026-10-03', $currency);
    }

    /**
     * @return JournalDraft|string|null
     */
    private static function outcome(callable $build): JournalDraft|string|null
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
        $legacy  = self::outcome(static fn () => $builder->payment($purchase, 1, 1));
        $engine  = self::outcome(static fn () => (new PostingEngine())->build(PurchasePaymentFacts::of($purchase, 1, 1, 2)));

        if (! $legacy instanceof JournalDraft || ! $engine instanceof JournalDraft) {
            $this->assertSame($legacy instanceof JournalDraft ? 'a journal' : $legacy, $engine instanceof JournalDraft ? 'a journal' : $engine, $label);

            return;
        }

        $this->assertSame(['only_legacy' => [], 'only_engine' => []], ShadowComparison::differences($legacy, $engine), $label);
        $this->assertSame([$legacy->description, $legacy->date, $legacy->branchId, $legacy->sourceType, $legacy->sourceId], [$engine->description, $engine->date, $engine->branchId, $engine->sourceType, $engine->sourceId], $label);
    }

    public function testOf_GivesTheEngineWhatMakesTheJournalOfTheCodeForEveryKindOfPurchase(): void
    {
        $types = [lang('Sales.cash'), lang('Sales.check'), lang('Sales.credit'), lang('Sales.bank_transfer'), lang('Sales.due'), lang('Sales.giftcard'), lang('Sales.rewards'), 'Barter', null];
        $count = 0;

        foreach ([ReceivingType::Receive, ReceivingType::Return, ReceivingType::Requisition] as $kind) {
            foreach ([$this->supplierId, null] as $supplier) {
                foreach ($types as $type) {
                    foreach ([null, $this->treasuryId] as $treasury) {
                        foreach ([['2', '40.00'], ['0', '40.00'], ['3', '0']] as [$quantity, $price]) {
                            $this->assertSameOutcome($this->purchase($kind, $supplier, $type, $treasury, $quantity, $price), "{$kind->value}/" . ($supplier ?? '-') . '/' . ($type ?? '-') . '/' . ($treasury ?? '-') . "/{$quantity}x{$price}");
                            $count++;
                        }
                    }
                }
            }
        }

        $this->assertSame(324, $count);
    }

    public function testOf_RequiresAForeignPurchaseInTheBaseCurrencyAtTheRateOfThePurchase(): void
    {
        foreach (['3.75', '3.7512', '0.0001'] as $rate) {
            foreach ([ReceivingType::Receive, ReceivingType::Return] as $kind) {
                foreach ([null, $this->treasuryId] as $treasury) {
                    $this->assertSameOutcome($this->purchase($kind, $this->supplierId, lang('Sales.cash'), $treasury, '2', '40.00', new DocumentCurrency('USD', $rate, 2)), "{$rate}/{$kind->value}/" . ($treasury ?? '-'));
                }
            }
        }
    }

    public function testOf_GivesTheEngineWhatMakesTheJournalOfTheCodeWhenARoutingRowItNeedsCannotBeUsed(): void
    {
        foreach ([RoutingKey::Suppliers, RoutingKey::Treasuries] as $key) {
            foreach (['cancelled', 'per_item', 'main_per_entity'] as $type) {
                $this->reinstallChart();
                $this->accountingDb()->table('accounting_routing')->where('routing_key', $key->value)->update(['routing_type' => $type, 'account_id' => null]);

                foreach ([[lang('Sales.cash'), $this->treasuryId], [lang('Sales.cash'), null], [lang('Sales.due'), null]] as [$label, $treasury]) {
                    $this->assertSameOutcome($this->purchase(ReceivingType::Receive, $this->supplierId, $label, $treasury), "{$key->value} {$type} {$label}/" . ($treasury ?? '-'));
                }
            }
        }
    }
}
