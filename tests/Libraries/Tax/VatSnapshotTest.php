<?php

namespace Tests\Libraries\Tax;

use App\Libraries\Tax\VatCategoryTotal;
use App\Libraries\Tax\VatSnapshot;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\ItemFixtureTrait;

class VatSnapshotTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use ItemFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private int $sale = 0;

    /**
     * @var list<int>
     */
    private array $returns = [];

    /**
     * @var list<int>
     */
    private array $items = [];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->sale = $this->saleRow(COMPLETED, SALE_TYPE_POS, null);
    }

    protected function tearDown(): void
    {
        $db = Database::connect();

        $db->table('sales_vat_totals')->whereIn('sale_id', [$this->sale, ...$this->returns])->delete();
        $db->table('sales_items_vat')->whereIn('sale_id', [$this->sale, ...$this->returns])->delete();
        $db->table('sales_items')->whereIn('sale_id', [$this->sale, ...$this->returns])->delete();

        if ($this->returns !== []) {
            $db->table('sales')->whereIn('sale_id', $this->returns)->delete();
        }

        $db->table('sales')->where('sale_id', $this->sale)->delete();

        if ($this->items !== []) {
            $db->table('item_quantities')->whereIn('item_id', $this->items)->delete();
            $db->table('inventory')->whereIn('trans_items', $this->items)->delete();
            $db->table('items')->whereIn('item_id', $this->items)->delete();
        }

        parent::tearDown();
    }

    private function saleRow(int $status, int $type, ?int $returnOf): int
    {
        $db = Database::connect();
        $db->table('sales')->insert(['sale_time' => '2026-11-21 10:00:00', 'customer_id' => null, 'employee_id' => 1, 'sale_status' => $status, 'sale_type' => $type, 'return_of_sale_id' => $returnOf, 'branch_id' => 1]);

        return (int) $db->insertID();
    }

    private function lineOf(int $saleId, int $itemId, int $line, string $quantity, string $category = 'S', string $percent = '15.000'): void
    {
        $db = Database::connect();
        $db->table('sales_items')->insert(['sale_id' => $saleId, 'item_id' => $itemId, 'line' => $line, 'quantity_purchased' => $quantity, 'item_cost_price' => '0', 'item_unit_price' => '1', 'discount' => '0', 'discount_type' => 0, 'item_location' => 1, 'description' => '', 'serialnumber' => '']);
        $db->table('sales_items_vat')->insert(['sale_id' => $saleId, 'item_id' => $itemId, 'line' => $line, 'category' => $category, 'percent' => $percent]);
    }

    private function returnOf(int $status, string $taxable, string $vat): int
    {
        $saleId          = $this->saleRow($status, SALE_TYPE_RETURN, $this->sale);
        $this->returns[] = $saleId;

        (new VatSnapshot())->record($saleId, ['S|15.000|' => new VatCategoryTotal('S', '15.000', null, null, $taxable, $vat, false)]);

        return $saleId;
    }

    public function testTotalsOf_ReadsTheCategoriesASaleWasSavedWith(): void
    {
        (new VatSnapshot())->record($this->sale, ['Z|0.000|VATEX-SA-32' => new VatCategoryTotal('Z', '0.000', 'VATEX-SA-32', 'Export of goods', '40.00', '0.00', false)]);

        $totals = (new VatSnapshot())->totalsOf($this->sale);

        $this->assertSame(['Z|0.000|VATEX-SA-32'], array_keys($totals));
        $this->assertSame(['40.00', '0.00', 'Export of goods'], [$totals['Z|0.000|VATEX-SA-32']->taxable, $totals['Z|0.000|VATEX-SA-32']->vat, $totals['Z|0.000|VATEX-SA-32']->exemptionText]);
        $this->assertSame([], (new VatSnapshot())->totalsOf($this->sale + 100000));
    }

    public function testLeftOf_TakesOffTheCompletedReturnsOfTheSaleOnly(): void
    {
        (new VatSnapshot())->record($this->sale, ['S|15.000|' => new VatCategoryTotal('S', '15.000', null, null, '0.30', '0.05', false)]);
        $this->returnOf(COMPLETED, '-0.10', '-0.02');
        $this->returnOf(CANCELED, '-0.10', '-0.02');

        $this->assertSame(['S|15.000|' => ['taxable' => '0.20', 'vat' => '0.03']], (new VatSnapshot())->leftOf($this->sale));
    }

    public function testLeftOf_GivesNothingWhenAReturnOfTheSaleWasSavedWithoutItsVatWorkedOnce(): void
    {
        (new VatSnapshot())->record($this->sale, ['S|15.000|' => new VatCategoryTotal('S', '15.000', null, null, '0.30', '0.05', false)]);
        $this->returns[] = $this->saleRow(COMPLETED, SALE_TYPE_RETURN, $this->sale);

        $this->assertSame([], (new VatSnapshot())->leftOf($this->sale));
    }

    public function testLeftOf_GivesNothingForASaleSavedWithoutItsVatWorkedOnce(): void
    {
        $this->assertSame([], (new VatSnapshot())->leftOf($this->sale));
    }

    public function testExhaustedBy_NamesTheCategoriesWhoseLastUnitsTheReturnTakesBack(): void
    {
        $first       = $this->createTestItem();
        $second      = $this->createTestItem();
        $this->items = [$first, $second];
        $this->lineOf($this->sale, $first, 1, '3');
        $this->lineOf($this->sale, $second, 2, '2', 'Z', '0.000');
        $this->lineOf($this->returnOf(COMPLETED, '-1.00', '-0.15'), $first, 1, '-1');

        $snapshot = new VatSnapshot();

        $this->assertSame([], $snapshot->exhaustedBy($this->sale, [$first => '1']));
        $this->assertSame(['S|15.000|'], $snapshot->exhaustedBy($this->sale, [$first => '2']));
        $this->assertSame(['Z|0.000|'], $snapshot->exhaustedBy($this->sale, [$second => '2']));
    }
}
