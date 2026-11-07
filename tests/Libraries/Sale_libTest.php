<?php

namespace Tests\Libraries;

use App\Libraries\Accounting\ReturnDocument;
use App\Libraries\HeaderCharges;
use App\Libraries\HeaderChargesProblem;
use App\Libraries\ShippingTaxOption;
use App\Libraries\InvoiceDiscountProblem;
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
use App\Models\Tax_definition;
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
        $ospos->settings = ['cash_rounding_code' => '', 'cash_decimals' => 2, 'currency_decimals' => 2, 'tax_decimals' => 2, 'quantity_decimals' => 2, 'tax_included' => '0', 'use_destination_based_tax' => '0'];
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

    public function testSetInvoiceNumber_HoldsANumberWithLettersAndLeadingZerosAsTyped(): void
    {
        $this->assertNull($this->saleLib->get_invoice_number());

        $this->saleLib->set_invoice_number('INV-0007');

        $this->assertSame('INV-0007', $this->saleLib->get_invoice_number());

        $this->saleLib->set_invoice_number('0007');

        $this->assertSame('0007', $this->saleLib->get_invoice_number());
    }

    public function testSetInvoiceNumber_ForgetsTheNumberWhenItIsEmptied(): void
    {
        $this->saleLib->set_invoice_number('INV-0007');
        $this->saleLib->set_invoice_number('');

        $this->assertNull($this->saleLib->get_invoice_number());
    }

    public function testSetInvoiceNumber_KeepsTheNumberAlreadyHeldWhenAskedToKeepIt(): void
    {
        $this->saleLib->set_invoice_number('INV-0007');
        $this->saleLib->set_invoice_number('INV-0008', true);

        $this->assertSame('INV-0007', $this->saleLib->get_invoice_number());

        $this->saleLib->clear_invoice_number();
        $this->saleLib->set_invoice_number('INV-0008', true);

        $this->assertSame('INV-0008', $this->saleLib->get_invoice_number());
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

    public function testGetReturnDocument_IsACreditNoteUntilARefundInvoiceIsAskedFor(): void
    {
        $this->assertSame(ReturnDocument::CreditNote, $this->saleLib->getReturnDocument());

        $this->saleLib->setReturnDocument(ReturnDocument::RefundInvoice);

        $this->assertSame(ReturnDocument::RefundInvoice, $this->saleLib->getReturnDocument());
        $this->assertSame('refund_invoice', session()->get('sales_return_document'));

        $this->saleLib->setReturnDocument(ReturnDocument::CreditNote);

        $this->assertSame(ReturnDocument::CreditNote, $this->saleLib->getReturnDocument());
        $this->assertNull(session()->get('sales_return_document'));

        $this->saleLib->setReturnDocument(ReturnDocument::RefundInvoice);
        $this->saleLib->clearReturnDocument();

        $this->assertSame(ReturnDocument::CreditNote, $this->saleLib->getReturnDocument());
    }

    public function testGetReturnOverride_GivesTheReasonThatWasSetAndNothingForNoneOrAnEmptyOne(): void
    {
        $this->assertNull($this->saleLib->getReturnOverride());

        $this->saleLib->setReturnOverride('lost the receipt');

        $this->assertSame('lost the receipt', $this->saleLib->getReturnOverride());
        $this->assertSame('lost the receipt', session()->get('sales_return_override'));

        $this->saleLib->setReturnOverride('');

        $this->assertNull($this->saleLib->getReturnOverride());
    }

    public function testClearReturnOverride_ForgetsTheReason(): void
    {
        $this->saleLib->setReturnOverride('lost the receipt');
        $this->saleLib->clearReturnOverride();

        $this->assertNull($this->saleLib->getReturnOverride());
        $this->assertNull(session()->get('sales_return_override'));
    }

    public function testClearAll_ForgetsTheReasonOfTheOverrideSoTheNextReturnHasToAskAgain(): void
    {
        $this->saleLib->setReturnOverride('lost the receipt');
        $this->saleLib->clear_all();

        $this->assertNull($this->saleLib->getReturnOverride());
        $this->assertNull(session()->get('sales_return_override'));
    }

    public function testClearAll_ForgetsTheDeliveryTheOriginAndTheDocumentOfTheSaleBeingMade(): void
    {
        $this->saleLib->set_delivered(false);
        $this->saleLib->set_return_origin(42);
        $this->saleLib->setReturnDocument(ReturnDocument::RefundInvoice);
        $this->saleLib->clear_all();

        $this->assertTrue($this->saleLib->is_delivered());
        $this->assertNull($this->saleLib->get_return_origin());
        $this->assertSame(ReturnDocument::CreditNote, $this->saleLib->getReturnDocument());
    }

    public function testCopyEntireSale_TakesOverWhetherTheGoodsLeftWhichSaleAReturnComesFromAndWhichDocumentItIs(): void
    {
        $empty = $this->createStub(ResultInterface::class);
        $empty->method('getResult')->willReturn([]);

        $sale = $this->createStub(Sale::class);
        $sale->method('getHeaderCharges')->willReturn(HeaderCharges::none());
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
        $sale->method('getReturnDocument')->willReturn(ReturnDocument::RefundInvoice);
        $sale->method('getInvoiceTiming')->willReturn(['invoice_date' => null, 'payment_terms_days' => null, 'due_date' => null]);
        Factories::injectMock('models', Sale::class, $sale);

        $saleLib = new Sale_lib();
        $saleLib->copy_entire_sale(9);

        $this->assertFalse($saleLib->is_delivered());
        $this->assertSame(42, $saleLib->get_return_origin());
        $this->assertSame(ReturnDocument::RefundInvoice, $saleLib->getReturnDocument());
    }

    public function testCopyEntireSale_TakesOverTheDayAndTheTermsTypedOnTheSale(): void
    {
        $empty = $this->createStub(ResultInterface::class);
        $empty->method('getResult')->willReturn([]);

        $sale = $this->createStub(Sale::class);
        $sale->method('getHeaderCharges')->willReturn(HeaderCharges::none());
        $sale->method('get_sale_items_ordered')->willReturn($empty);
        $sale->method('get_sale_payments')->willReturn($empty);
        $sale->method('get_customer')->willReturn((object) ['person_id' => 3]);
        $sale->method('get_employee')->willReturn((object) ['person_id' => 1]);
        $sale->method('get_sale_type')->willReturn(SALE_TYPE_INVOICE);
        $sale->method('get_comment')->willReturn('');
        $sale->method('isDelivered')->willReturn(true);
        $sale->method('getInvoiceTiming')->willReturn(['invoice_date' => '2026-10-20', 'payment_terms_days' => 30, 'due_date' => '2026-11-19']);
        $sale->method('getReturnDocument')->willReturn(ReturnDocument::CreditNote);
        Factories::injectMock('models', Sale::class, $sale);

        $saleLib = new Sale_lib();
        $saleLib->copy_entire_sale(9);

        $this->assertSame('2026-10-20', $saleLib->getInvoiceDate());
        $this->assertSame('30', $saleLib->getPaymentTerms());
    }

    public function testCopyEntireSale_TakesOverNoDayAndNoTermsFromASaleThatHasNone(): void
    {
        $empty = $this->createStub(ResultInterface::class);
        $empty->method('getResult')->willReturn([]);

        $sale = $this->createStub(Sale::class);
        $sale->method('getHeaderCharges')->willReturn(HeaderCharges::none());
        $sale->method('get_sale_items_ordered')->willReturn($empty);
        $sale->method('get_sale_payments')->willReturn($empty);
        $sale->method('get_customer')->willReturn((object) ['person_id' => 3]);
        $sale->method('get_employee')->willReturn((object) ['person_id' => 1]);
        $sale->method('get_sale_type')->willReturn(SALE_TYPE_INVOICE);
        $sale->method('get_comment')->willReturn('');
        $sale->method('isDelivered')->willReturn(true);
        $sale->method('getInvoiceTiming')->willReturn(['invoice_date' => null, 'payment_terms_days' => null, 'due_date' => null]);
        $sale->method('getReturnDocument')->willReturn(ReturnDocument::CreditNote);
        Factories::injectMock('models', Sale::class, $sale);

        $saleLib = new Sale_lib();
        $saleLib->setInvoiceDate('2026-10-01');
        $saleLib->setPaymentTerms('15');
        $saleLib->copy_entire_sale(9);

        $this->assertNull($saleLib->getInvoiceDate());
        $this->assertNull($saleLib->getPaymentTerms());
    }

    public function testSetInvoiceDate_KeepsTheDayTypedAndTakesItBackWhenSetEmpty(): void
    {
        $this->assertNull($this->saleLib->getInvoiceDate());

        $this->saleLib->setInvoiceDate('2026-10-20');

        $this->assertSame('2026-10-20', $this->saleLib->getInvoiceDate());

        $this->saleLib->setInvoiceDate('');

        $this->assertNull($this->saleLib->getInvoiceDate());

        $this->saleLib->setInvoiceDate('2026-10-20');
        $this->saleLib->setInvoiceDate(null);

        $this->assertNull($this->saleLib->getInvoiceDate());
    }

    public function testSetPaymentTerms_KeepsTheTermsTypedAndTakesThemBackWhenSetEmpty(): void
    {
        $this->assertNull($this->saleLib->getPaymentTerms());

        $this->saleLib->setPaymentTerms('30');

        $this->assertSame('30', $this->saleLib->getPaymentTerms());

        $this->saleLib->setPaymentTerms('');

        $this->assertNull($this->saleLib->getPaymentTerms());

        $this->saleLib->setPaymentTerms('30');
        $this->saleLib->setPaymentTerms(null);

        $this->assertNull($this->saleLib->getPaymentTerms());
    }

    public function testClearAll_ForgetsTheDayAndTheTermsSoTheNextSaleDoesNotInheritThem(): void
    {
        $this->saleLib->setInvoiceDate('2026-10-20');
        $this->saleLib->setPaymentTerms('30');

        $this->saleLib->clear_all();

        $this->assertNull($this->saleLib->getInvoiceDate());
        $this->assertNull($this->saleLib->getPaymentTerms());
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function referenceCart(): array
    {
        return [
            1 => ['item_id' => 3, 'item_location' => 1, 'line' => 1, 'quantity' => '2', 'price' => '75', 'discount' => '10', 'discount_type' => PERCENT, 'stock_type' => HAS_STOCK],
            2 => ['item_id' => 4, 'item_location' => 1, 'line' => 2, 'quantity' => '1', 'price' => '220', 'discount' => '0', 'discount_type' => PERCENT, 'stock_type' => HAS_STOCK],
        ];
    }

    public function testGetCart_GivesEveryLineTheShareOfTheDiscountOnTheWholeOfTheSale(): void
    {
        $this->saleLib->set_cart($this->referenceCart());
        $this->saleLib->setInvoiceDiscount(PERCENT, '10');

        $cart = $this->saleLib->get_cart();

        $this->assertSame('13.5000', $cart[1]['invoice_discount_share']);
        $this->assertSame('22.0000', $cart[2]['invoice_discount_share']);
        $this->assertSame('35.5000', $this->saleLib->getInvoiceDiscountTotal());
        $this->assertNull($this->saleLib->invoiceDiscountProblem());
    }

    public function testGetCart_GivesTheLinesAnAmountSharedInProportionToWhatTheyComeTo(): void
    {
        $this->saleLib->set_cart($this->referenceCart());
        $this->saleLib->setInvoiceDiscount(FIXED, '20');

        $cart = $this->saleLib->get_cart();

        $this->assertSame('7.6100', $cart[1]['invoice_discount_share']);
        $this->assertSame('12.3900', $cart[2]['invoice_discount_share']);
    }

    public function testGetCart_LeavesTheCartAsItIsWhenThereIsNoDiscount(): void
    {
        $this->saleLib->set_cart($this->referenceCart());

        $this->assertSame($this->referenceCart(), $this->saleLib->get_cart());
        $this->assertSame('0.0000', $this->fourDecimals($this->saleLib->getInvoiceDiscountTotal()));
    }

    public function testGetCart_TakesTheSharesOffWhenTheDiscountIsCleared(): void
    {
        $this->saleLib->set_cart($this->referenceCart());
        $this->saleLib->setInvoiceDiscount(PERCENT, '10');
        $this->saleLib->set_cart($this->saleLib->get_cart());
        $this->saleLib->clearInvoiceDiscount();

        $this->assertSame($this->referenceCart(), $this->saleLib->get_cart());
        $this->assertNull($this->saleLib->getInvoiceDiscount());
    }

    public function testGetCart_AppliesNoShareToAnyLineWhenTheDiscountCannotBeTakenOffWhatTheyComeTo(): void
    {
        $this->saleLib->set_cart($this->referenceCart());
        $this->saleLib->setInvoiceDiscount(FIXED, '355.01');

        $this->assertSame($this->referenceCart(), $this->saleLib->get_cart());
        $this->assertSame(InvoiceDiscountProblem::ExceedsTotal, $this->saleLib->invoiceDiscountProblem());
    }

    public function testGetCart_FollowsTheLinesWhenTheyChange(): void
    {
        $this->saleLib->set_cart($this->referenceCart());
        $this->saleLib->setInvoiceDiscount(PERCENT, '10');

        $cart = $this->saleLib->get_cart();
        unset($cart[2]);
        $this->saleLib->set_cart($cart);

        $this->assertSame('13.5000', $this->saleLib->get_cart()[1]['invoice_discount_share']);
        $this->assertSame('13.5000', $this->saleLib->getInvoiceDiscountTotal());
    }

    public function testGetCart_GivesAReturnTheShareOfTheDiscountOfTheSaleItComesFrom(): void
    {
        $sale = $this->createStub(Sale::class);
        $sale->method('invoiceDiscountOrigin')->willReturn([3 => ['sold' => '4', 'share' => '8.0000', 'returned' => '0', 'returnedShare' => '0.0000']]);
        Factories::injectMock('models', Sale::class, $sale);

        $saleLib = new Sale_lib();
        $saleLib->setInvoiceDiscount(PERCENT, '10');
        $saleLib->set_mode('return');
        $saleLib->set_return_origin(9);
        $saleLib->set_cart([1 => ['item_id' => 3, 'item_location' => 1, 'line' => 1, 'quantity' => '-1', 'price' => '75', 'discount' => '0', 'discount_type' => PERCENT, 'stock_type' => HAS_STOCK]]);

        $this->assertArrayHasKey('invoice_discount_share', $saleLib->get_cart()[1]);
        $this->assertSame('-2.0000', $saleLib->get_cart()[1]['invoice_discount_share']);
        $this->assertSame('-2.0000', $saleLib->getInvoiceDiscountTotal());
    }

    public function testGetCart_GivesAReturnNoShareWhenItNamesNoSale(): void
    {
        $this->saleLib->set_mode('return');
        $this->saleLib->set_cart([1 => ['item_id' => 3, 'item_location' => 1, 'line' => 1, 'quantity' => '-1', 'price' => '75', 'discount' => '0', 'discount_type' => PERCENT, 'stock_type' => HAS_STOCK]]);

        $this->assertArrayNotHasKey('invoice_discount_share', $this->saleLib->get_cart()[1]);
    }

    public function testGetCart_IgnoresTheDiscountEnteredBeforeTheRegisterTookAReturn(): void
    {
        $this->saleLib->setInvoiceDiscount(PERCENT, '10');
        $this->saleLib->set_mode('return');
        $this->saleLib->set_return_origin(9);
        $this->saleLib->set_cart([1 => ['item_id' => 3, 'item_location' => 1, 'line' => 1, 'quantity' => '-1', 'price' => '75', 'discount' => '0', 'discount_type' => PERCENT, 'stock_type' => HAS_STOCK]]);

        $this->assertArrayNotHasKey('invoice_discount_share', $this->saleLib->get_cart()[1]);
        $this->assertNull($this->saleLib->invoiceDiscountProblem());
    }

    public function testGetTotals_TakesTheDiscountOnTheWholeOfTheSaleOffTheSubtotalAndTheTotal(): void
    {
        $this->saleLib->set_cart($this->referenceCart());
        $this->saleLib->setInvoiceDiscount(PERCENT, '10');

        $totals = $this->saleLib->get_totals(['VAT' => ['tax_type' => Tax_lib::TAX_TYPE_EXCLUDED, 'sale_tax_amount' => '47.925']]);

        $this->assertSame('319.5000', $this->fourDecimals($totals['subtotal']));
        $this->assertSame('367.4250', $this->fourDecimals($totals['total']));
        $this->assertSame('35.5000', $this->fourDecimals($totals['invoice_discount']));
        $this->assertSame('355.0000', $this->fourDecimals(bcadd($totals['subtotal'], $totals['invoice_discount'])));
        $this->assertSame('15.0000', $this->fourDecimals($totals['total_discount']), 'the discounts of the lines stay their own');
    }

    public function testGetTotals_GivesTheSameWithoutADiscountAsBefore(): void
    {
        $this->saleLib->set_cart($this->referenceCart());

        $totals = $this->saleLib->get_totals(['VAT' => ['tax_type' => Tax_lib::TAX_TYPE_EXCLUDED, 'sale_tax_amount' => '53.25']]);

        $this->assertSame('355.0000', $this->fourDecimals($totals['subtotal']));
        $this->assertSame('408.2500', $this->fourDecimals($totals['total']));
        $this->assertSame('0.0000', $this->fourDecimals($totals['invoice_discount']));
    }

    public function testGetItemTotal_TakesTheShareOffTheLineWhenAskedToAndOtherwiseLeavesItAlone(): void
    {
        $this->assertSame('121.5000', $this->fourDecimals($this->saleLib->get_item_total('2', '75', '10', PERCENT, true, '13.5')));
        $this->assertSame('135.0000', $this->fourDecimals($this->saleLib->get_item_total('2', '75', '10', PERCENT, true)));
        $this->assertSame('150.0000', $this->fourDecimals($this->saleLib->get_item_total('2', '75', '10', PERCENT, false, '13.5')), 'without the discounts the share is not taken off');
    }

    public function testGetItemTax_WorksTheTaxOutOnWhatIsLeftAfterTheShare(): void
    {
        $this->assertSame('18.2250', $this->fourDecimals($this->saleLib->get_item_tax('2', '75', '10', PERCENT, '15', '13.5')));
        $this->assertSame('20.2500', $this->fourDecimals($this->saleLib->get_item_tax('2', '75', '10', PERCENT, '15')));
    }

    public function testGetSubtotal_TakesTheShareOffOnlyWhenTheDiscountsAreIncluded(): void
    {
        $this->saleLib->set_cart($this->referenceCart());
        $this->saleLib->setInvoiceDiscount(PERCENT, '10');

        $this->assertSame('319.5000', $this->fourDecimals($this->saleLib->get_subtotal(true)));
        $this->assertSame('370.0000', $this->fourDecimals($this->saleLib->get_subtotal(false)));
    }

    public function testClearAll_ForgetsTheDiscountOnTheWholeOfTheSale(): void
    {
        $this->saleLib->setInvoiceDiscount(FIXED, '20');
        $this->saleLib->clear_all();

        $this->assertNull($this->saleLib->getInvoiceDiscount());
    }

    /**
     * @param list<object> $lines
     */
    private function loadSale(array $lines, int $status, ?array $discount, int $type = SALE_TYPE_INVOICE, ?string $quoteNumber = null, ?int $sourceQuote = null, ?HeaderCharges $charges = null): Sale_lib
    {
        $rows = $this->createStub(ResultInterface::class);
        $rows->method('getResult')->willReturn($lines);

        $empty = $this->createStub(ResultInterface::class);
        $empty->method('getResult')->willReturn([]);

        $sale = $this->createStub(Sale::class);
        $sale->method('getHeaderCharges')->willReturn($charges ?? HeaderCharges::none());
        $sale->method('get_sale_items_ordered')->willReturn($rows);
        $sale->method('get_sale_payments')->willReturn($empty);
        $sale->method('get_customer')->willReturn((object) ['person_id' => 3]);
        $sale->method('get_employee')->willReturn((object) ['person_id' => 1]);
        $sale->method('get_sale_type')->willReturn($type);
        $sale->method('get_quote_number')->willReturn($quoteNumber);
        $sale->method('getSourceQuote')->willReturn($sourceQuote);
        $sale->method('get_comment')->willReturn('');
        $sale->method('isDelivered')->willReturn(true);
        $sale->method('getInvoiceDiscount')->willReturn($discount);
        $sale->method('getReturnDocument')->willReturn(ReturnDocument::CreditNote);
        $sale->method('getInvoiceTiming')->willReturn(['invoice_date' => null, 'payment_terms_days' => null, 'due_date' => null]);
        $sale->method('getSaleStatus')->willReturn($status);
        Factories::injectMock('models', Sale::class, $sale);

        $saleLib = $this->getMockBuilder(Sale_lib::class)->onlyMethods(['add_item'])->getMock();
        $saleLib->method('add_item')->willReturnCallback(static function (string &$itemId, int $location, string $quantity, string &$discount, int $discountType, int $mode, ?int $kitPrice, ?int $kitPrint, ?string $price) use ($saleLib): bool {
            $cart = session()->get('sales_cart') ?? [];
            $line = $cart === [] ? 1 : max(array_keys($cart)) + 1;

            foreach ($cart as $key => $row) {
                if ($row['item_id'] == $itemId && $row['item_location'] == $location) {
                    $cart[$key]['quantity'] = bcadd($row['quantity'], $quantity);
                    session()->set('sales_cart', $cart);

                    return true;
                }
            }

            $cart[$line] = ['item_id' => $itemId, 'item_location' => $location, 'line' => $line, 'quantity' => $quantity, 'price' => $price, 'discount' => $discount, 'discount_type' => $discountType, 'stock_type' => HAS_STOCK];
            session()->set('sales_cart', $cart);

            return true;
        });

        $saleLib->copy_entire_sale(9);

        return $saleLib;
    }

    public function testCopyEntireSale_KeepsTheSharesOfACompletedSaleAsTheyWereSaved(): void
    {
        $saleLib = $this->loadSale([
            (object) ['item_id' => 3, 'item_location' => 1, 'quantity_purchased' => '2', 'discount' => '10', 'discount_type' => PERCENT, 'item_unit_price' => '75', 'description' => 'a', 'serialnumber' => '', 'print_option' => 0, 'invoice_discount_share' => '13.0000'],
            (object) ['item_id' => 4, 'item_location' => 1, 'quantity_purchased' => '1', 'discount' => '0', 'discount_type' => PERCENT, 'item_unit_price' => '220', 'description' => 'b', 'serialnumber' => '', 'print_option' => 0, 'invoice_discount_share' => '22.5000'],
        ], COMPLETED, ['type' => PERCENT, 'value' => '10.0000']);

        $cart = $saleLib->get_cart();

        $this->assertSame('13.0000', $cart[1]['invoice_discount_share']);
        $this->assertSame('22.5000', $cart[2]['invoice_discount_share']);
        $this->assertSame('35.5000', $saleLib->getInvoiceDiscountTotal());
        $this->assertSame(['type' => PERCENT, 'value' => '10'], $saleLib->getInvoiceDiscount());
    }

    public function testCopyEntireSale_AddsUpTheSharesOfLinesThatWereMergedIntoOne(): void
    {
        $saleLib = $this->loadSale([
            (object) ['item_id' => 3, 'item_location' => 1, 'quantity_purchased' => '1', 'discount' => '0', 'discount_type' => PERCENT, 'item_unit_price' => '75', 'description' => 'a', 'serialnumber' => '', 'print_option' => 0, 'invoice_discount_share' => '1.5000'],
            (object) ['item_id' => 3, 'item_location' => 1, 'quantity_purchased' => '1', 'discount' => '0', 'discount_type' => PERCENT, 'item_unit_price' => '75', 'description' => 'a', 'serialnumber' => '', 'print_option' => 0, 'invoice_discount_share' => '2.0000'],
        ], COMPLETED, ['type' => FIXED, 'value' => '3.5']);

        $cart = $saleLib->get_cart();

        $this->assertCount(1, $cart);
        $this->assertSame('3.5000', $cart[1]['invoice_discount_share']);
    }

    public function testCopyEntireSale_GivesBackTheDiscountOfASuspendedSaleToBeWorkedOutAgainAsItsLinesChange(): void
    {
        $saleLib = $this->loadSale([
            (object) ['item_id' => 3, 'item_location' => 1, 'quantity_purchased' => '2', 'discount' => '10', 'discount_type' => PERCENT, 'item_unit_price' => '75', 'description' => 'a', 'serialnumber' => '', 'print_option' => 0, 'invoice_discount_share' => '13.0000'],
            (object) ['item_id' => 4, 'item_location' => 1, 'quantity_purchased' => '1', 'discount' => '0', 'discount_type' => PERCENT, 'item_unit_price' => '220', 'description' => 'b', 'serialnumber' => '', 'print_option' => 0, 'invoice_discount_share' => '22.5000'],
        ], SUSPENDED, ['type' => PERCENT, 'value' => '10.0000']);

        $cart = $saleLib->get_cart();

        $this->assertSame('13.5000', $cart[1]['invoice_discount_share'], 'what the lines come to now, not what was saved');
        $this->assertSame('22.0000', $cart[2]['invoice_discount_share']);
        $this->assertSame(['type' => PERCENT, 'value' => '10'], $saleLib->getInvoiceDiscount());
    }

    public function testCopyEntireSale_LeavesASaleWithoutADiscountWithoutShares(): void
    {
        $saleLib = $this->loadSale([
            (object) ['item_id' => 3, 'item_location' => 1, 'quantity_purchased' => '2', 'discount' => '10', 'discount_type' => PERCENT, 'item_unit_price' => '75', 'description' => 'a', 'serialnumber' => '', 'print_option' => 0, 'invoice_discount_share' => null],
        ], COMPLETED, null);

        $this->assertArrayNotHasKey('invoice_discount_share', $saleLib->get_cart()[1]);
        $this->assertNull($saleLib->getInvoiceDiscount());
    }

    public function testQuoteSource_KeepsTheQuoteTheInvoiceIsMadeFromAndTakesItBack(): void
    {
        $this->assertNull($this->saleLib->getQuoteSource());

        $this->saleLib->setQuoteSource(12);

        $this->assertSame(12, $this->saleLib->getQuoteSource());

        $this->saleLib->clearQuoteSource();

        $this->assertNull($this->saleLib->getQuoteSource());

        $this->saleLib->setQuoteSource(12);
        $this->saleLib->setQuoteSource(null);

        $this->assertNull($this->saleLib->getQuoteSource());
    }

    public function testClearAll_ForgetsTheQuoteTheInvoiceWasMadeFromSoTheNextSaleDoesNotConvertIt(): void
    {
        $this->saleLib->setQuoteSource(12);

        $this->saleLib->clear_all();

        $this->assertNull($this->saleLib->getQuoteSource());
    }

    public function testCopyEntireSale_GivesBackTheQuoteADraftWasMadeFromAndNothingForASaleThatCameFromNone(): void
    {
        $line = (object) ['item_id' => 3, 'item_location' => 1, 'quantity_purchased' => '2', 'discount' => '0', 'discount_type' => PERCENT, 'item_unit_price' => '75', 'description' => 'a', 'serialnumber' => '', 'print_option' => 0, 'invoice_discount_share' => null];

        $this->assertSame(12, $this->loadSale([$line], SUSPENDED, null, SALE_TYPE_INVOICE, null, 12)->getQuoteSource());
        $this->assertNull($this->loadSale([$line], SUSPENDED, null)->getQuoteSource());
    }

    public function testPrepareInvoiceFromQuote_FillsTheRegisterWithANewInvoiceThatKeepsTheLinesOfTheQuoteAndNamesIt(): void
    {
        $line    = (object) ['item_id' => 3, 'item_location' => 1, 'quantity_purchased' => '2', 'discount' => '10', 'discount_type' => PERCENT, 'item_unit_price' => '75', 'description' => 'a', 'serialnumber' => '', 'print_option' => 0, 'invoice_discount_share' => null];
        $saleLib = $this->loadSale([$line], SUSPENDED, ['type' => PERCENT, 'value' => '5'], SALE_TYPE_QUOTE, 'Q-0007');

        $saleLib->prepareInvoiceFromQuote(9);

        $cart = $saleLib->get_cart();

        $this->assertCount(1, $cart);
        $this->assertSame('2', $cart[1]['quantity']);
        $this->assertSame('75', $cart[1]['price']);
        $this->assertSame('sale_invoice', $saleLib->get_mode());
        $this->assertSame(SALE_TYPE_INVOICE, $saleLib->getSaleType());
        $this->assertSame(NEW_ENTRY, $saleLib->get_sale_id(), 'a new invoice, not the quote itself');
        $this->assertSame(9, $saleLib->getQuoteSource());
        $this->assertNull($saleLib->get_quote_number(), 'the invoice does not carry the number of the quote');
        $this->assertSame(['type' => PERCENT, 'value' => '5'], $saleLib->getInvoiceDiscount());
    }

    public function testPrepareInvoiceFromQuote_ForgetsWhatTheRegisterHeldBefore(): void
    {
        $line    = (object) ['item_id' => 3, 'item_location' => 1, 'quantity_purchased' => '2', 'discount' => '0', 'discount_type' => PERCENT, 'item_unit_price' => '75', 'description' => 'a', 'serialnumber' => '', 'print_option' => 0, 'invoice_discount_share' => null];
        $saleLib = $this->loadSale([$line], SUSPENDED, null, SALE_TYPE_QUOTE, 'Q-0007');

        session()->set('sales_cart', [5 => ['item_id' => 99, 'item_location' => 1, 'line' => 5, 'quantity' => '1', 'price' => '1', 'discount' => '0', 'discount_type' => PERCENT, 'stock_type' => HAS_STOCK]]);
        $saleLib->setInvoiceDate('2026-10-20');
        $saleLib->setPaymentTerms('30');
        $saleLib->setQuoteSource(4);
        $saleLib->set_invoice_number('TYPED-OLD');
        session()->set('sales_email_receipt', '1');

        $saleLib->prepareInvoiceFromQuote(9);

        $this->assertNull($saleLib->get_invoice_number(), 'the number typed for the sale that was there before');
        $this->assertNull(session()->get('sales_email_receipt'), 'the choice made for the sale that was there before');
        $this->assertSame([3], array_values(array_map(static fn (array $row): int => (int) $row['item_id'], $saleLib->get_cart())));
        $this->assertNull($saleLib->getInvoiceDate());
        $this->assertNull($saleLib->getPaymentTerms());
        $this->assertSame(9, $saleLib->getQuoteSource(), 'the quote of this conversion and not an earlier one');
    }

    /**
     * A register whose item carries two taxes, the first inside the price and the second added to it,
     * for a walk-in customer.
     *
     * @param array<string, array{included: bool|null, account: int|null}> $definitions
     */
    private function registerWithTaxes(bool $defaultIncluded = false, array $definitions = ['vat' => ['included' => true, 'account' => null], 'service' => ['included' => false, 'account' => null]], bool $byDestination = false): Sale_lib
    {
        $ospos           = new OSPOS();
        $ospos->settings = ['cash_rounding_code' => '', 'cash_decimals' => 2, 'currency_decimals' => 2, 'tax_decimals' => 2, 'quantity_decimals' => 2, 'tax_included' => $defaultIncluded ? '1' : '0', 'use_destination_based_tax' => $byDestination ? '1' : '0'];
        Factories::injectMock('config', OSPOS::class, $ospos);

        $customer = $this->createStub(Customer::class);
        $customer->method('get_info')->willReturn((object) ['taxable' => 1, 'city' => '', 'state' => '', 'sales_tax_code_id' => 0]);
        Factories::injectMock('models', Customer::class, $customer);

        $itemTaxes = $this->createStub(Item_taxes::class);
        $itemTaxes->method('get_info')->willReturn([['name' => 'VAT', 'percent' => '15'], ['name' => 'Service', 'percent' => '5']]);
        Factories::injectMock('models', Item_taxes::class, $itemTaxes);

        $list = $this->createStub(Tax_definition::class);
        $list->method('forNames')->willReturnCallback(static fn (array $names): array => array_intersect_key($definitions, array_flip(array_map(Tax_definition::key(...), $names))));
        Factories::injectMock('models', Tax_definition::class, $list);

        session()->destroy();

        $saleLib = new Sale_lib();
        $saleLib->set_cart([1 => ['item_id' => 3, 'item_location' => 1, 'line' => 1, 'quantity' => '1', 'price' => '100', 'discount' => '0', 'discount_type' => PERCENT, 'stock_type' => HAS_STOCK]]);

        return $saleLib;
    }

    public function testGetTotal_AddsOnlyTheTaxesThatAreAddedToThePrice(): void
    {
        $this->assertSame('104.3500', $this->fourDecimals($this->registerWithTaxes()->get_total()), 'the tax inside the price is already in it, and the other is charged on what is left of it');
    }

    public function testGetTotal_AddsNothingWhenEveryTaxIsInsideThePrice(): void
    {
        $saleLib = $this->registerWithTaxes(true, []);

        $this->assertSame('100.0000', $this->fourDecimals($saleLib->get_total()));
    }

    public function testGetTotal_AddsEveryTaxWhenNoneIsInsideThePrice(): void
    {
        $saleLib = $this->registerWithTaxes(false, []);

        $this->assertSame('120.0000', $this->fourDecimals($saleLib->get_total()), '15 and 5 on 100');
    }

    public function testGetSubtotal_TakesOnlyTheTaxInsideThePriceOutOfTheLineWhenAskedForItWithoutTax(): void
    {
        $saleLib = $this->registerWithTaxes();

        $this->assertSame('86.9565', $this->fourDecimals($saleLib->get_subtotal(true, true)));
        $this->assertSame('100.0000', $this->fourDecimals($saleLib->get_subtotal(true, false)));
    }

    public function testGetSubtotal_LeavesTheLineAloneWhenNoTaxIsInsideThePrice(): void
    {
        $this->assertSame('100.0000', $this->fourDecimals($this->registerWithTaxes(false, [])->get_subtotal(true, true)));
    }

    public function testGetSubtotal_LeavesTheLineAloneWhenTheShopTaxesByDestination(): void
    {
        $saleLib = $this->registerWithTaxes(false, ['vat' => ['included' => true, 'account' => null]], true);

        $this->assertSame('100.0000', $this->fourDecimals($saleLib->get_subtotal(true, true)), 'the taxes by destination are not those of the item');
    }

    public function testGetItemTotalTaxExclusive_TakesTheTaxInsideThePriceOutOfTheItemAndLeavesTheOneAddedToIt(): void
    {
        $this->assertSame('86.9565', $this->fourDecimals($this->registerWithTaxes()->get_item_total_tax_exclusive(3, '1', '100', '0', PERCENT, true)));
    }

    public function testGetTotals_TakesTheTaxInsideThePriceOffTheSubtotalAndAddsTheOneAddedToItToTheTotal(): void
    {
        $saleLib = $this->registerWithTaxes();

        $totals = $saleLib->get_totals([
            'a' => ['tax_type' => Tax_lib::TAX_TYPE_INCLUDED, 'sale_tax_amount' => '13.0435'],
            'b' => ['tax_type' => Tax_lib::TAX_TYPE_EXCLUDED, 'sale_tax_amount' => '4.35'],
        ]);

        $this->assertSame('86.9565', $this->fourDecimals($totals['subtotal']));
        $this->assertSame('104.3500', $this->fourDecimals($totals['total']));
        $this->assertSame('4.3500', $this->fourDecimals($totals['tax_total']));
    }

    public function testGetItemTax_WorksTheTaxOutOfThePriceOrOnTopOfItAsTheModeItIsGivenSays(): void
    {
        $this->assertSame('15.0000', $this->fourDecimals($this->saleLib->get_item_tax('1', '115', '0', PERCENT, '15', '0', true)));
        $this->assertSame('17.2500', $this->fourDecimals($this->saleLib->get_item_tax('1', '115', '0', PERCENT, '15', '0', false)));
        $this->assertSame('17.2500', $this->fourDecimals($this->saleLib->get_item_tax('1', '115', '0', PERCENT, '15')), 'without a mode the default of the shop decides');
    }

    private function vat(string $amount = '53.25'): array
    {
        return ['VAT' => ['tax_type' => Tax_lib::TAX_TYPE_EXCLUDED, 'sale_tax_amount' => $amount]];
    }

    public function testGetTotals_AddsTheShippingTheTaxOnItAndTheAdjustmentToTheTotalOnly(): void
    {
        $this->saleLib->set_cart($this->referenceCart());
        $this->saleLib->setHeaderCharges(new HeaderCharges('Rounding', '-10', '100', ShippingTaxOption::Added, '15'));

        $totals = $this->saleLib->get_totals($this->vat());

        $this->assertSame('355.0000', $this->fourDecimals($totals['subtotal']), 'the charges are not part of the subtotal of the lines');
        $this->assertSame('513.2500', $this->fourDecimals($totals['total']));
        $this->assertSame('408.2500', $this->fourDecimals($totals['items_total']));
        $this->assertSame('100.0000', $totals['shipping']);
        $this->assertSame('15.0000', $totals['shipping_tax']);
        $this->assertSame('-10.0000', $totals['adjustment']);
        $this->assertSame('Rounding', $totals['adjustment_label']);
        $this->assertSame('513.2500', $this->fourDecimals($totals['amount_due']));
    }

    public function testGetTotals_LeavesTheTaxOnShippingInsideTheAmountWhenTheTaxIsIncluded(): void
    {
        $this->saleLib->set_cart($this->referenceCart());
        $this->saleLib->setHeaderCharges(new HeaderCharges(shipping: '100', shippingTaxOption: ShippingTaxOption::Included, shippingTaxPercent: '15'));

        $totals = $this->saleLib->get_totals($this->vat());

        $this->assertSame('508.2500', $this->fourDecimals($totals['total']));
        $this->assertSame('13.0435', $totals['shipping_tax']);
        $this->assertTrue($totals['shipping_tax_included']);
    }

    public function testGetTotals_IgnoresAnAdjustmentWhenTheLinesCameToNothing(): void
    {
        $this->saleLib->set_cart([1 => ['item_id' => 3, 'item_location' => 1, 'line' => 1, 'quantity' => '1', 'price' => '0', 'discount' => '0', 'discount_type' => PERCENT, 'stock_type' => HAS_STOCK]]);
        $this->saleLib->setHeaderCharges(new HeaderCharges('Fee', '50'));

        $totals = $this->saleLib->get_totals([]);

        $this->assertSame('0.0000', $this->fourDecimals($totals['total']));
        $this->assertSame('0.0000', $this->fourDecimals($totals['adjustment']));
    }

    public function testGetTotals_CountsTheAdjustmentOnceTheShippingMakesTheTotalPositive(): void
    {
        $this->saleLib->set_cart([1 => ['item_id' => 3, 'item_location' => 1, 'line' => 1, 'quantity' => '1', 'price' => '0', 'discount' => '0', 'discount_type' => PERCENT, 'stock_type' => HAS_STOCK]]);
        $this->saleLib->setHeaderCharges(new HeaderCharges('Fee', '-5', '20'));

        $this->assertSame('15.0000', $this->fourDecimals($this->saleLib->get_totals([])['total']));
    }

    public function testGetTotals_GivesAReturnTheChargesInTheDirectionOfTheCredit(): void
    {
        $cart = $this->referenceCart();
        $cart[1]['quantity'] = '-2';
        $cart[2]['quantity'] = '-1';

        $this->saleLib->set_cart($cart);
        $this->saleLib->setHeaderCharges(new HeaderCharges('Goodwill', '-5', '20'));

        $totals = $this->saleLib->get_totals($this->vat('-53.25'));

        $this->assertSame('-20.0000', $totals['shipping']);
        $this->assertSame('5.0000', $totals['adjustment']);
        $this->assertSame('-423.2500', $this->fourDecimals($totals['total']));
    }

    public function testGetTotals_GivesTheSameTotalWithoutChargesAsBefore(): void
    {
        $this->saleLib->set_cart($this->referenceCart());

        $totals = $this->saleLib->get_totals($this->vat());

        $this->assertSame('408.2500', $this->fourDecimals($totals['total']));
        $this->assertSame('0.0000', $totals['shipping']);
        $this->assertSame('0.0000', $totals['adjustment']);
        $this->assertSame('', $totals['adjustment_label']);
    }

    public function testGetTotals_AddsNoChargesToAnEmptyCart(): void
    {
        $this->saleLib->setHeaderCharges(new HeaderCharges('Fee', '10', '5'));

        $this->assertSame('0.0000', $this->fourDecimals($this->saleLib->get_totals([])['total']));
    }

    public function testGetTotal_CountsTheChargesSoItIsTheTotalTheRegisterShows(): void
    {
        $this->saleLib->set_cart($this->referenceCart());
        $this->saleLib->setHeaderCharges(new HeaderCharges('Rounding', '-10', '100', ShippingTaxOption::Added, '15'));

        $this->assertSame('460.0000', $this->fourDecimals($this->saleLib->get_total()));
        $this->assertSame($this->fourDecimals($this->saleLib->get_totals([])['total']), $this->fourDecimals($this->saleLib->get_total()));
    }

    public function testGetTotal_CountsTheChargesInTheDirectionOfAReturn(): void
    {
        $cart = $this->referenceCart();
        $cart[1]['quantity'] = '-2';
        $cart[2]['quantity'] = '-1';

        $this->saleLib->set_cart($cart);
        $this->saleLib->setHeaderCharges(new HeaderCharges('Goodwill', '-5', '20'));

        $this->assertSame('-370.0000', $this->fourDecimals($this->saleLib->get_total()));
    }

    public function testGetTotal_IsTheTotalOfTheLinesWhenThereAreNoCharges(): void
    {
        $this->saleLib->set_cart($this->referenceCart());

        $this->assertSame('355.0000', $this->fourDecimals($this->saleLib->get_total()));
    }

    public function testGetTotal_RoundsTheTotalWithTheChargesAndNotTheLinesAloneWhenCashIsRounded(): void
    {
        config(OSPOS::class)->settings['cash_rounding_code'] = PHP_ROUND_HALF_UP;
        config(OSPOS::class)->settings['cash_decimals']      = 0;

        $saleLib = new Sale_lib();
        $saleLib->set_cart($this->referenceCart());
        $saleLib->setHeaderCharges(new HeaderCharges(shipping: '0.60'));
        session()->set('cash_mode', CASH_MODE_TRUE);

        $this->assertSame('356', (string) $saleLib->get_total());
        $this->assertSame('355.6000', $this->fourDecimals($saleLib->get_total(false)));
    }

    public function testGetAmountDue_IsWhatTheChargesLeaveOnceThePaymentsAreTaken(): void
    {
        $this->saleLib->set_cart($this->referenceCart());
        $this->saleLib->setHeaderCharges(new HeaderCharges('Rounding', '-30', '0'));

        $this->assertSame('325.0000', $this->fourDecimals($this->saleLib->get_amount_due()));

        $this->saleLib->addPayment(lang('Sales.cash'), '300');

        $this->assertSame('25.0000', $this->fourDecimals($this->saleLib->get_amount_due()));
        $this->assertSame('25.0000', $this->fourDecimals($this->saleLib->get_totals([])['amount_due']));
    }

    public function testSetHeaderCharges_KeepsWhatWasTypedAndForgetsItWhenEmptied(): void
    {
        $this->saleLib->setHeaderCharges(new HeaderCharges('Fee', '10.5', '7', ShippingTaxOption::Added, '15', 2));

        $kept = $this->saleLib->getHeaderCharges();

        $this->assertSame(['Fee', '10.5', '7', ShippingTaxOption::Added, '15', 2], [$kept->adjustmentLabel, $kept->adjustment, $kept->shipping, $kept->shippingTaxOption, $kept->shippingTaxPercent, $kept->shippingOptions]);

        $this->saleLib->setHeaderCharges(HeaderCharges::none());

        $this->assertTrue($this->saleLib->getHeaderCharges()->isEmpty());
        $this->assertNull(session()->get('sales_header_charges'));
    }

    public function testClearAll_ForgetsTheChargesOfTheHeaderSoTheNextSaleDoesNotInheritThem(): void
    {
        $this->saleLib->setHeaderCharges(new HeaderCharges('Fee', '10'));
        $this->saleLib->clear_all();

        $this->assertTrue($this->saleLib->getHeaderCharges()->isEmpty());
    }

    public function testHeaderChargesProblem_NamesAnAdjustmentThatWouldTakeTheTotalBelowZero(): void
    {
        $this->saleLib->set_cart($this->referenceCart());
        $this->saleLib->setHeaderCharges(new HeaderCharges('Fee', '-500'));

        $this->assertSame(HeaderChargesProblem::ExceedsTotal, $this->saleLib->headerChargesProblem($this->vat()));

        $this->saleLib->setHeaderCharges(new HeaderCharges('Fee', '-408.25'));

        $this->assertNull($this->saleLib->headerChargesProblem($this->vat()));
    }

    public function testCopyEntireSale_GivesBackTheChargesTheSaleWasSavedWith(): void
    {
        $saleLib = $this->loadSale([
            (object) ['item_id' => 3, 'item_location' => 1, 'quantity_purchased' => '2', 'discount' => '10', 'discount_type' => PERCENT, 'item_unit_price' => '75', 'description' => 'a', 'serialnumber' => '', 'print_option' => 0, 'invoice_discount_share' => null],
        ], COMPLETED, null, charges: new HeaderCharges('Fee', '12.5', '20', ShippingTaxOption::Added, '15', 3));

        $charges = $saleLib->getHeaderCharges();

        $this->assertSame('Fee', $charges->adjustmentLabel);
        $this->assertSame('12.5', $charges->adjustment);
        $this->assertSame('20', $charges->shipping);
        $this->assertSame(3, $charges->shippingOptions);
    }

    public function testCopyEntireSale_ForgetsTheChargesTheRegisterHeldBefore(): void
    {
        $this->saleLib->setHeaderCharges(new HeaderCharges('Old', '99'));

        $saleLib = $this->loadSale([
            (object) ['item_id' => 3, 'item_location' => 1, 'quantity_purchased' => '2', 'discount' => '10', 'discount_type' => PERCENT, 'item_unit_price' => '75', 'description' => 'a', 'serialnumber' => '', 'print_option' => 0, 'invoice_discount_share' => null],
        ], COMPLETED, null);

        $this->assertTrue($saleLib->getHeaderCharges()->isEmpty());
    }

    /**
     * @return array<string, mixed>
     */
    private function priced(string $price, string $source): array
    {
        return ['item_id' => 3, 'item_location' => 1, 'line' => 1, 'quantity' => '2', 'price' => $price, 'price_source' => $source, 'discount' => '0', 'discount_type' => PERCENT, 'stock_type' => HAS_NO_STOCK, 'total' => '0', 'discounted_total' => '0'];
    }

    public function testEditItem_MarksALineWhoseTypedPriceDiffersSoThatNoListPricesItAgain(): void
    {
        session()->set('sales_cart', [1 => $this->priced('10.00', 'list')]);

        $this->saleLib->edit_item('1', 'a', '', '2', '0', (string) PERCENT, '12.50');

        $this->assertSame('manual', $this->saleLib->get_cart()[1]['price_source']);
        $this->assertSame('12.50', $this->saleLib->get_cart()[1]['price']);
    }

    public function testEditItem_KeepsTheSourceOfAPriceWhenOnlyTheQuantityChanged(): void
    {
        session()->set('sales_cart', [1 => $this->priced('10.00', 'list')]);

        $this->saleLib->edit_item('1', 'a', '', '5', '0', (string) PERCENT, '10.0000');

        $this->assertSame('list', $this->saleLib->get_cart()[1]['price_source']);
    }

    public function testPriceListChoice_IsKeptForTheSaleAndForgottenWhenTheRegisterIsCleared(): void
    {
        $this->assertNull($this->saleLib->getPriceListChoice());

        $this->saleLib->setPriceListChoice(7);

        $this->assertSame(7, $this->saleLib->getPriceListChoice());

        $this->saleLib->clear_all();

        $this->assertNull($this->saleLib->getPriceListChoice());
    }

    private function fourDecimals(string $amount): string
    {
        return bcadd($amount, '0', 4);
    }

    public function testGetCurrency_IsNullUntilOneIsChosenAndClearCurrencyForgetsIt(): void
    {
        $this->assertNull($this->saleLib->getCurrency());

        $this->saleLib->setCurrency('USD');

        $this->assertSame('USD', $this->saleLib->getCurrency());

        $this->saleLib->clearCurrency();

        $this->assertNull($this->saleLib->getCurrency());
    }

    public function testGetCurrency_ReadsAnEmptyChoiceAsTheBase(): void
    {
        $this->saleLib->setCurrency('');

        $this->assertNull($this->saleLib->getCurrency());
    }

    public function testCurrencyRate_IsOneWhileTheSaleIsInTheBase(): void
    {
        $this->assertSame('1', $this->saleLib->currencyRate());
    }

    public function testCurrencyRate_IsOneForAReturnWhateverCurrencyTheSessionHolds(): void
    {
        $this->saleLib->setCurrency('USD');
        $this->saleLib->set_mode('return');

        $this->assertSame('1', $this->saleLib->currencyRate());
    }

    public function testCatalogPrice_LeavesThePriceAsItIsInTheBaseAndForAReturn(): void
    {
        $this->assertSame('375.00', $this->saleLib->catalogPrice('375.00'));

        $this->saleLib->setCurrency('USD');
        $this->saleLib->set_mode('return');

        $this->assertSame('375.00', $this->saleLib->catalogPrice('375.00'), 'a return is in the currency of the sale it takes back and is never priced from the catalog');
    }
}
