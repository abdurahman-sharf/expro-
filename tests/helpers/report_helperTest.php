<?php

namespace Tests\Helpers;

use App\Libraries\Accounting\FamilyReports\FamilyReportCatalog;
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

    private function familyPaths(array $permissionIds, bool $accountingOn = true): array
    {
        $paths = [];

        foreach (familyReportCatalog($permissionIds, $accountingOn) as $group) {
            foreach ($group['subjects'] as $subject) {
                foreach ($subject['links'] as $link) {
                    $paths[] = $link['path'];
                }
            }
        }

        return $paths;
    }

    public function testFamilyReportCatalog_OffersEveryReportToWhoHoldsTheModuleAndThePermissionOfIt(): void
    {
        $every = ['accounting_reports', ...array_map(static fn (array $definition): string => $definition['permission'], array_values(FamilyReportCatalog::all()))];

        $this->assertCount(count(FamilyReportCatalog::keys()), $this->familyPaths($every));

        foreach (FamilyReportCatalog::keys() as $key) {
            $this->assertContains(site_url('accounting_reports/' . $key), $this->familyPaths($every), $key);
        }
    }

    public function testFamilyReportCatalog_LabelsEveryLinkWithTheTitleOfItsReport(): void
    {
        $every  = ['accounting_reports', ...array_map(static fn (array $definition): string => $definition['permission'], array_values(FamilyReportCatalog::all()))];
        $labels = [];

        foreach (familyReportCatalog($every, true) as $group) {
            foreach ($group['subjects'] as $subject) {
                foreach ($subject['links'] as $link) {
                    $labels[$link['path']] = $link['label'];
                }
            }
        }

        $messages = include APPPATH . 'Language/en/Accounting_family_reports.php';

        foreach (FamilyReportCatalog::keys() as $key) {
            $label = $labels[site_url('accounting_reports/' . $key)] ?? '';

            $this->assertNotSame('', $label, $key);
            $this->assertStringNotContainsString('Accounting_family_reports.', $label, $key);
            $this->assertArrayHasKey(FamilyReportCatalog::titleKey($key), $messages, $key);
            $this->assertSame(lang('Accounting_family_reports.' . FamilyReportCatalog::titleKey($key)), $label, $key);
        }
    }

    public function testFamilyReportCatalog_OffersNothingWithoutTheModuleOfTheFinancialReports(): void
    {
        $permissions = array_map(static fn (array $definition): string => $definition['permission'], array_values(FamilyReportCatalog::all()));

        $this->assertSame([], familyReportCatalog($permissions, true));
        $this->assertSame([], $this->familyPaths(['accounting_reports']));
    }

    public function testFamilyReportCatalog_OffersOnlyTheReportsOfThePermissionsHeld(): void
    {
        $paths = $this->familyPaths(['accounting_reports', 'reports_suppliers']);

        $this->assertContains(site_url('accounting_reports/supplier_balances'), $paths);
        $this->assertNotContains(site_url('accounting_reports/expenses'), $paths);
        $this->assertNotContains(site_url('accounting_reports/stock_aging'), $paths);
        $this->assertNotContains(site_url('accounting_reports/journals'), $paths);
    }

    public function testFamilyReportCatalog_LeavesOutEveryReportWhileAccountingIsOff(): void
    {
        $every = ['accounting_reports', ...array_map(static fn (array $definition): string => $definition['permission'], array_values(FamilyReportCatalog::all()))];

        $this->assertSame([], familyReportCatalog($every, false));
    }

    /**
     * @param list<string> $permissionIds
     *
     * @return list<string>
     */
    private function booksPaths(array $permissionIds, bool $accountingOn = true): array
    {
        $paths = [];

        foreach (booksReportCatalog($permissionIds, $accountingOn) as $group) {
            foreach ($group['subjects'] as $subject) {
                foreach ($subject['links'] as $link) {
                    $paths[] = $link['path'];
                }
            }
        }

        return $paths;
    }

    /**
     * @return list<string> Every permission that opens some report.
     */
    private function everyReportPermission(): array
    {
        return [
            'accounting_reports', 'accounting_receivables', 'accounting_payables', 'accounting_cheques', 'accounting_treasuries', 'acc_bank_reconcile',
            'reports_sales', 'reports_items', 'reports_categories', 'reports_customers', 'reports_employees', 'reports_payments', 'reports_taxes',
            'reports_sales_taxes', 'reports_inventory', 'reports_receivings', 'reports_suppliers', 'reports_expenses_categories', 'reports_discounts',
            'receipt_vouchers', 'accounting_journals', 'accounting_chart',
        ];
    }

    public function testBooksReportCatalog_OffersTheStatementsToWhoHoldsTheFinancialReportsModule(): void
    {
        $paths = $this->booksPaths(['accounting_reports']);

        $this->assertSame([site_url('accounting_reports'), site_url('accounting_reports/ledger'), site_url('accounting_statements/income_statement'), site_url('accounting_statements/balance_sheet')], $paths);
    }

    public function testBooksReportCatalog_OffersTheReportsOfEachPartyToTheModuleThatOpensThem(): void
    {
        $this->assertSame([site_url('accounting_receivables/aged_ledger'), site_url('accounting_receivables/balances')], $this->booksPaths(['accounting_receivables']));
        $this->assertSame([site_url('accounting_payables/aged_ledger'), site_url('accounting_payables/balances')], $this->booksPaths(['accounting_payables']));
    }

    public function testBooksReportCatalog_OffersTheChequeReportsToTheChequesModule(): void
    {
        $this->assertSame([site_url('accounting_cheques/report/received'), site_url('accounting_cheques/report/paid')], $this->booksPaths(['accounting_cheques']));
    }

    public function testBooksReportCatalog_OffersTheBankReconciliationOnlyToWhoAlsoHoldsThePermissionToReconcile(): void
    {
        $this->assertSame([site_url('accounting_treasuries/inter_branch')], $this->booksPaths(['accounting_treasuries']));
        $this->assertSame([site_url('accounting_treasuries/bank'), site_url('accounting_treasuries/inter_branch')], $this->booksPaths(['accounting_treasuries', 'acc_bank_reconcile']));
        $this->assertSame([], $this->booksPaths(['acc_bank_reconcile']));
    }

    public function testBooksReportCatalog_OffersNothingWithoutAModuleOrWhileAccountingIsOff(): void
    {
        $this->assertSame([], booksReportCatalog([], true));
        $this->assertSame([], booksReportCatalog(['reports_sales', 'accounting_journals'], true));
        $this->assertSame([], $this->booksPaths($this->everyReportPermission(), false));
    }

    public function testBooksReportCatalog_LabelsEveryLinkInTheWordsOfItsReport(): void
    {
        foreach (booksReportCatalog($this->everyReportPermission(), true) as $group) {
            $this->assertSame(lang('Accounting_reports.catalog_group'), $group['label']);

            foreach ($group['subjects'] as $subject) {
                $this->assertStringNotContainsString('Accounting_reports.', $subject['label']);

                foreach ($subject['links'] as $link) {
                    $this->assertNotSame('', $link['label']);
                    $this->assertDoesNotMatchRegularExpression('/^Accounting\w*\.\w+$/', $link['label'], $link['path']);
                }
            }
        }
    }

    public function testAllReportCatalogs_LeavesOutTheReportsOfTheWholeCompanyForAnEmployeeOfSomeBranches(): void
    {
        $this->readingAs('some');

        $paths = $this->allPaths($this->everyReportPermission());

        $this->assertContains(site_url('accounting_reports'), $paths);
        $this->assertContains(site_url('accounting_treasuries/inter_branch'), $paths);
        $this->assertNotContains(site_url('accounting_receivables/aged_ledger'), $paths);
        $this->assertNotContains(site_url('accounting_receivables/balances'), $paths);
        $this->assertNotContains(site_url('accounting_payables/aged_ledger'), $paths);
        $this->assertNotContains(site_url('accounting_payables/balances'), $paths);
        $this->assertNotContains(site_url('reports/graphical_summary_expenses_categories'), $paths);
    }

    public function testAllReportCatalogs_KeepsEveryReportForAnEmployeeOfEveryBranch(): void
    {
        $this->readingAs('every');

        $paths = $this->allPaths($this->everyReportPermission());

        $this->assertContains(site_url('accounting_receivables/aged_ledger'), $paths);
        $this->assertContains(site_url('accounting_payables/balances'), $paths);
        $this->assertContains(site_url('reports/graphical_summary_expenses_categories'), $paths);
    }

    public function testAllReportCatalogs_OffersNothingWhileAccountingIsOffExceptTheReportsOfSalesAndStock(): void
    {
        $this->readingAs('every');

        foreach ($this->allPaths($this->everyReportPermission(), false) as $path) {
            $this->assertStringNotContainsString('accounting_', $path);
        }
    }

    /**
     * @param list<string> $permissionIds
     *
     * @return list<string>
     */
    private function allPaths(array $permissionIds, bool $accountingOn = true): array
    {
        $paths = [];

        foreach (allReportCatalogs($permissionIds, $accountingOn) as $group) {
            foreach ($group['subjects'] as $subject) {
                foreach ($subject['links'] as $link) {
                    $paths[] = $link['path'];
                }
            }
        }

        return $paths;
    }

    /**
     * The GET routes of the application by their pattern, each as the regular expression that matches an address.
     *
     * @return array<string, string> The regular expression by the route as it is written.
     */
    private function getRoutePatterns(): array
    {
        $patterns = [];

        foreach (array_keys(service('routes')->getRoutes('GET', false)) as $route) {
            $regex = strtr(trim($route, '/'), ['(:num)' => '[0-9]+', '(:segment)' => '[^/]+', '(:any)' => '.+', '(:alphanum)' => '[a-zA-Z0-9]+', '(:alpha)' => '[a-zA-Z]+']);

            $patterns[trim($route, '/')] = '#^' . $regex . '$#';
        }

        return $patterns;
    }

    private function routeOf(string $url): string
    {
        return trim((string) parse_url(str_replace(site_url(), 'http://x/', $url), PHP_URL_PATH), '/');
    }

    private function matchingRoutes(string $path): array
    {
        return array_keys(array_filter($this->getRoutePatterns(), static fn (string $pattern): bool => preg_match($pattern, $path) === 1));
    }

    /**
     * Report routes that no catalog links, each with the reason: a screen that is opened from another screen
     * for one record, or one that is a file or a setting of a report.
     *
     * @return array<string, string>
     */
    private function exemptReportRoutes(): array
    {
        return [
            'accounting_treasuries/statement/([0-9]+)'    => 'The statement of one treasury, opened from the list of treasuries.',
            'accounting_treasuries/bank/([0-9]+)/report'  => 'The reconciliation statement of one bank account, opened from the account.',
            'accounting_periods/year_end'                 => 'The preview of a closing, opened from the tabs of the periods and approved there.',
            'accounting_journals/audit'                   => 'The audit log, opened from the journals under its own permission.',
            'accounting_statements/cash_flow/settings'    => 'The settings of a report, not a report.',
            'accounting_reports/trial_balance'            => 'The same screen as accounting_reports.',
            'accounting_statements'                       => 'The same screen as the income statement.',
        ];
    }

    public function testAllReportCatalogs_OffersEveryReportRouteOfTheBooksOrNamesItsExemption(): void
    {
        $this->readingAs('every');

        $linked = [];

        foreach ($this->allPaths($this->everyReportPermission()) as $url) {
            foreach ($this->matchingRoutes($this->routeOf($url)) as $route) {
                $linked[$route] = true;
            }
        }

        $routes   = array_keys($this->getRoutePatterns());
        $reports  = array_filter($routes, fn (string $route): bool => ! preg_match('#/(csv|xlsx|pdf)$#', $route)
            && (str_starts_with($route, 'accounting_reports') || str_starts_with($route, 'accounting_statements') || in_array($route . '/csv', $routes, true)));
        $missing  = array_values(array_filter($reports, fn (string $route): bool => ! isset($linked[$route]) && ! isset($this->exemptReportRoutes()[$route])));

        $this->assertSame([], $missing, 'A report route has no card in the reports index: link it in a catalog or list it as an exemption with its reason');
    }

    public function testAllReportCatalogs_LinksOnlyToRoutesThatExist(): void
    {
        $this->readingAs('every');

        foreach ($this->allPaths($this->everyReportPermission()) as $url) {
            $path = $this->routeOf($url);

            if (str_starts_with($path, 'reports/')) {
                continue;
            }

            $this->assertNotSame([], $this->matchingRoutes($path), 'No route answers ' . $path);
        }
    }

    public function testAllReportCatalogs_NamesNoExemptionForARouteThatDoesNotExist(): void
    {
        $routes = array_keys($this->getRoutePatterns());

        foreach (array_keys($this->exemptReportRoutes()) as $route) {
            $this->assertContains($route, $routes, $route . ' is exempt but is not a route');
        }
    }
}
