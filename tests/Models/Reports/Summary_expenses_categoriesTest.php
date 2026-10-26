<?php

namespace Tests\Models\Reports;

use App\Models\Reports\Summary_expenses_categories;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
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

    protected function spend(): void
    {
        $db = $this->accountingDb();

        $db->table('expense_categories')->insert(['category_name' => 'ReportExpenses', 'category_description' => 'x', 'deleted' => 0]);
        $categoryId = (int) $db->insertID();

        $db->table('expenses')->insert(['date' => $this->reportDay . ' 12:00:00', 'amount' => '100.00', 'payment_type' => 'Cash', 'expense_category_id' => $categoryId, 'description' => 'report expense', 'employee_id' => $this->employeeId, 'deleted' => 0, 'tax_amount' => '15.00']);
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
}
