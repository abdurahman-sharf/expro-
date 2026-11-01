<?php

namespace Tests\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\OperationalReports\ItemGroup;
use App\Libraries\Accounting\OperationalReports\ProfitReport;
use App\Libraries\Accounting\OperationalReports\ReportTable;
use App\Libraries\Accounting\ReportScope;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The profit of the movements of stock the books valued: the price less the discount less the cost, the
 * day of the movement, the return at the cost of the sale it takes back, and the cost against the journals of
 * the cost of sales and the account 511.
 */
class ProfitReportTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;

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

        $this->startSelling();
    }

    protected function tearDown(): void
    {
        $this->stopSelling();

        parent::tearDown();
    }

    private function table(ItemGroup $group = ItemGroup::Item, bool $details = true, ?string $from = '2020-01-01', ?string $to = null, ?int $customerId = null, ?int $employeeId = null): ReportTable
    {
        return (new ProfitReport())->build(ReportScope::resolve(null, null), $group, $details, $from, $to ?? date('Y-m-d'), $customerId, $employeeId);
    }

    /**
     * @return list<array<string, string>>
     */
    private function rows(ReportTable $table): array
    {
        return array_merge([], ...array_map(static fn ($block): array => $block->rows, $table->blocks));
    }

    /**
     * The reference invoice of 4 creams at 75 with 10% off, costing 40 each, and 2 perfumes at 220 costing 120 each.
     */
    public function testBuild_ProfitIsThePriceLessTheDiscountLessTheCost(): void
    {
        $cycle = $this->referenceInvoice();
        $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, $this->customerId);

        $rows = array_column($this->rows($this->table()), null, 'quantity');

        $this->assertEquals(['sale_price' => '300.0000', 'discount' => '30.0000', 'cost' => '160.0000', 'profit' => '110.0000'], array_intersect_key($rows['4.0000'], array_flip(['sale_price', 'discount', 'cost', 'profit'])));
        $this->assertEquals(['sale_price' => '440.0000', 'discount' => '0.0000', 'cost' => '240.0000', 'profit' => '200.0000'], array_intersect_key($rows['2.0000'], array_flip(['sale_price', 'discount', 'cost', 'profit'])));
        $this->assertSame('310.0000', $this->table()->total['profit']);
    }

    public function testBuild_ShowsTheWholePriceAsProfitForGoodsThatCostNothing(): void
    {
        $free = $this->item('0');
        $this->save([$this->line($free, 0, '3', '10', '0')], [], [], SALE_TYPE_POS, $this->customerId);

        $table = $this->table();

        $this->assertSame('30.0000', $table->total['profit']);
        $this->assertSame('0.0000', $table->total['cost']);
    }

    public function testBuild_AllowsALossWhenGoodsSellBelowTheirCost(): void
    {
        $item = $this->item('40');
        $this->save([$this->line($item, 0, '1', '0.10', '40')], [], [], SALE_TYPE_POS, $this->customerId);

        $this->assertSame('-39.9000', $this->table()->total['profit']);
    }

    public function testBuild_LeavesAServiceOutBecauseItNeverMoves(): void
    {
        $service = $this->item('0', false);
        $goods   = $this->item('5');
        $this->save([$this->line($service, 0, '1', '100', '0'), $this->line($goods, 1, '2', '20', '5')], [], [], SALE_TYPE_POS, $this->customerId);

        $table = $this->table();

        $this->assertSame(1, $table->documents);
        $this->assertSame('30.0000', $table->total['profit']);
    }

    public function testBuild_ValuesAReturnAtTheOriginalSaleCost(): void
    {
        $item = $this->item('60');
        $sale = $this->save([$this->line($item, 0, '5', '100', '60')], [], [], SALE_TYPE_INVOICE, $this->customerId);

        $this->accountingDb()->table('items')->where('item_id', $item)->update(['cost_price' => '65.70']);
        $this->save([$this->line($item, 0, '-1', '100', '65.70')], [], [], SALE_TYPE_RETURN, $this->customerId, true, $sale);

        $table = $this->table();
        $rows  = array_column($this->rows($table), null, 'quantity');

        $this->assertEquals(['cost' => '-60.0000', 'sale_price' => '-100.0000', 'profit' => '-40.0000'], array_intersect_key($rows['-1.0000'], array_flip(['cost', 'sale_price', 'profit'])));
        $this->assertSame('240.0000', $table->total['cost']);
        $this->assertSame('160.0000', $table->total['profit']);
    }

    public function testBuild_CostEqualsTheAccountOfTheCostOfSalesForThePeriod(): void
    {
        $cycle = $this->referenceInvoice();
        $sale  = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, $this->customerId);
        $this->save([$this->line($cycle['cream'], 0, '-1', '75', '99')], [], [], SALE_TYPE_RETURN, $this->customerId, true, $sale);

        $table = $this->table();

        $this->assertSame($this->balance($this->accountId('cogs')), bcadd($table->total['cost'], '0', 2));
        $this->assertSame('360.0000', $table->total['cost']);
        $this->assertTrue($table->ties());
        $this->assertSame($table->total['cost'], $table->ties[0]->ledger);
    }

    public function testBuild_DatesARowByTheMovementAndLeavesOutGoodsThatHaveNotLeft(): void
    {
        $this->grantInvoiceDate();

        $goods = $this->item('10');
        $day   = date('Y-m-d', strtotime('-3 days'));
        $sale  = $this->save([$this->line($goods, 0, '2', '30', '10')], [], [], SALE_TYPE_INVOICE, $this->customerId, false, null, COMPLETED, NEW_ENTRY, null, invoiceDate: $day);

        $this->assertSame(0, $this->table()->documents);
        $this->assertGreaterThan(0, $this->sales->confirm_delivery($sale, $this->employeeId));

        $rows = $this->rows($this->table(ItemGroup::Day, true, $day, $day));
        $cost = $this->accountingDb()->table('accounting_journals')->select('journal_date')->where(['source_type' => 'sale', 'source_id' => $sale, 'entry_type' => 'cogs', 'status' => 'posted'])->get()->getRow();

        $this->assertCount(1, $rows);
        $this->assertSame($cost->journal_date, $rows[0]['date'] ?? $day);
        $this->assertSame('40.0000', $this->table()->total['profit']);
    }

    public function testBuild_GroupsByDayWithEmptyDaysAndByUser(): void
    {
        $item = $this->item('10');
        $this->save([$this->line($item, 0, '1', '25', '10')], [], [], SALE_TYPE_POS, $this->customerId);

        $today = date('Y-m-d');
        $from  = date('Y-m-d', strtotime('-1 day'));
        $days  = $this->table(ItemGroup::Day, false, $from, $today);
        $users = $this->table(ItemGroup::User, false);

        $this->assertSame([$from, $today], array_column($days->blocks[0]->rows, 'period'));
        $this->assertSame(['0.0000', '15.0000'], array_column($days->blocks[0]->rows, 'profit'));
        $this->assertCount(1, $users->blocks[0]->rows);
        $this->assertSame('15.0000', $users->blocks[0]->rows[0]['profit']);
        $this->assertSame('15.0000', $this->table(ItemGroup::User, false, employeeId: $this->employeeId)->total['profit']);
        $this->assertSame('0.0000', $this->table(ItemGroup::User, false, employeeId: $this->employeeId + 1000)->total['profit']);
    }

    public function testBuild_ReadsNothingForAScopeOfNoBranch(): void
    {
        $item = $this->item('10');
        $this->save([$this->line($item, 0, '1', '25', '10')], [], [], SALE_TYPE_POS, $this->customerId);

        $table = (new ProfitReport())->build(ReportScope::resolve(null, []), ItemGroup::Item, false, '2020-01-01', date('Y-m-d'));

        $this->assertTrue($table->isEmpty());
        $this->assertSame('0.0000', $table->total['profit']);
    }
    public function testBuild_ReadsOnlyTheBranchesOfTheScopeAndTiesTheCostOfThem(): void
    {
        $second = $this->createBranch('PFB', 'Second profit branch');
        $first  = $this->item('10');
        $other  = $this->item('20');

        $this->save([$this->line($first, 0, '1', '30', '10')], [], [], SALE_TYPE_POS, $this->customerId);
        $store = $this->branchLocation($second);

        $this->save([$this->line($other, 0, '1', '50', '20', '0', PERCENT, $store)], [], [], SALE_TYPE_POS, $this->customerId);

        $report = new ProfitReport();
        $of     = static fn (ReportScope $scope): ReportTable => $report->build($scope, ItemGroup::Item, false, '2020-01-01', date('Y-m-d'));

        $this->assertSame('50.0000', $of(ReportScope::resolve(null, null))->total['profit']);
        $this->assertSame('20.0000', $of(ReportScope::resolve([1], null))->total['profit']);
        $this->assertSame('30.0000', $of(ReportScope::resolve(null, [$second]))->total['profit']);
        $this->assertTrue($of(ReportScope::resolve([1], null))->ties());
        $this->assertSame('10.0000', $of(ReportScope::resolve([1], null))->ties[0]->ledger);

        $this->accountingDb()->table('item_quantities')->where('location_id', $store)->delete();
        $this->accountingDb()->table('inventory')->where('trans_location', $store)->delete();
    }

    public function testBuild_LeavesOutTheMovementsOfASaleThatWasCancelled(): void
    {
        $item = $this->item('10');
        $kept = $this->save([$this->line($item, 0, '1', '30', '10')], [], [], SALE_TYPE_POS, $this->customerId);
        $gone = $this->save([$this->line($item, 0, '2', '30', '10')], [], [], SALE_TYPE_POS, $this->customerId);

        $this->assertSame('30.0000', $this->table()->total['cost']);
        $this->assertTrue($this->sales->delete($gone, false, true, $this->employeeId));

        $table = $this->table();

        $this->assertSame('10.0000', $table->total['cost']);
        $this->assertSame('20.0000', $table->total['profit']);
        $this->assertTrue($table->ties());
        $this->assertGreaterThan(0, $kept);
    }

    public function testBuild_CountsOnlyTheUnitsThatMovedWhenSomeOfThemCameBackBeforeTheDelivery(): void
    {
        $item    = $this->item('10');
        $invoice = $this->save([$this->line($item, 0, '2', '30', '10')], [], [], SALE_TYPE_INVOICE, $this->customerId, false);
        $this->save([$this->line($item, 0, '-1', '30', '10')], [], [], SALE_TYPE_RETURN, $this->customerId, true, $invoice);

        $this->assertGreaterThan(0, $this->sales->confirm_delivery($invoice, $this->employeeId));

        $table = $this->table();
        $rows  = $this->rows($table);

        $this->assertCount(1, $rows);
        $this->assertSame(['1.0000', '10.0000', '30.0000', '20.0000'], [$rows[0]['quantity'], $rows[0]['cost'], $rows[0]['sale_price'], $rows[0]['profit']]);
        $this->assertTrue($table->ties());
    }
}
