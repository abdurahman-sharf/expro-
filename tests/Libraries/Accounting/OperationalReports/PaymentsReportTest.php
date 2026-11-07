<?php

namespace Tests\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\CustomerBalanceReport;
use App\Libraries\Accounting\OperationalReports\PaymentGroup;
use App\Libraries\Accounting\OperationalReports\PaymentsReport;
use App\Libraries\Accounting\OperationalReports\ReportTable;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\TreasuryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * What customers paid: only the completed payments whose journals stand, the method and the treasury as
 * two groupings that agree on the total, the credit applied left out, and the total against the accounts
 * the money went into and against the balances of the customers.
 */
class PaymentsReportTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private int $drawerA;
    private int $drawerB;
    private int $second;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startSelling();

        $this->second  = $this->anotherCustomer();
        $this->drawerA = (new TreasuryService())->create(['name' => 'Drawer A', 'kind' => 'cash']);
        $this->drawerB = (new TreasuryService())->create(['name' => 'Drawer B', 'kind' => 'cash']);
    }

    protected function tearDown(): void
    {
        $this->stopSelling();

        parent::tearDown();
    }

    /**
     * A cash payment of 100 on an invoice of 115 into drawer A, a check of 80 on an invoice of 80 into
     * drawer A, and a cash payment of 30 on a sale to nobody into drawer B.
     */
    private function payments(): void
    {
        $this->save([$this->line($this->item('0', false), 0, '1', '115')], [], [[...$this->payment(lang('Sales.cash'), '100'), 'treasury_id' => $this->drawerA]], SALE_TYPE_INVOICE, $this->customerId);
        $this->save([$this->line($this->item('0', false), 0, '1', '80')], [], [[...$this->payment(lang('Sales.check'), '80'), 'treasury_id' => $this->drawerA, 'reference_code' => 'CHQ-1']], SALE_TYPE_INVOICE, $this->second);
        $this->save([$this->line($this->item('0', false), 0, '1', '30')], [], [[...$this->payment(lang('Sales.cash'), '30'), 'treasury_id' => $this->drawerB]], SALE_TYPE_POS, null);
    }

    private function table(PaymentGroup $group, bool $details = false, array $arguments = []): ReportTable
    {
        return (new PaymentsReport())->build(
            ReportScope::resolve(null, null),
            $group,
            $details,
            $arguments['from'] ?? '2020-01-01',
            $arguments['to'] ?? date('Y-m-d'),
            $arguments['customer'] ?? null,
            $arguments['user'] ?? null,
            $arguments['collector'] ?? null,
            $arguments['method'] ?? '',
            $arguments['treasury'] ?? null,
        );
    }

    /**
     * @return array<string, string>
     */
    private function byLabel(ReportTable $table, string $column): array
    {
        $totals = [];

        foreach ($table->blocks[0]->rows as $row) {
            $totals[$row[$column]] = $row['amount'];
        }

        return $totals;
    }

    public function testBuild_GivesTheSameTotalInEveryGrouping(): void
    {
        $this->payments();

        foreach (PaymentGroup::cases() as $group) {
            foreach ([false, true] as $details) {
                $this->assertSame('210.0000', $this->table($group, $details)->total['amount'], $group->value);
            }
        }
    }

    public function testBuild_GroupsByTreasuryAndByMethodIndependently(): void
    {
        $this->payments();

        $this->assertEquals(['Drawer A' => '180.0000', 'Drawer B' => '30.0000'], $this->byLabel($this->table(PaymentGroup::Treasury), 'treasury'));
        $this->assertEquals([lang('Sales.cash') => '130.0000', lang('Sales.check') => '80.0000'], $this->byLabel($this->table(PaymentGroup::Method), 'method'));
    }

    public function testBuild_GroupsByCustomerWithTheSaleToNobodyUnderItsOwnName(): void
    {
        $this->payments();

        $rows = $this->byLabel($this->table(PaymentGroup::Customer), 'customer');

        $this->assertSame('30.0000', $rows[lang('Accounting_operational_reports.walk_in')]);
        $this->assertSame('100.0000', $rows['Omar Khaled']);
        $this->assertCount(3, $rows);
    }

    public function testBuild_ShowsTheReferenceAndTheMethodOfAPayment(): void
    {
        $this->payments();

        $rows = array_merge([], ...array_map(static fn ($block): array => $block->rows, $this->table(PaymentGroup::Method, true)->blocks));

        $this->assertContains('CHQ-1', array_column($rows, 'reference'));
        $this->assertEqualsCanonicalizing(['100.0000', '30.0000', '80.0000'], array_column($rows, 'amount'));
    }

    public function testBuild_CountsOnlyCompletedPaymentsAndNotCreditApplied(): void
    {
        $invoice = $this->save([$this->line($this->item('0', false), 0, '1', '115')], [], [[...$this->payment(lang('Sales.cash'), '100'), 'treasury_id' => $this->drawerA]], SALE_TYPE_INVOICE, $this->customerId);

        $this->assertTrue($this->sales->update($invoice, ['comment' => 'reviewed', 'payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '50', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId, 'reference_code' => null, 'payment_status' => 'review'],
        ]]));
        $this->creditNoteOf($invoice, '57.50', $this->customerId);

        $table = $this->table(PaymentGroup::Customer);

        $this->assertSame('100.0000', $table->total['amount']);
        $this->assertSame(1, $table->documents);
    }

    public function testBuild_CountsARefundAgainstWhatWasPaid(): void
    {
        $invoice = $this->save([$this->line($this->item('0', false), 0, '1', '115')], [], [[...$this->payment(lang('Sales.cash'), '115'), 'treasury_id' => $this->drawerA]], SALE_TYPE_INVOICE, $this->customerId);
        $this->save([$this->line($this->item('0', false), 0, '-1', '40')], [], [[...$this->payment(lang('Sales.cash'), '-40'), 'treasury_id' => $this->drawerA]], SALE_TYPE_RETURN, $this->customerId, true, $invoice);

        $this->assertSame('75.0000', $this->table(PaymentGroup::Treasury)->total['amount']);
    }

    public function testBuild_TiesToTheAccountsTheMoneyWentIntoAndToTheBalancesOfTheCustomers(): void
    {
        $this->payments();

        $table = $this->table(PaymentGroup::Treasury);
        $drawer = (new TreasuryService())->position($this->drawerA)->balance;

        $this->assertTrue($table->ties());
        $this->assertSame('210.0000', $table->ties[0]->ledger);
        $this->assertSame('180.0000', bcadd($drawer, '0', 4));

        $balances = (new CustomerBalanceReport())->build(ReportPeriod::of('2020-01-01', date('Y-m-d')));
        $paid     = '0';

        foreach ($balances->rows as $row) {
            $paid = bcadd($paid, $row->payments, 4);
        }

        $this->assertSame('180.0000', bcadd($paid, '0', 4));
    }

    public function testBuild_FiltersByCustomerMethodAndTreasury(): void
    {
        $this->payments();

        $this->assertSame('100.0000', $this->table(PaymentGroup::Customer, false, ['customer' => $this->customerId])->total['amount']);
        $this->assertSame('80.0000', $this->table(PaymentGroup::Customer, false, ['method' => 'check'])->total['amount']);
        $this->assertSame('30.0000', $this->table(PaymentGroup::Customer, false, ['treasury' => (int) $this->accountingDb()->table('accounting_accounts')->select('account_id')->where(['entity_type' => 'treasury', 'entity_id' => $this->drawerB])->get()->getRow()->account_id])->total['amount']);
        $this->assertSame('0.0000', $this->table(PaymentGroup::Customer, false, ['collector' => $this->employeeId + 1000])->total['amount']);
    }

    public function testBuild_TellsTheEmployeeOfTheInvoiceFromTheOneWhoTookThePayment(): void
    {
        $collector = $this->createEmployee('Cora', 'Collector');

        $this->save([$this->line($this->item('0', false), 0, '1', '100')], [], [$this->payment(lang('Sales.cash'), '60')], SALE_TYPE_INVOICE, $this->customerId);
        $other = $this->save([$this->line($this->item('0', false), 0, '1', '50')], [], [$this->payment(lang('Sales.cash'), '50')], SALE_TYPE_INVOICE, $this->customerId);

        $this->accountingDb()->table('sales_payments')->where('sale_id', $other)->update(['employee_id' => $collector]);

        $this->assertEquals(['Temp Employee' => '110.0000'], $this->byLabel($this->table(PaymentGroup::User), 'user'));
        $this->assertEquals(['Temp Employee' => '60.0000', 'Cora Collector' => '50.0000'], $this->byLabel($this->table(PaymentGroup::Collector), 'collector'));
        $this->assertSame('110.0000', $this->table(PaymentGroup::Customer, false, ['user' => $this->employeeId])->total['amount']);
        $this->assertSame('0.0000', $this->table(PaymentGroup::Customer, false, ['user' => $collector])->total['amount']);
        $this->assertSame('50.0000', $this->table(PaymentGroup::Customer, false, ['collector' => $collector])->total['amount']);
        $this->assertSame('60.0000', $this->table(PaymentGroup::Customer, false, ['collector' => $this->employeeId])->total['amount']);
    }

    public function testBuild_FillsEmptyDaysAndStartsAWeekOnMonday(): void
    {
        $this->payments();

        $today = date('Y-m-d');
        $from  = date('Y-m-d', strtotime('-1 day'));
        $days  = $this->table(PaymentGroup::Day, false, ['from' => $from]);
        $weeks = $this->table(PaymentGroup::Week, false, ['from' => $from]);

        $this->assertSame(['0.0000', '210.0000'], array_column($days->blocks[0]->rows, 'amount'));
        $this->assertSame([$from, $today], array_column($days->blocks[0]->rows, 'period'));
        $this->assertSame('1', date('N', strtotime($weeks->blocks[0]->rows[0]['period_from'])));
    }

    public function testBuild_ReadsNothingForAScopeOfNoBranch(): void
    {
        $this->payments();

        $table = (new PaymentsReport())->build(ReportScope::resolve(null, []), PaymentGroup::Customer, false, '2020-01-01', date('Y-m-d'));

        $this->assertTrue($table->isEmpty());
        $this->assertSame('0.0000', $table->total['amount']);
    }
    public function testBuild_ShowsAPaymentIntoTheDefaultCashAccountThatIsNoTreasury(): void
    {
        $this->save([$this->line($this->item('0', false), 0, '1', '10')], [], [$this->payment(lang('Sales.cash'), '10')], SALE_TYPE_INVOICE, $this->customerId);

        $table = $this->table(PaymentGroup::Treasury);

        $this->assertSame('10.0000', $table->total['amount']);
        $this->assertCount(1, $table->blocks[0]->rows);
        $this->assertTrue($table->ties());
    }

    public function testBuild_LeavesOutTheRoundingOfASaleToTheCashDifference(): void
    {
        $this->save([$this->line($this->item('0', false), 0, '1', '10')], [], [$this->payment(lang('Sales.cash'), '10'), $this->payment(lang('Sales.cash_adjustment'), '0.02', '0', 1)], SALE_TYPE_INVOICE, $this->customerId);

        $table = $this->table(PaymentGroup::Customer, true);

        $this->assertSame('10.0000', $table->total['amount']);
        $this->assertSame(1, $table->documents);
    }

    public function testBuild_LeavesOutAPaymentThatWasTakenOffTheSale(): void
    {
        $invoice = $this->save([$this->line($this->item('0', false), 0, '1', '100')], [], [], SALE_TYPE_INVOICE, $this->customerId);
        $payment = $this->addPayment($invoice, '60');

        $this->assertSame('60.0000', $this->table(PaymentGroup::Customer)->total['amount']);

        $this->removePayment($invoice, $payment);

        $this->assertSame('0.0000', $this->table(PaymentGroup::Customer)->total['amount']);
    }

    public function testBuild_ReadsOnlyThePaymentsOfTheBranchesOfTheScope(): void
    {
        $second = $this->createBranch('PRB', 'Second payments branch');
        $this->invoiceInBranch(1, '100', [$this->payment(lang('Sales.cash'), '100')], $this->customerId);
        $this->invoiceInBranch($second, '70', [$this->payment(lang('Sales.cash'), '70')], $this->customerId);

        $report = new PaymentsReport();
        $of     = static fn (ReportScope $scope): string => $report->build($scope, PaymentGroup::Customer, false, '2020-01-01', date('Y-m-d'))->total['amount'];

        $this->assertSame('170.0000', $of(ReportScope::resolve(null, null)));
        $this->assertSame('100.0000', $of(ReportScope::resolve([1], null)));
        $this->assertSame('70.0000', $of(ReportScope::resolve(null, [$second])));
    }
}
