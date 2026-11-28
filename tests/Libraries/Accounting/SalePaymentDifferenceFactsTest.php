<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\DocumentCurrency;
use App\Libraries\Accounting\Engine\PostingEngine;
use App\Libraries\Accounting\Engine\ShadowComparison;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\PaymentStatus;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\RoutingResolver;
use App\Libraries\Accounting\SaleDocument;
use App\Libraries\Accounting\SalePayment;
use App\Libraries\Accounting\SalePaymentDifferenceFacts;
use App\Libraries\Accounting\SalePostingBuilder;
use App\Libraries\Accounting\TreasuryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class SalePaymentDifferenceFactsTest extends CIUnitTestCase
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
        $this->treasuryId = (new TreasuryService())->create(['name' => 'Exchange drawer ' . uniqid(), 'kind' => 'cash', 'branch_id' => 0]);
        $this->customerId = $this->insertCustomer();
        (new EntityAccountService())->ensure(EntityAccountService::CUSTOMER, $this->customerId, 'Omar Khaled');
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

    /**
     * Every payment of the sale gives the engine what makes the exchange difference the code posts for it.
     */
    private function assertSameOutcomes(SaleDocument $sale, string $label): void
    {
        $builder = new SalePostingBuilder(new RoutingResolver(), 2, static fn (): int => 2);

        foreach ($sale->payments as $payment) {
            $legacy = self::outcome(static fn () => $builder->paymentDifference($sale, $payment, 1, 1));
            $engine = self::outcome(static fn () => (new PostingEngine())->build(SalePaymentDifferenceFacts::of($sale, $payment, 1, 1, 2, new RoutingResolver())));
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
     * @param list<array{0: string, 1: ?string, 2?: string, 3?: PaymentStatus, 4?: ?int, 5?: bool}> $payments Amount, rate, type, status, treasury, cash rounding.
     */
    private function sale(array $payments, ?int $customerId, ?DocumentCurrency $currency): SaleDocument
    {
        $built = [];

        foreach ($payments as $index => $payment) {
            $built[] = new SalePayment(90 + $index, $payment[2] ?? lang('Sales.cash'), $payment[0], '0', $payment[5] ?? false, '2026-10-22', $payment[3] ?? PaymentStatus::Completed, $payment[4] ?? null, null, $payment[1]);
        }

        return new SaleDocument(32, '2026-10-21', 1, 0, $customerId, 1, true, null, [], $built, currency: $currency);
    }

    public function testOf_GivesTheEngineWhatMakesTheExchangeDifferenceOfTheCodeForEveryPaymentOfASale(): void
    {
        $usd   = new DocumentCurrency('USD', '3.7500', 2);
        $count = 0;

        $cases = [
            'one at the rate of the document'     => [['100.00', '3.7500']],
            'one at a higher rate'                => [['100.00', '3.8000']],
            'one at a lower rate'                 => [['100.00', '3.7000']],
            'thirds that round'                   => [['33.33', '3.7512'], ['33.33', '3.7488'], ['33.34', '3.7500']],
            'a refund after a payment'            => [['100.00', '3.7600'], ['-40.00', '3.7300']],
            'a payment left on credit between'    => [['50.00', '3.7600'], ['30.00', '3.7700', lang('Sales.due')], ['20.00', '3.7300']],
            'an incomplete payment between'       => [['50.00', '3.7600'], ['30.00', '3.7700', lang('Sales.cash'), PaymentStatus::Incomplete], ['20.00', '3.7300']],
            'a payment into a treasury'           => [['60.00', '3.7900', lang('Sales.cash'), PaymentStatus::Completed, $this->treasuryId], ['40.00', '3.7100']],
            'a payment with no rate'              => [['60.00', null]],
            'a payment in a way nobody knows'     => [['60.00', '3.7900', 'Barter']],
        ];

        foreach ($cases as $label => $payments) {
            foreach ([$this->customerId, null] as $customer) {
                $this->assertSameOutcomes($this->sale($payments, $customer, $usd), $label . '/' . ($customer ?? '-'));
                $this->assertSameOutcomes($this->sale($payments, $customer, null), $label . ' in the base currency/' . ($customer ?? '-'));
                $count++;
            }
        }

        $this->assertSame(20, $count);
    }

    public function testOf_GivesTheEngineWhatMakesTheExchangeDifferenceOfTheCodeWhenARoutingRowItNeedsCannotBeUsed(): void
    {
        $payments = [['100.00', '3.8000'], ['50.00', '3.7000', lang('Sales.cash'), PaymentStatus::Completed, $this->treasuryId]];

        foreach ([RoutingKey::FxGain, RoutingKey::FxLoss, RoutingKey::Customers, RoutingKey::CounterCustomers, RoutingKey::Treasuries] as $key) {
            foreach (['cancelled', 'per_item'] as $type) {
                $this->reinstallChart();
                $this->reopenEntityAccounts(['customer' => [$this->customerId], 'treasury' => [$this->treasuryId]]);
                $this->accountingDb()->table('accounting_routing')->where('routing_key', $key->value)->update(['routing_type' => $type, 'account_id' => null]);

                foreach ([$this->customerId, null] as $customer) {
                    $this->assertSameOutcomes($this->sale($payments, $customer, new DocumentCurrency('USD', '3.7500', 2)), "{$key->value} {$type}/" . ($customer ?? '-'));
                }
            }
        }
    }
}
