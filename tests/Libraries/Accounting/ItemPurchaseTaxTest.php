<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\ItemPurchaseTax;
use CodeIgniter\Test\CIUnitTestCase;
use stdClass;

class ItemPurchaseTaxTest extends CIUnitTestCase
{
    private const SALES = [['name' => 'VAT', 'percent' => '15.000'], ['name' => 'Excise', 'percent' => '5.000']];

    /**
     * @param array<string, string|null> $columns
     */
    private function item(array $columns): stdClass
    {
        $item = new stdClass();

        foreach ($columns as $name => $value) {
            $item->{$name} = $value;
        }

        return $item;
    }

    public function testDefaultPercent_FollowsTheSumOfTheSalesTaxesWhileNoPurchaseTaxIsSet(): void
    {
        $this->assertSame('20.000', ItemPurchaseTax::defaultPercent($this->item([]), self::SALES));
        $this->assertSame('20.000', ItemPurchaseTax::defaultPercent($this->item(['purchase_tax_1_percent' => null, 'purchase_tax_2_percent' => null]), self::SALES));
        $this->assertSame('20.000', ItemPurchaseTax::defaultPercent($this->item(['purchase_tax_1_percent' => '', 'purchase_tax_2_percent' => '']), self::SALES));
    }

    public function testDefaultPercent_KeepsReadingEverySalesTaxOfAnItemThatSetsNoPurchaseTax(): void
    {
        $sales = [...self::SALES, ['name' => 'Levy', 'percent' => '1.250']];

        $this->assertSame('21.250', ItemPurchaseTax::defaultPercent($this->item([]), $sales));
    }

    public function testDefaultPercent_GivesNothingForAnItemWithoutTaxes(): void
    {
        $this->assertSame('0', ItemPurchaseTax::defaultPercent($this->item([]), []));
    }

    public function testDefaultPercent_TakesTheSumOfTheTwoPurchaseTaxesWhenBothAreSet(): void
    {
        $item = $this->item(['purchase_tax_1_percent' => '14.000', 'purchase_tax_2_percent' => '2.500']);

        $this->assertSame('16.500', ItemPurchaseTax::defaultPercent($item, self::SALES));
        $this->assertSame('16.500', ItemPurchaseTax::defaultPercent($item, []));
    }

    public function testDefaultPercent_FollowsTheSalesTaxOfTheSameNumberForAPurchaseTaxThatIsNotSet(): void
    {
        $this->assertSame('17.000', ItemPurchaseTax::defaultPercent($this->item(['purchase_tax_1_percent' => '12.000', 'purchase_tax_2_percent' => null]), self::SALES));
        $this->assertSame('17.000', ItemPurchaseTax::defaultPercent($this->item(['purchase_tax_1_percent' => null, 'purchase_tax_2_percent' => '2.000']), self::SALES));
    }

    public function testDefaultPercent_TakesAPurchaseTaxOfZeroAsNoTaxWhateverTheSalesTaxIs(): void
    {
        $this->assertSame('0.000', ItemPurchaseTax::defaultPercent($this->item(['purchase_tax_1_percent' => '0', 'purchase_tax_2_percent' => '0']), self::SALES));
        $this->assertSame('5.000', ItemPurchaseTax::defaultPercent($this->item(['purchase_tax_1_percent' => '0.000', 'purchase_tax_2_percent' => null]), self::SALES));
    }

    public function testDefaultPercent_AddsNothingForAnUnsetPurchaseTaxWhoseSalesTaxDoesNotExist(): void
    {
        $this->assertSame('9.000', ItemPurchaseTax::defaultPercent($this->item(['purchase_tax_1_percent' => '9']), []));
        $this->assertSame('11.000', ItemPurchaseTax::defaultPercent($this->item(['purchase_tax_2_percent' => '1']), [['percent' => '10']]));
    }

    public function testDefaultPercent_ReadsARowGivenAsAnArrayAsItReadsAnObject(): void
    {
        $this->assertSame('16.500', ItemPurchaseTax::defaultPercent(['purchase_tax_1_percent' => '14', 'purchase_tax_2_percent' => '2.5'], self::SALES));
    }

    public function testDefaultPercent_KeepsTheThreeDecimalsAPercentageIsStoredAt(): void
    {
        $this->assertSame('14.125', ItemPurchaseTax::defaultPercent($this->item(['purchase_tax_1_percent' => '14.125', 'purchase_tax_2_percent' => '0']), self::SALES));
    }

    public function testPercents_GivesWhatTheItemSetsAndNothingForWhatItLeavesEmpty(): void
    {
        $this->assertSame([null, null], ItemPurchaseTax::percents($this->item([])));
        $this->assertSame([null, null], ItemPurchaseTax::percents($this->item(['purchase_tax_1_percent' => '', 'purchase_tax_2_percent' => null])));
        $this->assertSame(['14.000', '0.000'], ItemPurchaseTax::percents($this->item(['purchase_tax_1_percent' => '14', 'purchase_tax_2_percent' => '0'])));
        $this->assertCount(ItemPurchaseTax::SLOTS, ItemPurchaseTax::percents($this->item([])));
    }
}
