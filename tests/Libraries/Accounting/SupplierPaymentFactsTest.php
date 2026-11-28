<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\DocumentCurrency;
use App\Libraries\Accounting\Engine\PostingEngine;
use App\Libraries\Accounting\Engine\ShadowComparison;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\PaymentStatus;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\ReceivingDocument;
use App\Libraries\Accounting\ReceivingLine;
use App\Libraries\Accounting\ReceivingPostingBuilder;
use App\Libraries\Accounting\ReceivingType;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\RoutingResolver;
use App\Libraries\Accounting\StockLedger;
use App\Libraries\Accounting\SupplierPayment;
use App\Libraries\Accounting\SupplierPaymentFacts;
use App\Libraries\Accounting\TreasuryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class SupplierPaymentFactsTest extends CIUnitTestCase
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
        $this->treasuryId = (new TreasuryService())->create(['name' => 'Supplier drawer ' . uniqid(), 'kind' => 'cash', 'branch_id' => 0]);
        $this->supplierId = $this->insertSupplier();
        (new EntityAccountService())->ensure(EntityAccountService::SUPPLIER, $this->supplierId, 'Gulf Supplies');
    }

    protected function tearDown(): void
    {
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function purchase(SupplierPayment $payment, ReceivingType $type = ReceivingType::Receive, ?int $supplierId = -1, ?DocumentCurrency $currency = null): ReceivingDocument
    {
        return new ReceivingDocument(16, '2026-10-01', $type, $supplierId === -1 ? $this->supplierId : $supplierId, 1, lang('Sales.due'), null, [new ReceivingLine(1, 1, '2', '1', '50.00', '0', 0, '15', 1, true, '0')], null, [$payment], null, null, [], '2026-10-01', $currency);
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
        $payment = $purchase->payments[0];
        $builder = new ReceivingPostingBuilder(new RoutingResolver(), new StockLedger(), static fn (): int => 2);
        $legacy  = self::outcome(static fn () => $builder->supplierPayment($purchase, $payment, 1, 1));
        $engine  = self::outcome(static fn () => (new PostingEngine())->build(SupplierPaymentFacts::of($purchase, $payment, 1, 1, 2)));

        if (! $legacy instanceof JournalDraft || ! $engine instanceof JournalDraft) {
            $this->assertSame($legacy instanceof JournalDraft ? 'a journal' : $legacy, $engine instanceof JournalDraft ? 'a journal' : $engine, $label);

            return;
        }

        $this->assertSame(['only_legacy' => [], 'only_engine' => []], ShadowComparison::differences($legacy, $engine), $label);
        $this->assertSame([$legacy->description, $legacy->date, $legacy->branchId, $legacy->sourceType, $legacy->sourceId], [$engine->description, $engine->date, $engine->branchId, $engine->sourceType, $engine->sourceId], $label);
    }

    public function testOf_GivesTheEngineWhatMakesTheJournalOfTheCodeForEveryKindOfPayment(): void
    {
        $types = [lang('Sales.cash'), lang('Sales.check'), lang('Sales.credit'), lang('Sales.bank_transfer'), lang('Sales.due'), lang('Sales.giftcard'), lang('Sales.rewards'), 'Barter'];
        $count = 0;

        foreach (['60.00', '0', '-25.00'] as $amount) {
            foreach ($types as $type) {
                foreach ([null, $this->treasuryId] as $treasury) {
                    foreach ([PaymentStatus::Completed, PaymentStatus::Draft] as $status) {
                        $payment = new SupplierPayment(71, $type, $amount, '2026-10-05', $status, $treasury);

                        $this->assertSameOutcome($this->purchase($payment), "{$amount}/{$type}/" . ($treasury ?? '-') . "/{$status->value}");
                        $this->assertSameOutcome($this->purchase($payment, ReceivingType::Return), "return {$amount}/{$type}/" . ($treasury ?? '-') . "/{$status->value}");
                        $this->assertSameOutcome($this->purchase($payment, supplierId: null), "no supplier {$amount}/{$type}/" . ($treasury ?? '-') . "/{$status->value}");
                        $count++;
                    }
                }
            }
        }

        $this->assertSame(96, $count);
    }

    public function testOf_RequiresAForeignPaymentInTheBaseCurrencyAtItsOwnRateWithTheAmountOfThePurchase(): void
    {
        foreach (['100.00', '33.33', '-12.01', '0.01'] as $amount) {
            foreach (['3.75', '3.7512', '0.2667', null] as $rate) {
                foreach ([null, $this->treasuryId] as $treasury) {
                    $payment = new SupplierPayment(72, lang('Sales.cash'), $amount, '2026-10-05', PaymentStatus::Completed, $treasury, null, $rate);

                    $this->assertSameOutcome($this->purchase($payment, currency: new DocumentCurrency('USD', '3.70', 2)), "{$amount}@" . ($rate ?? '-') . '/' . ($treasury ?? '-'));
                }
            }
        }
    }

    public function testOf_GivesTheEngineWhatMakesTheJournalOfTheCodeWhenARoutingRowItNeedsCannotBeUsed(): void
    {
        foreach ([RoutingKey::Suppliers, RoutingKey::Treasuries] as $key) {
            foreach (['cancelled', 'per_item', 'main_per_entity'] as $type) {
                $this->reinstallChart();
                $this->reopenEntityAccounts(['supplier' => [$this->supplierId], 'treasury' => [$this->treasuryId]]);
                $this->accountingDb()->table('accounting_routing')->where('routing_key', $key->value)->update(['routing_type' => $type, 'account_id' => null]);

                foreach ([$this->treasuryId, null] as $treasury) {
                    $this->assertSameOutcome($this->purchase(new SupplierPayment(73, lang('Sales.cash'), '40.00', '2026-10-05', PaymentStatus::Completed, $treasury)), "{$key->value} {$type}/" . ($treasury ?? '-'));
                }
            }
        }
    }
}
