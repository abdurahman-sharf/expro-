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

        $this->assertCount(1, $paths);
        $this->assertStringEndsWith('accounting_reports/inventory_valuation', $paths[0]);

        foreach ($this->pathsFor(['reports_inventory', 'reports_sales', 'accounting_journals', 'accounting_payables']) as $path) {
            $this->assertStringNotContainsString('inventory_valuation', $path);
        }
    }

    public function testReportCatalog_LeavesOutTheReportsOfTheBooksWhileAccountingIsOff(): void
    {
        $this->assertSame([], reportCatalog(['accounting_reports'], false));

        $paths = $this->pathsFor(['accounting_reports', 'reports_inventory'], false);

        $this->assertCount(2, $paths, 'The legacy inventory reports stay');

        foreach ($paths as $path) {
            $this->assertStringNotContainsString('inventory_valuation', $path);
        }
    }
}
