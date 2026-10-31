<?php

namespace Tests\Libraries;

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
use App\Models\Tax;
use App\Models\Tax_category;
use App\Models\Tax_code;
use App\Models\Tax_jurisdiction;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use Config\OSPOS;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\Support\ParityFixture;

/**
 * The tax of one line, checked against amounts recorded in the reference system (fixtures T-01
 * to T-03): 15% not included, rounded half up to two decimals.
 */
class Tax_libTest extends CIUnitTestCase
{
    private const FIXTURES = ['T-01', 'T-02', 'T-03'];

    protected function setUp(): void
    {
        parent::setUp();

        bcscale(4);
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function taxProvider(): array
    {
        $sets = [];

        foreach (self::FIXTURES as $fixture) {
            foreach (ParityFixture::cases($fixture, 'tax') as $name => [$case]) {
                $sets["{$fixture} {$name}"] = [$fixture, $case];
            }
        }

        return $sets;
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('taxProvider')]
    public function testGetTaxForAmount_MatchesTheTaxTheReferenceSystemComputed(string $fixture, array $case): void
    {
        $taxLib = (new ReflectionClass(Tax_lib::class))->newInstanceWithoutConstructor();

        $tax = $taxLib->get_tax_for_amount($case['inputs']['basis'], $case['inputs']['rate'], Rounding_mode::HALF_UP, 2);

        $this->assertSame(bcadd($case['expected']['tax'], '0', 2), bcadd($tax, '0', 2), $case['id']);
        $this->assertSame('exact', $case['classification']);
    }

    private function taxLib(bool $taxIncluded, bool $destinationBased = false): Tax_lib
    {
        $ospos           = new OSPOS();
        $ospos->settings = ['cash_rounding_code' => '', 'cash_decimals' => 2, 'currency_decimals' => 2, 'tax_decimals' => 3, 'quantity_decimals' => 2, 'tax_included' => $taxIncluded ? '1' : '0', 'use_destination_based_tax' => $destinationBased ? '1' : '0', 'default_tax_code' => 3, 'default_tax_category' => 1];
        Factories::injectMock('config', OSPOS::class, $ospos);

        foreach ([Attribute::class, Dinner_table::class, Item::class, Item_kit_items::class, Item_quantity::class, Sale::class, Stock_location::class, Tax::class, Tax_category::class, Tax_code::class, Tax_jurisdiction::class] as $model) {
            Factories::injectMock('models', $model, $this->getMockBuilder($model)->disableOriginalConstructor()->getMock());
        }

        $customer = $this->createStub(Customer::class);
        $customer->method('get_info')->willReturnCallback(static fn ($customerId): object => (object) ['taxable' => (int) $customerId === 5 ? 0 : 1, 'city' => '', 'state' => '', 'sales_tax_code_id' => (int) $customerId === 8 ? 7 : 0]);
        Factories::injectMock('models', Customer::class, $customer);

        $tax = $this->createStub(Tax::class);
        $tax->method('get_taxes')->willReturnCallback(static fn (int $taxCodeId, int $taxCategoryId): array => [[
            'cascade_sequence'     => 0,
            'tax_rate'             => $taxCodeId === 7 ? '10' : '20',
            'tax_rounding_code'    => Rounding_mode::HALF_UP,
            'tax_type'             => Tax_lib::TAX_TYPE_EXCLUDED,
            'tax_group'            => 'VAT',
            'rate_jurisdiction_id' => null,
            'rate_tax_category_id' => $taxCategoryId,
            'tax_group_sequence'   => 1,
        ]]);
        Factories::injectMock('models', Tax::class, $tax);

        $itemTaxes = $this->createStub(Item_taxes::class);
        $itemTaxes->method('get_info')->willReturn([['name' => 'VAT', 'percent' => '15']]);
        Factories::injectMock('models', Item_taxes::class, $itemTaxes);

        session()->destroy();

        return new Tax_lib();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function cart(string $firstShare, string $secondShare): array
    {
        $cart = [
            1 => ['item_id' => 3, 'line' => 1, 'quantity' => '2', 'price' => '75', 'discount' => '10', 'discount_type' => PERCENT],
            2 => ['item_id' => 4, 'line' => 2, 'quantity' => '1', 'price' => '220', 'discount' => '0', 'discount_type' => PERCENT],
        ];

        if ($firstShare !== '') {
            $cart[1]['invoice_discount_share'] = $firstShare;
            $cart[2]['invoice_discount_share'] = $secondShare;
        }

        return $cart;
    }

    public function testGetTaxes_WorksTheTaxOfEveryLineOutOnWhatIsLeftAfterItsShareOfTheDiscountOnTheWholeSale(): void
    {
        $cart  = $this->cart('13.5000', '22.0000');
        $taxes = $this->taxLib(false)->get_taxes($cart);

        $this->assertSame(['18.225', '29.700'], array_map(static fn (array $row): string => bcadd($row['item_tax_amount'], '0', 3), $taxes[1]));
        $this->assertSame('47.925', bcadd(array_sum(array_column($taxes[1], 'item_tax_amount')) . '', '0', 3));
    }

    public function testGetTaxes_WorksTheTaxOfALineWithAnAmountSharedOutOnWhatIsLeft(): void
    {
        $cart  = $this->cart('7.6100', '12.3900');
        $taxes = $this->taxLib(false)->get_taxes($cart);

        $this->assertSame(['19.109', '31.142'], array_map(static fn (array $row): string => bcadd($row['item_tax_amount'], '0', 3), $taxes[1]));
        $this->assertSame('50.25', bcadd((string) array_sum(array_column($taxes[1], 'item_tax_amount')), '0', 2));
    }

    public function testGetTaxes_TaxesALineAsItAlwaysDidWhenItCarriesNoShare(): void
    {
        $cart  = $this->cart('', '');
        $taxes = $this->taxLib(false)->get_taxes($cart);

        $this->assertSame(['20.250', '33.000'], array_map(static fn (array $row): string => bcadd($row['item_tax_amount'], '0', 3), $taxes[1]));
        $this->assertSame('53.25', bcadd((string) $taxes[0][array_key_first($taxes[0])]['sale_tax_amount'], '0', 2));
    }

    public function testGetTaxes_TaxesALineAsItAlwaysDidWhenItsShareIsWrittenAsNothing(): void
    {
        $cart  = $this->cart('0.0000', '0.0000');
        $taxes = $this->taxLib(false)->get_taxes($cart);

        $this->assertSame(['20.250', '33.000'], array_map(static fn (array $row): string => bcadd($row['item_tax_amount'], '0', 3), $taxes[1]));
    }

    public function testGetTaxes_TakesTheTaxThatWasInsidePricesOutOfWhatIsLeftAfterTheShare(): void
    {
        $cart  = [1 => ['item_id' => 3, 'line' => 1, 'quantity' => '1', 'price' => '115', 'discount' => '0', 'discount_type' => PERCENT, 'invoice_discount_share' => '11.5000']];
        $taxes = $this->taxLib(true)->get_taxes($cart);

        $this->assertSame('13.500', bcadd($taxes[1][0]['item_tax_amount'], '0', 3));
        $this->assertSame((string) Tax_lib::TAX_TYPE_INCLUDED, (string) $taxes[1][0]['tax_type']);
    }

    public function testGetTaxes_TaxesALineOfAReturnOnWhatIsLeftAfterTheShareItTakesBack(): void
    {
        $cart  = [1 => ['item_id' => 3, 'line' => 1, 'quantity' => '-1', 'price' => '75', 'discount' => '10', 'discount_type' => PERCENT, 'invoice_discount_share' => '-6.7500']];
        $taxes = $this->taxLib(false)->get_taxes($cart);

        $this->assertSame('-9.113', bcadd($taxes[1][0]['item_tax_amount'], '0', 3));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function oneLine(): array
    {
        return [1 => ['item_id' => 3, 'line' => 1, 'quantity' => '1', 'price' => '100', 'discount' => '0', 'discount_type' => PERCENT, 'tax_category_id' => null]];
    }

    public function testGetTaxes_TaxesTheCustomerItIsGivenAndNotTheOneOfTheRegister(): void
    {
        $taxLib = $this->taxLib(false);

        $cart = $this->cart('', '');
        $this->assertNotSame([[], []], $taxLib->get_taxes($cart), 'the walk-in customer of the register is taxed');

        $cart = $this->cart('', '');
        $this->assertSame([[], []], $taxLib->get_taxes($cart, -1, 5), 'a customer who is not taxable is not, whoever the register holds');
    }

    public function testGetTaxes_TaxesWithTheCodeOfTheCustomerItIsGivenInTheModeItIsGiven(): void
    {
        $taxLib = $this->taxLib(false, true);

        $cart  = $this->oneLine();
        $taxes = $taxLib->get_taxes($cart, -1, 8, 'sale_invoice');

        $this->assertSame('10.000', bcadd($taxes[1][0]['item_tax_amount'], '0', 3), 'an invoice takes the code of the customer');
    }

    public function testGetTaxes_TaxesWithTheDefaultCodeInTheSaleModeWhateverCodeTheCustomerHas(): void
    {
        $taxLib = $this->taxLib(false, true);

        $cart  = $this->oneLine();
        $taxes = $taxLib->get_taxes($cart, -1, 8, 'sale');

        $this->assertSame('20.000', bcadd($taxes[1][0]['item_tax_amount'], '0', 3));
    }

    public function testGetTaxes_TaxesInTheModeOfTheRegisterWhenItIsGivenNone(): void
    {
        $taxLib = $this->taxLib(false, true);

        $cart  = $this->oneLine();
        $taxes = $taxLib->get_taxes($cart, -1, 8);

        $this->assertSame('20.000', bcadd($taxes[1][0]['item_tax_amount'], '0', 3), 'the register of the test sells, and a sale takes the default code');
    }

    public function testGetIncludedTax_WorksTheTaxOutOfWhatIsLeftAfterTheShare(): void
    {
        $taxLib = $this->taxLib(true);

        $this->assertSame('13.5000', bcadd($taxLib->get_included_tax('1', '115', '0', PERCENT, '15', 3, Rounding_mode::HALF_UP, '11.5'), '0', 4));
        $this->assertSame('15.0000', bcadd($taxLib->get_included_tax('1', '115', '0', PERCENT, '15', 3, Rounding_mode::HALF_UP), '0', 4));
    }
}
