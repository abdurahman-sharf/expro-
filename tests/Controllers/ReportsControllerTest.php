<?php

namespace Tests\Controllers;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use App\Database\Seeds\TestDatabaseBootstrapSeeder;
use App\Models\Employee;
use Config\OSPOS;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\ReportBranchFixtureTrait;
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
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use ReportBranchFixtureTrait;

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
    }

    protected function tearDown(): void
    {
        if ($this->buyingStarted) {
            $this->forgetTheReportBusiness();
            $this->stopBuying();
            $this->removeTheReportItems();
        }

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
     * Business in two branches, and an employee who reads the reports of the branches given, or of
     * every branch, logged in.
     *
     * @param list<int>|null $branchIds
     */
    private function readerOfTheBranches(?array $branchIds): int
    {
        $personId = $this->employeeWithGrants([
            'reports', 'reports_sales', 'reports_categories', 'reports_taxes', 'reports_inventory', 'reports_receivings', 'reports_expenses_categories', 'reports_customers',
        ], $branchIds);

        $this->loginAs($personId);

        return $personId;
    }

    private function assertExpensesReportIsRefusedToAnEmployeeOfOneBranch(string $report): void
    {
        $this->openTheBooks();
        $this->readerOfTheBranches([1]);

        $response = $this->get('/reports/' . $report . '/' . $this->reportDay . '/' . $this->reportDay . '/sales');

        $response->assertRedirect();
        $this->assertStringContainsString('no_access/reports/reports_expenses_categories', $response->getRedirectUrl(), $report);
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
        $this->openTheBooks();
        $db        = $this->accountingDb();
        $storeName = (string) $db->table('stock_locations')->where('location_id', $this->storeB)->get()->getRow()->location_name;

        $personId = $this->readerOfTheBranches([1]);
        $this->grantTheStore($personId, 'sales', 1);
        $this->grantTheStore($personId, 'sales', $this->storeB);
        $this->assertStringNotContainsString($storeName, $this->pageOf('/reports/summary_sales'));

        $personId = $this->readerOfTheBranches(null);
        $this->grantTheStore($personId, 'sales', 1);
        $this->grantTheStore($personId, 'sales', $this->storeB);
        $this->assertStringContainsString($storeName, $this->pageOf('/reports/summary_sales'));
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
        $third = $this->createBranch('R' . substr(md5(uniqid('', true)), 0, 8), 'Report branch C');

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

        $this->assertNotSame($third, $this->branchB);
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
}
