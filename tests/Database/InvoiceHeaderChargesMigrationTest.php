<?php

namespace Tests\Database;

use App\Database\Migrations\InvoiceHeaderCharges;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

class InvoiceHeaderChargesMigrationTest extends CIUnitTestCase
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

    private const COLUMNS = ['adjustment_label', 'adjustment_amount', 'shipping_amount', 'shipping_options', 'shipping_tax_option', 'shipping_tax_percent', 'shipping_tax_amount'];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
    }

    protected function tearDown(): void
    {
        (new InvoiceHeaderCharges())->up();

        $this->stopSelling();
        $this->reinstallChart();

        parent::tearDown();
    }

    private function column(string $name): ?object
    {
        return $this->accountingDb()->query('SELECT IS_NULLABLE, COLUMN_DEFAULT, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$this->accountingDb()->prefixTable('sales'), $name])->getRow();
    }

    private function routing(string $key): ?object
    {
        return $this->accountingDb()->table('accounting_routing')->where('routing_key', $key)->get()->getRow();
    }

    private function accountOf(string $systemKey): ?object
    {
        return $this->accountingDb()->table('accounting_accounts')->where('system_key', $systemKey)->get()->getRow();
    }

    /**
     * The chart as it was before: no accounts of adjustments and shipping, the adjustment row cancelled and
     * pointing at the account of discounts allowed, and no row for shipping.
     */
    private function chartBefore(): void
    {
        (new InvoiceHeaderCharges())->down();

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.shipping')->delete();
    }

    public function testUp_AddsTheColumnsOfTheHeaderToSalesAsNullableWithNoDefault(): void
    {
        foreach (self::COLUMNS as $name) {
            $column = $this->column($name);

            $this->assertNotNull($column, $name);
            $this->assertSame('YES', $column->IS_NULLABLE, $name);
            $this->assertContains($column->COLUMN_DEFAULT, [null, 'NULL'], $name);
        }
    }

    public function testUp_LeavesEverySaleThatExistsWithoutChargesAndRunsAgainWithoutHarm(): void
    {
        $this->startSelling();

        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes']);
        $before = $this->accountingDb()->table('sales')->where('sale_id', $saleId)->get()->getRowArray();

        (new InvoiceHeaderCharges())->up();
        (new InvoiceHeaderCharges())->up();

        $this->assertSame($before, $this->accountingDb()->table('sales')->where('sale_id', $saleId)->get()->getRowArray());

        foreach (self::COLUMNS as $name) {
            $this->assertNull($before[$name], $name);
        }
    }

    public function testUp_OpensTheAccountsOfAdjustmentsAndShippingUnderOtherRevenueOfAnExistingChart(): void
    {
        $this->chartBefore();

        $this->accountingDb()->table('accounting_accounts')->whereIn('system_key', ['sales_adjustment', 'sales_shipping'])->update(['system_key' => null]);
        $this->accountingDb()->table('accounting_accounts')->whereIn('code', ['4204', '4208'])->delete();

        (new InvoiceHeaderCharges())->up();

        foreach (['sales_adjustment' => ['4204', 'sales.adjustment'], 'sales_shipping' => ['4208', 'sales.shipping']] as $systemKey => [$code, $routing]) {
            $account = $this->accountOf($systemKey);

            $this->assertNotNull($account, $systemKey);
            $this->assertSame($code, $account->code);
            $this->assertSame('revenue', $account->account_type);
            $this->assertSame('credit', $account->normal_balance);
            $this->assertSame('0', (string) $account->is_group);
            $this->assertSame('auto', $this->routing($routing)->routing_type);
            $this->assertSame((string) $account->account_id, (string) $this->routing($routing)->account_id);
        }
    }

    public function testUp_MovesTheAdjustmentRowOffDiscountsAllowedWhenNobodyChangedIt(): void
    {
        $this->chartBefore();

        $discount = (int) $this->accountOf('sales_discount')->account_id;

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.adjustment')->update(['routing_type' => 'cancelled', 'account_id' => $discount]);

        (new InvoiceHeaderCharges())->up();

        $row = $this->routing('sales.adjustment');

        $this->assertSame('auto', $row->routing_type);
        $this->assertSame((string) $this->accountOf('sales_adjustment')->account_id, (string) $row->account_id);
    }

    public function testUp_LeavesAnAdjustmentRowSomeoneChangedAsItWas(): void
    {
        $this->chartBefore();

        $custom = $this->accountIdByCode('4201');

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.adjustment')->update(['routing_type' => 'cancelled', 'account_id' => $custom]);

        (new InvoiceHeaderCharges())->up();

        $this->assertSame((string) $custom, (string) $this->routing('sales.adjustment')->account_id);
        $this->assertSame('cancelled', $this->routing('sales.adjustment')->routing_type);

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.adjustment')->update(['routing_type' => 'auto', 'account_id' => $this->accountOf('sales_adjustment')->account_id]);

        (new InvoiceHeaderCharges())->up();

        $this->assertSame('auto', $this->routing('sales.adjustment')->routing_type);
    }

    public function testUp_TakesTheNextFreeCodeWhenTheFirstOneBelongsToAnotherAccount(): void
    {
        $this->chartBefore();

        $this->accountingDb()->table('accounting_accounts')->where('system_key', 'sales_shipping')->update(['system_key' => null]);
        $this->accountingDb()->table('accounting_accounts')->where('code', '4208')->update(['code' => '4208-old']);

        (new InvoiceHeaderCharges())->up();

        $this->assertSame('4208', $this->accountOf('sales_shipping')->code, 'the first code is free again after the old one was renamed');

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.shipping')->delete();
        $this->accountingDb()->table('accounting_accounts')->where('system_key', 'sales_shipping')->update(['system_key' => null, 'code' => '4208-kept']);
        $this->accountingDb()->table('accounting_accounts')->insert(['parent_id' => $this->accountIdByCode('42'), 'code' => '4208', 'name' => 'Taken', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'is_group' => 0, 'is_system' => 0, 'active' => 1]);

        (new InvoiceHeaderCharges())->up();

        $this->assertSame('4212', $this->accountOf('sales_shipping')->code);
    }

    public function testUp_ChangesNothingInAnExistingRoutingRowOfShippingThatAlreadyHasAnAccount(): void
    {
        $custom = $this->accountIdByCode('4201');

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.shipping')->update(['account_id' => $custom]);

        (new InvoiceHeaderCharges())->up();

        $this->assertSame((string) $custom, (string) $this->routing('sales.shipping')->account_id);
    }

    public function testUp_DoesNothingToTheChartWhenThereIsNoChartYet(): void
    {
        $db = $this->accountingDb();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->table('accounting_routing')->truncate();
        $db->table('accounting_accounts')->truncate();
        $db->query('SET FOREIGN_KEY_CHECKS = 1');

        (new InvoiceHeaderCharges())->up();

        $this->assertSame(0, $this->countRows('accounting_accounts'));
        $this->assertSame(0, $this->countRows('accounting_routing'));
    }

    public function testDown_RemovesTheColumnsAndTheRowOfShippingAndGivesTheAdjustmentRowBackToDiscounts(): void
    {
        (new InvoiceHeaderCharges())->down();

        foreach (self::COLUMNS as $name) {
            $this->assertNull($this->column($name), $name);
        }

        $this->assertNull($this->routing('sales.shipping'));
        $this->assertSame('cancelled', $this->routing('sales.adjustment')->routing_type);
        $this->assertSame((string) $this->accountOf('sales_discount')->account_id, (string) $this->routing('sales.adjustment')->account_id);
        $this->assertNull($this->accountOf('sales_shipping'));
    }
}
