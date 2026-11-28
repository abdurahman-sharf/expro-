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
use App\Libraries\Accounting\SupplierPaymentDifferenceFacts;
use App\Libraries\Accounting\TreasuryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class SupplierPaymentDifferenceFactsTest extends CIUnitTestCase
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
        $this->treasuryId = (new TreasuryService())->create(['name' => 'Exchange drawer ' . uniqid(), 'kind' => 'cash', 'branch_id' => 0]);
        $this->supplierId = $this->insertSupplier();
        (new EntityAccountService())->ensure(EntityAccountService::SUPPLIER, $this->supplierId, 'Gulf Supplies');
    }

    protected function tearDown(): void
    {
        $this->removeInsertedEntities();

        parent::tearDown();
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

    private function assertSameOutcomes(ReceivingDocument $purchase, string $label): void
    {
        $builder = new ReceivingPostingBuilder(new RoutingResolver(), new StockLedger(), static fn (): int => 2);

        foreach ($purchase->payments as $payment) {
            $legacy = self::outcome(static fn () => $builder->supplierPaymentDifference($purchase, $payment, 1, 1));
            $engine = self::outcome(static fn () => (new PostingEngine())->build(SupplierPaymentDifferenceFacts::of($purchase, $payment, 1, 1, 2, new RoutingResolver())));
            $which  = "{$label} payment {$payment->paymentId}";

            if (! $legacy instanceof JournalDraft || ! $engine instanceof JournalDraft) {
                $this->assertSame($legacy instanceof JournalDraft ? 'a journal' : $legacy, $engine instanceof JournalDraft ? 'a journal' : $engine, $which);

                continue;
            }

            $this->assertSame(['only_legacy' => [], 'only_engine' => []], ShadowComparison::differences($legacy, $engine), $which);
            $this->assertSame([$legacy->description, $legacy->date, $legacy->sourceType], [$engine->description, $engine->date, $engine->sourceType], $which);
        }
    }

    /**
     * @param list<array{0: string, 1: ?string, 2?: string, 3?: PaymentStatus, 4?: ?int}> $payments Amount, rate, type, status, treasury.
     */
    private function purchase(array $payments, ?int $supplierId, ?DocumentCurrency $currency, ?string $paidAtOnce = null, ReceivingType $type = ReceivingType::Receive): ReceivingDocument
    {
        $built = [];

        foreach ($payments as $index => $payment) {
            $built[] = new SupplierPayment(60 + $index, $payment[2] ?? lang('Sales.cash'), $payment[0], '2026-10-05', $payment[3] ?? PaymentStatus::Completed, $payment[4] ?? null, null, $payment[1]);
        }

        return new ReceivingDocument(17, '2026-10-01', $type, $supplierId, 1, $paidAtOnce, null, [new ReceivingLine(1, 1, '3', '1', '33.33', '0', 0, '15', 1, true, '0')], null, $built, null, null, [], '2026-10-01', $currency);
    }

    public function testOf_GivesTheEngineWhatMakesTheExchangeDifferenceOfTheCodeForEveryPaymentOfAPurchase(): void
    {
        $usd   = new DocumentCurrency('USD', '3.7500', 2);
        $count = 0;

        $cases = [
            'one at the rate of the purchase' => [['100.00', '3.7500']],
            'one at a higher rate'            => [['100.00', '3.8000']],
            'one at a lower rate'             => [['100.00', '3.7000']],
            'thirds that round'               => [['33.33', '3.7512'], ['33.33', '3.7488'], ['33.34', '3.7500']],
            'money given back after'          => [['100.00', '3.7600'], ['-40.00', '3.7300']],
            'one left on credit between'      => [['50.00', '3.7600'], ['30.00', '3.7700', lang('Sales.due')], ['20.00', '3.7300']],
            'a draft between'                 => [['50.00', '3.7600'], ['30.00', '3.7700', lang('Sales.cash'), PaymentStatus::Draft], ['20.00', '3.7300']],
            'one out of a treasury'           => [['60.00', '3.7900', lang('Sales.cash'), PaymentStatus::Completed, $this->treasuryId], ['40.00', '3.7100']],
            'one with no rate'                => [['60.00', null]],
            'one in a way nobody knows'       => [['60.00', '3.7900', 'Barter']],
        ];

        foreach ($cases as $label => $payments) {
            foreach ([null, lang('Sales.cash'), lang('Sales.due')] as $atOnce) {
                $this->assertSameOutcomes($this->purchase($payments, $this->supplierId, $usd, $atOnce), "{$label}/" . ($atOnce ?? '-'));
                $this->assertSameOutcomes($this->purchase($payments, null, $usd, $atOnce), "{$label} with no supplier/" . ($atOnce ?? '-'));
                $this->assertSameOutcomes($this->purchase($payments, $this->supplierId, null, $atOnce), "{$label} in the base currency/" . ($atOnce ?? '-'));
                $this->assertSameOutcomes($this->purchase($payments, $this->supplierId, $usd, $atOnce, ReceivingType::Return), "{$label} of a return/" . ($atOnce ?? '-'));
                $count++;
            }
        }

        $this->assertSame(30, $count);
    }

    public function testOf_GivesTheEngineWhatMakesTheExchangeDifferenceOfTheCodeWhenARoutingRowItNeedsCannotBeUsed(): void
    {
        $payments = [['100.00', '3.8000'], ['50.00', '3.7000', lang('Sales.cash'), PaymentStatus::Completed, $this->treasuryId]];

        foreach ([RoutingKey::FxGain, RoutingKey::FxLoss, RoutingKey::Suppliers, RoutingKey::Treasuries] as $key) {
            foreach (['cancelled', 'per_item', 'main_per_entity'] as $type) {
                $this->reinstallChart();
                $this->reopenEntityAccounts(['supplier' => [$this->supplierId], 'treasury' => [$this->treasuryId]]);
                $this->accountingDb()->table('accounting_routing')->where('routing_key', $key->value)->update(['routing_type' => $type, 'account_id' => null]);

                $this->assertSameOutcomes($this->purchase($payments, $this->supplierId, new DocumentCurrency('USD', '3.7500', 2), lang('Sales.cash')), "{$key->value} {$type}");
            }
        }
    }
}
