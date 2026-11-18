<?php

namespace Tests\Controllers;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use App\Database\Seeds\TestDatabaseBootstrapSeeder;
use App\Libraries\Roles\RoleService;
use App\Models\Employee;
use Config\Database;
use Config\OSPOS;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\ReportBranchFixtureTrait;
use Tests\Support\ReportSaleFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * Regression tests for the reports permission bypass
 *
 * Reports::__construct() previously derived the report method name from
 * $request->getUri()->getSegment(2), which CodeIgniter decodes once, while
 * the router decodes the same path a second time before dispatch. Encoding
 * the report name's underscore as %255F meant the constructor saw no
 * underscore, skipped the has_grant() check entirely, and the router still
 * dispatched to the real (double-decoded) method — letting any authenticated
 * employee read reports they had no grant for.
 */
class ReportsControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use EmployeeFixtureTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use ReportBranchFixtureTrait;
    use ReportSaleFixtureTrait;

    private const REPORT_MONTH = '2005-07-';

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private static bool $doneBootstrap = false;

    /**
     * Set up test environment
     */
    protected function setUp(): void
    {
        if (self::$doneBootstrap === false) {
            TestDatabaseBootstrapSeeder::reset();

            self::$doneBootstrap = true;
        }

        parent::setUp();

        config(OSPOS::class)->update_settings();
        $this->dropReportTables();
        $this->removeReportSales(self::REPORT_MONTH);
    }

    protected function tearDown(): void
    {
        if ($this->buyingStarted) {
            $this->forgetTheReportBusiness();
            $this->stopBuying();
            $this->removeTheReportItems();
        }

        $this->dropReportTables();
        $this->removeReportSales(self::REPORT_MONTH);
        config(OSPOS::class)->update_settings();

        parent::tearDown();
    }

    /**
     * Create a non-admin employee for testing
     *
     * @param array $overrides
     * @return int
     */
    protected function createNonAdminEmployee(array $overrides = []): int
    {
        $uniqueSuffix = uniqid();

        $personData = [
            'first_name'   => $overrides['first_name'] ?? 'NonAdmin',
            'last_name'    => $overrides['last_name'] ?? 'User',
            'email'        => $overrides['email'] ?? "nonadmin{$uniqueSuffix}@test.com",
            'phone_number' => $overrides['phone_number'] ?? '555-1234'
        ];

        $employeeData = [
            'username'      => $overrides['username'] ?? "nonadmin{$uniqueSuffix}",
            'password'      => password_hash($overrides['password'] ?? 'password123', PASSWORD_DEFAULT),
            'hash_version'  => 2,
            'language_code' => 'en',
            'language'      => 'english'
        ];

        $grantsData = $overrides['grants'] ?? [
            ['permission_id' => 'customers', 'menu_group' => 'home'],
            ['permission_id' => 'sales', 'menu_group' => 'home']
        ];

        $employeeModel = model(Employee::class);
        $saved = $employeeModel->save_employee($personData, $employeeData, $grantsData, NEW_ENTRY);

        $this->assertTrue($saved, 'Failed to save non-admin employee fixture.');
        $this->assertArrayHasKey('person_id', $personData, 'Saved employee fixture missing person_id.');

        return (int) $personData['person_id'];
    }

    /**
     * Log in as the given employee
     *
     * @param int $personId
     * @return void
     */
    protected function loginAs(int $personId): void
    {
        $this->withSession([
            'person_id'  => $personId,
            'menu_group' => 'home',
        ]);
    }

    /**
     * A non-admin employee with no reports_customers grant must be denied
     * access to the summary_customers report.
     *
     * @return void
     */
    public function testNonAdminWithoutReportGrantIsDeniedSummaryCustomers(): void
    {
        $nonAdminId = $this->createNonAdminEmployee([
            'grants' => [
                ['permission_id' => 'reports_sales', 'menu_group' => 'home']
            ]
        ]);
        $this->loginAs($nonAdminId);

        $response = $this->get('/reports/summary_customers');

        $response->assertRedirect();
        $this->assertStringContainsString('no_access', $response->getRedirectUrl());
    }

    /**
     * Regression test for the double-URL-encoding bypass itself: replacing
     * the underscore with %255F must not change the outcome versus the
     * plain request in testNonAdminWithoutReportGrantIsDeniedSummaryCustomers().
     *
     * @return void
     */
    public function testDoubleEncodedUnderscoreCannotBypassSummaryCustomersGrantCheck(): void
    {
        $nonAdminId = $this->createNonAdminEmployee([
            'grants' => [
                ['permission_id' => 'reports_sales', 'menu_group' => 'home']
            ]
        ]);
        $this->loginAs($nonAdminId);

        $response = $this->get('/reports/summary%255Fcustomers');

        $response->assertRedirect();
        $this->assertStringContainsString('no_access', $response->getRedirectUrl());
    }

    /**
     * Same bypass attempt against a different report prefix, to confirm the
     * fix isn't narrowly specific to the summary_ prefix's regex path.
     *
     * @return void
     */
    public function testDoubleEncodedUnderscoreCannotBypassDetailedSalesGrantCheck(): void
    {
        $nonAdminId = $this->createNonAdminEmployee([
            'grants' => [
                ['permission_id' => 'reports_customers', 'menu_group' => 'home']
            ]
        ]);
        $this->loginAs($nonAdminId);

        $response = $this->get('/reports/detailed%255Fsales');

        $response->assertRedirect();
        $this->assertStringContainsString('no_access', $response->getRedirectUrl());
    }

    /**
     * An employee with the reports_customers grant must be able to access
     * the summary_customers report.
     *
     * @return void
     */
    public function testEmployeeWithReportGrantCanAccessSummaryCustomers(): void
    {
        $employeeId = $this->createNonAdminEmployee([
            'username' => 'reportviewer',
            'email'    => 'reportviewer@test.com',
            'grants'   => [
                ['permission_id' => 'reports', 'menu_group' => 'home'],
                ['permission_id' => 'reports_customers', 'menu_group' => 'home']
            ]
        ]);
        $this->loginAs($employeeId);

        $response = $this->get('/reports/summary_customers');

        $response->assertStatus(200);
    }

    /**
     * An employee with the base reports grant plus a submodule grant must be
     * able to access the base /reports listing route (no submodule id derivable).
     *
     * @return void
     */
    public function testEmployeeWithReportsGrantCanAccessBaseReportsIndex(): void
    {
        $employeeId = $this->createNonAdminEmployee([
            'username' => 'reportsindexviewer',
            'email'    => 'reportsindexviewer@test.com',
            'grants'   => [
                ['permission_id' => 'reports', 'menu_group' => 'home'],
                ['permission_id' => 'reports_customers', 'menu_group' => 'home']
            ]
        ]);
        $this->loginAs($employeeId);

        $response = $this->get('/reports');

        $response->assertStatus(200);
    }

    /**
     * @return list<string> The address of the page where each of the two reports is asked its dates, and of the report
     *                      itself, so that every way in to a report is tried.
     */
    private function addressesOf(string $report, string $arguments): array
    {
        return ["/reports/{$report}", "/reports/graphical_{$report}", "/reports/{$report}/{$arguments}", "/reports/graphical_{$report}/{$arguments}"];
    }

    /**
     * @return list<string> The permissions that guard a report, which are all those the database holds under the module of the
     *                      reports apart from the module itself, so a new one cannot be left out of the walks that use this.
     */
    private function reportPermissions(): array
    {
        $listed = [
            'reports_sales', 'reports_customers', 'reports_employees', 'reports_payments', 'reports_discounts', 'reports_items', 'reports_categories',
            'reports_inventory', 'reports_receivings', 'reports_suppliers', 'reports_taxes', 'reports_sales_taxes', 'reports_expenses_categories',
        ];
        $stored = array_column($this->accountingDb()->table('permissions')->like('permission_id', 'reports_', 'after')->get()->getResultArray(), 'permission_id');

        sort($stored);
        sort($listed);

        $this->assertSame($listed, $stored, 'a report permission is missing from the list, so the walks over the reports index would skip it');

        return $listed;
    }

    private function assertIsRefusedTo(string $address, string $refusedFor): void
    {
        $response = $this->get($address);

        $this->assertSame(302, $response->response()->getStatusCode(), $address);
        $this->assertStringContainsString('no_access/reports/' . $refusedFor, $response->getRedirectUrl(), $address);
    }

    /**
     * @param list<string> $permissionIds
     */
    private function assertEveryAddressIsRefusedTo(array $permissionIds, array $addresses, string $refusedFor): void
    {
        $this->loginAs($this->employeeWithGrants($permissionIds));

        foreach ($addresses as $address) {
            $this->assertIsRefusedTo($address, $refusedFor);
        }
    }

    /**
     * @param list<string> $permissionIds
     */
    private function assertEveryAddressIsOpenTo(array $permissionIds, array $addresses): void
    {
        $this->loginAs($this->employeeWithGrants($permissionIds));

        foreach ($addresses as $address) {
            $this->get($address)->assertStatus(200);
        }
    }

    public function testSummaryExpensesCategories_RejectsAnEmployeeWhoHoldsTheCategoriesPermissionButNotTheExpensesOne(): void
    {
        $addresses = $this->addressesOf('summary_expenses_categories', $this->reportDay . '/' . $this->reportDay . '/sales');

        $this->assertEveryAddressIsRefusedTo(['reports', 'reports_categories'], $addresses, 'reports_expenses_categories');
    }

    public function testSummaryExpensesCategories_AcceptsAnEmployeeWhoHoldsTheExpensesPermissionAloneWithoutTheCategoriesOne(): void
    {
        $addresses = $this->addressesOf('summary_expenses_categories', $this->reportDay . '/' . $this->reportDay . '/sales');

        $this->assertEveryAddressIsOpenTo(['reports', 'reports_expenses_categories'], $addresses);
    }

    public function testSummarySalesTaxes_RejectsAnEmployeeWhoHoldsTheTaxesPermissionButNotTheSalesTaxesOne(): void
    {
        $addresses = $this->addressesOf('summary_sales_taxes', $this->reportDay . '/' . $this->reportDay . '/sales/all');

        $this->assertEveryAddressIsRefusedTo(['reports', 'reports_taxes'], $addresses, 'reports_sales_taxes');
    }

    public function testSummarySalesTaxes_AcceptsAnEmployeeWhoHoldsTheSalesTaxesPermissionAloneWithoutTheTaxesOne(): void
    {
        $addresses = $this->addressesOf('summary_sales_taxes', $this->reportDay . '/' . $this->reportDay . '/sales/all');

        $this->assertEveryAddressIsOpenTo(['reports', 'reports_sales_taxes'], $addresses);
    }

    public function testSummaryTaxes_RequiresTheTaxesPermissionAndNotTheSalesTaxesOne(): void
    {
        $addresses = $this->addressesOf('summary_taxes', $this->reportDay . '/' . $this->reportDay . '/sales/all');

        $this->assertEveryAddressIsRefusedTo(['reports', 'reports_sales_taxes'], $addresses, 'reports_taxes');
        $this->assertEveryAddressIsOpenTo(['reports', 'reports_taxes'], $addresses);
    }

    public function testReportPages_RejectsEverySpellingOfTheAddressOfAReportWhoseNameDoesNotSpellItsPermission(): void
    {
        $this->loginAs($this->employeeWithGrants(['reports', 'reports_categories', 'reports_taxes']));

        $day     = $this->reportDay;
        $refused = [
            'summary%255Fexpenses%255Fcategories'                    => 'reports_expenses_categories',
            'summary%5Fexpenses%5Fcategories'                        => 'reports_expenses_categories',
            'summary_Expenses_Categories'                            => 'reports_expenses_categories',
            'summary_expenses_Categories'                            => 'reports_expenses_categories',
            'graphical_summary_Expenses_Categories'                  => 'reports_expenses_categories',
            'summary%255Fsales%255Ftaxes'                            => 'reports_sales_taxes',
            'summary_Sales_Taxes'                                    => 'reports_sales_taxes',
            'graphical_summary_Sales_Taxes'                          => 'reports_sales_taxes',
            "summary_expenses_categories%252F{$day}_categories"       => 'reports_expenses_categories',
            "graphical_summary_expenses_categories%252F{$day}_categories" => 'reports_expenses_categories',
            "summary_sales_taxes%252F{$day}_taxes"                    => 'reports_sales_taxes',
            "graphical_summary_sales_taxes%252F{$day}_taxes"          => 'reports_sales_taxes',
        ];
        $withoutArguments = [
            'summary%255Fexpenses%255Fcategories'                    => 'reports_expenses_categories',
            "graphical_summary_expenses_categories%252F{$day}_categories" => 'reports_expenses_categories',
            "summary_sales_taxes%252F{$day}_taxes"                    => 'reports_sales_taxes',
            'summary_Sales_Taxes'                                    => 'reports_sales_taxes',
        ];

        foreach ($refused as $segment => $refusedFor) {
            $this->assertIsRefusedTo("/reports/{$segment}/{$day}/{$day}/" . ($refusedFor === 'reports_sales_taxes' ? 'sales/all' : 'sales'), $refusedFor);
        }

        foreach ($withoutArguments as $segment => $refusedFor) {
            $this->assertIsRefusedTo("/reports/{$segment}", $refusedFor);
        }

        foreach (['Summary_Expenses_Categories', 'GRAPHICAL_SUMMARY_EXPENSES_CATEGORIES', 'Summary_Sales_Taxes'] as $segment) {
            try {
                $status = $this->get("/reports/{$segment}/{$day}/{$day}/sales")->response()->getStatusCode();
            } catch (PageNotFoundException) {
                $status = 404;
            }

            $this->assertNotSame(200, $status, $segment);
        }
    }

    public function testReportPages_RejectsAnotherPermissionHiddenBehindASlashInTheNameOfTheReport(): void
    {
        helper('report');

        $permissionOf = [];

        foreach ($this->reportPermissions() as $permission) {
            foreach (reportCatalog([$permission], false) as $group) {
                foreach ($group['subjects'] as $subject) {
                    foreach ($subject['links'] as $link) {
                        $permissionOf[basename($link['path'])] = $permission;
                    }
                }
            }
        }

        $this->assertGreaterThan(25, count($permissionOf));

        $holders = [];

        foreach (array_unique($permissionOf) as $permission) {
            if (substr_count($permission, '_') === 1) {
                $holders[$permission] = $this->employeeWithGrants(['reports', $permission]);
            }
        }

        foreach ($permissionOf as $name => $guardedBy) {
            foreach ($holders as $permission => $personId) {
                $this->loginAs($personId);

                if ($permission === $guardedBy) {
                    continue;
                }

                $this->assertIsRefusedTo("/reports/{$name}%252F{$this->reportDay}_" . substr($permission, strlen('reports_')) . "/{$this->reportDay}/{$this->reportDay}/sales/all", $guardedBy);
            }
        }
    }

    public function testReportPages_AcceptsTheIndexPermissionOfEachLinkAndRejectsEveryOtherReportPermission(): void
    {
        helper('report');

        $permissions = $this->reportPermissions();

        foreach ($permissions as $permission) {
            $addresses = [];

            foreach (reportCatalog([$permission], false) as $group) {
                foreach ($group['subjects'] as $subject) {
                    foreach ($subject['links'] as $link) {
                        $addresses[] = '/' . ltrim(substr($link['path'], strlen(site_url(''))), '/');
                    }
                }
            }

            $this->assertNotSame([], $addresses, $permission);

            foreach ($addresses as $address) {
                $this->assertEveryAddressIsOpenTo(['reports', $permission], [$address]);
                $this->assertEveryAddressIsRefusedTo(['reports', ...array_values(array_diff($permissions, [$permission]))], [$address], 'reports_');
            }
        }
    }

    /**
     * Business in two branches, and an employee who reads the reports of the branches given, or of
     * every branch, logged in.
     *
     * @param list<int>|null $branchIds
     */
    private function readerOfTheBranches(?array $branchIds): int
    {
        $personId = $this->employeeWithGrants([
            'reports', 'reports_sales', 'reports_categories', 'reports_taxes', 'reports_sales_taxes', 'reports_inventory', 'reports_receivings', 'reports_expenses_categories', 'reports_customers', 'reports_discounts',
            'reports_items', 'reports_suppliers', 'reports_employees', 'reports_payments',
        ], $branchIds);

        $this->loginAs($personId);

        return $personId;
    }

    /**
     * Business in two branches, and a reader of every branch who sees their own records only and has sold a unit
     * on the day of the report, logged in.
     *
     * @return array{reader: int, mine: int}
     */
    private function ownRecordsReaderWhoSold(): array
    {
        $this->openTheBooks();

        $reader = $this->readerOfTheBranches(null);
        $sold   = $this->ownRecordsEmployeeWhoSold($reader);

        return ['reader' => $reader, 'mine' => $sold['mine']];
    }

    /**
     * @return array<string, string> The sales reports of the legacy menu, by name, with the address of each for the day of the report.
     */
    private function salesReportPaths(): array
    {
        $day      = $this->reportDay;
        $withDays = static fn (string $name, string $rest = '/sales/all'): string => "/reports/{$name}/{$day}/{$day}{$rest}";
        $paths    = [];

        foreach (['sales', 'categories', 'customers', 'suppliers', 'items', 'employees', 'taxes', 'sales_taxes'] as $kind) {
            $paths['summary_' . $kind]           = $withDays('summary_' . $kind);
            $paths['graphical_summary_' . $kind] = $withDays('graphical_summary_' . $kind);
        }

        $paths['summary_discounts']           = $withDays('summary_discounts', '/sales/all/0');
        $paths['graphical_summary_discounts'] = $withDays('graphical_summary_discounts', '/sales/all/0');
        $paths['summary_payments']            = $withDays('summary_payments', '');
        $paths['graphical_summary_payments']  = $withDays('graphical_summary_payments');
        $paths['detailed_sales']              = $withDays('detailed_sales');
        $paths['detailed_item_sales']         = $withDays('detailed_item_sales', '/sales/all/all');
        $paths['specific_customers']          = $withDays('specific_customers', '/' . $this->customerId . '/sales/all');
        $paths['specific_employees']          = $withDays('specific_employees', '/' . $this->employeeId . '/sales');
        $paths['specific_discounts']          = $withDays('specific_discounts', '/10/sales/' . PERCENT);
        $paths['specific_suppliers']          = $withDays('specific_suppliers', '/' . $this->supplierId . '/sales');

        return $paths;
    }

    /**
     * What a graphical report draws, added up: the figures are in the page as the data of the chart, where the
     * names of the other employee's customers or taxes may not be.
     */
    private function chartedBy(string $page): float
    {
        preg_match_all('/"values":\[([^\]]*)\]/', html_entity_decode($page), $charts);

        return array_sum(array_map(static fn (string $values): float => array_sum(array_map('floatval', explode(',', $values))), $charts[1]));
    }

    /**
     * Business in two branches, and a reader of every branch who sees their own records only and has bought a unit
     * from a supplier of their own on the day of the report, logged in.
     *
     * @return array{reader: int, mine: int}
     */
    private function ownRecordsReaderWhoBought(): array
    {
        $this->openTheBooks();

        $reader   = $this->readerOfTheBranches(null);
        $author   = $this->employeeId;
        $supplier = $this->anotherSupplier('OwnOnlySupplier');

        $this->employeeId = $reader;
        $mine             = $this->buy([$this->purchaseLine($this->reportItems['a'], 0, '1', '25.00')], $supplier);
        $this->employeeId = $author;

        $this->assertGreaterThan(0, $mine);
        $this->accountingDb()->table('receivings')->where('receiving_id', $mine)->update(['receiving_time' => $this->reportDay . ' 11:00:00']);
        (new RoleService())->setRecordScope($reader, 'own', null);

        return ['reader' => $reader, 'mine' => $mine];
    }

    private function assertExpensesReportIsRefusedToAnEmployeeOfOneBranch(string $report): void
    {
        $this->openTheBooks();
        $this->readerOfTheBranches([1]);

        $response = $this->get('/reports/' . $report . '/' . $this->reportDay . '/' . $this->reportDay . '/sales');

        $this->assertSame(403, $response->response()->getStatusCode(), $report);
        $this->assertStringContainsString(lang('Branches.error_company_scope_needed'), $response->getBody(), $report);
        $this->assertStringNotContainsString(lang('Error.no_permission_module'), $response->getBody(), $report);
    }

    private function openTheBooks(): void
    {
        $this->startBuying();
        $this->businessInTwoBranches();
    }

    /**
     * What a report page shows, as one piece of text.
     */
    private function pageOf(string $path): string
    {
        $this->dropEveryReportTable();

        $response = $this->get($path);

        $response->assertStatus(200);

        return $response->getBody();
    }

    public function testSummaryCategories_ListsOnlyWhatTheBranchesTheEmployeeWorksInSold(): void
    {
        $this->openTheBooks();
        $path = '/reports/summary_categories/' . $this->reportDay . '/' . $this->reportDay . '/sales/all';

        $this->readerOfTheBranches([1]);
        $page = $this->pageOf($path);
        $this->assertStringContainsString('ReportCatA', $page);
        $this->assertStringNotContainsString('ReportCatB', $page);

        $this->readerOfTheBranches([$this->branchB]);
        $page = $this->pageOf($path);
        $this->assertStringContainsString('ReportCatB', $page);
        $this->assertStringNotContainsString('ReportCatA', $page);

        $this->readerOfTheBranches(null);
        $page = $this->pageOf($path);
        $this->assertStringContainsString('ReportCatA', $page);
        $this->assertStringContainsString('ReportCatB', $page);
    }

    public function testSummaryTaxes_ListsOnlyTheTaxOfTheBranchesTheEmployeeWorksIn(): void
    {
        $this->openTheBooks();
        $path = '/reports/summary_taxes/' . $this->reportDay . '/' . $this->reportDay . '/sales/all';

        $this->readerOfTheBranches([$this->branchB]);
        $page = $this->pageOf($path);
        $this->assertStringContainsString('VAT-B', $page);
        $this->assertStringNotContainsString('VAT-A', $page);

        $this->readerOfTheBranches(null);
        $page = $this->pageOf($path);
        $this->assertStringContainsString('VAT-A', $page);
        $this->assertStringContainsString('VAT-B', $page);
    }

    public function testDetailedSales_ListsOnlyTheSalesOfTheBranchesTheEmployeeWorksIn(): void
    {
        $this->openTheBooks();
        $path = '/reports/detailed_sales/' . $this->reportDay . '/' . $this->reportDay . '/sales/all';

        $this->readerOfTheBranches([1]);
        $page = $this->pageOf($path);
        $this->assertStringNotContainsString('ReportBuyerB', $page);

        $this->readerOfTheBranches([$this->branchB]);
        $page = $this->pageOf($path);
        $this->assertStringContainsString('ReportBuyerB', $page);

        $this->readerOfTheBranches(null);
        $this->assertStringContainsString('ReportBuyerB', $this->pageOf($path));
    }

    public function testSalesReports_ShowAnOwnRecordsEmployeeNothingOfWhatAnotherEmployeeSold(): void
    {
        $this->ownRecordsReaderWhoSold();

        $own = [];

        foreach ($this->salesReportPaths() as $name => $path) {
            $own[$name] = $this->pageOf($path);
        }

        $this->readerOfTheBranches(null);

        $foreign = ['ReportBuyerB', 'Report item B', 'ReportSupplierB', 'ReportCatB', 'VAT-B', 'VAT-A', '126.00', '207.00', '373.00'];
        $unseen  = [];

        foreach ($this->salesReportPaths() as $name => $path) {
            $everything = $this->pageOf($path);
            $compared   = 0;

            foreach ($foreign as $marker) {
                if (str_contains($everything, $marker)) {
                    $compared++;
                    $this->assertStringNotContainsString($marker, $own[$name], "{$name} shows {$marker} of a sale another employee made");
                }
            }

            if (str_starts_with($name, 'graphical_')) {
                $this->assertGreaterThan(0, $this->chartedBy($everything), "{$name} draws nothing for a reader who sees every record");
                $this->assertGreaterThan(0, $this->chartedBy($own[$name]), "{$name} draws nothing for the sales of an own records employee");
                $this->assertLessThan($this->chartedBy($everything), $this->chartedBy($own[$name]), "{$name} draws what another employee sold");

                continue;
            }

            $compared === 0 && $unseen[] = $name;
        }

        $this->assertSame([], $unseen, 'no marker of the other employee shows on these reports of a reader who sees every record, so nothing was compared');
        $this->assertStringContainsString('VAT-OWN', $own['summary_taxes']);
        $this->assertMatchesRegularExpression('/(?<![\d.,])30\.00/', $own['summary_sales']);
        $this->assertMatchesRegularExpression('/(?<![\d.,])30\.00/', $own['detailed_sales']);
    }

    public function testGetDetailedSalesRow_FindsASaleAnOwnRecordsEmployeeMadeAndNotOneAnotherMade(): void
    {
        ['mine' => $mine] = $this->ownRecordsReaderWhoSold();

        $this->dropEveryReportTable();
        $found = json_decode($this->get('/reports/get_detailed_sales_row/' . $mine)->getJSON(), true);

        $this->assertArrayHasKey((string) $mine, $found);

        foreach ([$this->reportSales['a'], $this->reportSales['b'], 987654] as $theirs) {
            $this->dropEveryReportTable();

            try {
                $this->get('/reports/get_detailed_sales_row/' . $theirs);
                $this->fail("The sale {$theirs} of another employee was found");
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSummarySales_NamesNoForeignSaleAnotherEmployeeMadeToAnOwnRecordsEmployee(): void
    {
        ['mine' => $mine] = $this->ownRecordsReaderWhoSold();
        $path             = '/reports/summary_sales/' . $this->reportDay . '/' . $this->reportDay . '/sales/all';

        $this->accountingDb()->table('sales')->where('sale_id', $mine)->update(['currency_code' => 'EUR', 'currency_rate' => '4.10000000']);
        $this->accountingDb()->table('sales')->where('sale_id', $this->reportSales['b'])->update(['currency_code' => 'USD', 'currency_rate' => '3.75000000']);

        $page = $this->pageOf($path);

        $this->assertStringContainsString('POS ' . $mine . ' (EUR)', $page);
        $this->assertStringNotContainsString('POS ' . $this->reportSales['b'] . ' (', $page);

        $this->readerOfTheBranches(null);
        $page = $this->pageOf($path);

        $this->assertStringContainsString('POS ' . $mine . ' (EUR)', $page);
        $this->assertStringContainsString('POS ' . $this->reportSales['b'] . ' (USD)', $page);
    }

    public function testDetailedReceivings_ListsOnlyTheReceivingsOfTheBranchesTheEmployeeWorksIn(): void
    {
        $this->openTheBooks();
        $path = '/reports/detailed_receivings/' . $this->reportDay . '/' . $this->reportDay . '/all/all';

        $this->readerOfTheBranches([1]);
        $page = $this->pageOf($path);
        $this->assertStringContainsString('Gulf Supplies', $page);
        $this->assertStringNotContainsString('ReportSupplierB', $page);

        $this->readerOfTheBranches([$this->branchB]);
        $page = $this->pageOf($path);
        $this->assertStringContainsString('ReportSupplierB', $page);
        $this->assertStringNotContainsString('Gulf Supplies', $page);
    }

    public function testInventorySummary_ListsOnlyTheStockOfTheBranchesTheEmployeeWorksIn(): void
    {
        $this->openTheBooks();

        $this->readerOfTheBranches([1]);
        $page = $this->pageOf('/reports/inventory_summary/all/all');
        $this->assertStringContainsString($this->reportNames['a'], $page);
        $this->assertStringNotContainsString($this->reportNames['b'], $page);

        $this->readerOfTheBranches(null);
        $page = $this->pageOf('/reports/inventory_summary/all/all');
        $this->assertStringContainsString($this->reportNames['a'], $page);
        $this->assertStringContainsString($this->reportNames['b'], $page);
    }

    public function testInventoryLow_ListsOnlyTheItemsRunningLowInTheBranchesTheEmployeeWorksIn(): void
    {
        $this->openTheBooks();

        $this->readerOfTheBranches([$this->branchB]);
        $page = $this->pageOf('/reports/inventory_low');
        $this->assertStringContainsString($this->reportNames['b'], $page);
        $this->assertStringNotContainsString($this->reportNames['a'], $page);
    }

    public function testDateInput_OffersOnlyTheStockLocationsOfTheBranchesTheEmployeeWorksIn(): void
    {
        $this->assertTheScreenOffersOnlyTheStoresOfTheBranchesOfTheEmployee('/reports/summary_sales', 'sales');
    }

    public function testSummaryDiscountsInput_OffersOnlyTheStockLocationsOfTheBranchesTheEmployeeWorksIn(): void
    {
        $this->assertTheScreenOffersOnlyTheStoresOfTheBranchesOfTheEmployee('/reports/summary_discounts', 'sales');
    }

    public function testDateInputSales_OffersOnlyTheStockLocationsOfTheBranchesTheEmployeeWorksIn(): void
    {
        $this->assertTheScreenOffersOnlyTheStoresOfTheBranchesOfTheEmployee('/reports/detailed_sales', 'sales');
    }

    public function testDateInputItemSales_OffersOnlyTheStockLocationsOfTheBranchesTheEmployeeWorksIn(): void
    {
        $this->assertTheScreenOffersOnlyTheStoresOfTheBranchesOfTheEmployee('/reports/detailed_item_sales', 'sales');
    }

    public function testDateInputRecv_OffersOnlyTheStockLocationsOfTheBranchesTheEmployeeWorksIn(): void
    {
        $this->assertTheScreenOffersOnlyTheStoresOfTheBranchesOfTheEmployee('/reports/detailed_receivings', 'receivings');
    }

    public function testInventorySummaryInput_OffersOnlyTheStockLocationsOfTheBranchesTheEmployeeWorksIn(): void
    {
        $this->assertTheScreenOffersOnlyTheStoresOfTheBranchesOfTheEmployee('/reports/inventory_summary', 'items');
    }

    private function assertTheScreenOffersOnlyTheStoresOfTheBranchesOfTheEmployee(string $path, string $module): void
    {
        $this->openTheBooks();
        $db        = $this->accountingDb();
        $storeName = (string) $db->table('stock_locations')->where('location_id', $this->storeB)->get()->getRow()->location_name;

        $personId = $this->readerOfTheBranches([1]);
        $this->grantTheStore($personId, $module, 1);
        $this->grantTheStore($personId, $module, $this->storeB);
        $this->assertStringNotContainsString($storeName, $this->pageOf($path));

        $personId = $this->readerOfTheBranches(null);
        $this->grantTheStore($personId, $module, 1);
        $this->grantTheStore($personId, $module, $this->storeB);
        $this->assertStringContainsString($storeName, $this->pageOf($path));
    }

    public function testDateInput_OffersAnInactiveStockLocationBecauseItKeepsItsHistory(): void
    {
        $this->openTheBooks();
        $db        = $this->accountingDb();
        $storeName = (string) $db->table('stock_locations')->where('location_id', $this->storeB)->get()->getRow()->location_name;

        $db->table('stock_locations')->where('location_id', $this->storeB)->update(['status' => 0]);

        try {
            foreach ([['/reports/summary_sales', 'sales'], ['/reports/summary_discounts', 'sales'], ['/reports/detailed_sales', 'sales'], ['/reports/detailed_item_sales', 'sales'], ['/reports/detailed_receivings', 'receivings'], ['/reports/inventory_summary', 'items']] as [$path, $module]) {
                $personId = $this->readerOfTheBranches(null);
                $this->grantTheStore($personId, $module, 1);
                $this->grantTheStore($personId, $module, $this->storeB);

                $this->assertStringContainsString($storeName, $this->pageOf($path), $path);
            }
        } finally {
            $db->table('stock_locations')->where('location_id', $this->storeB)->update(['status' => 1]);
        }
    }

    private function grantTheStore(int $personId, string $module, int $locationId): void
    {
        $db   = $this->accountingDb();
        $name = (string) $db->table('stock_locations')->where('location_id', $locationId)->get()->getRow()->location_name;

        $db->table('permissions')->ignore(true)->insert(['permission_id' => "{$module}_{$name}", 'module_id' => $module, 'location_id' => $locationId]);
        $db->table('grants')->ignore(true)->insert(['permission_id' => "{$module}_{$name}", 'person_id' => $personId, 'menu_group' => 'home']);
    }

    public function testReportPages_SayWhoseFiguresTheyHoldToSomeoneWhoReadsMoreThanOneBranch(): void
    {
        $this->openTheBooks();
        $path  = '/reports/summary_categories/' . $this->reportDay . '/' . $this->reportDay . '/sales/all';
        $this->createBranch('R' . substr(md5(uniqid('', true)), 0, 8), 'Report branch C');

        $this->readerOfTheBranches([1, $this->branchB]);
        $page = $this->pageOf($path);
        $this->assertStringContainsString('id="report_scope"', $page);
        $this->assertStringContainsString('alert-warning', $page);
        $this->assertStringContainsString(lang('Accounting_reports.scope_partial', [implode(lang('Accounting_reports.branch_separator'), ['HQ', (string) $this->accountingDb()->table('branches')->where('branch_id', $this->branchB)->get()->getRow()->code])]), html_entity_decode($page));

        $this->readerOfTheBranches(null);
        $page = $this->pageOf($path);
        $this->assertStringContainsString('id="report_scope"', $page);
        $this->assertStringContainsString(lang('Accounting_reports.scope_company'), $page);
        $this->assertStringNotContainsString('alert-warning', $page);
    }

    public function testReportPages_SayNothingOfBranchesToSomeoneWhoReadsOneOnly(): void
    {
        $this->openTheBooks();

        $this->readerOfTheBranches([1]);

        $this->assertStringNotContainsString('id="report_scope"', $this->pageOf('/reports/summary_categories/' . $this->reportDay . '/' . $this->reportDay . '/sales/all'));
    }

    public function testSummaryExpensesCategories_RejectsAnEmployeeWhoDoesNotWorkInEveryBranch(): void
    {
        $this->assertExpensesReportIsRefusedToAnEmployeeOfOneBranch('summary_expenses_categories');
    }

    public function testGraphicalSummaryExpensesCategories_RejectsAnEmployeeWhoDoesNotWorkInEveryBranch(): void
    {
        $this->assertExpensesReportIsRefusedToAnEmployeeOfOneBranch('graphical_summary_expenses_categories');
    }

    public function testGetIndex_OffersTheExpensesReportsOnlyToAnEmployeeOfEveryBranch(): void
    {
        $this->openTheBooks();
        $this->readerOfTheBranches([1]);

        $body = $this->get('/reports')->getBody();

        $this->assertStringContainsString('reports/summary_categories', $body);
        $this->assertStringNotContainsString('reports/summary_expenses_categories', $body);
        $this->assertStringNotContainsString('reports/graphical_summary_expenses_categories', $body);

        $this->readerOfTheBranches(null);

        $this->assertStringContainsString('reports/summary_expenses_categories', $this->get('/reports')->getBody());
    }

    public function testGetIndex_ListsTheOperationalReportsOnlyForWhoHoldsTheFinancialReportsModule(): void
    {
        $this->openTheBooks();

        $this->loginAs($this->employeeWithGrants(['reports', 'accounting_reports', 'reports_sales', 'reports_items'], null));

        $with = $this->get('/reports')->getBody();

        $this->assertStringContainsString('accounting_reports/revenue?group=day', $with);
        $this->assertStringContainsString('accounting_reports/net_position', $with);
        $this->assertStringContainsString('accounting_reports/item_sales?group=item', $with);
        $this->assertStringNotContainsString('accounting_reports/payments', $with);

        $this->loginAs($this->employeeWithGrants(['reports', 'reports_sales', 'reports_items'], null));

        $this->assertStringNotContainsString('accounting_reports/revenue', $this->get('/reports')->getBody());
    }

    public function testSummaryExpensesCategories_AcceptsAnEmployeeOfEveryBranch(): void
    {
        $this->openTheBooks();
        $this->readerOfTheBranches(null);

        $this->get('/reports/summary_expenses_categories/' . $this->reportDay . '/' . $this->reportDay . '/sales')->assertStatus(200);
    }

    public function testGraphicalSummaryExpensesCategories_AcceptsAnEmployeeOfEveryBranch(): void
    {
        $this->openTheBooks();
        $this->readerOfTheBranches(null);

        $this->get('/reports/graphical_summary_expenses_categories/' . $this->reportDay . '/' . $this->reportDay . '/sales')->assertStatus(200);
    }

    /**
     * Business in two branches, and a reader of every branch who sees their own records only and has spent 7.00 on the
     * day of the report where the company spent 147.00, logged in.
     *
     * @return array{reader: int, mine: int}
     */
    private function ownRecordsReaderWhoSpent(): array
    {
        $this->openTheBooks();

        $reader = $this->readerOfTheBranches(null);
        $spent  = $this->ownRecordsEmployeeWhoSpent($reader);

        return ['reader' => $reader, 'mine' => $spent['mine']];
    }

    /**
     * @return array<string, string> The expenses reports of the legacy menu, by name, with the address of each for the day of the report.
     */
    private function expensesReportPaths(): array
    {
        return [
            'summary_expenses_categories'           => '/reports/summary_expenses_categories/' . $this->reportDay . '/' . $this->reportDay . '/sales',
            'graphical_summary_expenses_categories' => '/reports/graphical_summary_expenses_categories/' . $this->reportDay . '/' . $this->reportDay . '/sales',
        ];
    }

    private function chartedExpensesOf(string $page): float
    {
        preg_match_all('/"values":\[([^\]]*)\]/', html_entity_decode($page), $charts);

        return array_sum(array_map(static fn (string $values): float => array_sum(array_map('floatval', explode(',', $values))), $charts[1]));
    }

    public function testExpensesReports_ShowAnOwnRecordsEmployeeNothingOfWhatAnotherEmployeeSpent(): void
    {
        $this->ownRecordsReaderWhoSpent();

        $own = [];

        foreach ($this->expensesReportPaths() as $name => $path) {
            $own[$name] = $this->pageOf($path);
        }

        $this->readerOfTheBranches(null);

        $foreign = ['ReportExpensesOther', '107.00', '40.00', '147.00', '22.00'];

        foreach ($this->expensesReportPaths() as $name => $path) {
            $everything = $this->pageOf($path);
            $compared   = 0;

            foreach ($foreign as $marker) {
                if (str_contains($everything, $marker)) {
                    $compared++;
                    $this->assertStringNotContainsString($marker, $own[$name], "{$name} shows {$marker} of an expense another employee made");
                }
            }

            $this->assertGreaterThan(0, $compared, "{$name} shows nothing of the other employee to a reader who sees every record, so nothing was compared");
            $this->assertStringContainsString('ReportExpenses', $own[$name], $name);
        }

        $this->assertMatchesRegularExpression('/(?<![\d.,])7\.00/', $own['summary_expenses_categories']);
        $this->assertEqualsWithDelta(7.0, $this->chartedExpensesOf($own['graphical_summary_expenses_categories']), 0.001);
        $this->assertEqualsWithDelta(147.0, $this->chartedExpensesOf($this->pageOf($this->expensesReportPaths()['graphical_summary_expenses_categories'])), 0.001);
    }

    public function testGraphicalSummaryExpensesCategories_DrawsNothingForAnOwnRecordsEmployeeWhoseOwnExpenseIsDeleted(): void
    {
        $mine = $this->ownRecordsReaderWhoSpent()['mine'];
        $this->accountingDb()->table('expenses')->where('expense_id', $mine)->update(['deleted' => 1]);

        $page = $this->pageOf($this->expensesReportPaths()['graphical_summary_expenses_categories']);

        $this->assertStringContainsString(lang('Reports.no_data_for_period'), $page);
        $this->assertStringNotContainsString('ReportExpenses', $page);
        $this->assertSame(0.0, $this->chartedExpensesOf($page));
    }

    public function testSummaryExpensesCategories_ShowsEveryExpenseToAnEmployeeOfEveryBranchWhoSeesEveryRecord(): void
    {
        $this->ownRecordsReaderWhoSpent();
        $this->readerOfTheBranches(null);

        $tabular = $this->pageOf($this->expensesReportPaths()['summary_expenses_categories']);

        foreach (['ReportExpenses', 'ReportExpensesOther', '107.00', '40.00', '147.00'] as $marker) {
            $this->assertStringContainsString($marker, $tabular);
        }

        $this->assertEqualsWithDelta(147.0, $this->chartedExpensesOf($this->pageOf($this->expensesReportPaths()['graphical_summary_expenses_categories'])), 0.001);
    }

    public function testSummaryExpensesCategories_RejectsAnOwnRecordsEmployeeOfOneBranch(): void
    {
        $this->openTheBooks();
        $this->ownRecordsEmployeeWhoSpent($this->readerOfTheBranches([1]));

        foreach ($this->expensesReportPaths() as $name => $path) {
            $this->assertSame(403, $this->get($path)->response()->getStatusCode(), $name);
        }
    }

    public function testSummaryExpensesCategories_CutsToTheExpensesOfAnOwnRecordsEmployeeWhenTheReportIsAskedByTime(): void
    {
        $this->ownRecordsReaderWhoSpent();

        $before = config(OSPOS::class)->settings['date_or_time_format'] ?? null;

        config(OSPOS::class)->settings['date_or_time_format'] = '1';

        try {
            $noon    = $this->pageOf('/reports/summary_expenses_categories/' . $this->hoursStillEncoded($this->reportDay . ' 11:00:00', $this->reportDay . ' 13:00:00') . '/sales');
            $evening = $this->pageOf('/reports/summary_expenses_categories/' . $this->hoursStillEncoded($this->reportDay . ' 13:00:00', $this->reportDay . ' 14:00:00') . '/sales');

            $noonChart    = $this->pageOf('/reports/graphical_summary_expenses_categories/' . $this->hoursStillEncoded($this->reportDay . ' 11:00:00', $this->reportDay . ' 13:00:00') . '/sales');
            $eveningChart = $this->pageOf('/reports/graphical_summary_expenses_categories/' . $this->hoursStillEncoded($this->reportDay . ' 13:00:00', $this->reportDay . ' 14:00:00') . '/sales');
        } finally {
            config(OSPOS::class)->settings['date_or_time_format'] = $before;
        }

        $this->assertStringContainsString('ReportExpenses', $noon);
        $this->assertStringNotContainsString('ReportExpensesOther', $noon);
        $this->assertMatchesRegularExpression('/(?<![\d.,])7\.00/', $noon);
        $this->assertStringNotContainsString('ReportExpenses', $evening);
        $this->assertEqualsWithDelta(7.0, $this->chartedExpensesOf($noonChart), 0.001);
        $this->assertSame(0.0, $this->chartedExpensesOf($eveningChart));
    }

    public function testGetDetailedSalesRow_FindsASaleOfTheBranchesTheEmployeeWorksInAndNotOfAnother(): void
    {
        $this->openTheBooks();

        $this->readerOfTheBranches([1]);
        $this->dropEveryReportTable();
        $found = json_decode($this->get('/reports/get_detailed_sales_row/' . $this->reportSales['a'])->getJSON(), true);

        $this->assertArrayHasKey((string) $this->reportSales['a'], $found);

        $this->dropEveryReportTable();
        $this->expectException(PageNotFoundException::class);

        $this->get('/reports/get_detailed_sales_row/' . $this->reportSales['b']);
    }

    public function testGetDetailedReceivingsRow_FindsAReceivingOfTheBranchesTheEmployeeWorksInAndNotOfAnother(): void
    {
        $this->openTheBooks();

        $this->readerOfTheBranches([1]);
        $this->dropEveryReportTable();
        $found = json_decode($this->get('/reports/get_detailed_receivings_row/' . $this->reportReceivings['a'])->getJSON(), true);

        $this->assertArrayHasKey((string) $this->reportReceivings['a'], $found);

        $this->dropEveryReportTable();
        $this->expectException(PageNotFoundException::class);

        $this->get('/reports/get_detailed_receivings_row/' . $this->reportReceivings['b']);
    }

    protected function loginAsReportViewer(): void
    {
        $this->loginAs($this->employeeWithGrants(['reports', 'reports_sales']));
    }

    public function testGetDetailedSalesRow_ShowsTheDiscountOnTheWholeOfTheSale(): void
    {
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $saleId = $this->reportSale($itemId, '2', '50', ['type' => FIXED, 'value' => '12.5'], self::REPORT_MONTH . '06');
        $this->loginAsReportViewer();

        $response = $this->get('/reports/get_detailed_sales_row/' . $saleId);

        $response->assertStatus(200);

        $row = json_decode($response->getJSON(), true)[$saleId];

        $this->assertSame(to_currency('12.5'), $row['invoice_discount']);
        $this->assertSame(to_currency('87.5'), $row['subtotal']);
    }

    public function testDetailedSales_ShowsTheDiscountOnTheWholeOfEachSaleAndNothingForASaleWithoutOne(): void
    {
        $itemId = $this->createTestItem(HAS_NO_STOCK);

        $this->reportSale($itemId, '2', '50', ['type' => FIXED, 'value' => '12.5'], self::REPORT_MONTH . '06');
        $this->reportSale($itemId, '1', '30', null, self::REPORT_MONTH . '06');
        $this->loginAsReportViewer();

        $response = $this->get('/reports/detailed_sales/' . self::REPORT_MONTH . '06/' . self::REPORT_MONTH . '06/complete');

        $response->assertStatus(200);

        $page = $response->getBody();

        $this->assertStringContainsString('"invoice_discount":' . json_encode(to_currency('12.5')), $page);
        $this->assertStringContainsString('"invoice_discount":' . json_encode(to_currency('0')), $page);
    }

    private function accountingSwitch(): string
    {
        $row = Database::connect()->table('app_config')->where('key', 'accounting_enabled')->get()->getRow();

        return $row === null ? '0' : (string) $row->value;
    }

    private function turnAccounting(string $enabled): void
    {
        Database::connect()->table('app_config')->replace(['key' => 'accounting_enabled', 'value' => $enabled]);
    }

    /**
     * @param list<string> $permissionIds
     */
    private function reportsIndexOf(array $permissionIds, string $accounting): string
    {
        $before     = $this->accountingSwitch();
        $employeeId = $this->createNonAdminEmployee([
            'grants' => array_map(static fn (string $permissionId): array => ['permission_id' => $permissionId, 'menu_group' => 'home'], ['reports', ...$permissionIds]),
        ]);

        $this->loginAs($employeeId);
        $this->turnAccounting($accounting);

        try {
            $response = $this->get('/reports');

            $response->assertStatus(200);

            return $response->getBody();
        } finally {
            $this->turnAccounting($before);
        }
    }

    public function testGetIndex_OffersTheInventoryValuationToWhoHoldsTheFinancialReportsModuleWhileAccountingIsOn(): void
    {
        $body = $this->reportsIndexOf(['reports_inventory', 'accounting_reports'], '1');

        $this->assertStringContainsString('accounting_reports/inventory_valuation', $body);
        $this->assertStringContainsString('reports/inventory_summary', $body, 'The legacy report stays where it was');
    }

    public function testGetIndex_DoesNotOfferTheInventoryValuationWhileAccountingIsOff(): void
    {
        $body = $this->reportsIndexOf(['reports_inventory', 'accounting_reports'], '0');

        $this->assertStringNotContainsString('inventory_valuation', $body);
        $this->assertStringContainsString('reports/inventory_summary', $body, 'With accounting off the reports are as they were');
    }

    public function testGetIndex_DoesNotOfferTheInventoryValuationToWhoLacksTheFinancialReportsModule(): void
    {
        $body = $this->reportsIndexOf(['reports_inventory', 'accounting_journals'], '1');

        $this->assertStringNotContainsString('inventory_valuation', $body);
    }

    public function testGetIndex_OffersTheReportsOfTheFamiliesToWhoHoldsTheModuleAndThePermissionWhileAccountingIsOn(): void
    {
        $body = $this->reportsIndexOf(['reports_suppliers', 'reports_inventory', 'accounting_reports'], '1');

        $this->assertStringContainsString('accounting_reports/supplier_balances', $body);
        $this->assertStringContainsString('accounting_reports/stock_aging', $body);
        $this->assertStringNotContainsString('accounting_reports/expenses', $body, 'No permission for the expenses report');
        $this->assertStringContainsString('reports/inventory_summary', $body);
    }

    public function testGetIndex_DoesNotOfferTheReportsOfTheFamiliesWhileAccountingIsOffOrWithoutTheModule(): void
    {
        $this->assertStringNotContainsString('accounting_reports/supplier_balances', $this->reportsIndexOf(['reports_suppliers', 'accounting_reports'], '0'));
        $this->assertStringNotContainsString('accounting_reports/supplier_balances', $this->reportsIndexOf(['reports_suppliers', 'accounting_journals'], '1'));
    }

    /**
     * The two ends of a range of hours encoded so many times that they reach the controller still encoded once,
     * which the report models decode a last time and so must the note.
     */
    private function hoursStillEncoded(string $from, string $to): string
    {
        return rawurlencode(rawurlencode(rawurlencode($from))) . '/' . rawurlencode(rawurlencode(rawurlencode($to)));
    }

    private function inDollars(int $saleId): void
    {
        $this->accountingDb()->table('sales')->where('sale_id', $saleId)->update(['currency_code' => 'USD', 'currency_rate' => '3.75000000']);
    }

    public function testSummarySales_SaysUnderTheHeaderThatTheDaysHoldADocumentInAForeignCurrency(): void
    {
        $this->openTheBooks();
        $this->readerOfTheBranches(null);
        $this->inDollars($this->reportSales['a']);

        $page = $this->pageOf('/reports/summary_sales/' . $this->reportDay . '/' . $this->reportDay . '/sales/all');

        $this->assertStringContainsString('id="report_foreign_currency"', $page);
        $this->assertStringContainsString('POS ' . $this->reportSales['a'] . ' (USD)', $page);
        $this->assertStringNotContainsString('POS ' . $this->reportSales['b'] . ' (', $page);
    }

    /**
     * @return list<string> The address of every legacy screen that adds up the documents of the days, for the second sale.
     */
    private function legacyScreens(): array
    {
        $range = $this->reportDay . '/' . $this->reportDay;
        $paths = [];

        foreach (['summary_sales', 'summary_categories', 'summary_customers', 'summary_suppliers', 'summary_items', 'summary_employees', 'summary_taxes', 'summary_sales_taxes'] as $report) {
            $paths[] = "/reports/{$report}/{$range}/sales/all";
            $paths[] = "/reports/graphical_{$report}/{$range}/sales/all";
        }

        return array_merge($paths, [
            "/reports/summary_discounts/{$range}/sales/all/0",
            "/reports/graphical_summary_discounts/{$range}/sales/all/0",
            "/reports/summary_payments/{$range}",
            "/reports/graphical_summary_payments/{$range}/sales/all",
            "/reports/detailed_sales/{$range}/sales/all",
            "/reports/detailed_item_sales/{$range}/sales/all/all",
            "/reports/specific_customers/{$range}/{$this->customerB}/sales/all",
            "/reports/specific_employees/{$range}/{$this->employeeId}/sales",
            "/reports/specific_suppliers/{$range}/{$this->supplierB}/sales",
            "/reports/specific_discounts/{$range}/0/sales/0",
        ]);
    }

    public function testGetSubtitleReport_NamesTheForeignDocumentOnEveryLegacyScreen(): void
    {
        $this->openTheBooks();
        $this->readerOfTheBranches(null);
        $this->inDollars($this->reportSales['b']);

        foreach ($this->legacyScreens() as $path) {
            $page = $this->pageOf($path);

            $this->assertStringContainsString('id="report_foreign_currency"', $page, $path);
            $this->assertStringContainsString('POS ' . $this->reportSales['b'] . ' (USD)', $page, $path);
        }
    }

    public function testGetSubtitleReport_SaysNothingOnAnyLegacyScreenWhenTheDaysHoldOnlyBaseDocuments(): void
    {
        $this->openTheBooks();
        $this->readerOfTheBranches(null);

        foreach ($this->legacyScreens() as $path) {
            $this->assertStringNotContainsString('report_foreign_currency', $this->pageOf($path), $path);
        }
    }

    public function testGraphicalSummarySales_NamesTheChartByItsTitleAndDatesAndShowsTheNoteApart(): void
    {
        $this->openTheBooks();
        $this->readerOfTheBranches(null);
        $this->inDollars($this->reportSales['b']);

        $page = $this->pageOf('/reports/graphical_summary_sales/' . $this->reportDay . '/' . $this->reportDay . '/sales/all');

        $this->assertSame(1, preg_match('/<canvas[^>]*aria-label="([^"]*)"/', $page, $found));

        $label = html_entity_decode($found[1]);

        $this->assertStringNotContainsString("\n", $label);
        $this->assertStringNotContainsString('USD', $label);
        $this->assertStringStartsWith(lang('Reports.sales_summary_report') . ' — ', $label);
        $this->assertSame(1, substr_count($label, ' — '));
        $this->assertStringContainsString('id="report_foreign_currency"', $page);
    }

    public function testSummarySales_SaysNothingOfCurrenciesWhenEveryDocumentOfTheDaysIsInTheBase(): void
    {
        $this->openTheBooks();
        $this->readerOfTheBranches(null);

        $page = $this->pageOf('/reports/summary_sales/' . $this->reportDay . '/' . $this->reportDay . '/sales/all');

        $this->assertStringNotContainsString('report_foreign_currency', $page);
        $this->assertStringNotContainsString('(USD)', $page);
    }

    public function testSummarySales_SaysNothingOfADocumentInAForeignCurrencyOfAnotherDay(): void
    {
        $this->openTheBooks();
        $this->readerOfTheBranches(null);
        $this->inDollars($this->reportSales['a']);

        $page = $this->pageOf('/reports/summary_sales/2031-03-15/2031-03-15/sales/all');

        $this->assertStringNotContainsString('report_foreign_currency', $page);
    }

    public function testSummaryExpensesCategories_SaysNothingOfCurrenciesBecauseExpensesAreInTheBase(): void
    {
        $this->openTheBooks();
        $this->readerOfTheBranches(null);
        $this->inDollars($this->reportSales['a']);

        $page = $this->pageOf('/reports/summary_expenses_categories/' . $this->reportDay . '/' . $this->reportDay . '/sales');

        $this->assertStringNotContainsString('report_foreign_currency', $page);
    }

    public function testDetailedReceivings_NamesTheReceivingsInAForeignCurrencyOfTheDays(): void
    {
        $this->openTheBooks();
        $this->readerOfTheBranches(null);
        $this->accountingDb()->table('receivings')->where('receiving_id', $this->reportReceivings['a'])->update(['currency_code' => 'USD', 'currency_rate' => '3.75000000']);

        $page = $this->pageOf('/reports/detailed_receivings/' . $this->reportDay . '/' . $this->reportDay . '/all/all');

        $this->assertStringContainsString('RECV ' . $this->reportReceivings['a'] . ' (USD)', $page);
        $this->assertStringNotContainsString('RECV ' . $this->reportReceivings['b'] . ' (', $page);
    }

    public function testSummarySales_NamesNoForeignDocumentOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $this->openTheBooks();
        $this->inDollars($this->reportSales['b']);
        $path = '/reports/summary_sales/' . $this->reportDay . '/' . $this->reportDay . '/sales/all';
        $name = 'POS ' . $this->reportSales['b'] . ' (USD)';

        foreach ([[1], []] as $branchIds) {
            $this->readerOfTheBranches($branchIds);
            $page = $this->pageOf($path);

            $this->assertStringNotContainsString('report_foreign_currency', $page);
            $this->assertStringNotContainsString($name, $page);
        }

        foreach ([[$this->branchB], [1, $this->branchB], null] as $branchIds) {
            $this->readerOfTheBranches($branchIds);

            $this->assertStringContainsString($name, $this->pageOf($path));
        }
    }

    public function testDetailedReceivings_ListsOnlyTheReceivingsAnOwnRecordsEmployeeMade(): void
    {
        $this->ownRecordsReaderWhoBought();
        $path = '/reports/detailed_receivings/' . $this->reportDay . '/' . $this->reportDay . '/all/all';

        $page = $this->pageOf($path);

        $this->assertStringContainsString('OwnOnlySupplier', $page);
        $this->assertStringNotContainsString('Gulf Supplies', $page);
        $this->assertStringNotContainsString('ReportSupplierB', $page);

        $this->readerOfTheBranches(null);
        $page = $this->pageOf($path);

        foreach (['OwnOnlySupplier', 'Gulf Supplies', 'ReportSupplierB'] as $supplier) {
            $this->assertStringContainsString($supplier, $page);
        }
    }

    public function testGetDetailedReceivingsRow_FindsAReceivingAnOwnRecordsEmployeeMadeAndNotOneAnotherMade(): void
    {
        ['mine' => $mine] = $this->ownRecordsReaderWhoBought();

        $this->dropEveryReportTable();
        $found = json_decode($this->get('/reports/get_detailed_receivings_row/' . $mine)->getJSON(), true);

        $this->assertArrayHasKey((string) $mine, $found);

        $this->dropEveryReportTable();
        $this->expectException(PageNotFoundException::class);

        $this->get('/reports/get_detailed_receivings_row/' . $this->reportReceivings['a']);
    }

    public function testDetailedReceivings_NamesNoForeignReceivingAnotherEmployeeMadeToAnOwnRecordsEmployee(): void
    {
        ['mine' => $mine] = $this->ownRecordsReaderWhoBought();
        $path             = '/reports/detailed_receivings/' . $this->reportDay . '/' . $this->reportDay . '/all/all';

        $this->accountingDb()->table('receivings')->whereIn('receiving_id', [$mine, $this->reportReceivings['a']])->update(['currency_code' => 'USD', 'currency_rate' => '3.75000000']);

        $page = $this->pageOf($path);

        $this->assertStringContainsString('RECV ' . $mine . ' (USD)', $page);
        $this->assertStringNotContainsString('RECV ' . $this->reportReceivings['a'] . ' (', $page);

        $this->readerOfTheBranches(null);
        $page = $this->pageOf($path);

        $this->assertStringContainsString('RECV ' . $mine . ' (USD)', $page);
        $this->assertStringContainsString('RECV ' . $this->reportReceivings['a'] . ' (USD)', $page);
    }

    public function testDetailedReceivings_NamesNoForeignReceivingOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $this->openTheBooks();
        $this->accountingDb()->table('receivings')->where('receiving_id', $this->reportReceivings['b'])->update(['currency_code' => 'USD', 'currency_rate' => '3.75000000']);
        $path = '/reports/detailed_receivings/' . $this->reportDay . '/' . $this->reportDay . '/all/all';
        $name = 'RECV ' . $this->reportReceivings['b'] . ' (USD)';

        $this->readerOfTheBranches([1]);
        $this->assertStringNotContainsString('report_foreign_currency', $this->pageOf($path));

        $this->readerOfTheBranches([$this->branchB]);
        $this->assertStringContainsString($name, $this->pageOf($path));

        $this->readerOfTheBranches(null);
        $this->assertStringContainsString($name, $this->pageOf($path));
    }

    public function testSummarySales_NamesTheForeignDocumentsOfTheKindOfSaleOfTheReportOnly(): void
    {
        $this->openTheBooks();
        $this->readerOfTheBranches(null);
        $this->inDollars($this->reportSales['a']);
        $this->accountingDb()->table('sales')->where('sale_id', $this->reportSales['a'])->update(['sale_status' => CANCELED]);
        $range = $this->reportDay . '/' . $this->reportDay;
        $name  = 'POS ' . $this->reportSales['a'] . ' (USD)';

        $this->assertStringContainsString($name, $this->pageOf("/reports/summary_sales/{$range}/canceled/all"));
        $this->assertStringNotContainsString('report_foreign_currency', $this->pageOf("/reports/summary_sales/{$range}/sales/all"));
        $this->assertStringNotContainsString('report_foreign_currency', $this->pageOf("/reports/summary_sales/{$range}/complete/all"));
    }

    public function testSummarySales_NamesTheForeignDocumentsOfTheLocationOfTheReportOnly(): void
    {
        $this->openTheBooks();
        $this->readerOfTheBranches(null);
        $this->inDollars($this->reportSales['b']);
        $range = $this->reportDay . '/' . $this->reportDay;

        $this->assertStringContainsString('POS ' . $this->reportSales['b'] . ' (USD)', $this->pageOf("/reports/summary_sales/{$range}/sales/{$this->storeB}"));
        $this->assertStringNotContainsString('report_foreign_currency', $this->pageOf("/reports/summary_sales/{$range}/sales/" . self::LOCATION_ID));
    }

    public function testSummarySales_ReadsTheHoursOfTheNoteWhenTheReportIsAskedByTime(): void
    {
        $this->openTheBooks();
        $this->readerOfTheBranches(null);
        $this->inDollars($this->reportSales['b']);
        config(OSPOS::class)->settings['date_or_time_format'] = '1';

        $this->assertStringContainsString(
            'POS ' . $this->reportSales['b'] . ' (USD)',
            $this->pageOf('/reports/summary_sales/' . $this->hoursStillEncoded($this->reportDay . ' 09:00:00', $this->reportDay . ' 11:00:00') . '/sales/all'),
        );
        $this->assertStringNotContainsString(
            'report_foreign_currency',
            $this->pageOf('/reports/summary_sales/' . $this->hoursStillEncoded($this->reportDay . ' 10:30:00', $this->reportDay . ' 11:00:00') . '/sales/all'),
        );
    }

    public function testDetailedReceivings_ReadsTheHoursOfTheNoteWhenTheReportIsAskedByTime(): void
    {
        $this->openTheBooks();
        $this->readerOfTheBranches(null);
        $this->accountingDb()->table('receivings')->where('receiving_id', $this->reportReceivings['a'])->update(['currency_code' => 'USD', 'currency_rate' => '3.75000000']);
        config(OSPOS::class)->settings['date_or_time_format'] = '1';

        $this->assertStringContainsString(
            'RECV ' . $this->reportReceivings['a'] . ' (USD)',
            $this->pageOf('/reports/detailed_receivings/' . $this->hoursStillEncoded($this->reportDay . ' 08:00:00', $this->reportDay . ' 10:00:00') . '/all/all'),
        );
        $this->assertStringNotContainsString(
            'report_foreign_currency',
            $this->pageOf('/reports/detailed_receivings/' . $this->hoursStillEncoded($this->reportDay . ' 09:30:00', $this->reportDay . ' 10:00:00') . '/all/all'),
        );
    }

    public function testSpecificCustomerInput_EscapesTheNameAndTheCompanyOfEveryCustomer(): void
    {
        $this->insertCustomer('<img src=x onerror=alert(1)>', '"><svg onload=alert(2)>', '<b>Company</b>');
        $employeeId = $this->createNonAdminEmployee([
            'username' => 'customerreportviewer',
            'email'    => 'customerreportviewer@test.com',
            'grants'   => [
                ['permission_id' => 'reports', 'menu_group' => 'home'],
                ['permission_id' => 'reports_customers', 'menu_group' => 'home'],
            ],
        ]);
        $this->loginAs($employeeId);

        try {
            $page = (string) $this->get('/reports/specific_customers')->response()->getBody();
        } finally {
            $this->removeInsertedEntities();
        }

        $this->assertStringNotContainsString('<img src=x', $page);
        $this->assertStringNotContainsString('<svg onload', $page);
        $this->assertStringNotContainsString('<b>Company</b>', $page);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $page);
    }
}
