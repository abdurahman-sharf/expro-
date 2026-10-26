<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\PaymentTreasuryStamping;
use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\RoutingService;
use App\Libraries\Accounting\SalePosting;
use App\Libraries\Accounting\TreasuryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * A payment that names no treasury follows the routing of its way of payment, wherever it points
 * later. Stamping it with the treasury its journal went into makes it stay where it was posted,
 * and changes nothing else.
 */
class PaymentTreasuryStampingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private PaymentTreasuryStamping $stamping;
    private TreasuryService $treasuries;
    private int $main;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBuying();
        $this->stamping   = new PaymentTreasuryStamping();
        $this->treasuries = new TreasuryService();
        $this->main       = $this->treasuries->ensureMain();
    }

    protected function tearDown(): void
    {
        $this->stopBuying();

        parent::tearDown();
    }

    private function bank(string $name = 'Routed bank'): int
    {
        return $this->treasuries->create(['name' => $name, 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890']);
    }

    private function saleWithPayment(string $type, string $amount = '100.00', array $extra = []): array
    {
        $item   = $this->item('10.00');
        $saleId = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment($type, $amount) + $extra]);

        return [$saleId, (int) $this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->get()->getRow()->payment_id];
    }

    private function treasuryOfSalePayment(int $paymentId): ?int
    {
        $stored = $this->accountingDb()->table('sales_payments')->where('payment_id', $paymentId)->get()->getRow()->treasury_id;

        return $stored === null ? null : (int) $stored;
    }

    private function treasuryOfSupplierPayment(int $paymentId): ?int
    {
        $stored = $this->accountingDb()->table('receivings_payments')->where('payment_id', $paymentId)->get()->getRow()->treasury_id;

        return $stored === null ? null : (int) $stored;
    }

    /**
     * @param array{stamp: list<array{table: string, payment_id: int, treasury_id: int, payment_type: string}>, skipped: list<array<string, mixed>>} $plan
     */
    private function stampOf(array $plan, string $table, int $paymentId): ?int
    {
        foreach ($plan['stamp'] as $row) {
            if ($row['table'] === $table && $row['payment_id'] === $paymentId) {
                return $row['treasury_id'];
            }
        }

        return null;
    }

    private function journalCount(): int
    {
        return $this->accountingDb()->table('accounting_journals')->countAllResults();
    }

    private function routeTo(string $routingKey, int $accountId): void
    {
        $this->accountingDb()->table('accounting_routing')->where('routing_key', $routingKey)->update(['account_id' => $accountId]);
    }

    public function testPlan_NamesTheTreasuryAnUnstampedSalePaymentAndSupplierPaymentWentInto(): void
    {
        [, $salePaymentId] = $this->saleWithPayment(lang('Sales.cash'));
        $purchaseId        = $this->buy([$this->purchaseLine($this->item('0.00'), 0, '4', '50')]);
        $supplierPaymentId = $this->payPurchase($purchaseId, '60.00');
        $plan              = $this->stamping->plan();

        $this->assertNull($this->treasuryOfSalePayment($salePaymentId));
        $this->assertSame($this->main, $this->stampOf($plan, 'sales_payments', $salePaymentId));
        $this->assertSame($this->main, $this->stampOf($plan, 'receivings_payments', $supplierPaymentId));
    }

    public function testPlan_LeavesOutWhatIsStampedWhatSettlesNothingInCashAndWhatWentToNoTreasury(): void
    {
        $drawer = $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash']);

        [, $stamped]  = $this->saleWithPayment(lang('Sales.cash'), '100.00', ['treasury_id' => $drawer]);
        [, $due]      = $this->saleWithPayment(lang('Sales.due'), '100.00');
        $this->routeTo('payment.bank_transfer', $this->accountId('sales_revenue'));
        [, $nowhere]  = $this->saleWithPayment(lang('Sales.bank_transfer'), '100.00');
        $plan         = $this->stamping->plan();

        $this->assertNull($this->stampOf($plan, 'sales_payments', $stamped));
        $this->assertNull($this->stampOf($plan, 'sales_payments', $due));
        $this->assertNull($this->stampOf($plan, 'sales_payments', $nowhere));
    }

    public function testStamp_StampsThePaymentsAndLeavesTheirJournalsAndTheBalancesAsTheyWere(): void
    {
        [$saleId, $salePaymentId] = $this->saleWithPayment(lang('Sales.cash'));
        $purchaseId               = $this->buy([$this->purchaseLine($this->item('0.00'), 0, '4', '50')]);
        $supplierPaymentId        = $this->payPurchase($purchaseId, '60.00');
        $journals                 = $this->journalCount();
        $before                   = $this->treasuries->position($this->main)->balance;

        $stamped = $this->stamping->stamp();

        $this->assertNotEmpty($stamped);
        $this->assertSame($this->main, $this->treasuryOfSalePayment($salePaymentId));
        $this->assertSame($this->main, $this->treasuryOfSupplierPayment($supplierPaymentId));
        $this->assertSame($journals, $this->journalCount(), 'No journal was written or reversed');
        $this->assertSame($before, $this->treasuries->position($this->main)->balance);

        (new SalePosting())->syncPayments($saleId);

        $this->assertSame($journals, $this->journalCount(), 'Posting it again with the stamp gives the journal it has');
        $this->assertSame([], $this->stamping->stamp(), 'A second run finds nothing');
    }

    public function testStamp_WritesDownHowManyPaymentsItStampedAndWho(): void
    {
        $this->saleWithPayment(lang('Sales.cash'));

        $employee = $this->employeeWithGrants([]);

        $this->stamping->stamp(null, $employee);

        $entry = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'payment', 'action' => 'treasury_stamped'])->orderBy('audit_id', 'DESC')->get()->getRow();

        $this->assertNotNull($entry);
        $this->assertSame((string) $employee, (string) $entry->employee_id);
        $this->assertGreaterThanOrEqual(1, json_decode($entry->details, true)['sales']);
    }

    public function testStamp_KeepsAStampedPaymentWhereItWasPostedWhenTheRoutingChangesAfterwards(): void
    {
        [$saleId, $paymentId] = $this->saleWithPayment(lang('Sales.cash'));
        $bank                 = $this->bank();

        $this->stamping->stamp();
        $this->routeTo('payment.cash', (int) $this->treasuries->accountOf($bank));
        (new SalePosting())->syncPayments($saleId);

        $this->assertSame($this->main, $this->treasuryOfSalePayment($paymentId));
        $this->assertSame('100.0000', $this->treasuries->position($this->main)->balance);
        $this->assertSame('0.0000', $this->treasuries->position($bank)->balance);
    }

    public function testSyncPayments_MovesAnUnstampedPaymentWhenTheRoutingChangesWhichIsWhatStampingPrevents(): void
    {
        [$saleId] = $this->saleWithPayment(lang('Sales.cash'));
        $bank     = $this->bank();

        $this->routeTo('payment.cash', (int) $this->treasuries->accountOf($bank));
        (new SalePosting())->syncPayments($saleId);

        $this->assertSame('0.0000', $this->treasuries->position($this->main)->balance);
        $this->assertSame('100.0000', $this->treasuries->position($bank)->balance);
    }

    public function testSave_StampsThePaymentsOfAWayOfPaymentBeforeItsRoutingChangesAndLeavesTheOthersAlone(): void
    {
        [$saleId, $cashId] = $this->saleWithPayment(lang('Sales.cash'));
        [, $debitId]       = $this->saleWithPayment(lang('Sales.debit'));
        $bank              = $this->bank();

        (new RoutingService())->save(['payment.cash' => (int) $this->treasuries->accountOf($bank)]);

        $this->assertSame($this->main, $this->treasuryOfSalePayment($cashId), 'The payment of the way that was moved stays');
        $this->assertNull($this->treasuryOfSalePayment($debitId), 'Another way of payment is not touched');

        (new SalePosting())->syncPayments($saleId);

        $this->assertSame('0.0000', $this->treasuries->position($bank)->balance);
    }

    public function testSave_StampsNothingWhenTheRoutingOfTheWayOfPaymentStaysWhereItWas(): void
    {
        [, $cashId] = $this->saleWithPayment(lang('Sales.cash'));

        (new RoutingService())->save(['payment.cash' => $this->accountId('cash')]);

        $this->assertNull($this->treasuryOfSalePayment($cashId));
    }

    public function testPlan_OnlyListsTheWaysOfPaymentAskedFor(): void
    {
        [, $cashId]  = $this->saleWithPayment(lang('Sales.cash'));
        [, $debitId] = $this->saleWithPayment(lang('Sales.debit'));
        $plan        = $this->stamping->plan([PaymentType::Debit]);

        $this->assertNull($this->stampOf($plan, 'sales_payments', $cashId));
        $this->assertSame($this->main, $this->stampOf($plan, 'sales_payments', $debitId));
    }

    public function testPlan_ListsAPaymentAsOneThatCannotBeStampedWhenTheRoutingOfTheTreasuriesCannotNameItsAccount(): void
    {
        [, $paymentId] = $this->saleWithPayment(lang('Sales.cash'));

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'treasuries.accounts')->update(['routing_type' => 'per_item']);

        $plan = $this->stamping->plan();

        $this->assertNull($this->stampOf($plan, 'sales_payments', $paymentId));
        $this->assertContains($paymentId, array_column($plan['skipped'], 'payment_id'));
        $this->assertSame([], $this->stamping->stamp(), 'Stamping does not fail');
    }

    public function testStamp_WritesTheEntryOfARunWithoutAnEmployeeAsTheSystemsOwn(): void
    {
        $this->saleWithPayment(lang('Sales.cash'));

        $this->stamping->stamp();

        $entry = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'payment', 'action' => 'treasury_stamped'])->orderBy('audit_id', 'DESC')->get()->getRow();

        $this->assertSame('system', $entry->origin);
    }

    public function testStamp_FailsRatherThanWriteWhileAnEditHoldsAPayment(): void
    {
        [, $paymentId] = $this->saleWithPayment(lang('Sales.cash'));
        $other         = $this->openSecondConnection();

        $other->transStart();
        $other->table('sales_payments')->where('payment_id', $paymentId)->update(['payment_type' => lang('Sales.debit')]);

        $this->accountingDb()->query('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $this->stamping->stamp();
            $this->fail('Stamping did not wait for the edit that holds the payment.');
        } catch (AccountingException $waited) {
            $this->assertSame(AccountingError::WriteFailed, $waited->error);
        } finally {
            $other->transRollback();
            $other->close();
            $this->accountingDb()->query('SET SESSION innodb_lock_wait_timeout = 50');
        }

        $this->assertNull($this->treasuryOfSalePayment($paymentId));
        $this->assertNotEmpty($this->stamping->stamp(), 'It stamps once the edit is gone');
    }

    public function testApply_StampsNothingForAPaymentWhoseWayOfPaymentChangedAfterThePlanWasMade(): void
    {
        [, $paymentId] = $this->saleWithPayment(lang('Sales.cash'));
        $plan          = $this->stamping->plan();

        $this->accountingDb()->table('sales_payments')->where('payment_id', $paymentId)->update(['payment_type' => lang('Sales.debit')]);

        $this->assertNotNull($this->stampOf($plan, 'sales_payments', $paymentId));
        $this->assertSame([], array_filter($this->stamping->apply($plan['stamp']), static fn (array $row): bool => $row['payment_id'] === $paymentId && $row['table'] === 'sales_payments'));
        $this->assertNull($this->treasuryOfSalePayment($paymentId));
    }

    public function testApply_StampsNothingForAPaymentThatWasGivenATreasuryAfterThePlanWasMade(): void
    {
        [, $paymentId] = $this->saleWithPayment(lang('Sales.cash'));
        $plan          = $this->stamping->plan();
        $drawer        = $this->treasuries->create(['name' => 'Named later', 'kind' => 'cash']);

        $this->accountingDb()->table('sales_payments')->where('payment_id', $paymentId)->update(['treasury_id' => $drawer]);
        $this->stamping->apply($plan['stamp']);

        $this->assertSame($drawer, $this->treasuryOfSalePayment($paymentId));
    }
}
