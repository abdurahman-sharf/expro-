<?php

namespace Tests\Libraries;

use App\Libraries\Sale_lib;
use App\Libraries\Tax_lib;
use App\Models\Attribute;
use App\Models\Customer;
use App\Models\Dinner_table;
use App\Models\Enums\Rounding_mode;
use App\Models\Item;
use App\Models\Item_kit_items;
use App\Models\Item_quantity;
use App\Models\Item_taxes;
use App\Models\Sale;
use App\Models\Stock_location;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use Config\OSPOS;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\Support\ParityFixture;

/**
 * The line and invoice arithmetic, checked against amounts recorded in the reference system
 * (fixtures T-01 to T-03). Each data set is one input and the output the reference system gave.
 */
class Sale_libTest extends CIUnitTestCase
{
    private const FIXTURES = ['T-01', 'T-02', 'T-03'];

    private Sale_lib $saleLib;

    protected function setUp(): void
    {
        parent::setUp();

        bcscale(4);

        $ospos           = new OSPOS();
        $ospos->settings = ['cash_rounding_code' => '', 'cash_decimals' => 2, 'currency_decimals' => 2, 'tax_decimals' => 2, 'quantity_decimals' => 2, 'tax_included' => '0'];
        Factories::injectMock('config', OSPOS::class, $ospos);

        foreach ([Attribute::class, Customer::class, Dinner_table::class, Item::class, Item_kit_items::class, Item_quantity::class, Item_taxes::class, Sale::class, Stock_location::class] as $model) {
            Factories::injectMock('models', $model, $this->getMockBuilder($model)->disableOriginalConstructor()->getMock());
        }

        session()->destroy();
        $this->saleLib = new Sale_lib();
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function lineProvider(): array
    {
        return self::dataSets('line');
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function totalsProvider(): array
    {
        return self::dataSets('totals');
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    private static function dataSets(string $kind): array
    {
        $sets = [];

        foreach (self::FIXTURES as $fixture) {
            foreach (ParityFixture::cases($fixture, $kind) as $name => [$case]) {
                $sets["{$fixture} {$name}"] = [$fixture, $case];
            }
        }

        return $sets;
    }

    private function discountType(string $name): int
    {
        return $name === 'fixed' ? FIXED : PERCENT;
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('lineProvider')]
    public function testGetItemTotal_MatchesTheLineTheReferenceSystemComputed(string $fixture, array $case): void
    {
        $in = $case['inputs'];

        $total = $this->saleLib->get_item_total($in['quantity'], $in['price'], $in['discount'], $this->discountType($in['discount_type']), true);
        $gross = $this->saleLib->get_item_total($in['quantity'], $in['price'], $in['discount'], $this->discountType($in['discount_type']));

        $this->assertSame($this->fourDecimals($case['expected']['line_total']), $total, $case['id']);
        $this->assertSame($this->fourDecimals($case['expected']['discount_amount']), bcsub($gross, $total), $case['id']);
        $this->assertSame('exact', $case['classification']);
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('totalsProvider')]
    public function testGetTotals_MatchesTheInvoiceTheReferenceSystemComputed(string $fixture, array $case): void
    {
        $taxLib = (new ReflectionClass(Tax_lib::class))->newInstanceWithoutConstructor();
        $cart   = [];
        $tax    = '0';

        foreach ($case['inputs']['lines'] as $index => $line) {
            $type  = $this->discountType($line['discount_type']);
            $total = $this->saleLib->get_item_total($line['quantity'], $line['price'], $line['discount'], $type, true);
            $tax   = bcadd($tax, $taxLib->get_tax_for_amount($total, $case['inputs']['tax_rate'], Rounding_mode::HALF_UP, 2), 2);

            $cart[$index + 1] = [
                'item_id'       => $line['item_id'],
                'line'          => $index + 1,
                'quantity'      => $line['quantity'],
                'price'         => $line['price'],
                'discount'      => $line['discount'],
                'discount_type' => $type,
                'stock_type'    => HAS_STOCK,
            ];
        }

        $this->saleLib->set_cart($cart);
        $totals = $this->saleLib->get_totals(['VAT' => ['tax_type' => Tax_lib::TAX_TYPE_EXCLUDED, 'sale_tax_amount' => $tax]]);

        foreach ($case['expected'] as $key => $expected) {
            $this->assertSame($this->fourDecimals($expected), $this->fourDecimals((string) $totals[$key]), "{$case['id']} {$key}");
        }

        $this->assertSame('exact', $case['classification']);
    }

    private function fourDecimals(string $amount): string
    {
        return bcadd($amount, '0', 4);
    }
}
