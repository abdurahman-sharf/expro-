<?php

namespace Tests\Libraries;

use App\Libraries\Tax\LineVat;
use App\Libraries\Tax\LineVatSource;
use App\Libraries\Tax\VatClassificationError;
use App\Libraries\Tax\VatClassificationException;
use App\Libraries\Tax\VatCategoryTotal;
use App\Libraries\Tax\VatSnapshot;
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
use App\Models\Tax_definition;
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

    /**
     * @param array<string, array{included: bool|null, account: int|null}> $definitions     By the name in lower case.
     * @param list<array<string, mixed>>                                     $itemTaxes       What the items carry.
     * @param array<int, list<array<string, mixed>>>                         $storedTaxes     What a saved sale carries, by item.
     */
    private function taxLib(bool $taxIncluded, bool $destinationBased = false, array $definitions = [], array $itemTaxes = [['name' => 'VAT', 'percent' => '15']], array $storedTaxes = [], int $taxDecimals = 3): Tax_lib
    {
        $ospos           = new OSPOS();
        $ospos->settings = ['cash_rounding_code' => '', 'cash_decimals' => 2, 'currency_decimals' => 2, 'tax_decimals' => $taxDecimals, 'quantity_decimals' => 2, 'tax_included' => $taxIncluded ? '1' : '0', 'use_destination_based_tax' => $destinationBased ? '1' : '0', 'default_tax_code' => 3, 'default_tax_category' => 1];
        Factories::injectMock('config', OSPOS::class, $ospos);

        foreach ([Attribute::class, Dinner_table::class, Item::class, Item_kit_items::class, Item_quantity::class, Stock_location::class, Tax::class, Tax_category::class, Tax_code::class, Tax_jurisdiction::class] as $model) {
            Factories::injectMock('models', $model, $this->getMockBuilder($model)->disableOriginalConstructor()->getMock());
        }

        $sale = $this->createStub(Sale::class);
        $sale->method('get_sales_item_taxes')->willReturnCallback(static fn (int $saleId, int $itemId): array => $storedTaxes[$itemId] ?? []);
        Factories::injectMock('models', Sale::class, $sale);

        $list = $this->createStub(Tax_definition::class);
        $list->method('forNames')->willReturnCallback(static fn (array $names): array => array_intersect_key($definitions, array_flip(array_map(Tax_definition::key(...), $names))));
        Factories::injectMock('models', Tax_definition::class, $list);

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

        $item = $this->createStub(Item_taxes::class);
        $item->method('get_info')->willReturn($itemTaxes);
        Factories::injectMock('models', Item_taxes::class, $item);

        session()->destroy();

        $taxLib = new Tax_lib();
        $taxLib->useVatSnapshot($this->snapshot(false));

        return $taxLib;
    }

    /**
     * @param array<string, VatCategoryTotal>                    $saved
     * @param array<string, array{taxable: string, vat: string}> $left
     * @param list<string>                                       $exhausted
     */
    private function snapshot(bool $installed, array $saved = [], array $left = [], array $exhausted = []): VatSnapshot
    {
        $snapshot = $this->createStub(VatSnapshot::class);
        $snapshot->method('isInstalled')->willReturn($installed);
        $snapshot->method('totalsOf')->willReturn($saved);
        $snapshot->method('leftOf')->willReturn($left);
        $snapshot->method('exhaustedBy')->willReturn($exhausted);

        return $snapshot;
    }

    /**
     * @param list<string> $prices
     *
     * @return array<int, array<string, mixed>>
     */
    private function cartOf(array $prices, string $quantity = '1'): array
    {
        $cart = [];

        foreach ($prices as $index => $price) {
            $cart[$index + 1] = ['item_id' => $index + 3, 'line' => $index + 1, 'quantity' => $quantity, 'price' => $price, 'discount' => '0', 'discount_type' => PERCENT, 'tax_category_id' => null];
        }

        return $cart;
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function lineOf(string $price): array
    {
        return [1 => ['item_id' => 3, 'line' => 1, 'quantity' => '1', 'price' => $price, 'discount' => '0', 'discount_type' => PERCENT, 'tax_category_id' => null]];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<string>
     */
    private function amounts(array $rows): array
    {
        return array_map(static fn (array $row): string => bcadd((string) $row['item_tax_amount'], '0', 4), $rows);
    }

    public function testGetTaxes_ChargesAnIncludedAndAnAddedTaxOnTheSameNet(): void
    {
        $cart  = $this->lineOf('100');
        $taxes = $this->taxLib(false, false, ['vat' => ['included' => true, 'account' => null], 'service' => ['included' => false, 'account' => null]], [['name' => 'VAT', 'percent' => '15'], ['name' => 'Service', 'percent' => '5']], [], 4)->get_taxes($cart);

        $this->assertSame(['13.0435', '4.3478'], $this->amounts($taxes[1]));
        $this->assertSame([Tax_lib::TAX_TYPE_INCLUDED, Tax_lib::TAX_TYPE_EXCLUDED], array_column($taxes[1], 'tax_type'));
    }

    public function testGetTaxes_ChargesTheAddedTaxOfAMixedLineOnTheNetAndNotOnThePrice(): void
    {
        $cart  = $this->lineOf('100');
        $taxes = $this->taxLib(false, false, ['vat' => ['included' => true, 'account' => null]], [['name' => 'VAT', 'percent' => '15'], ['name' => 'Service', 'percent' => '5']], [], 4)->get_taxes($cart);
        $group = array_values($taxes[0]);

        $this->assertSame('100.0000', bcadd((string) $group[0]['sale_tax_basis'], '0', 4), 'the tax inside the price keeps the price as its basis');
        $this->assertSame('86.9565', bcadd((string) $group[1]['sale_tax_basis'], '0', 4), 'the tax that is added is charged on what is left of the price');
        $this->assertSame(Tax_lib::TAX_TYPE_EXCLUDED, (string) $group[1]['tax_type']);
    }

    public function testGetTaxes_TakesSeveralIncludedTaxesOutOfThePriceTogether(): void
    {
        $cart  = $this->lineOf('115');
        $taxes = $this->taxLib(true, false, [], [['name' => 'VAT', 'percent' => '10'], ['name' => 'Levy', 'percent' => '5']], [], 4)->get_taxes($cart);

        $this->assertSame(['10.0000', '5.0000'], $this->amounts($taxes[1]), 'each is charged on the net of 100, not on the price');
        $this->assertSame([Tax_lib::TAX_TYPE_INCLUDED, Tax_lib::TAX_TYPE_INCLUDED], array_column($taxes[1], 'tax_type'));
    }

    public function testGetTaxes_FindsTheTaxOfAnIncludedTenPercentOnALineOfOneHundred(): void
    {
        $cart  = $this->lineOf('100');
        $taxes = $this->taxLib(false, false, ['inclusive' => ['included' => true, 'account' => null]], [['name' => 'Inclusive', 'percent' => '10']], [], 4)->get_taxes($cart);

        $this->assertSame('9.09', bcadd($this->amounts($taxes[1])[0], '0', 2), 'T-03 of batch C: the tax is 9.09');
    }

    public function testLineTaxes_LeavesTheNetOfAnIncludedTenPercentOnALineOfOneHundredAsTheBasisOfAnAddedTax(): void
    {
        $item  = ['quantity' => '1', 'price' => '100', 'discount' => '0', 'discount_type' => PERCENT];
        $taxes = $this->taxLib(false)->lineTaxes($item, [['name' => 'Inclusive', 'percent' => '10'], ['name' => 'Added', 'percent' => '0']], ['inclusive' => ['included' => true, 'account' => null]], 4);

        $this->assertSame('100.00', number_format((float) $taxes[0]['basis'], 2, '.', ''), 'the tax inside the price keeps the price as its basis');
        $this->assertSame('90.91', number_format((float) $taxes[1]['basis'], 2, '.', ''), 'T-03 of batch C: what is left of the price is 90.91');
    }

    public function testGetTaxes_FollowsTheDefaultModeForATaxTheListDoesNotHold(): void
    {
        foreach ([[true, Tax_lib::TAX_TYPE_INCLUDED], [false, Tax_lib::TAX_TYPE_EXCLUDED]] as [$default, $type]) {
            $cart  = $this->lineOf('100');
            $taxes = $this->taxLib($default)->get_taxes($cart);

            $this->assertSame($type, (string) $taxes[1][0]['tax_type'], $default ? 'included by default' : 'added by default');
        }
    }

    public function testGetTaxes_FollowsTheDefaultModeForATaxTheListHoldsWithoutAMode(): void
    {
        $cart  = $this->lineOf('100');
        $taxes = $this->taxLib(true, false, ['vat' => ['included' => null, 'account' => 41]])->get_taxes($cart);

        $this->assertSame(Tax_lib::TAX_TYPE_INCLUDED, (string) $taxes[1][0]['tax_type']);
        $this->assertSame(41, $taxes[1][0]['liability_account_id'], 'and the account of the tax is still the one the list gives it');
    }

    public function testGetTaxes_PrefersTheModeOfTheTaxToTheDefaultMode(): void
    {
        foreach ([[true, false, Tax_lib::TAX_TYPE_EXCLUDED], [false, true, Tax_lib::TAX_TYPE_INCLUDED]] as [$default, $own, $type]) {
            $cart  = $this->lineOf('100');
            $taxes = $this->taxLib($default, false, ['vat' => ['included' => $own, 'account' => null]])->get_taxes($cart);

            $this->assertSame($type, (string) $taxes[1][0]['tax_type']);
        }
    }

    public function testGetTaxes_MatchesTheNameOfTheTaxInTheListWhateverItsCase(): void
    {
        $cart  = $this->lineOf('100');
        $taxes = $this->taxLib(false, false, ['vat' => ['included' => true, 'account' => null]], [['name' => ' vat ', 'percent' => '15']])->get_taxes($cart);

        $this->assertSame(Tax_lib::TAX_TYPE_INCLUDED, (string) $taxes[1][0]['tax_type']);
    }

    public function testGetTaxes_GivesANewSaleTheAccountOfEachTaxAndNoneToATaxWithoutOne(): void
    {
        $cart  = $this->lineOf('100');
        $taxes = $this->taxLib(false, false, ['vat' => ['included' => null, 'account' => 41]], [['name' => 'VAT', 'percent' => '15'], ['name' => 'Service', 'percent' => '5']])->get_taxes($cart);

        $this->assertSame([41, null], array_column($taxes[1], 'liability_account_id'));
    }

    public function testGetTaxes_ReadsTheModeAndTheAccountOfASavedSaleFromWhatItWasSavedWith(): void
    {
        $stored = [3 => [['item_id' => 3, 'name' => 'VAT', 'percent' => '15', 'tax_type' => '1', 'liability_account_id' => 77]]];
        $cart   = $this->lineOf('100');
        $taxes  = $this->taxLib(true, false, ['vat' => ['included' => true, 'account' => 41]], [['name' => 'VAT', 'percent' => '15']], $stored)->get_taxes($cart, 12);

        $this->assertSame([Tax_lib::TAX_TYPE_EXCLUDED], array_column($taxes[1], 'tax_type'), 'the list and the default of today do not change a saved sale');
        $this->assertSame([77], array_column($taxes[1], 'liability_account_id'));
        $this->assertSame(['15.0000'], $this->amounts($taxes[1]));
    }

    public function testGetTaxes_TakesAReturnsTaxesFromTheSaleItTakesGoodsBackFrom(): void
    {
        $stored = [3 => [['item_id' => 3, 'name' => 'VAT', 'percent' => '20', 'tax_type' => '0', 'liability_account_id' => 77]]];
        $taxLib = $this->taxLib(false, false, ['vat' => ['included' => false, 'account' => 41]], [['name' => 'VAT', 'percent' => '15']], $stored);

        session()->set('sales_mode', 'return');
        session()->set('sales_return_origin', 12);

        $cart  = [1 => ['item_id' => 3, 'line' => 1, 'quantity' => '-1', 'price' => '120', 'discount' => '0', 'discount_type' => PERCENT, 'tax_category_id' => null]];
        $taxes = $taxLib->get_taxes($cart);

        $this->assertSame(['-20.0000'], $this->amounts($taxes[1]), 'a tax of 20% inside the price, as the sale charged it');
        $this->assertSame([Tax_lib::TAX_TYPE_INCLUDED], array_column($taxes[1], 'tax_type'));
        $this->assertSame([77], array_column($taxes[1], 'liability_account_id'));
    }

    public function testGetTaxes_ChargesAReturnWithNoSaleToTakeBackFromWhatTheItemsCarry(): void
    {
        $taxLib = $this->taxLib(false);

        session()->set('sales_mode', 'return');

        $cart  = [1 => ['item_id' => 3, 'line' => 1, 'quantity' => '-1', 'price' => '100', 'discount' => '0', 'discount_type' => PERCENT, 'tax_category_id' => null]];
        $taxes = $taxLib->get_taxes($cart);

        $this->assertSame(['-15.0000'], $this->amounts($taxes[1]));
    }

    public function testGetTaxes_ChargesNoTaxOnAReturnOfASaleThatCarriedNone(): void
    {
        $taxLib = $this->taxLib(false, false, [], [['name' => 'VAT', 'percent' => '15']], []);

        session()->set('sales_mode', 'return');
        session()->set('sales_return_origin', 12);

        $cart  = [1 => ['item_id' => 3, 'line' => 1, 'quantity' => '-1', 'price' => '100', 'discount' => '0', 'discount_type' => PERCENT, 'tax_category_id' => null]];
        $taxes = $taxLib->get_taxes($cart);

        $this->assertSame([[], []], $taxes);
    }

    public function testGetTaxes_KeepsTheTwoModesOfOneTaxInGroupsOfTheirOwn(): void
    {
        $stored = [
            3 => [['item_id' => 3, 'name' => 'VAT', 'percent' => '15', 'tax_type' => '0', 'liability_account_id' => null]],
            4 => [['item_id' => 4, 'name' => 'VAT', 'percent' => '15', 'tax_type' => '1', 'liability_account_id' => null]],
        ];
        $cart = [
            1 => ['item_id' => 3, 'line' => 1, 'quantity' => '1', 'price' => '115', 'discount' => '0', 'discount_type' => PERCENT, 'tax_category_id' => null],
            2 => ['item_id' => 4, 'line' => 2, 'quantity' => '1', 'price' => '100', 'discount' => '0', 'discount_type' => PERCENT, 'tax_category_id' => null],
        ];

        $taxes = $this->taxLib(false, false, [], [], $stored)->get_taxes($cart, 12);

        $this->assertCount(2, $taxes[0]);
        $this->assertSame([Tax_lib::TAX_TYPE_INCLUDED, Tax_lib::TAX_TYPE_EXCLUDED], array_values(array_map(static fn (array $group): string => (string) $group['tax_type'], $taxes[0])));
        $this->assertSame(['15.00', '15.00'], array_values(array_map(static fn (array $group): string => number_format((float) $group['sale_tax_amount'], 2, '.', ''), $taxes[0])));
    }

    /**
     * The rows a sale saved before the taxes were taken out of the price together: 10% and 5% inside a
     * price of 115 were taken out of the whole price one after the other.
     *
     * @return array<int, list<array<string, mixed>>>
     */
    private function savedWithTwoTaxesInsideThePrice(string $soldQuantity = '1', string $vat = '10.4545', string $levy = '5.4762'): array
    {
        return [3 => [
            ['item_id' => 3, 'name' => 'VAT', 'percent' => '10', 'tax_type' => '0', 'liability_account_id' => null, 'item_tax_amount' => $vat, 'sold_quantity' => $soldQuantity],
            ['item_id' => 3, 'name' => 'Levy', 'percent' => '5', 'tax_type' => '0', 'liability_account_id' => null, 'item_tax_amount' => $levy, 'sold_quantity' => $soldQuantity],
        ]];
    }

    public function testGetTaxes_KeepsTheAmountsASaleSavedForSeveralTaxesInsideThePriceWhenItIsPrintedAgain(): void
    {
        $cart  = $this->lineOf('115');
        $taxes = $this->taxLib(true, false, [], [['name' => 'VAT', 'percent' => '10'], ['name' => 'Levy', 'percent' => '5']], $this->savedWithTwoTaxesInsideThePrice(), 4)->get_taxes($cart, 12);

        $this->assertSame(['10.4545', '5.4762'], $this->amounts($taxes[1]), 'not the 10 and 5 the arithmetic of today would make of the price');
        $this->assertSame(['10.4545', '5.4762'], array_values(array_map(static fn (array $group): string => bcadd((string) $group['sale_tax_amount'], '0', 4), $taxes[0])));
    }

    public function testGetTaxes_GivesBackExactlyWhatASaleSavedForSeveralTaxesInsideThePriceWhenItsWholeIsReturned(): void
    {
        session()->set('sales_mode', 'return');
        session()->set('sales_return_origin', 12);

        $taxLib = $this->taxLib(true, false, [], [['name' => 'VAT', 'percent' => '10'], ['name' => 'Levy', 'percent' => '5']], $this->savedWithTwoTaxesInsideThePrice(), 4);
        $cart   = [1 => ['item_id' => 3, 'line' => 1, 'quantity' => '-1', 'price' => '115', 'discount' => '0', 'discount_type' => PERCENT, 'tax_category_id' => null]];
        $taxes  = $taxLib->get_taxes($cart);

        $this->assertSame(['-10.4545', '-5.4762'], $this->amounts($taxes[1]));
        $this->assertSame([Tax_lib::TAX_TYPE_INCLUDED, Tax_lib::TAX_TYPE_INCLUDED], array_column($taxes[1], 'tax_type'));
    }

    public function testGetTaxes_GivesBackTheShareOfWhatASaleSavedForSeveralTaxesInsideThePriceThatTheReturnedUnitsAre(): void
    {
        session()->set('sales_mode', 'return');
        session()->set('sales_return_origin', 12);

        $taxLib = $this->taxLib(true, false, [], [['name' => 'VAT', 'percent' => '10'], ['name' => 'Levy', 'percent' => '5']], $this->savedWithTwoTaxesInsideThePrice('4', '41.8182', '21.9048'), 4);
        $cart   = [1 => ['item_id' => 3, 'line' => 1, 'quantity' => '-1', 'price' => '115', 'discount' => '0', 'discount_type' => PERCENT, 'tax_category_id' => null]];
        $taxes  = $taxLib->get_taxes($cart);

        $this->assertSame(['-10.4545', '-5.4762'], $this->amounts($taxes[1]), 'a quarter of what the four units were charged');
    }

    public function testGetTaxes_WorksSeveralTaxesInsideThePriceOfASaleThatSavedNoAmountsWithTheArithmeticOfToday(): void
    {
        $saved = $this->savedWithTwoTaxesInsideThePrice();

        foreach (array_keys($saved[3]) as $index) {
            unset($saved[3][$index]['item_tax_amount']);
        }

        $cart  = $this->lineOf('115');
        $taxes = $this->taxLib(true, false, [], [], $saved, 4)->get_taxes($cart, 12);

        $this->assertSame(['10.0000', '5.0000'], $this->amounts($taxes[1]));
    }

    public function testGetTaxes_WorksSeveralTaxesInsideThePriceOfASaleWhoseUnitsAreUnknownWithTheArithmeticOfToday(): void
    {
        $cart  = $this->lineOf('115');
        $taxes = $this->taxLib(true, false, [], [], $this->savedWithTwoTaxesInsideThePrice('0'), 4)->get_taxes($cart, 12);

        $this->assertSame(['10.0000', '5.0000'], $this->amounts($taxes[1]));
    }

    public function testGetTaxes_ChargesTheTaxThatIsAddedToASavedLineOnWhatIsLeftAfterTheTaxesItSavedInsideThePrice(): void
    {
        $saved      = $this->savedWithTwoTaxesInsideThePrice();
        $saved[3][] = ['item_id' => 3, 'name' => 'Service', 'percent' => '10', 'tax_type' => '1', 'liability_account_id' => null, 'item_tax_amount' => '9.9069', 'sold_quantity' => '1'];
        $cart       = $this->lineOf('115');
        $taxes      = $this->taxLib(false, false, [], [], $saved, 4)->get_taxes($cart, 12);
        $service    = array_values($taxes[0])[2];

        $this->assertSame('99.0693', bcadd((string) $service['sale_tax_basis'], '0', 4), 'the price less the 15.9307 that was saved inside it');
        $this->assertSame('9.9069', $this->amounts($taxes[1])[2]);
    }

    public function testRoundTaxes_RoundsATaxInsideThePriceToTaxDecimalsAndAnAddedOneToTheCurrency(): void
    {
        $taxLib = $this->taxLib(false);
        $taxes  = [
            'a' => ['tax_type' => Tax_lib::TAX_TYPE_INCLUDED, 'sale_tax_amount' => '1.23456', 'rounding_code' => Rounding_mode::HALF_UP, 'print_sequence' => 1],
            'b' => ['tax_type' => Tax_lib::TAX_TYPE_EXCLUDED, 'sale_tax_amount' => '1.23456', 'rounding_code' => Rounding_mode::HALF_UP, 'print_sequence' => 2],
        ];

        $taxLib->round_taxes($taxes);

        $this->assertSame([1.235, 1.23], [$taxes['a']['sale_tax_amount'], $taxes['b']['sale_tax_amount']]);
    }

    public function testRoundTaxes_RoundsUpAndDownToTheDecimalsOfTheTax(): void
    {
        $taxLib = $this->taxLib(true, false);
        $taxes  = [
            'up'      => ['tax_type' => Tax_lib::TAX_TYPE_EXCLUDED, 'sale_tax_amount' => '1.234', 'rounding_code' => Rounding_mode::ROUND_UP, 'print_sequence' => 1],
            'down'    => ['tax_type' => Tax_lib::TAX_TYPE_EXCLUDED, 'sale_tax_amount' => '1.239', 'rounding_code' => Rounding_mode::ROUND_DOWN, 'print_sequence' => 2],
            'exact'   => ['tax_type' => Tax_lib::TAX_TYPE_EXCLUDED, 'sale_tax_amount' => '1.10', 'rounding_code' => Rounding_mode::ROUND_UP, 'print_sequence' => 3],
            'returns' => ['tax_type' => Tax_lib::TAX_TYPE_EXCLUDED, 'sale_tax_amount' => '-1.234', 'rounding_code' => Rounding_mode::ROUND_UP, 'print_sequence' => 4],
        ];

        $taxLib->round_taxes($taxes);

        $this->assertSame([1.24, 1.23, 1.1, -1.24], [$taxes['up']['sale_tax_amount'], $taxes['down']['sale_tax_amount'], $taxes['exact']['sale_tax_amount'], $taxes['returns']['sale_tax_amount']]);
    }

    public function testRoundTaxes_KeepsTheRoundingOfTheShopForTaxesByDestination(): void
    {
        $taxLib = $this->taxLib(true, true);
        $taxes  = ['a' => ['tax_type' => Tax_lib::TAX_TYPE_EXCLUDED, 'sale_tax_amount' => '1.23456', 'rounding_code' => Rounding_mode::HALF_UP, 'print_sequence' => 1]];

        $taxLib->round_taxes($taxes);

        $this->assertSame(1.235, $taxes['a']['sale_tax_amount'], 'a shop that taxes by destination rounds as its included setting says');
    }

    public function testTaxesOfItem_GivesTheTaxesOfTheItemWithTheirModes(): void
    {
        $taxes = $this->taxLib(false, false, ['vat' => ['included' => true, 'account' => null]], [['name' => 'VAT', 'percent' => '15'], ['name' => 'Service', 'percent' => '5']], [], 4)->taxesOfItem(3, '1', '100', '0', PERCENT);

        $this->assertSame(['13.0435', '4.3478'], array_map(static fn (array $tax): string => bcadd($tax['amount'], '0', 4), $taxes));
        $this->assertSame([Tax_lib::TAX_TYPE_INCLUDED, Tax_lib::TAX_TYPE_EXCLUDED], array_column($taxes, 'type'));
    }

    /**
     * @param array<int, LineVat> $classes What each classified item is sold under.
     * @param array<int, LineVat> $sold    What each item of a saved sale was sold under.
     * @param list<string>        $dates   The days the rates were asked for.
     */
    private function vatSource(bool $applies, array $classes, array $sold = [], array &$dates = []): LineVatSource
    {
        return new class ($applies, $classes, $sold, $dates) implements LineVatSource {
            /**
             * @param array<int, LineVat> $classes
             * @param array<int, LineVat> $sold
             * @param list<string>        $dates
             */
            public function __construct(private bool $applies, private array $classes, private array $sold, private array &$dates)
            {
            }

            public function appliesNow(): bool
            {
                return $this->applies;
            }

            public function lineVat(int $itemId, string $date): ?LineVat
            {
                $this->dates[] = $date;

                return $this->classes[$itemId] ?? null;
            }

            public function soldUnder(int $saleId, int $itemId, int $line): ?LineVat
            {
                return $this->sold[$itemId] ?? null;
            }
        };
    }

    public function testGetTaxes_ChargesAClassifiedItemTheRateOfItsClassInSaudiMode(): void
    {
        $taxLib = $this->taxLib(false);
        $taxLib->useVatSource($this->vatSource(true, [
            3 => new LineVat(2, 'Z', '0.000', 'VATEX-SA-32', 'Export of goods', 'VAT'),
            4 => new LineVat(1, 'S', '15.000', null, null, 'VAT'),
        ]));

        $cart  = $this->cart('', '');
        $taxes = $taxLib->get_taxes($cart);

        $this->assertSame(['0.000', '33.000'], array_map(static fn (array $row): string => bcadd($row['item_tax_amount'], '0', 3), $taxes[1]));
        $this->assertSame(['Z', 'S'], array_map(static fn (array $row): string => $row['vat']->category, $taxes[1]));
        $this->assertSame('VATEX-SA-32', $taxes[1][0]['vat']->exemptionCode);
    }

    public function testGetTaxes_KeepsTheTaxesOfAnUnclassifiedItemInSaudiMode(): void
    {
        $taxLib = $this->taxLib(false);
        $taxLib->useVatSource($this->vatSource(true, [3 => new LineVat(2, 'Z', '0.000', 'VATEX-SA-32', 'Export of goods', 'VAT')]));

        $cart  = $this->cart('', '');
        $taxes = $taxLib->get_taxes($cart);

        $this->assertSame(['0.000', '33.000'], array_map(static fn (array $row): string => bcadd($row['item_tax_amount'], '0', 3), $taxes[1]));
        $this->assertNull($taxes[1][1]['vat']);
    }

    public function testGetTaxes_KeepsTheTaxesOfTheItemWhileTheModeIsOff(): void
    {
        $taxLib = $this->taxLib(false);
        $taxLib->useVatSource($this->vatSource(false, [3 => new LineVat(2, 'Z', '0.000', 'VATEX-SA-32', 'Export of goods', 'VAT')]));

        $cart  = $this->cart('', '');
        $taxes = $taxLib->get_taxes($cart);

        $this->assertSame(['20.250', '33.000'], array_map(static fn (array $row): string => bcadd($row['item_tax_amount'], '0', 3), $taxes[1]));
        $this->assertNull($taxes[1][0]['vat']);
    }

    public function testGetTaxes_AsksForTheRateOfTheDayOfTheInvoice(): void
    {
        $dates  = [];
        $taxLib = $this->taxLib(false);
        $taxLib->useVatSource($this->vatSource(true, [3 => new LineVat(1, 'S', '5.000', null, null, 'VAT')], [], $dates));
        session()->set('sales_invoice_date', '2020-06-30');

        $cart  = $this->cart('', '');
        $taxes = $taxLib->get_taxes($cart);

        $this->assertSame(['2020-06-30', '2020-06-30'], $dates);
        $this->assertSame('6.750', bcadd($taxes[1][0]['item_tax_amount'], '0', 3));
    }

    public function testGetTaxes_CarriesTheClassASavedSaleWasSoldUnder(): void
    {
        $taxLib = $this->taxLib(false, false, [], [['name' => 'VAT', 'percent' => '15']], [3 => [['name' => 'VAT', 'percent' => '0']], 4 => [['name' => 'VAT', 'percent' => '15']]]);
        $taxLib->useVatSource($this->vatSource(true, [], [3 => new LineVat(2, 'Z', '0.000', 'VATEX-SA-32', 'Export of goods', 'VAT')]));

        $cart  = $this->cart('', '');
        $taxes = $taxLib->get_taxes($cart, 77);

        $this->assertSame('Z', $taxes[1][0]['vat']->category);
        $this->assertNull($taxes[1][1]['vat']);
    }

    public function testGetTaxes_KeepsTheTaxesOfTheItemWhenItsClassHasNoRateOnTheDay(): void
    {
        $taxLib = $this->taxLib(false);
        $taxLib->useVatSource(new class () implements LineVatSource {
            public function appliesNow(): bool
            {
                return true;
            }

            public function lineVat(int $itemId, string $date): ?LineVat
            {
                throw new VatClassificationException(VatClassificationError::NoRateOnDate, 'No rate.');
            }

            public function soldUnder(int $saleId, int $itemId, int $line): ?LineVat
            {
                return null;
            }
        });

        $cart  = $this->cart('', '');
        $taxes = $taxLib->get_taxes($cart);

        $this->assertSame(['20.250', '33.000'], array_map(static fn (array $row): string => bcadd($row['item_tax_amount'], '0', 3), $taxes[1]));
        $this->assertNull($taxes[1][0]['vat']);
    }

    public function testGetTaxes_WorksTheVatOfASaudiSaleOnceAndRoundsEachCategoryOnItsTaxableAmount(): void
    {
        $standard = new LineVat(1, 'S', '15.000', null, null, 'VAT');
        $taxLib   = $this->taxLib(false);
        $taxLib->useVatSource($this->vatSource(true, [3 => $standard, 4 => $standard, 5 => $standard]));
        $taxLib->useVatSnapshot($this->snapshot(true));

        $cart  = $this->cartOf(['0.10', '0.10', '0.10']);
        $taxes = $taxLib->get_taxes($cart);

        $this->assertSame(['0.015000', '0.015000', '0.015000'], array_column($taxes[1], 'item_tax_amount'));
        $this->assertSame(['0.10', '0.02', '0.12'], [$taxes[1][0]['vat_amounts']->net, $taxes[1][0]['vat_amounts']->vat, $taxes[1][0]['vat_amounts']->gross]);
        $this->assertSame(['0.30', '0.05'], [$taxes[2]['S|15.000|']->taxable, $taxes[2]['S|15.000|']->vat]);
        $this->assertSame([['0.30', '0.05', Tax_lib::TAX_TYPE_EXCLUDED]], array_map(static fn (array $row): array => [$row['sale_tax_basis'], $row['sale_tax_amount'], $row['tax_type']], array_values($taxes[0])));
    }

    public function testGetTaxes_TakesTheNetOutOfAPriceThatHoldsTheTaxInASaudiSale(): void
    {
        $taxLib = $this->taxLib(false, false, ['vat' => ['included' => true, 'account' => 41]]);
        $taxLib->useVatSource($this->vatSource(true, [3 => new LineVat(1, 'S', '15.000', null, null, 'VAT')]));
        $taxLib->useVatSnapshot($this->snapshot(true));

        $cart  = $this->cartOf(['100']);
        $taxes = $taxLib->get_taxes($cart);

        $this->assertSame(['86.96', '13.04', '100.00'], [$taxes[1][0]['vat_amounts']->net, $taxes[1][0]['vat_amounts']->vat, $taxes[1][0]['vat_amounts']->gross]);
        $this->assertSame([Tax_lib::TAX_TYPE_INCLUDED, 41], [$taxes[1][0]['tax_type'], $taxes[1][0]['liability_account_id']]);
        $this->assertSame('13.04', array_values($taxes[0])[0]['sale_tax_amount']);
    }

    public function testGetTaxes_KeepsTheTaxesOfASaudiSaleWithAnUnclassifiedLineAsTheyWere(): void
    {
        $taxLib = $this->taxLib(false);
        $taxLib->useVatSource($this->vatSource(true, [3 => new LineVat(1, 'S', '15.000', null, null, 'VAT')]));
        $taxLib->useVatSnapshot($this->snapshot(true));

        $cart  = $this->cartOf(['0.10', '0.10']);
        $taxes = $taxLib->get_taxes($cart);

        $this->assertArrayNotHasKey(2, $taxes);
        $this->assertArrayNotHasKey('vat_amounts', $taxes[1][0]);
    }

    public function testGetTaxes_HoldsTheTaxableAmountOfASaudiReturnToWhatIsLeftOfItsSale(): void
    {
        $stored = [3 => [['item_id' => 3, 'name' => 'VAT', 'percent' => '15', 'tax_type' => Tax_lib::TAX_TYPE_EXCLUDED, 'liability_account_id' => null]]];
        $taxLib = $this->taxLib(false, false, [], [['name' => 'VAT', 'percent' => '15']], $stored);
        $taxLib->useVatSource($this->vatSource(true, [], [3 => new LineVat(1, 'S', '15.000', null, null, 'VAT')]));
        $taxLib->useVatSnapshot($this->snapshot(true, [], ['S|15.000|' => ['taxable' => '0.34', 'vat' => '0.05']], ['S|15.000|']));

        session()->set('sales_mode', 'return');
        session()->set('sales_return_origin', 12);

        $cart  = [1 => ['item_id' => 3, 'line' => 1, 'quantity' => '-1', 'price' => '0.333', 'discount' => '0', 'discount_type' => PERCENT, 'tax_category_id' => null]];
        $taxes = $taxLib->get_taxes($cart);

        $this->assertSame(['-0.34', '-0.05'], [$taxes[2]['S|15.000|']->taxable, $taxes[2]['S|15.000|']->vat], 'the last units give back what is left, not the 0.33 they come to');
        $this->assertSame('-0.34', $taxes[1][0]['vat_amounts']->net);
        $this->assertSame('-0.05', array_values($taxes[0])[0]['sale_tax_amount']);
    }

    public function testGetTaxes_ReadsTheHeaderOfASavedSaudiSaleAsItWasSaved(): void
    {
        $stored = [3 => [['item_id' => 3, 'name' => 'VAT', 'percent' => '15', 'tax_type' => Tax_lib::TAX_TYPE_EXCLUDED, 'liability_account_id' => null, 'item_tax_amount' => '-0.0150', 'sold_quantity' => '-1']]];
        $taxLib = $this->taxLib(false, false, [], [['name' => 'VAT', 'percent' => '15']], $stored);
        $taxLib->useVatSnapshot($this->snapshot(true, ['S|15.000|' => new VatCategoryTotal('S', '15.000', null, null, '-0.10', '-0.01', false)]));

        $cart  = $this->cartOf(['0.10'], '-1');
        $taxes = $taxLib->get_taxes($cart, 77);

        $this->assertSame([['-0.10', '-0.01']], array_map(static fn (array $row): array => [$row['sale_tax_basis'], $row['sale_tax_amount']], array_values($taxes[0])), 'the return as it was held to its sale, not the -0.02 its line rounds to');
    }
}
