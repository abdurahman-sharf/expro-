<?php

namespace Tests\Helpers;

use App\Libraries\Accounting\ReportScopeKind;
use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\AccountingFixtureTrait;

/**
 * @internal
 */
final class report_helperTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $seedOnce    = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private static bool $doneBootstrap = false;

    protected function setUp(): void
    {
        if (self::$doneBootstrap === false) {
            Config::seeder($this->DBGroup)->call('App\Database\Seeds\TestDatabaseBootstrapSeeder');
            Config::connect($this->DBGroup)->close();

            self::$doneBootstrap = true;
        }

        parent::setUp();

        helper(['url', 'report']);
    }

    protected function tearDown(): void
    {
        session()->remove('person_id');

        parent::tearDown();
    }

    /**
     * Logs in an employee of the first branch and a second one, of every branch, or of none, in a
     * company of three branches.
     *
     * @return int The second branch
     */
    private function readingAs(string $scope): int
    {
        $second = $this->createBranch('H' . substr(md5(uniqid('', true)), 0, 6), 'Helper branch B');
        $this->createBranch('H' . substr(md5(uniqid('', true)), 0, 6), 'Helper branch C');

        session()->set('person_id', $this->employeeWithGrants([], match ($scope) {
            'some'  => [1, $second],
            'none'  => [],
            default => null,
        }));

        return $second;
    }

    public function testReportCatalog_IncludesOnlyPermittedSubjects(): void
    {
        $catalog = reportCatalog(['reports_items', 'home']);

        $this->assertCount(1, $catalog);
        $this->assertCount(1, $catalog[0]['subjects']);
        $this->assertSame('bi-box-seam', $catalog[0]['subjects'][0]['icon']);
    }

    public function testReportCatalog_OmitsGroupsWithoutPermittedSubjects(): void
    {
        $this->assertSame([], reportCatalog([]));
        $this->assertSame([], reportCatalog(['reports_inventory_low']));
    }

    public function testReportCatalog_LinksEachSubjectToItsReportRoutes(): void
    {
        $paths = [];

        foreach (reportCatalog(['reports_sales']) as $group) {
            foreach ($group['subjects'] as $subject) {
                foreach ($subject['links'] as $link) {
                    $paths[] = $link['path'];
                }
            }
        }

        $this->assertCount(4, $paths);
        $this->assertStringEndsWith('reports/graphical_summary_sales', $paths[0]);
        $this->assertStringEndsWith('reports/summary_sales', $paths[1]);
        $this->assertStringEndsWith('reports/detailed_sales', $paths[2]);
        $this->assertStringEndsWith('reports/detailed_item_sales', $paths[3]);
    }

    public function testReportScope_SaysItIsPartialForAnEmployeeOfSomeBranchesAndLimitsItToThem(): void
    {
        $second = $this->readingAs('some');

        $scope = reportScope();

        $this->assertSame(ReportScopeKind::Partial, $scope->kind);
        $this->assertSame([1, $second], $scope->ids);
    }

    public function testReportScope_SaysItIsTheWholeCompanyForAnEmployeeOfEveryBranchAndForTheSystem(): void
    {
        $this->readingAs('every');

        $this->assertSame(ReportScopeKind::Company, reportScope()->kind);

        session()->remove('person_id');

        $this->assertSame(ReportScopeKind::Company, reportScope()->kind);
    }

    public function testReportScope_ReadsNoBranchForAnEmployeeWhoWorksInNone(): void
    {
        $this->readingAs('none');

        $this->assertSame([], reportScope()->ids);
    }

    public function testOpenableReportPermissions_LeavesOutTheExpensesReportsForAnEmployeeOfSomeBranches(): void
    {
        $this->readingAs('some');

        $this->assertSame(['reports_sales'], openableReportPermissions(['reports_sales', 'reports_expenses_categories']));
    }

    public function testOpenableReportPermissions_KeepsEveryReportForAnEmployeeOfEveryBranchAndForTheSystem(): void
    {
        $this->readingAs('every');

        $this->assertSame(['reports_sales', 'reports_expenses_categories'], openableReportPermissions(['reports_sales', 'reports_expenses_categories']));

        session()->remove('person_id');

        $this->assertSame(['reports_expenses_categories'], openableReportPermissions(['reports_expenses_categories']));
    }

    /**
     * @param list<string> $permissionIds
     *
     * @return list<string>
     */
    private function pathsFor(array $permissionIds, bool $accountingOn = true): array
    {
        $paths = [];

        foreach (reportCatalog($permissionIds, $accountingOn) as $group) {
            foreach ($group['subjects'] as $subject) {
                foreach ($subject['links'] as $link) {
                    $paths[] = $link['path'];
                }
            }
        }

        return $paths;
    }

    public function testReportCatalog_OffersTheInventoryValuationToTheFinancialReportsModuleOnly(): void
    {
        $paths = $this->pathsFor(['accounting_reports']);

        $this->assertCount(4, $paths, 'The stock value, the tax report, the profit and loss and the cash flow');
        $this->assertStringEndsWith('accounting_reports/inventory_valuation', $paths[0]);

        foreach ($this->pathsFor(['reports_inventory', 'reports_sales', 'accounting_journals', 'accounting_payables']) as $path) {
            $this->assertStringNotContainsString('inventory_valuation', $path);
        }
    }

    public function testReportCatalog_OffersTheTaxReportFromTheBooksToTheFinancialReportsModuleOnly(): void
    {
        $paths = $this->pathsFor(['accounting_reports']);

        $this->assertStringEndsWith('accounting_reports/tax', $paths[1]);

        foreach ($this->pathsFor(['reports_taxes', 'reports_sales_taxes', 'accounting_journals', 'accounting_payables']) as $path) {
            $this->assertStringNotContainsString('accounting_reports/tax', $path);
        }
    }

    public function testReportCatalog_OffersTheProfitAndLossAndTheCashFlowToTheFinancialReportsModuleOnly(): void
    {
        $paths = $this->pathsFor(['accounting_reports']);

        $this->assertStringEndsWith('accounting_statements/profit_loss', $paths[2]);
        $this->assertStringEndsWith('accounting_statements/cash_flow', $paths[3]);

        foreach ($this->pathsFor(['reports_taxes', 'reports_sales_taxes', 'accounting_journals', 'accounting_payables']) as $path) {
            $this->assertStringNotContainsString('accounting_statements', $path);
        }
    }

    public function testReportCatalog_PutsTheTaxReportFromTheBooksBesideTheOperationalTaxReports(): void
    {
        $catalog = reportCatalog(['reports_taxes', 'reports_sales_taxes', 'accounting_reports'], true);
        $finance = array_values(array_filter($catalog, static fn (array $group): bool => $group['label'] === lang('Reports.group_finance')));

        $this->assertCount(1, $finance);
        $this->assertSame(
            [lang('Reports.taxes'), lang('Reports.sales_taxes'), lang('Accounting_tax_reports.title'), lang('Accounting_profit_loss.title'), lang('Accounting_cash_flow.title')],
            array_map(static fn (array $subject): string => $subject['label'], $finance[0]['subjects']),
        );
    }

    public function testReportCatalog_LeavesOutTheReportsOfTheBooksWhileAccountingIsOff(): void
    {
        $this->assertSame([], reportCatalog(['accounting_reports'], false));

        $paths = $this->pathsFor(['accounting_reports', 'reports_inventory'], false);

        $this->assertCount(2, $paths, 'The legacy inventory reports stay');

        foreach ($paths as $path) {
            $this->assertStringNotContainsString('inventory_valuation', $path);
            $this->assertStringNotContainsString('accounting_reports/tax', $path);
            $this->assertStringNotContainsString('accounting_statements', $path);
        }
    }
    public function testOperationalReportCatalog_OffersEachReportToWhoHoldsTheModuleAndThePermissionOfIt(): void
    {
        $paths = $this->operationalPathsFor(['accounting_reports', 'reports_sales', 'reports_payments']);

        $this->assertCount(5, $paths);
        $this->assertStringEndsWith('accounting_reports/revenue?group=day', $paths[0]);
        $this->assertStringEndsWith('accounting_reports/net_position', $paths[1]);
        $this->assertStringEndsWith('accounting_reports/invoice_returns', $paths[2]);
        $this->assertStringEndsWith('accounting_reports/payments?group=customer', $paths[3]);
        $this->assertStringEndsWith('accounting_reports/advance_payments', $paths[4]);
    }

    public function testOperationalReportCatalog_OpensTheFirstGroupingThePersonMayRead(): void
    {
        $paths = $this->operationalPathsFor(['accounting_reports', 'reports_customers', 'reports_categories']);

        $this->assertStringEndsWith('accounting_reports/revenue?group=customer', $paths[0]);
        $this->assertStringEndsWith('accounting_reports/clients_sales', $paths[1]);
        $this->assertStringEndsWith('accounting_reports/item_sales?group=category', $paths[2]);
        $this->assertStringEndsWith('accounting_reports/profit?group=category', $paths[3]);
        $this->assertStringEndsWith('accounting_reports/item_profit?group=category', $paths[4]);
    }

    public function testOperationalReportCatalog_OffersNothingWithoutTheModuleOrWhileAccountingIsOff(): void
    {
        $this->assertSame([], operationalReportCatalog(['reports_sales', 'reports_items', 'reports_payments'], true));
        $this->assertSame([], operationalReportCatalog(['accounting_reports', 'reports_sales'], false));
        $this->assertSame([], operationalReportCatalog(['accounting_reports'], true));
        $this->assertSame([], $this->operationalPathsFor(['accounting_reports', 'accounting_journals', 'reports_taxes']));
    }

    public function testOperationalReportCatalog_LeavesTheCatalogOfTheOperationalReportsAsItWas(): void
    {
        $this->assertCount(4, $this->pathsFor(['accounting_reports']));
        $this->assertCount(4, $this->pathsFor(['reports_sales']));
    }

    /**
     * @param list<string> $permissionIds
     *
     * @return list<string>
     */
    private function operationalPathsFor(array $permissionIds): array
    {
        $paths = [];

        foreach (operationalReportCatalog($permissionIds, true) as $group) {
            foreach ($group['subjects'] as $subject) {
                foreach ($subject['links'] as $link) {
                    $paths[] = $link['path'];
                }
            }
        }

        return $paths;
    }
}
