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
use CodeIgniter\Database\ResultInterface;
use CodeIgniter\Test\CIUnitTestCase;
use Config\OSPOS;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\Support\ParityFixture;

/**
 * The line and invoice arithmetic, checked against amounts recorded in the reference system
 * (fixtures T-01 to T-03), where each data set is one input and the output the reference system
 * gave, and what the register remembers about the delivery and the origin of the sale it holds.
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

    public function testIsDelivered_IsTrueUntilTheCashierSaysTheGoodsStay(): void
    {
        $this->assertTrue($this->saleLib->is_delivered());

        $this->saleLib->set_delivered(false);

        $this->assertFalse($this->saleLib->is_delivered());

        $this->saleLib->clear_delivered();

        $this->assertTrue($this->saleLib->is_delivered());
    }

    public function testGetReturnOrigin_HoldsTheSaleAReturnComesFromUntilItIsCleared(): void
    {
        $this->assertNull($this->saleLib->get_return_origin());

        $this->saleLib->set_return_origin(42);

        $this->assertSame(42, $this->saleLib->get_return_origin());

        $this->saleLib->set_return_origin(null);

        $this->assertNull($this->saleLib->get_return_origin());

        $this->saleLib->set_return_origin(7);
        $this->saleLib->clear_return_origin();

        $this->assertNull($this->saleLib->get_return_origin());
    }

    public function testClearAll_ForgetsTheDeliveryAndTheOriginOfTheSaleBeingMade(): void
    {
        $this->saleLib->set_delivered(false);
        $this->saleLib->set_return_origin(42);
        $this->saleLib->clear_all();

        $this->assertTrue($this->saleLib->is_delivered());
        $this->assertNull($this->saleLib->get_return_origin());
    }

    public function testCopyEntireSale_TakesOverWhetherTheGoodsLeftAndWhichSaleAReturnComesFrom(): void
    {
        $empty = $this->createStub(ResultInterface::class);
        $empty->method('getResult')->willReturn([]);

        $sale = $this->createStub(Sale::class);
        $sale->method('get_sale_items_ordered')->willReturn($empty);
        $sale->method('get_sale_payments')->willReturn($empty);
        $sale->method('get_customer')->willReturn((object) ['person_id' => 3]);
        $sale->method('get_employee')->willReturn((object) ['person_id' => 1]);
        $sale->method('get_quote_number')->willReturn(null);
        $sale->method('get_work_order_number')->willReturn(null);
        $sale->method('get_sale_type')->willReturn(SALE_TYPE_RETURN);
        $sale->method('get_comment')->willReturn('');
        $sale->method('get_dinner_table')->willReturn(null);
        $sale->method('isDelivered')->willReturn(false);
        $sale->method('getReturnOrigin')->willReturn(42);
        Factories::injectMock('models', Sale::class, $sale);

        $saleLib = new Sale_lib();
        $saleLib->copy_entire_sale(9);

        $this->assertFalse($saleLib->is_delivered());
        $this->assertSame(42, $saleLib->get_return_origin());
    }

    public function testReturnEntireSale_NamesTheSaleAsTheOriginAndTakesBackOnlyWhatHasNotComeBack(): void
    {
        $lines = [
            (object) ['item_id' => 11, 'item_location' => 1, 'quantity_purchased' => '4', 'discount' => '0', 'discount_type' => 0, 'item_unit_price' => '10', 'description' => 'a', 'serialnumber' => ''],
            (object) ['item_id' => 12, 'item_location' => 1, 'quantity_purchased' => '2', 'discount' => '0', 'discount_type' => 0, 'item_unit_price' => '20', 'description' => 'b', 'serialnumber' => ''],
            (object) ['item_id' => 13, 'item_location' => 1, 'quantity_purchased' => '1', 'discount' => '0', 'discount_type' => 0, 'item_unit_price' => '30', 'description' => 'c', 'serialnumber' => ''],
        ];

        $sold = $this->createStub(ResultInterface::class);
        $sold->method('getResult')->willReturn($lines);

        $added = [];

        $sale = $this->createStub(Sale::class);
        $sale->method('get_sale_items_ordered')->willReturn($sold);
        $sale->method('getReturnedQuantities')->willReturn([11 => 1.0, 12 => 2.0]);
        $sale->method('get_customer')->willReturn((object) ['person_id' => 3]);
        Factories::injectMock('models', Sale::class, $sale);

        $saleLib = $this->getMockBuilder(Sale_lib::class)->onlyMethods(['add_item'])->getMock();
        $saleLib->method('add_item')->willReturnCallback(static function (string &$itemId, int $location, string $quantity) use (&$added): bool {
            $added[(int) $itemId] = (float) $quantity;

            return true;
        });

        $saleLib->return_entire_sale('POS 9');

        $this->assertSame([11 => -3.0, 13 => -1.0], $added);
        $this->assertSame(9, $saleLib->get_return_origin());
        $this->assertSame(3, $saleLib->get_customer());
    }

    private function fourDecimals(string $amount): string
    {
        return bcadd($amount, '0', 4);
    }
}
