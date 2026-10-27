<?php

namespace Tests\Models\Reports;

use App\Models\Reports\Summary_payments;
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
 * What was paid, and how, is counted from the sales of the branches the employee works in.
 */
class Summary_paymentsTest extends CIUnitTestCase
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

    /**
     * @return list<string>
     */
    private function paidBy(string $scope): array
    {
        $this->readingAs($scope === 'system' ? null : $this->reportEmployee($scope));

        $rows = (new Summary_payments())->getData($this->saleInputs());
        $paid = array_filter($rows, static fn (array $row): bool => $row['trans_group'] === lang('Reports.trans_payments'));

        return array_values(array_unique(array_filter(array_column($paid, 'trans_type'), static fn (mixed $type): bool => $type !== null)));
    }

    private function counted(string $scope): int
    {
        $this->readingAs($scope === 'system' ? null : $this->reportEmployee($scope));

        $rows = array_filter((new Summary_payments())->getData($this->saleInputs()), static fn (array $row): bool => $row['trans_group'] === lang('Reports.trans_sales'));

        return (int) array_sum(array_column($rows, 'trans_sales'));
    }

    public function testGetData_ShowsOnlyTheWaysOfPayingOfTheBranchesTheEmployeeWorksIn(): void
    {
        $this->assertSame([lang('Sales.cash')], $this->paidBy('a'));
        $this->assertSame([lang('Sales.debit')], $this->paidBy('b'));
    }

    public function testGetData_ShowsEveryBranchToAnEmployeeOfTheTwoAndOfEveryBranchAndToTheSystem(): void
    {
        foreach (['both', 'all', 'system'] as $scope) {
            $seen = $this->paidBy($scope);

            sort($seen);

            $expected = [lang('Sales.cash'), lang('Sales.debit')];
            sort($expected);

            $this->assertSame($expected, $seen, $scope);
        }
    }

    public function testGetData_CountsOnlyTheSalesOfTheBranchesTheEmployeeWorksIn(): void
    {
        $this->assertSame(2, $this->counted('a'));
        $this->assertSame(1, $this->counted('b'));
        $this->assertSame(3, $this->counted('all'));
        $this->assertSame(0, $this->counted('none'));
    }
}
