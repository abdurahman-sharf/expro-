<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\ReceivingLineEdit;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ReceivingLineEditTest extends CIUnitTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function storedRow(): array
    {
        return [
            'item_id'            => 5,
            'line'               => 2,
            'quantity_purchased' => '10.000',
            'item_unit_price'    => '100.00',
            'discount'           => '10.00',
            'discount_type'      => PERCENT,
            'tax_percent'        => '15.000',
            'description'        => 'Boxes',
            'serialnumber'       => null,
        ];
    }

    public function testChanging_AcceptsPlainNumbersAndRoundsThemToTheDecimalsTheLineKeeps(): void
    {
        $edit = ReceivingLineEdit::changing(5, 2, ['quantity' => '2.5005', 'price' => '10.005', 'discount' => '3', 'discount_type' => '1', 'tax_percent' => '7.5']);

        $this->assertNotNull($edit);
        $this->assertFalse($edit->remove);
        $this->assertSame([5, 2], [$edit->itemId, $edit->line]);
        $this->assertSame('2.501', $edit->quantity);
        $this->assertSame('10.01', $edit->price);
        $this->assertSame('3.00', $edit->discount);
        $this->assertSame(FIXED, $edit->discountType);
        $this->assertSame('7.500', $edit->taxPercent);
    }

    public function testChanging_AcceptsNumbersGivenAsIntegersAndFloats(): void
    {
        $edit = ReceivingLineEdit::changing(5, 2, ['quantity' => 4, 'price' => 12.5, 'discount' => 0, 'tax_percent' => 0.0]);

        $this->assertNotNull($edit);
        $this->assertSame(['4.000', '12.50', '0.00', '0.000'], [$edit->quantity, $edit->price, $edit->discount, $edit->taxPercent]);
    }

    public function testChanging_LeavesWhatIsNotGivenAsNullSoThatItStaysAsStored(): void
    {
        $edit = ReceivingLineEdit::changing(5, 2, ['quantity' => '3', 'price' => '', 'description' => null]);

        $this->assertNotNull($edit);
        $this->assertSame('3.000', $edit->quantity);
        $this->assertNull($edit->price);
        $this->assertNull($edit->discount);
        $this->assertNull($edit->discountType);
        $this->assertNull($edit->taxPercent);
        $this->assertNull($edit->description);
        $this->assertNull($edit->serialnumber);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidFieldsProvider(): array
    {
        return [
            'quantity of nothing'           => [['quantity' => '0']],
            'quantity that rounds to zero'  => [['quantity' => '0.0004']],
            'negative quantity'             => [['quantity' => '-1']],
            'quantity that is no number'    => [['quantity' => 'abc']],
            'quantity in exponent form'     => [['quantity' => '1e3']],
            'quantity as a list'            => [['quantity' => ['1']]],
            'negative price'                => [['price' => '-0.01']],
            'price with a comma'            => [['price' => '1,5']],
            'negative discount'             => [['discount' => '-5']],
            'discount that is no number'    => [['discount' => 'ten']],
            'tax over a hundred'            => [['tax_percent' => '100.001']],
            'negative tax'                  => [['tax_percent' => '-1']],
            'unknown discount type'         => [['discount_type' => '3']],
            'discount type that is no int'  => [['discount_type' => 'percent']],
            'description over the length'   => [['description' => str_repeat('x', ReceivingLineEdit::DESCRIPTION_LENGTH + 1)]],
            'serial number over the length' => [['serialnumber' => str_repeat('x', ReceivingLineEdit::DESCRIPTION_LENGTH + 1)]],
            'description as a list'         => [['description' => ['x']]],
        ];
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[DataProvider('invalidFieldsProvider')]
    public function testChanging_RejectsWhatIsNotALineThatCanBeSaved(array $fields): void
    {
        $this->assertNull(ReceivingLineEdit::changing(5, 2, $fields));
    }

    public function testChanging_AcceptsTheLimitsOfTheValues(): void
    {
        $edit = ReceivingLineEdit::changing(5, 2, ['quantity' => '0.001', 'price' => '0', 'tax_percent' => '100', 'description' => str_repeat('x', ReceivingLineEdit::DESCRIPTION_LENGTH), 'serialnumber' => '']);

        $this->assertNotNull($edit);
        $this->assertSame('0.001', $edit->quantity);
        $this->assertSame('0.00', $edit->price);
        $this->assertSame('100.000', $edit->taxPercent);
        $this->assertSame('', $edit->serialnumber);
    }

    public function testRemoving_NamesTheLineAndTakesItOut(): void
    {
        $edit = ReceivingLineEdit::removing(5, 2);

        $this->assertTrue($edit->remove);
        $this->assertSame([5, 2], [$edit->itemId, $edit->line]);
    }

    public function testApplyTo_KeepsWhatTheCorrectionLeavesOutAndNormalisesTheRest(): void
    {
        $edit = ReceivingLineEdit::changing(5, 2, ['quantity' => '8', 'description' => 'Damaged']);

        $this->assertSame([
            'quantity_purchased' => '8.000',
            'item_unit_price'    => '100.00',
            'discount'           => '10.00',
            'discount_type'      => PERCENT,
            'tax_percent'        => '15.000',
            'description'        => 'Damaged',
            'serialnumber'       => null,
        ], $edit->applyTo($this->storedRow()));
    }

    public function testApplyTo_GivesTheStoredLineBackWhenTheCorrectionRepeatsIt(): void
    {
        $edit = ReceivingLineEdit::changing(5, 2, ['quantity' => '10', 'price' => '100.00', 'discount' => '10', 'discount_type' => PERCENT, 'tax_percent' => '15', 'description' => 'Boxes']);

        $this->assertSame(ReceivingLineEdit::fieldsOf($this->storedRow()), $edit->applyTo($this->storedRow()));
    }

    public function testApplyTo_RejectsAPercentageOverAHundredWhetherItsTypeIsGivenOrStored(): void
    {
        $this->assertNull(ReceivingLineEdit::changing(5, 2, ['discount' => '100.01'])->applyTo($this->storedRow()));
        $this->assertNull(ReceivingLineEdit::changing(5, 2, ['discount' => '100.01', 'discount_type' => PERCENT])->applyTo($this->storedRow()));
        $this->assertNull(ReceivingLineEdit::changing(5, 2, ['discount_type' => PERCENT])->applyTo([...$this->storedRow(), 'discount' => '150.00']));
        $this->assertNotNull(ReceivingLineEdit::changing(5, 2, ['discount' => '100'])->applyTo($this->storedRow()));
    }

    public function testApplyTo_AcceptsAFixedDiscountOverAHundred(): void
    {
        $line = ReceivingLineEdit::changing(5, 2, ['discount' => '250', 'discount_type' => FIXED_LINE])->applyTo($this->storedRow());

        $this->assertSame(['250.00', FIXED_LINE], [$line['discount'], $line['discount_type']]);
    }

    public function testFieldsOf_PutsAStoredRowAtTheDecimalsTheLinesAreComparedAt(): void
    {
        $fields = ReceivingLineEdit::fieldsOf([...$this->storedRow(), 'quantity_purchased' => '10', 'item_unit_price' => '100', 'discount' => '0.5', 'tax_percent' => '15', 'discount_type' => '2', 'serialnumber' => 'SN']);

        $this->assertSame(['10.000', '100.00', '0.50', 2, '15.000', 'SN'], [$fields['quantity_purchased'], $fields['item_unit_price'], $fields['discount'], $fields['discount_type'], $fields['tax_percent'], $fields['serialnumber']]);
    }
}
