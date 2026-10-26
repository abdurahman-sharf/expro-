<?php

namespace Tests\Libraries;

use App\Libraries\Receiving_lib;
use App\Models\Attribute;
use App\Models\Item;
use App\Models\Item_kit_items;
use App\Models\Item_quantity;
use App\Models\Item_taxes;
use App\Models\Receiving;
use App\Models\Stock_location;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What the purchase screen says a line and the whole purchase come to, with a percentage
 * discount taken from the line and a fixed discount taken from every unit (or from the whole
 * line, on a line saved before it was taken from every unit).
 */
class Receiving_libTest extends CIUnitTestCase
{
    private Receiving_lib $receivingLib;

    protected function setUp(): void
    {
        parent::setUp();

        bcscale(4);

        foreach ([Attribute::class, Item::class, Item_kit_items::class, Item_quantity::class, Item_taxes::class, Receiving::class, Stock_location::class] as $model) {
            Factories::injectMock('models', $model, $this->getMockBuilder($model)->disableOriginalConstructor()->getMock());
        }

        session()->destroy();
        $this->receivingLib = new Receiving_lib();
    }

    #[DataProvider('lines')]
    public function testGetItemTotal_TakesAFixedDiscountFromEveryUnit(float $quantity, float $price, float $discount, int $discountType, float $perPack, string $total): void
    {
        $this->assertSame($total, bcadd($this->receivingLib->get_item_total($quantity, $price, $discount, $discountType, $perPack), '0', 4));
    }

    /**
     * @return array<string, array{float, float, float, int, float, string}>
     */
    public static function lines(): array
    {
        return [
            'no discount'                  => [10, 100, 0, PERCENT, 1, '1000.0000'],
            'percent discount'             => [10, 100, 10, PERCENT, 1, '900.0000'],
            'fixed discount for each unit' => [10, 100, 50, FIXED, 1, '500.0000'],
            'fixed discount of packs'      => [2, 5, 1, FIXED, 12, '96.0000'],
            'percent discount of packs'    => [2, 5, 10, PERCENT, 12, '108.0000'],
            'a return of units'            => [-4, 10, 1, FIXED, 1, '-36.0000'],
            'fixed discount of a line saved before it was per unit' => [10, 100, 50, FIXED_LINE, 1, '950.0000'],
            'fixed discount of a line of packs saved before'         => [2, 5, 1, FIXED_LINE, 12, '119.0000'],
            'a return of a line saved before'                        => [-4, 10, 5, FIXED_LINE, 1, '-35.0000'],
        ];
    }

    public function testGetTotal_AddsTheLinesAfterTheirDiscounts(): void
    {
        session()->set('recv_cart', [
            1 => ['quantity' => 10, 'price' => 100, 'discount' => 50, 'discount_type' => FIXED, 'receiving_quantity' => 1, 'tax_percent' => '15'],
            2 => ['quantity' => 5, 'price' => 20, 'discount' => 10, 'discount_type' => PERCENT, 'receiving_quantity' => 1, 'tax_percent' => '0'],
        ]);

        $this->assertSame('590.0000', bcadd($this->receivingLib->get_total(), '0', 4));
        $this->assertSame('75.0000', bcadd($this->receivingLib->get_tax_total(), '0', 4));
        $this->assertSame('665.0000', bcadd($this->receivingLib->get_total_with_tax(), '0', 4));
    }
}
