<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\DocumentCurrency;
use App\Libraries\Accounting\Engine\PostingEngine;
use App\Libraries\Accounting\Engine\ShadowComparison;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\PaymentStatus;
use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\RoutingResolver;
use App\Libraries\Accounting\SaleDocument;
use App\Libraries\Accounting\SalePayment;
use App\Libraries\Accounting\SalePaymentFacts;
use App\Libraries\Accounting\SalePostingBuilder;
use App\Libraries\Accounting\TreasuryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class SalePaymentFactsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private int $treasuryId;
    private int $customerId;

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
        $this->treasuryId = (new TreasuryService())->create(['name' => 'Payment drawer ' . uniqid(), 'kind' => 'cash', 'branch_id' => 0]);
        $this->customerId = $this->insertCustomer();
        (new EntityAccountService())->ensure(EntityAccountService::CUSTOMER, $this->customerId, 'Omar Khaled');
    }

    protected function tearDown(): void
    {
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function sale(SalePayment $payment, ?int $customerId, ?DocumentCurrency $currency = null): SaleDocument
    {
        return new SaleDocument(31, '2026-10-21', 1, 0, $customerId, 1, true, null, [], [$payment], currency: $currency);
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

    private function assertSameOutcome(SaleDocument $sale, string $label): void
    {
        $payment = $sale->payments[0];
        $builder = new SalePostingBuilder(new RoutingResolver(), 2, static fn (): int => 2);
        $legacy  = self::outcome(static fn () => $builder->payment($sale, $payment, 1, 1));
        $engine  = self::outcome(static fn () => (new PostingEngine())->build(SalePaymentFacts::of($sale, $payment, 1, 1, 2)));

        if (! $legacy instanceof JournalDraft || ! $engine instanceof JournalDraft) {
            $this->assertSame($legacy instanceof JournalDraft ? 'a journal' : $legacy, $engine instanceof JournalDraft ? 'a journal' : $engine, $label);

            return;
        }

        $this->assertSame(['only_legacy' => [], 'only_engine' => []], ShadowComparison::differences($legacy, $engine), $label);
        $this->assertSame([$legacy->description, $legacy->date, $legacy->branchId, $legacy->sourceType, $legacy->sourceId], [$engine->description, $engine->date, $engine->branchId, $engine->sourceType, $engine->sourceId], $label);
    }

    public function testOf_GivesTheEngineWhatMakesTheJournalOfTheCodeForEveryKindOfPayment(): void
    {
        $types = [lang('Sales.cash'), lang('Sales.check'), lang('Sales.credit'), lang('Sales.bank_transfer'), lang('Sales.wallet'), lang('Sales.due'), lang('Sales.giftcard'), lang('Sales.rewards'), 'Barter'];
        $count = 0;

        foreach (['50.00', '0', '-20.00'] as $amount) {
            foreach (['0', '5.00'] as $refund) {
                foreach ($types as $type) {
                    foreach ([null, $this->treasuryId] as $treasury) {
                        foreach ([PaymentStatus::Completed, PaymentStatus::Incomplete] as $status) {
                            foreach ([$this->customerId, null] as $customer) {
                                $payment = new SalePayment(81, $type, $amount, $refund, false, '2026-10-22', $status, $treasury);

                                $this->assertSameOutcome($this->sale($payment, $customer), "{$amount}/{$refund}/{$type}/" . ($treasury ?? '-') . "/{$status->value}/" . ($customer ?? '-'));
                                $count++;
                            }
                        }
                    }
                }
            }
        }

        $this->assertSame(432, $count);
    }

    public function testOf_RequiresTheRoundingOfCashToGoToCashDifferencesOnTheOtherSide(): void
    {
        foreach (['0.05', '-0.05', '0'] as $amount) {
            foreach ([$this->customerId, null] as $customer) {
                $this->assertSameOutcome($this->sale(new SalePayment(82, lang('Sales.cash'), $amount, '0', true, '2026-10-22'), $customer), "adjustment {$amount}/" . ($customer ?? '-'));
            }
        }

        $this->assertSameOutcome($this->sale(new SalePayment(82, lang('Sales.cash'), '0.05', '0', true, '2026-10-22'), $this->customerId, new DocumentCurrency('USD', '3.75', 2)), 'foreign adjustment');
    }

    public function testOf_RequiresAForeignPaymentInTheBaseCurrencyAtItsOwnRateWithTheAmountOfTheDocument(): void
    {
        foreach (['100.00', '33.33', '-12.01', '0.01'] as $amount) {
            foreach (['3.75', '3.7512', '0.2667'] as $rate) {
                foreach ([null, $this->treasuryId] as $treasury) {
                    $this->assertSameOutcome($this->sale(new SalePayment(83, lang('Sales.cash'), $amount, '0', false, '2026-10-22', PaymentStatus::Completed, $treasury, null, $rate), $this->customerId, new DocumentCurrency('USD', '3.70', 2)), "{$amount}@{$rate}/" . ($treasury ?? '-'));
                }
            }
        }

        $this->assertSameOutcome($this->sale(new SalePayment(83, lang('Sales.cash'), '10.00', '0', false, '2026-10-22'), $this->customerId, new DocumentCurrency('USD', '3.70', 2)), 'no rate');
    }

    public function testOf_GivesTheEngineWhatMakesTheJournalOfTheCodeWhenARoutingRowItNeedsCannotBeUsed(): void
    {
        foreach ([RoutingKey::Customers, RoutingKey::CounterCustomers, RoutingKey::Treasuries, RoutingKey::CashDifference] as $key) {
            foreach (['cancelled', 'per_item'] as $type) {
                $this->reinstallChart();
                $this->accountingDb()->table('accounting_routing')->where('routing_key', $key->value)->update(['routing_type' => $type, 'account_id' => null]);

                foreach ([[lang('Sales.cash'), false, $this->treasuryId], [lang('Sales.cash'), false, null], [lang('Sales.cash'), true, null], [lang('Sales.due'), false, null]] as [$label, $adjustment, $treasury]) {
                    foreach ([$this->customerId, null] as $customer) {
                        $this->assertSameOutcome($this->sale(new SalePayment(84, $label, '40.00', '0', $adjustment, '2026-10-22', PaymentStatus::Completed, $treasury), $customer), "{$key->value} {$type} " . ($adjustment ? 'adjustment' : ($treasury === null ? 'routed ' . $label : 'treasury')) . '/' . ($customer ?? '-'));
                    }
                }
            }
        }

        $this->reinstallChart();
        $this->accountingDb()->table('accounting_routing')->where('routing_key', PaymentType::Cash->routingKey())->update(['account_id' => null]);
        $this->assertSameOutcome($this->sale(new SalePayment(84, lang('Sales.cash'), '40.00', '0', false, '2026-10-22'), $this->customerId), 'way of paying without an account');
    }
}
