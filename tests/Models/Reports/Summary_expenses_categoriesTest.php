<?php

namespace Tests\Models\Reports;

use App\Models\Reports\Summary_expenses_categories;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\OSPOS;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\ReportBranchFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * Expenses are kept for the company and not by branch, so only an employee of every branch reads them.
 */
class Summary_expenses_categoriesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use ReportBranchFixtureTrait;

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

        $this->startBuying();
        $this->businessInTwoBranches();
    }

    protected function tearDown(): void
    {
        $this->forgetTheReportBusiness();
        $this->stopBuying();
        $this->removeTheReportItems();

        parent::tearDown();
    }

    private function categories(string $scope): array
    {
        $this->readingAs($scope === 'system' ? null : $this->reportEmployee($scope));

        return array_column((new Summary_expenses_categories())->getData($this->saleInputs()), 'category_name');
    }

    private function spent(string $scope): string
    {
        $this->readingAs($scope === 'system' ? null : $this->reportEmployee($scope));

        return $this->amount((new Summary_expenses_categories())->getSummaryData($this->saleInputs())['expenses_total_amount'] ?? '0');
    }

    /**
     * What the report reads as the employee logged in (or nobody, which is the system) when it is told to read for the
     * viewer given, or for nobody.
     *
     * @param array<string, string> $inputs
     *
     * @return array{rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    private function readAs(?int $employeeId, ?int $viewerId, array $inputs = []): array
    {
        $this->readingAs($employeeId);

        $inputs = ($inputs === [] ? $this->saleInputs() : $inputs) + ($viewerId === null ? [] : ['viewer_id' => $viewerId]);
        $report = new Summary_expenses_categories();

        return ['rows' => $report->getData($inputs), 'summary' => $report->getSummaryData($inputs)];
    }

    protected function spend(): void
    {
        $this->spendOnTheReportDay($this->employeeId, '100.00', '15.00');
    }

    public function testGetData_ShowsTheExpensesOfTheWholeCompanyToAnEmployeeOfEveryBranchAndToTheSystem(): void
    {
        $this->spend();

        foreach (['all', 'system'] as $scope) {
            $this->assertSame(['ReportExpenses'], $this->categories($scope), $scope);
            $this->assertSame('100.00', $this->spent($scope), $scope);
        }
    }

    public function testGetData_ShowsNothingToAnEmployeeWhoDoesNotWorkInEveryBranchBecauseExpensesBelongToNoBranch(): void
    {
        $this->spend();

        foreach (['a', 'b', 'both', 'none'] as $scope) {
            $this->assertSame([], $this->categories($scope), $scope);
            $this->assertSame('0.00', $this->spent($scope), $scope);
        }
    }

    public function testGetData_ListsOnlyTheExpensesAnOwnRecordsEmployeeMade(): void
    {
        $own  = $this->ownRecordsEmployeeWhoSpent()['own'];
        $read = $this->readAs($own, $own);

        $this->assertSame(['ReportExpenses'], array_column($read['rows'], 'category_name'));
        $this->assertSame('1', (string) $read['rows'][0]['count']);
        $this->assertSame('7.00', $this->amount($read['rows'][0]['total_amount']));
        $this->assertSame('1.00', $this->amount($read['rows'][0]['total_tax_amount']));
    }

    public function testGetSummaryData_AddsUpOnlyTheExpensesAnOwnRecordsEmployeeMade(): void
    {
        $own  = $this->ownRecordsEmployeeWhoSpent()['own'];
        $read = $this->readAs($own, $own);

        $this->assertSame('7.00', $this->amount($read['summary']['expenses_total_amount']));
        $this->assertSame('1.00', $this->amount($read['summary']['expenses_total_tax_amount']));
        $this->assertSame($this->amount(array_sum(array_column($read['rows'], 'total_amount'))), $this->amount($read['summary']['expenses_total_amount']));
    }

    public function testGetData_ShowsEveryExpenseToAnEmployeeWhoSeesEveryRecordAndToTheSystem(): void
    {
        $this->ownRecordsEmployeeWhoSpent();
        $every = $this->reportEmployee('all');

        foreach ([[$every, $every], [$every, null], [null, null]] as $index => [$employeeId, $viewerId]) {
            $read = $this->readAs($employeeId, $viewerId);

            $this->assertSame(['ReportExpenses', 'ReportExpensesOther'], array_column($read['rows'], 'category_name'), (string) $index);
            $this->assertSame(['107.00', '40.00'], array_map($this->amount(...), array_column($read['rows'], 'total_amount')), (string) $index);
            $this->assertSame('147.00', $this->amount($read['summary']['expenses_total_amount']), (string) $index);
            $this->assertSame('22.00', $this->amount($read['summary']['expenses_total_tax_amount']), (string) $index);
        }
    }

    public function testGetData_ShowsEveryExpenseWhenNoViewerIsNamedWhoeverIsLoggedIn(): void
    {
        $own  = $this->ownRecordsEmployeeWhoSpent()['own'];
        $read = $this->readAs($own, null);

        $this->assertSame(['ReportExpenses', 'ReportExpensesOther'], array_column($read['rows'], 'category_name'));
        $this->assertSame('147.00', $this->amount($read['summary']['expenses_total_amount']));
    }

    public function testGetData_ShowsNothingToAnOwnRecordsEmployeeWhoSpentNothing(): void
    {
        $own = $this->ownRecordsEmployeeWhoSpent()['own'];
        $this->accountingDb()->table('expenses')->where('employee_id', $own)->delete();

        $read = $this->readAs($own, $own);

        $this->assertSame([], $read['rows']);
        $this->assertSame('0.00', $this->amount($read['summary']['expenses_total_amount']));
        $this->assertSame('140.00', $this->amount($this->readAs($this->reportEmployee('all'), null)['summary']['expenses_total_amount']));
    }

    public function testGetData_LeavesOutTheDeletedExpensesOfAnOwnRecordsEmployee(): void
    {
        ['own' => $own, 'mine' => $mine] = $this->ownRecordsEmployeeWhoSpent();
        $this->accountingDb()->table('expenses')->where('expense_id', $mine)->update(['deleted' => 1]);

        $read = $this->readAs($own, $own);

        $this->assertSame([], $read['rows']);
        $this->assertSame('0.00', $this->amount($read['summary']['expenses_total_amount']));
    }

    public function testGetData_CutsByTheHoursWhenTheReportIsAskedByTime(): void
    {
        $own     = $this->ownRecordsEmployeeWhoSpent()['own'];
        $before  = config(OSPOS::class)->settings['date_or_time_format'] ?? null;
        $inHours = static fn (string $from, string $to): array => ['start_date' => $from, 'end_date' => $to, 'sale_type' => 'sales', 'location_id' => 'all'];

        config(OSPOS::class)->settings['date_or_time_format'] = '1';

        try {
            $noon   = $inHours($this->reportDay . ' 11:00:00', $this->reportDay . ' 13:00:00');
            $evening = $inHours($this->reportDay . ' 13:00:00', $this->reportDay . ' 14:00:00');

            $this->assertSame('7.00', $this->amount($this->readAs($own, $own, $noon)['summary']['expenses_total_amount']));
            $this->assertSame('147.00', $this->amount($this->readAs($this->reportEmployee('all'), null, $noon)['summary']['expenses_total_amount']));
            $this->assertSame([], $this->readAs($own, $own, $evening)['rows']);
        } finally {
            config(OSPOS::class)->settings['date_or_time_format'] = $before;
        }
    }

    public function testGetData_ShowsNothingToAnOwnRecordsEmployeeOfOneBranch(): void
    {
        $own  = $this->ownRecordsEmployeeWhoSpent($this->reportEmployee('a'))['own'];
        $read = $this->readAs($own, $own);

        $this->assertSame([], $read['rows']);
        $this->assertSame('0.00', $this->amount($read['summary']['expenses_total_amount'] ?? '0'));
    }
}
