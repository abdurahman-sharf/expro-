<?php

namespace Tests\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\CreditSource;
use App\Libraries\Accounting\CustomerAdvances;
use App\Libraries\Accounting\OperationalReports\AdvancePaymentsReport;
use App\Libraries\Accounting\OperationalReports\ReportTable;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\TreasuryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The payments that belong to no invoice: each advance with what was spent of it and what is left, the
 * state filter, and the total against what the advances were saved with.
 */
class AdvancePaymentsReportTest extends CIUnitTestCase
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

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startSelling();
    }

    protected function tearDown(): void
    {
        $this->stopSelling();

        parent::tearDown();
    }

    private function teller(): int
    {
        return $this->employeeWithGrants([AccountingGuard::RECEIVABLES_MANAGE]);
    }

    private function table(string $state = '', ?int $customerId = null, ?int $createdBy = null, string $method = ''): ReportTable
    {
        return (new AdvancePaymentsReport())->build(ReportScope::resolve(null, null), '2020-01-01', date('Y-m-d'), $customerId, $createdBy, $method, $state);
    }

    public function testBuild_ListsAnAdvanceWithWhatIsLeftOfIt(): void
    {
        (new CustomerAdvances())->receive($this->customerId, '200', date('Y-m-d'), 'Cash', null, 'REF-1', '', $this->teller());

        $table = $this->table();
        $row   = $table->blocks[0]->rows[0];

        $this->assertSame(['200.0000', '0.0000', '0.0000', '200.0000'], [$row['amount'], $row['applied'], $row['refunded'], $row['unsettled']]);
        $this->assertSame('200.0000', $table->total['unsettled']);
        $this->assertTrue($table->ties());
    }

    public function testBuild_ShowsWhatAnInvoiceSpentOfAnAdvance(): void
    {
        (new CustomerAdvances())->receive($this->customerId, '200', date('Y-m-d'), 'Cash', null, '', '', $this->teller());
        $this->save([$this->line($this->item('0', false), 0, '1', '120')], [], [], SALE_TYPE_INVOICE, $this->customerId);
        $this->settle();

        $row = $this->table()->blocks[0]->rows[0];

        $this->assertSame(['200.0000', '120.0000', '80.0000'], [$row['amount'], $row['applied'], $row['unsettled']]);
        $this->assertCount(1, $this->table('open')->blocks[0]->rows);
        $this->assertCount(0, $this->table('spent')->blocks[0]->rows);
    }

    public function testBuild_LeavesOutACancelledAdvanceAndLimitsToACustomer(): void
    {
        $other     = $this->anotherCustomer();
        $advances  = new CustomerAdvances();
        $kept      = $advances->receive($this->customerId, '50', date('Y-m-d'), 'Cash', null, '', '', $this->teller());
        $cancelled = $advances->receive($other, '70', date('Y-m-d'), 'Cash', null, '', '', $this->teller());

        $advances->cancel($cancelled, 'wrong customer', $this->teller());

        $this->assertSame('50.0000', $this->table()->total['amount']);
        $this->assertSame('0.0000', $this->table('', $other)->total['amount']);
        $this->assertGreaterThan(0, $kept);
    }

    public function testBuild_ShowsAnAdvanceWhoseJournalDoesNotStandAsNothingTheBooksHoldAndSaysTheyDiffer(): void
    {
        $advances = new CustomerAdvances();
        $advances->receive($this->customerId, '50', date('Y-m-d'), 'Cash', null, '', '', $this->teller());
        $lost = $advances->receive($this->customerId, '70', date('Y-m-d'), 'Cash', null, '', '', $this->teller());

        $this->accountingDb()->table('accounting_journals')->where(['source_type' => CreditSource::CUSTOMER_ADVANCE, 'source_id' => $lost])->update(['status' => 'reversed']);

        $table = $this->table();
        $rows  = $table->blocks[0]->rows;

        $this->assertSame(['50.0000', '0.0000'], array_column($rows, 'amount'));
        $this->assertSame('50.0000', $table->total['unsettled']);
        $this->assertFalse($table->ties());
        $this->assertSame(['50.0000', '120.0000'], [$table->ties[0]->reported, $table->ties[0]->ledger]);
        $this->assertCount(1, $this->table('open')->blocks[0]->rows);
        $this->assertCount(0, $this->table('spent')->blocks[0]->rows);
    }

    public function testBuild_FiltersByTheEmployeeWhoMadeTheAdvanceAndByTheMethod(): void
    {
        $other  = $this->teller();
        $advances = new CustomerAdvances();

        $advances->receive($this->customerId, '50', date('Y-m-d'), 'Cash', null, '', '', $this->teller());
        $advances->receive($this->customerId, '70', date('Y-m-d'), 'Check', null, '', '', $other);

        $this->assertSame('120.0000', $this->table()->total['amount']);
        $this->assertSame('70.0000', $this->table('', null, $other)->total['amount']);
        $this->assertSame('0.0000', $this->table('', null, $this->employeeId + 1000)->total['amount']);
        $this->assertSame('70.0000', $this->table('', null, null, 'check')->total['amount']);
        $this->assertSame('50.0000', $this->table('', null, null, 'cash')->total['amount']);
    }

    public function testBuild_ReadsNothingForAScopeOfNoBranch(): void
    {
        (new CustomerAdvances())->receive($this->customerId, '50', date('Y-m-d'), 'Cash', null, '', '', $this->teller());

        $table = (new AdvancePaymentsReport())->build(ReportScope::resolve(null, []), '2020-01-01', date('Y-m-d'));

        $this->assertTrue($table->isEmpty());
    }
    public function testBuild_ReadsOnlyTheAdvancesOfTheBranchesOfTheScope(): void
    {
        $second = $this->createBranch('ADB', 'Second advances branch');
        $drawer = (new TreasuryService())->create(['name' => 'Advance drawer', 'kind' => 'cash', 'branch_id' => $second]);
        $teller = $this->teller();

        (new CustomerAdvances())->receive($this->customerId, '50', date('Y-m-d'), 'Cash', null, '', '', $teller);
        (new CustomerAdvances())->receive($this->customerId, '70', date('Y-m-d'), 'Cash', $drawer, '', '', $teller);

        $report = new AdvancePaymentsReport();
        $of     = static fn (ReportScope $scope): string => $report->build($scope, '2020-01-01', date('Y-m-d'))->total['amount'];

        $this->assertSame('120.0000', $of(ReportScope::resolve(null, null)));
        $this->assertSame('50.0000', $of(ReportScope::resolve([1], null)));
        $this->assertSame('70.0000', $of(ReportScope::resolve(null, [$second])));
    }
}
