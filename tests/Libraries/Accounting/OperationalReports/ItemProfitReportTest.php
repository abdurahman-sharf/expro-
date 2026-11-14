<?php

namespace Tests\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\OperationalReports\ItemGroup;
use App\Libraries\Accounting\OperationalReports\ItemProfitReport;
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
 * The profit of each item with the three percentages: the share of the profit of all, the ratio to the net
 * sales and the ratio to the net cost, a hundred in the total, and the cost against the cost of sales of
 * the books.
 */
class ItemProfitReportTest extends CIUnitTestCase
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

    private int $itemA;
    private int $itemB;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startSelling();

        $this->itemA = $this->item('40');
        $this->itemB = $this->item('20');
        $this->accountingDb()->table('items')->where('item_id', $this->itemA)->update(['name' => 'Alpha cream', 'category' => 'Creams']);
        $this->accountingDb()->table('items')->where('item_id', $this->itemB)->update(['name' => 'Beta oil', 'category' => 'Oils']);

        $sale = $this->save([$this->line($this->itemA, 0, '10', '100', '40'), $this->line($this->itemB, 1, '5', '50', '20')], [], [], SALE_TYPE_INVOICE, $this->customerId);
        $this->save([$this->line($this->itemA, 0, '-2', '100', '40')], [], [], SALE_TYPE_RETURN, $this->customerId, true, $sale);
    }

    protected function tearDown(): void
    {
        $this->stopSelling();

        parent::tearDown();
    }

    private function table(ItemGroup $group = ItemGroup::Item, ?int $itemId = null): ReportTable
    {
        return (new ItemProfitReport())->build(ReportScope::resolve(null, null), $group, '2020-01-01', date('Y-m-d'), null, null, $itemId);
    }

    /**
     * @return array<string, string>
     */
    private function rowNamed(ReportTable $table, string $name): array
    {
        foreach ($table->blocks[0]->rows as $row) {
            if (in_array($name, $row, true)) {
                return $row;
            }
        }

        $this->fail("No row for {$name}.");
    }

    public function testBuild_SplitsSalesReturnsAndNetByItem(): void
    {
        $row = $this->rowNamed($this->table(), 'Alpha cream');

        $this->assertEquals(['sales_quantity' => '10.0000', 'sales_value' => '1000.0000', 'returns_quantity' => '2.0000', 'returns_value' => '200.0000', 'net_quantity' => '8.0000', 'net_value' => '800.0000', 'cost' => '320.0000', 'profit' => '480.0000'], array_intersect_key($row, array_flip(['sales_quantity', 'sales_value', 'returns_quantity', 'returns_value', 'net_quantity', 'net_value', 'cost', 'profit'])));
    }

    public function testBuild_RatiosFollowTheDefinition(): void
    {
        $table = $this->table();
        $alpha = $this->rowNamed($table, 'Alpha cream');
        $beta  = $this->rowNamed($table, 'Beta oil');

        $this->assertSame(['76.19', '60.00', '150.00'], [$alpha['profit_share'], $alpha['profit_to_sales'], $alpha['profit_to_cost']]);
        $this->assertSame(['23.81', '60.00', '150.00'], [$beta['profit_share'], $beta['profit_to_sales'], $beta['profit_to_cost']]);
    }

    public function testBuild_ShowsAHundredPercentOfTheShareInTheTotalAndNotTheAverageOfTheRows(): void
    {
        $total = $this->table()->total;

        $this->assertSame('100.00', $total['profit_share']);
        $this->assertSame('630.0000', $total['profit']);
        $this->assertSame('60.00', $total['profit_to_sales']);
        $this->assertSame('150.00', $total['profit_to_cost']);
    }

    public function testBuild_TiesTheCostToTheCostOfSalesOfTheBooks(): void
    {
        $table = $this->table();

        $this->assertSame('420.0000', $table->total['cost']);
        $this->assertSame($this->balance($this->accountId('cogs')), bcadd($table->total['cost'], '0', 2));
        $this->assertTrue($table->ties());
    }

    public function testBuild_GroupsByCategoryAndLimitsToAnItem(): void
    {
        $categories = $this->table(ItemGroup::Category);

        $this->assertSame(['Creams', 'Oils'], array_column($categories->blocks[0]->rows, 'category'));
        $this->assertSame('150.0000', $this->table(ItemGroup::Item, $this->itemB)->total['profit']);
        $this->assertSame([], $this->table(ItemGroup::Item, $this->itemB)->ties);
    }

    public function testBuild_ShowsAZeroShareWhenTheTotalProfitIsNothing(): void
    {
        $table = (new ItemProfitReport())->build(ReportScope::resolve(null, null), ItemGroup::Item, '2001-01-01', '2001-01-31');

        $this->assertSame([], $table->blocks[0]->rows);
        $this->assertSame('0.00', $table->total['profit_share']);
        $this->assertSame('0.00', $table->total['profit_to_cost']);
    }

    private function untilTomorrow(ItemGroup $group = ItemGroup::Item, ?int $customerId = null): ReportTable
    {
        return (new ItemProfitReport())->build(ReportScope::resolve(null, null), $group, '2020-01-01', date('Y-m-d', strtotime('+1 day')), $customerId);
    }

    public function testBuild_RefusesByNameWhenAnInvoiceInDollarsHasGoodsThatMoved(): void
    {
        $this->enableDollars();
        $invoice = $this->foreignInvoice('100', [], '40.00', '2');

        $this->assertGreaterThan(0, $invoice);

        foreach ([ItemGroup::Item, ItemGroup::Category, ItemGroup::Customer, ItemGroup::User] as $group) {
            $table = $this->untilTomorrow($group);

            $this->assertTrue($table->isRefused(), $group->value);
            $this->assertSame('Accounting_operational_reports.profit_foreign_docs', $table->refusal);
            $this->assertSame(['POS ' . $invoice . ' (USD)'], $table->refusalArguments);
            $this->assertSame([], $table->blocks);
            $this->assertSame([], $table->ties);
        }
    }

    public function testBuild_ReportsTheSameWhenNoDocumentIsInAForeignCurrency(): void
    {
        $this->enableDollars();

        $table = $this->untilTomorrow();

        $this->assertFalse($table->isRefused());
        $this->assertTrue($table->ties());
        $this->assertSame(['1050.0000', '420.0000', '630.0000'], [$table->total['net_value'], $table->total['cost'], $table->total['profit']]);
    }

    public function testBuild_DoesNotRefuseWhenTheDocumentInDollarsIsOfServicesThatNeverMove(): void
    {
        $this->enableDollars();
        $this->assertGreaterThan(0, $this->foreignInvoice('100'));

        $this->assertFalse($this->untilTomorrow()->isRefused());
    }

    public function testBuild_ReportsTheCustomersWhoseDocumentsAreAllInTheBaseWhenAnotherCustomerHasOneInDollars(): void
    {
        $this->enableDollars();
        $other = $this->anotherCustomer('Hind', 'Saad');
        $this->grantInvoiceDate();

        $this->sales->useCurrency('USD');
        $this->assertGreaterThan(0, $this->save([$this->line($this->itemA, 0, '1', '100', '40')], [], [], SALE_TYPE_INVOICE, $other, true, null, COMPLETED, NEW_ENTRY, null, invoiceDate: $this->daysAgo(10)));

        $this->assertFalse($this->untilTomorrow(ItemGroup::Item, $this->customerId)->isRefused());
        $this->assertTrue($this->untilTomorrow(ItemGroup::Item, $other)->isRefused());
    }
}
