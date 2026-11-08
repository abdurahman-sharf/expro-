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
