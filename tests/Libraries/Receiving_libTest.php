<?php

namespace Tests\Libraries;

use App\Libraries\Accounting\InvoiceDiscount;
use App\Libraries\Accounting\InvoiceDiscountProblem;
use App\Libraries\Receiving_lib;
use App\Models\Attribute;
use App\Models\Item;
use App\Models\Item_kit_items;
use App\Models\Item_quantity;
use App\Models\Item_taxes;
use App\Models\Receiving;
use App\Models\Stock_location;
use CodeIgniter\Config\Factories;
use CodeIgniter\Database\ResultInterface;
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

    public function testSetInvoiceDate_KeepsTheDayTypedAndTakesItBackWhenSetEmpty(): void
    {
        $this->assertNull($this->receivingLib->getInvoiceDate());

        $this->receivingLib->setInvoiceDate('2026-10-20');

        $this->assertSame('2026-10-20', $this->receivingLib->getInvoiceDate());

        $this->receivingLib->setInvoiceDate('');

        $this->assertNull($this->receivingLib->getInvoiceDate());

        $this->receivingLib->setInvoiceDate('2026-10-20');
        $this->receivingLib->setInvoiceDate(null);

        $this->assertNull($this->receivingLib->getInvoiceDate());
    }

    public function testSetPaymentTerms_KeepsTheTermsTypedAndTakesThemBackWhenSetEmpty(): void
    {
        $this->assertNull($this->receivingLib->getPaymentTerms());

        $this->receivingLib->setPaymentTerms('30');

        $this->assertSame('30', $this->receivingLib->getPaymentTerms());

        $this->receivingLib->setPaymentTerms('');

        $this->assertNull($this->receivingLib->getPaymentTerms());

        $this->receivingLib->setPaymentTerms('30');
        $this->receivingLib->setPaymentTerms(null);

        $this->assertNull($this->receivingLib->getPaymentTerms());
    }

    public function testClearAll_ForgetsTheDayAndTheTermsSoTheNextPurchaseDoesNotInheritThem(): void
    {
        $this->receivingLib->setInvoiceDate('2026-10-20');
        $this->receivingLib->setPaymentTerms('30');

        $this->receivingLib->clear_all();

        $this->assertNull($this->receivingLib->getInvoiceDate());
        $this->assertNull($this->receivingLib->getPaymentTerms());
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

    /**
     * @return array<string, array{string, string, string, float, string, float}>
     */
    public static function wholeLineDiscounts(): array
    {
        return [
            'nothing came back before, the whole discount'          => ['100.00', '3', '1', 0.0, '100.00', -3.0],
            'a part came back, the rest of the units take the rest' => ['100.00', '10', '1', 3.0, '70.00', -7.0],
            'a third of 100 is not shared as two thirds rounded'    => ['100.00', '3', '1', 1.0, '66.67', -2.0],
            'the last unit takes what the others left'              => ['100.00', '3', '1', 2.0, '33.33', -1.0],
            'a half cent goes to the units that came back first'    => ['1.01', '2', '1', 1.0, '0.50', -1.0],
            'a half cent again with another amount'                 => ['0.05', '2', '1', 1.0, '0.02', -1.0],
            'a line of packs counts the units in them'              => ['1.00', '2', '12', 5.0, '0.79', -19 / 12],
        ];
    }

    #[DataProvider('wholeLineDiscounts')]
    public function testReturnRemainingOfReceiving_TakesTheShareOfAWholeLineDiscountThatTheUnitsBackBeforeLeftSoTheSharesAddUpToTheDiscount(string $discount, string $bought, string $perPack, float $returnedBefore, string $share, float $packs): void
    {
        $row = (object) ['item_id' => 7, 'item_location' => 1, 'quantity_purchased' => $bought, 'receiving_quantity' => $perPack, 'discount' => $discount, 'discount_type' => FIXED_LINE, 'item_unit_price' => '50.00', 'description' => '', 'serialnumber' => '', 'tax_percent' => '0'];

        $items = $this->createStub(ResultInterface::class);
        $items->method('getResult')->willReturn([$row]);

        $receiving = $this->createStub(Receiving::class);
        $receiving->method('getReturnableQuantities')->willReturn([7 => ['bought' => (float) $bought * (float) $perPack, 'returned' => $returnedBefore]]);
        $receiving->method('get_receiving_items')->willReturn($items);
        $receiving->method('get_supplier')->willReturn((object) ['person_id' => 4]);
        Factories::injectMock('models', Receiving::class, $receiving);

        $handed  = [];
        $library = $this->getMockBuilder(Receiving_lib::class)->onlyMethods(['add_item'])->getMock();
        $library->method('add_item')->willReturnCallback(static function (string $itemId, int|float $quantity, ?int $location, float $given, int $type) use (&$handed): bool {
            $handed[] = [$itemId, round((float) $quantity, 4), $type, number_format($given, 2, '.', '')];

            return true;
        });

        $library->return_remaining_of_receiving(9);

        $this->assertSame([['7', round($packs, 4), FIXED_LINE, $share]], $handed);
        $this->assertSame(9, $library->get_return_origin());
    }

    public function testReturnRemainingOfReceiving_LeavesAPercentDiscountAndAFixedDiscountPerUnitAsTheLineHasThem(): void
    {
        $rows = [
            (object) ['item_id' => 7, 'item_location' => 1, 'quantity_purchased' => '4', 'receiving_quantity' => '1', 'discount' => '10.00', 'discount_type' => PERCENT, 'item_unit_price' => '50.00', 'description' => '', 'serialnumber' => '', 'tax_percent' => '0'],
            (object) ['item_id' => 8, 'item_location' => 1, 'quantity_purchased' => '4', 'receiving_quantity' => '1', 'discount' => '2.50', 'discount_type' => FIXED, 'item_unit_price' => '50.00', 'description' => '', 'serialnumber' => '', 'tax_percent' => '0'],
        ];

        $items = $this->createStub(ResultInterface::class);
        $items->method('getResult')->willReturn($rows);

        $receiving = $this->createStub(Receiving::class);
        $receiving->method('getReturnableQuantities')->willReturn([7 => ['bought' => 4.0, 'returned' => 1.0], 8 => ['bought' => 4.0, 'returned' => 1.0]]);
        $receiving->method('get_receiving_items')->willReturn($items);
        $receiving->method('get_supplier')->willReturn((object) ['person_id' => 4]);
        Factories::injectMock('models', Receiving::class, $receiving);

        $handed  = [];
        $library = $this->getMockBuilder(Receiving_lib::class)->onlyMethods(['add_item'])->getMock();
        $library->method('add_item')->willReturnCallback(static function (string $itemId, int|float $quantity, ?int $location, float $given, int $type) use (&$handed): bool {
            $handed[] = [$itemId, (float) $quantity, $type, number_format($given, 2, '.', '')];

            return true;
        });

        $library->return_remaining_of_receiving(9);

        $this->assertSame([['7', -3.0, PERCENT, '10.00'], ['8', -3.0, FIXED, '2.50']], $handed);
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function twoLines(): array
    {
        return [
            1 => ['quantity' => 10, 'price' => 100, 'discount' => 10, 'discount_type' => PERCENT, 'receiving_quantity' => 1, 'tax_percent' => '15'],
            2 => ['quantity' => 5, 'price' => 20, 'discount' => 0, 'discount_type' => PERCENT, 'receiving_quantity' => 1, 'tax_percent' => '0'],
        ];
    }

    public function testGetTotal_TakesThePercentageDiscountOnTheWholePurchaseOffAfterTheLineDiscountsAndBeforeTheTax(): void
    {
        session()->set('recv_cart', self::twoLines());
        $this->receivingLib->setInvoiceDiscount(InvoiceDiscount::of(PERCENT, '10'));

        $this->assertSame('1000.0000', bcadd($this->receivingLib->get_total(), '0', 4), 'The lines after their own discounts: 900 and 100');
        $this->assertSame('-100.0000', bcadd($this->receivingLib->getInvoiceDiscountAmount(), '0', 4));
        $this->assertSame('121.5000', bcadd($this->receivingLib->get_tax_total(), '0', 4), 'Fifteen per cent of the 810 that is left of the first line');
        $this->assertSame('1021.50', bcadd($this->receivingLib->get_total_with_tax(), '0', 2));
    }

    public function testGetTotal_SharesAnAmountDiscountInProportionToWhatTheLinesCost(): void
    {
        session()->set('recv_cart', self::twoLines());
        $this->receivingLib->setInvoiceDiscount(InvoiceDiscount::of(FIXED, '50'));

        $this->assertSame('-50.0000', bcadd($this->receivingLib->getInvoiceDiscountAmount(), '0', 4));
        $this->assertSame('128.2500', bcadd($this->receivingLib->get_tax_total(), '0', 4), 'The first line takes 45 of the 50, and 15% of the 855 is 128.25');
    }

    public function testGetTotal_IsWhatItWasWhenTheDiscountIsNone(): void
    {
        session()->set('recv_cart', self::twoLines());

        $without = [$this->receivingLib->get_total(), $this->receivingLib->get_tax_total(), $this->receivingLib->get_total_with_tax(), $this->receivingLib->getInvoiceDiscountAmount()];

        $this->receivingLib->setInvoiceDiscount(InvoiceDiscount::none());

        $this->assertSame($without, [$this->receivingLib->get_total(), $this->receivingLib->get_tax_total(), $this->receivingLib->get_total_with_tax(), $this->receivingLib->getInvoiceDiscountAmount()]);
        $this->assertSame('0', $this->receivingLib->getInvoiceDiscountAmount());
    }

    public function testGetTotal_UsesTheSharesASavedPurchaseKeptWhateverTheRegisterHolds(): void
    {
        $items = self::twoLines();

        $items[1]['invoice_discount_share'] = '90.0000';
        $items[2]['invoice_discount_share'] = '10.0000';

        session()->set('recv_cart', $items);
        $this->receivingLib->setInvoiceDiscount(InvoiceDiscount::of(PERCENT, '50'));

        $this->assertSame('-100.0000', bcadd($this->receivingLib->getInvoiceDiscountAmount(), '0', 4));
        $this->assertSame('121.5000', bcadd($this->receivingLib->get_tax_total(), '0', 4));
    }

    public function testGetTotal_AddsUpACartOfGoodsGoingBackWithTheSignOfTheGoodsAndKeepsTheRegisterDiscountOutOfIt(): void
    {
        session()->set('recv_cart', [1 => ['quantity' => -4, 'price' => 100, 'discount' => 0, 'discount_type' => PERCENT, 'receiving_quantity' => 1, 'tax_percent' => '15', 'invoice_discount_share' => '40.0000']]);

        $this->assertSame('-400.0000', bcadd($this->receivingLib->get_total(), '0', 4));
        $this->assertSame('40.0000', bcadd($this->receivingLib->getInvoiceDiscountAmount(), '0', 4), 'Above nothing: it lowers what is given back');
        $this->assertSame('-54.0000', bcadd($this->receivingLib->get_tax_total(), '0', 4));
        $this->assertSame('-414.00', bcadd($this->receivingLib->get_total_with_tax(), '0', 2));
    }

    public function testGetInvoiceDiscount_ReturnsWhatWasSetAndNoneAfterItIsClearedOrTheRegisterIsEmptied(): void
    {
        $this->assertTrue($this->receivingLib->getInvoiceDiscount()->isNone());

        $this->receivingLib->setInvoiceDiscount(InvoiceDiscount::of(FIXED, '12.5'));

        $this->assertSame([FIXED, '12.50'], [$this->receivingLib->getInvoiceDiscount()->type, $this->receivingLib->getInvoiceDiscount()->value]);

        $this->receivingLib->clearInvoiceDiscount();

        $this->assertTrue($this->receivingLib->getInvoiceDiscount()->isNone());

        $this->receivingLib->setInvoiceDiscount(InvoiceDiscount::of(PERCENT, '5'));
        $this->receivingLib->clear_all();

        $this->assertTrue($this->receivingLib->getInvoiceDiscount()->isNone());
    }

    public function testInvoiceDiscountProblem_AcceptsADiscountTheLinesCarry(): void
    {
        session()->set('recv_cart', self::twoLines());

        foreach ([InvoiceDiscount::of(PERCENT, '100'), InvoiceDiscount::of(FIXED, '1000')] as $discount) {
            $this->receivingLib->setInvoiceDiscount($discount);

            $this->assertNull($this->receivingLib->invoiceDiscountProblem());
        }
    }

    public function testInvoiceDiscountProblem_RejectsAnAmountAboveWhatTheLinesCameToAfterOneWasLowered(): void
    {
        $items = self::twoLines();

        session()->set('recv_cart', $items);
        $this->receivingLib->setInvoiceDiscount(InvoiceDiscount::of(FIXED, '1000'));

        $items[1]['quantity'] = 5;

        session()->set('recv_cart', $items);

        $this->assertSame(InvoiceDiscountProblem::MoreThanTheLines, $this->receivingLib->invoiceDiscountProblem());
    }

    public function testInvoiceDiscountProblem_RejectsALineOfGoodsGoingOut(): void
    {
        $items = self::twoLines();

        $items[2]['quantity'] = -5;

        session()->set('recv_cart', $items);
        $this->receivingLib->setInvoiceDiscount(InvoiceDiscount::of(PERCENT, '10'));

        $this->assertSame(InvoiceDiscountProblem::NotGoodsIn, $this->receivingLib->invoiceDiscountProblem());
    }

    public function testInvoiceDiscountProblem_AcceptsARegisterWithoutADiscountWithoutLinesOrNotReceiving(): void
    {
        $items = self::twoLines();

        $items[2]['quantity'] = -5;

        session()->set('recv_cart', $items);

        $this->assertNull($this->receivingLib->invoiceDiscountProblem(), 'No discount');

        $this->receivingLib->setInvoiceDiscount(InvoiceDiscount::of(FIXED, '5000'));
        session()->set('recv_cart', []);

        $this->assertNull($this->receivingLib->invoiceDiscountProblem(), 'No lines');

        session()->set('recv_cart', $items);
        session()->set('recv_mode', 'return');

        $this->assertNull($this->receivingLib->invoiceDiscountProblem(), 'Not the receive mode');
    }

    public function testSetInvoiceDiscount_ClearsWhatWasKeptWhenTheDiscountIsNone(): void
    {
        $this->receivingLib->setInvoiceDiscount(InvoiceDiscount::of(PERCENT, '5'));
        $this->receivingLib->setInvoiceDiscount(InvoiceDiscount::none());

        $this->assertNull(session()->get('recv_invoice_discount'));
    }

    public function testGetOrderSource_IsNothingUntilAnOrderIsSetAndTakesItBackWhenSetEmptyOrCleared(): void
    {
        $this->assertNull($this->receivingLib->getOrderSource());

        $this->receivingLib->setOrderSource(7);

        $this->assertSame(7, $this->receivingLib->getOrderSource());

        $this->receivingLib->setOrderSource(null);

        $this->assertNull($this->receivingLib->getOrderSource());

        $this->receivingLib->setOrderSource(9);
        $this->receivingLib->clearOrderSource();

        $this->assertNull($this->receivingLib->getOrderSource());
    }

    public function testClearAll_ForgetsThePurchaseOrderTheReceivingWasConvertedFromSoTheNextReceivingIsNotTakenForIt(): void
    {
        $this->receivingLib->setOrderSource(7);
        $this->receivingLib->clear_all();

        $this->assertNull($this->receivingLib->getOrderSource());
    }

    public function testPrepareReceivingFromOrder_StartsFromAnEmptyRegisterAndRemembersTheOrderTheSupplierAndTheMode(): void
    {
        $this->receivingLib->set_mode('return');
        $this->receivingLib->set_comment('left over');
        $this->receivingLib->setOrderSource(3);

        $this->receivingLib->prepareReceivingFromOrder(11, 5, 1, []);

        $this->assertSame(11, $this->receivingLib->getOrderSource());
        $this->assertSame(5, $this->receivingLib->get_supplier());
        $this->assertSame('receive', $this->receivingLib->get_mode());
        $this->assertSame('', $this->receivingLib->get_comment());
        $this->assertSame([], $this->receivingLib->get_cart());
    }

    public function testAddItem_RefusesAnOrderLineWhoseItemWasDeletedOrDoesNotExistAndNeverLooksItUpByBarcode(): void
    {
        $items = model(Item::class);

        $items->expects($this->never())->method('get_info_by_id_or_number');
        $items->method('get_info')->willReturnMap([
            [7, (object) ['item_id' => 7, 'deleted' => 1]],
            [8, (object) ['item_id' => null, 'deleted' => 0]],
        ]);

        $this->assertFalse($this->receivingLib->add_item('7', 1, 1, orderLine: true));
        $this->assertFalse($this->receivingLib->add_item('8', 1, 1, orderLine: true));
        $this->assertSame([], $this->receivingLib->get_cart());
    }
}
