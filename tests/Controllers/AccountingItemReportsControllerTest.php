<?php

namespace Tests\Controllers;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\OperationalReportFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The screen of what was sold: each grouping under the permission of what it groups by, nothing shown until
 * the report is run, the branches of the reader, and the file.
 */
class AccountingItemReportsControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use OperationalReportFixtureTrait;
    use SaleAccountingFixtureTrait;

    private const SCREEN = '/accounting_reports/item_sales';

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

    private function sell(): void
    {
        $cream = $this->item('40');
        $this->accountingDb()->table('items')->where('item_id', $cream)->update(['name' => 'Cream', 'category' => 'Creams']);
        $this->save([$this->line($cream, 0, '4', '75', '40', '10')], [$this->tax($cream, 0, '40.50')], [], SALE_TYPE_INVOICE, $this->customerId);
    }

    public function testGetIndex_ShowsNoTableUntilTheReportIsRun(): void
    {
        $this->reader();

        $response = $this->fetch(self::SCREEN);

        $response->assertStatus(200);
        $response->assertSee('id="report_not_run"');
        $response->assertDontSee('id="report_table"');
    }

    public function testGetIndex_ShowsTheItemsSoldWithTheirTotals(): void
    {
        $this->sell();
        $this->reader();

        $response = $this->fetch(self::SCREEN . '?show=1&group=item');

        $this->assertSame($this->shown('310.50'), $this->grandTotal($response, 'total'));
        $this->assertSame(['Cream'], $this->column($response, 'item'));
        $this->assertCount(1, $this->tieRows($response));
    }

    public function testGetIndex_ReadsEachGroupingUnderThePermissionOfWhatItGroupsBy(): void
    {
        $this->reader(['accounting_reports', 'reports_items']);

        $this->fetch(self::SCREEN . '?show=1&group=item')->assertStatus(200);
        $this->fetch(self::SCREEN . '?show=1&group=day')->assertStatus(200);
        $this->fetch(self::SCREEN . '?show=1&group=category')->assertRedirect();
        $this->fetch(self::SCREEN . '?show=1&group=customer')->assertRedirect();
        $this->fetch(self::SCREEN . '?show=1&group=user')->assertRedirect();

        $this->reader(['accounting_reports', 'reports_categories']);

        $this->fetch(self::SCREEN . '?show=1&group=category')->assertStatus(200);
        $this->fetch(self::SCREEN . '?show=1&group=item')->assertRedirect();
        $this->fetch(self::SCREEN . '/csv?group=item')->assertRedirect();
    }

    public function testGetIndex_RedirectsSomeoneWithoutTheModuleAndIsNotFoundWhileAccountingIsOff(): void
    {
        $this->reader(['reports_items']);

        $this->fetch(self::SCREEN)->assertRedirect();

        $this->reader();
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->fetch(self::SCREEN);
    }

    public function testGetIndex_AddsUpOnlyTheBranchesOfTheReader(): void
    {
        $second = $this->createBranch('IB2', 'Second item branch');
        $this->invoiceInBranch(1, '100', [], $this->customerId);
        $this->invoiceInBranch($second, '70', [], $this->customerId);

        $this->reader($this->everyReportPermission(), [1]);

        $this->assertSame($this->shown('100'), $this->grandTotal($this->fetch(self::SCREEN . '?show=1&group=item'), 'total'));

        $this->reader($this->everyReportPermission(), [$second]);

        $this->assertSame($this->shown('70'), $this->grandTotal($this->fetch(self::SCREEN . '?show=1&group=item'), 'total'));
    }

    public function testGetIndex_LimitsToACategoryAndShowsNoCheckWhileLimited(): void
    {
        $this->sell();
        $this->reader();

        $creams = $this->fetch(self::SCREEN . '?show=1&group=item&category=Creams');
        $oils   = $this->fetch(self::SCREEN . '?show=1&group=item&category=Oils');

        $this->assertSame($this->shown('310.50'), $this->grandTotal($creams, 'total'));
        $this->assertSame($this->shown('0'), $this->grandTotal($oils, 'total'));
        $this->assertSame([], $this->tieRows($creams));
    }

    public function testGetCsv_GivesTheLinesWithTheirTotalsAndNeutralisesAFormula(): void
    {
        $item = $this->item('1');
        $this->accountingDb()->table('items')->where('item_id', $item)->update(['name' => '=cmd|calc', 'category' => 'Odd']);
        $this->save([$this->line($item, 0, '2', '10', '1')], [], [], SALE_TYPE_POS, $this->customerId);
        $this->reader();

        $this->fetch(self::SCREEN . '/csv?from=nonsense')->assertStatus(422);

        $response = $this->fetch(self::SCREEN . '/csv?group=item&view=details');
        $body     = $response->response()->getBody();

        $this->assertStringContainsString("'=cmd|calc", $body);
        $this->assertStringNotContainsString(',=cmd|calc', $body);
        $this->assertContains('20.0000', array_merge(...$this->csvRows($response)));
    }
}
