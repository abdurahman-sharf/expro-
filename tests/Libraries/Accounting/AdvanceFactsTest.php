<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AdvanceFacts;
use App\Libraries\Accounting\CustomerAdvances;
use App\Libraries\Accounting\Engine\PostingEngine;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\RoutingResolver;
use App\Libraries\Accounting\SupplierAdvances;
use App\Libraries\Accounting\TreasuryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class AdvanceFactsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private int $treasuryId;

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
        $this->treasuryId = (new TreasuryService())->create(['name' => 'Advance drawer ' . uniqid(), 'kind' => 'cash', 'branch_id' => 0]);
    }

    protected function tearDown(): void
    {
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    /**
     * @return list<string>
     */
    private static function shape(?object $draft): array
    {
        return $draft === null ? [] : array_map(static fn ($line): string => $line->accountId . ':' . bcadd($line->debit, '0', 4) . ':' . bcadd($line->credit, '0', 4), $draft->lines);
    }

    public function testCustomer_RequiresTheMoneyInTheTreasuryOrTheWayOfPayingAndTheCreditOnTheCustomer(): void
    {
        $customer = $this->insertCustomer();
        $account  = (new EntityAccountService())->ensure(EntityAccountService::CUSTOMER, $customer, 'Omar Khaled');
        $engine   = new PostingEngine();

        $byTreasury = $engine->build(AdvanceFacts::customer(5, $customer, '250.00', '2026-10-22', lang('Sales.cash'), $this->treasuryId, 1, 1));
        $byPayment  = $engine->build(AdvanceFacts::customer(5, $customer, '250.00', '2026-10-22', lang('Sales.bank_transfer'), null, 1, 1));

        $this->assertSame([(new TreasuryService())->accountOf($this->treasuryId) . ':250.0000:0.0000', $account . ':0.0000:250.0000'], self::shape($byTreasury));
        $this->assertSame([(new RoutingResolver())->accountForPayment(PaymentType::BankTransfer) . ':250.0000:0.0000', $account . ':0.0000:250.0000'], self::shape($byPayment));
        $this->assertSame([CustomerAdvances::SOURCE, 5, 'Advance payment from customer #' . $customer], [$byTreasury->sourceType, $byTreasury->sourceId, $byTreasury->description]);
    }

    public function testSupplier_RequiresTheDebitOnTheSupplierAndTheMoneyOutOfTheTreasuryOrTheWayOfPaying(): void
    {
        $supplier = $this->insertSupplier();
        $account  = (new EntityAccountService())->ensure(EntityAccountService::SUPPLIER, $supplier, 'Gulf Supplies');
        $draft    = (new PostingEngine())->build(AdvanceFacts::supplier(6, $supplier, '80.00', '2026-10-22', lang('Sales.cash'), $this->treasuryId, 1, 1));

        $this->assertSame([(new TreasuryService())->accountOf($this->treasuryId) . ':0.0000:80.0000', $account . ':80.0000:0.0000'], self::shape($draft));
        $this->assertSame([SupplierAdvances::SOURCE, 'Payment to supplier #' . $supplier . ' without a purchase'], [$draft->sourceType, $draft->description]);
    }

    public function testCustomer_RejectsACustomerWithoutAnAccountAndAWayOfPayingWithNoAccountAsTheCodeDoes(): void
    {
        $customer = $this->insertCustomer('No', 'Account');
        $this->accountingDb()->table('accounting_routing')->where('routing_key', RoutingKey::Customers->value)->update(['routing_type' => 'main_per_entity']);

        $this->assertFailure(PostingError::EntityAccountMissing, fn () => (new PostingEngine())->build(AdvanceFacts::customer(7, $customer, '10.00', '2026-10-22', lang('Sales.cash'), null, 1, 1)));

        $this->reinstallChart();

        $this->assertFailure(PostingError::PaymentTypeUnknown, fn () => (new PostingEngine())->build(AdvanceFacts::customer(7, $customer, '10.00', '2026-10-22', lang('Sales.due'), null, 1, 1)));
    }

    private function assertFailure(PostingError $expected, callable $build): void
    {
        try {
            $build();
            $this->fail("Expected {$expected->value}.");
        } catch (PostingException $failure) {
            $this->assertSame($expected, $failure->error);
        }
    }
}
