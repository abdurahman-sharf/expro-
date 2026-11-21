<?php

namespace Tests\Libraries\Tax;

use App\Libraries\Tax\VatCategoryTotal;
use App\Libraries\Tax\VatSnapshot;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

class VatSnapshotTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private int $sale = 0;

    /**
     * @var list<int>
     */
    private array $returns = [];

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

        if ($this->returns !== []) {
            $db->table('sales')->whereIn('sale_id', $this->returns)->delete();
        }

        $db->table('sales')->where('sale_id', $this->sale)->delete();

        parent::tearDown();
    }

    private function saleRow(int $status, int $type, ?int $returnOf): int
    {
        $db = Database::connect();
        $db->table('sales')->insert(['sale_time' => '2026-11-21 10:00:00', 'customer_id' => null, 'employee_id' => 1, 'sale_status' => $status, 'sale_type' => $type, 'return_of_sale_id' => $returnOf, 'branch_id' => 1]);

        return (int) $db->insertID();
    }

    private function returnOf(int $status, string $taxable, string $vat): void
    {
        $saleId          = $this->saleRow($status, SALE_TYPE_RETURN, $this->sale);
        $this->returns[] = $saleId;

        (new VatSnapshot())->record($saleId, ['S|15.000|' => new VatCategoryTotal('S', '15.000', null, null, $taxable, $vat, false)]);
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

    public function testLeftOf_GivesNothingForASaleSavedWithoutItsVatWorkedOnce(): void
    {
        $this->assertSame([], (new VatSnapshot())->leftOf($this->sale));
    }
}
