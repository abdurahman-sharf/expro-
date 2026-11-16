<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingOverview;
use App\Libraries\Accounting\ClosedPeriodService;
use App\Libraries\Accounting\CustomerBalanceReport;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\FiscalYearService;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\TreasuryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

/**
 * @internal
 */
final class AccountingOverviewTest extends CIUnitTestCase
{
    use AccountingFixtureTrait;
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    public static function setUpBeforeClass(): void
    {
        Database::seeder('tests')->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function postBetween(int $debitAccount, int $creditAccount, string $amount, string $date = '2026-10-21', ?int $employeeId = null): int
    {
        return (new JournalService())->postNew([
            'date'        => $date,
            'description' => 'Overview journal',
            'lines'       => [
                ['account_id' => $debitAccount, 'debit' => $amount, 'credit' => ''],
                ['account_id' => $creditAccount, 'debit' => '', 'credit' => $amount],
            ],
        ], $employeeId);
    }

    private function treasuryWithBalance(string $name, string $amount): int
    {
        $treasuries = new TreasuryService();
        $treasuries->ensureMain();
        $id = $treasuries->create(['name' => $name, 'kind' => TreasuryService::CASH, 'branch_id' => 1]);

        $this->postBetween($treasuries->accountOf($id), $this->accountId('sales_revenue'), $amount);

        return $id;
    }

    public function testTreasuries_ListsTheActiveTreasuriesWithTheirBalancesAndTheirTotal(): void
    {
        $this->treasuryWithBalance('Front desk', '40.00');
        $this->treasuryWithBalance('Back office', '60.00');

        $overview = (new AccountingOverview())->treasuries(null);
        $balances = array_column($overview['rows'], 'balance', 'name');

        $this->assertSame('40.0000', $balances['Front desk']);
        $this->assertSame('60.0000', $balances['Back office']);
        $this->assertSame('100.0000', $overview['total']);
        $this->assertSame(0, $overview['more']);
    }

    public function testTreasuries_LeavesOutATreasuryThatIsNotActive(): void
    {
        $treasuries = new TreasuryService();
        $treasuries->ensureMain();
        $treasuries->deactivate($treasuries->create(['name' => 'Closed drawer', 'kind' => TreasuryService::CASH, 'branch_id' => 1]));

        $names = array_column((new AccountingOverview())->treasuries(null)['rows'], 'name');

        $this->assertNotContains('Closed drawer', $names);
    }

    public function testTreasuries_ShowsTheFirstFewAndCountsTheRestInTheTotal(): void
    {
        $shown = AccountingOverview::TREASURIES_SHOWN;

        for ($number = 1; $number <= $shown + 2; $number++) {
            $this->treasuryWithBalance("Drawer {$number}", '10.00');
        }

        $overview = (new AccountingOverview())->treasuries(null);

        $this->assertCount($shown, $overview['rows']);
        $this->assertGreaterThanOrEqual(2, $overview['more']);
        $this->assertSame(bcmul('10', (string) ($shown + 2), 4), $overview['total']);
    }

    public function testTreasuries_ListsOnlyTheTreasuriesOfTheBranchesTheReaderMayUse(): void
    {
        $second = $this->createBranch('B2');
        $this->treasuryWithBalance('Main branch drawer', '40.00');
        (new TreasuryService())->create(['name' => 'Second branch drawer', 'kind' => TreasuryService::CASH, 'branch_id' => $second]);

        $names = array_column((new AccountingOverview())->treasuries([$second])['rows'], 'name');

        $this->assertContains('Second branch drawer', $names);
        $this->assertNotContains('Main branch drawer', $names);
        $this->assertSame([], (new AccountingOverview())->treasuries([])['rows']);
    }

    public function testPartyTotal_AddsWhatEveryCustomerOwesAndAgreesWithTheCustomerBalanceReport(): void
    {
        $entities = new EntityAccountService();
        $first    = $entities->ensure(EntityAccountService::CUSTOMER, $this->insertCustomer('Omar', 'Khaled'), 'Omar Khaled');
        $second   = $entities->ensure(EntityAccountService::CUSTOMER, $this->insertCustomer('Sara', 'Nasser'), 'Sara Nasser');
        $this->postBetween($first, $this->accountId('sales_revenue'), '100.00');
        $this->postBetween($second, $this->accountId('sales_revenue'), '35.50');

        $total  = (new AccountingOverview())->partyTotal(EntityAccountService::CUSTOMER, null);
        $report = (new CustomerBalanceReport())->build(ReportPeriod::of(null, date('Y-m-d')))->total('balance');

        $this->assertSame('135.5000', $total);
        $this->assertSame($report, $total);
    }

    public function testPartyTotal_AddsWhatEverySupplierIsOwedAsAPositiveFigure(): void
    {
        $supplier = (new EntityAccountService())->ensure(EntityAccountService::SUPPLIER, $this->insertSupplier(), 'Gulf Supplies');
        $this->postBetween($this->accountId('cash'), $supplier, '70.00');
        $this->postBetween($this->accountId('cash'), $this->accountId('sales_revenue'), '999.00');

        $this->assertSame('70.0000', (new AccountingOverview())->partyTotal(EntityAccountService::SUPPLIER, null));
    }

    public function testPartyTotal_IsZeroWithoutAnyAccountOfTheKind(): void
    {
        $this->assertSame('0.0000', (new AccountingOverview())->partyTotal(EntityAccountService::SUPPLIER, null));
    }

    public function testPartyTotal_CountsOnlyTheJournalsOfTheBranchesTheReaderMayUse(): void
    {
        $second   = $this->createBranch('B2');
        $customer = (new EntityAccountService())->ensure(EntityAccountService::CUSTOMER, $this->insertCustomer(), 'Omar Khaled');
        $this->postBetween($customer, $this->accountId('sales_revenue'), '100.00');
        (new JournalService())->postNew($this->journalInput('40.00') + ['branch_id' => $second]);
        (new JournalService())->postNew([
            'date'        => '2026-10-21',
            'description' => 'Second branch sale',
            'branch_id'   => $second,
            'lines'       => [
                ['account_id' => $customer, 'debit' => '25.00', 'credit' => ''],
                ['account_id' => $this->accountId('sales_revenue'), 'debit' => '', 'credit' => '25.00'],
            ],
        ]);

        $this->assertSame('125.0000', (new AccountingOverview())->partyTotal(EntityAccountService::CUSTOMER, null));
        $this->assertSame('25.0000', (new AccountingOverview())->partyTotal(EntityAccountService::CUSTOMER, [$second]));
    }

    public function testPeriod_NamesTheYearThatCoversTodayAndTheDateTheBooksAreClosedThrough(): void
    {
        (new FiscalYearService())->ensureCovering(date('Y-m-d'));

        $period = (new AccountingOverview())->period();

        $this->assertNotNull($period['year']);
        $this->assertLessThanOrEqual(date('Y-m-d'), $period['year']->start_date);
        $this->assertGreaterThanOrEqual(date('Y-m-d'), $period['year']->end_date);
        $this->assertSame((new ClosedPeriodService())->cutoff(null), $period['closed_through']);
    }

    public function testPeriod_HasNoYearWhenNoneCoversToday(): void
    {
        (new FiscalYearService())->ensureCovering(date('Y-m-d'));
        $table   = $this->accountingDb()->table('accounting_fiscal_years');
        $covered = $this->accountingDb()->table('accounting_fiscal_years')->where('start_date <=', date('Y-m-d'))->where('end_date >=', date('Y-m-d'))->get()->getResultArray();
        $table->whereIn('fiscal_year_id', array_column($covered, 'fiscal_year_id'))->delete();

        try {
            $this->assertNull((new AccountingOverview())->period()['year']);
        } finally {
            $this->accountingDb()->table('accounting_fiscal_years')->insertBatch($covered);
        }
    }

    public function testLatestJournals_ListsTheNewestFirstAndNoMoreThanTheFirstFew(): void
    {
        $cash    = $this->accountId('cash');
        $revenue = $this->accountId('sales_revenue');

        for ($day = 1; $day <= AccountingOverview::JOURNALS_SHOWN + 2; $day++) {
            $this->postBetween($cash, $revenue, '10.00', sprintf('2026-10-%02d', $day));
        }

        $rows = (new AccountingOverview())->latestJournals(null);

        $this->assertCount(AccountingOverview::JOURNALS_SHOWN, $rows);
        $this->assertSame(sprintf('2026-10-%02d', AccountingOverview::JOURNALS_SHOWN + 2), $rows[0]->journal_date);
    }

    public function testLatestJournals_ShowsAnEmployeeWithoutTheRightToEveryJournalOnlyTheirOwn(): void
    {
        $cash    = $this->accountId('cash');
        $revenue = $this->accountId('sales_revenue');
        $author  = $this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_DRAFT, AccountingGuard::JOURNALS_POST]);
        $reader  = $this->employeeWithGrants(['accounting_journals']);
        $auditor = $this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_ALL]);
        $this->postBetween($cash, $revenue, '10.00', '2026-10-21', $author);

        $this->assertCount(1, (new AccountingOverview())->latestJournals($author));
        $this->assertSame([], (new AccountingOverview())->latestJournals($reader));
        $this->assertCount(1, (new AccountingOverview())->latestJournals($auditor));
    }
}
