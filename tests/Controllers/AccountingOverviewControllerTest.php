<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\TreasuryService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\AccountingControllerFixtureTrait;
use Tests\Support\DatabaseFingerprintTrait;

class AccountingOverviewControllerTest extends CIUnitTestCase
{
    use AccountingControllerFixtureTrait;
    use DatabaseFingerprintTrait;
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareAccounting();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();

        parent::tearDown();
    }

    private function overviewAs(array $grants, ?array $branchIds = null): string
    {
        $this->actAs($this->employeeWithGrants($grants, $branchIds));

        return $this->get('/accounting_reports/overview')->getBody();
    }

    public function testGetIndex_OpensForWhoHoldsTheReportsModuleWithTheShortcutsOfTheReports(): void
    {
        $html = $this->overviewAs(['accounting_reports']);

        $this->assertStringContainsString(lang('Accounting_reports.overview_title'), $html);
        $this->assertStringContainsString('accounting_reports/trial_balance', $html);
        $this->assertStringContainsString('accounting_statements/balance_sheet', $html);
        $this->assertStringContainsString('accounting_statements/income_statement', $html);
        $this->assertStringContainsString('accounting_reports/ledger', $html);
    }

    public function testGetIndex_RedirectsAUserWithoutTheReportsModule(): void
    {
        $this->actAs($this->employeeWithGrants(['accounting_journals']));

        $response = $this->get('/accounting_reports/overview');

        $response->assertRedirect();
    }

    public function testGetIndex_IsNotFoundWhileAccountingIsDisabled(): void
    {
        $this->prepareAccounting(false);
        $this->actAs($this->employeeWithGrants(['accounting_reports']));

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_reports/overview');
    }

    public function testGetIndex_ShowsTheTreasuriesOnlyToWhoHoldsTheTreasuriesModule(): void
    {
        $treasuries = new TreasuryService();
        $treasuries->ensureMain();
        $treasuries->create(['name' => 'Front desk', 'kind' => TreasuryService::CASH, 'branch_id' => 1]);

        $with    = $this->overviewAs(['accounting_reports', 'accounting_treasuries']);
        $without = $this->overviewAs(['accounting_reports']);

        $this->assertStringContainsString('id="overview_treasuries_title"', $with);
        $this->assertStringContainsString('Front desk', $with);
        $this->assertStringNotContainsString('overview_treasuries_title', $without);
        $this->assertStringNotContainsString('Front desk', $without);
    }

    public function testGetIndex_ShowsWhatCustomersOweAndWhatSuppliersAreOwedOnlyToWhoHoldsEachModule(): void
    {
        $customers = $this->overviewAs(['accounting_reports', 'accounting_receivables']);
        $suppliers = $this->overviewAs(['accounting_reports', 'accounting_payables']);
        $neither   = $this->overviewAs(['accounting_reports']);

        $this->assertStringContainsString('overview_receivables_title', $customers);
        $this->assertStringNotContainsString('overview_payables_title', $customers);
        $this->assertStringContainsString('overview_payables_title', $suppliers);
        $this->assertStringNotContainsString('overview_receivables_title', $suppliers);
        $this->assertStringNotContainsString('overview_receivables_title', $neither);
        $this->assertStringNotContainsString('overview_payables_title', $neither);
    }

    public function testGetIndex_LeavesOutTheBalancesOfCustomersAndSuppliersForSomeoneLimitedToBranches(): void
    {
        $html = $this->overviewAs(['accounting_reports', 'accounting_receivables', 'accounting_payables'], [1]);

        $this->assertStringNotContainsString('overview_receivables_title', $html);
        $this->assertStringNotContainsString('overview_payables_title', $html);
    }

    public function testGetIndex_ShowsTheTotalOfWhatCustomersOwe(): void
    {
        $customer = (new EntityAccountService())->ensure(EntityAccountService::CUSTOMER, $this->insertCustomer(), 'Omar Khaled');
        (new JournalService())->postNew([
            'date'        => '2026-10-21',
            'description' => 'Sale',
            'lines'       => [
                ['account_id' => $customer, 'debit' => '321.50', 'credit' => ''],
                ['account_id' => $this->accountId('sales_revenue'), 'debit' => '', 'credit' => '321.50'],
            ],
        ]);

        $html = $this->overviewAs(['accounting_reports', 'accounting_receivables']);

        $this->assertStringContainsString('321.50', $html);
        $this->removeInsertedEntities();
    }

    public function testGetIndex_ShowsThePeriodOnlyToWhoHoldsThePeriodsModule(): void
    {
        $with    = $this->overviewAs(['accounting_reports', 'accounting_periods']);
        $without = $this->overviewAs(['accounting_reports']);

        $this->assertStringContainsString('overview_period_title', $with);
        $this->assertStringNotContainsString('overview_period_title', $without);
    }

    public function testGetIndex_ShowsTheLatestJournalsOnlyToWhoHoldsTheJournalsModuleAndOnlyTheirOwnWithoutTheRightToAll(): void
    {
        $author = $this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_DRAFT, AccountingGuard::JOURNALS_POST]);
        (new JournalService())->postNew($this->journalInput('10.00', 'Rent for October'), $author);

        $own     = $this->overviewAs(['accounting_reports', 'accounting_journals']);
        $all     = $this->overviewAs(['accounting_reports', 'accounting_journals', AccountingGuard::JOURNALS_ALL]);
        $without = $this->overviewAs(['accounting_reports']);

        $this->assertStringContainsString('overview_journals_title', $own);
        $this->assertStringNotContainsString('Rent for October', $own);
        $this->assertStringContainsString(lang('Accounting_reports.overview_no_journals'), $own);
        $this->assertStringContainsString('Rent for October', $all);
        $this->assertStringNotContainsString('overview_journals_title', $without);
    }

    public function testGetIndex_OffersTheNewJournalOnlyToWhoMayDraftOne(): void
    {
        $this->assertStringContainsString('accounting_journals/new', $this->overviewAs(['accounting_reports', 'accounting_journals', AccountingGuard::JOURNALS_DRAFT]));
        $this->assertStringNotContainsString('accounting_journals/new', $this->overviewAs(['accounting_reports', 'accounting_journals']));
    }

    public function testGetIndex_OffersTheChartOnlyToWhoHoldsTheChartModule(): void
    {
        $this->assertMatchesRegularExpression('~href="[^"]*/accounting_chart"~', $this->overviewAs(['accounting_reports', 'accounting_chart']));
        $this->assertDoesNotMatchRegularExpression('~href="[^"]*/accounting_chart"~', $this->overviewAs(['accounting_reports']));
    }

    public function testGetIndex_ExplainsThatTotalsLeaveOutOtherBranches(): void
    {
        $this->createBranch('B2');

        $this->assertStringContainsString('acc-scope-note', $this->overviewAs(['accounting_reports'], [1]));
    }

    public function testGetIndex_WritesNothing(): void
    {
        $treasuries = new TreasuryService();
        $treasuries->ensureMain();
        (new JournalService())->postNew($this->journalInput('10.00'));
        $this->actAs($this->employeeWithGrants([
            'accounting_reports', 'accounting_treasuries', 'accounting_receivables', 'accounting_payables', 'accounting_periods',
            'accounting_journals', 'accounting_chart', AccountingGuard::JOURNALS_ALL, AccountingGuard::JOURNALS_DRAFT,
        ]));
        $before = $this->databaseFingerprint();

        $response = $this->get('/accounting_reports/overview');

        $response->assertStatus(200);
        $this->assertSame($before, $this->databaseFingerprint());
    }
}
