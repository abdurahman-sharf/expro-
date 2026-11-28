<?php

namespace Tests\Libraries\Tax;

use App\Libraries\Tax\LineVat;
use App\Libraries\Tax\VatCalculator;
use App\Libraries\Tax\VatCategoryTotal;
use App\Libraries\Tax\VatLine;
use App\Libraries\Tax\VatResult;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;

class VatCalculatorTest extends CIUnitTestCase
{
    private VatCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new VatCalculator();
    }

    private static function standard(string $percent = '15'): LineVat
    {
        return new LineVat(1, 'S', $percent, null, null, 'VAT');
    }

    private static function zero(): LineVat
    {
        return new LineVat(2, 'Z', '0', 'VATEX-SA-32', 'Export of goods', 'VAT');
    }

    /**
     * @param list<array{0: string, 1?: bool, 2?: LineVat}> $lines Base, whether the price holds the tax, class.
     */
    private function calculate(array $lines): VatResult
    {
        $input = [];

        foreach ($lines as $index => $line) {
            $input[] = new VatLine((string) $index, $line[0], $line[2] ?? self::standard(), $line[1] ?? false);
        }

        return $this->calculator->calculate($input);
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private static function line(VatResult $result, string $key = '0'): array
    {
        return [$result->lines[$key]->net, $result->lines[$key]->vat, $result->lines[$key]->gross];
    }

    public function testCalculate_ChargesAPriceWithoutTax(): void
    {
        $result = $this->calculate([['100']]);

        $this->assertSame(['100.00', '15.00', '115.00'], self::line($result));
        $this->assertSame(['100.00', '15.00', '115.00'], [$result->taxable(), $result->vat(), $result->totalWithVat()]);
    }

    #[Group('T-01-T-11')]
    #[Group('T-04')]
    public function testCalculate_ChargesTheTaxOnThreeAt33Point33AsNinetyNinePoint99And15(): void
    {
        $result = $this->calculate([[bcmul('3', '33.33', 2)]]);

        $this->assertSame(['99.99', '15.00', '114.99'], self::line($result));
        $this->assertSame(['99.99', '15.00', '114.99'], [$result->taxable(), $result->vat(), $result->totalWithVat()]);
    }

    #[Group('T-01-T-11')]
    #[Group('T-05')]
    public function testCalculate_TakesTheNetOutOfAPriceThatHoldsTheTax(): void
    {
        $this->assertSame(['100.00', '15.00', '115.00'], self::line($this->calculate([['115', true]])));
        $this->assertSame(['86.96', '13.04', '100.00'], self::line($this->calculate([['100', true]])));
    }

    #[Group('T-01-T-11')]
    #[Group('T-06')]
    public function testCalculate_ChargesNothingOnALineOfZeroRateAndKeepsTheExemptionCodeAndItsReason(): void
    {
        $result   = $this->calculate([['100', false, self::zero()]]);
        $category = array_values($result->categories)[0];

        $this->assertSame(['100.00', '0.00', '100.00'], self::line($result));
        $this->assertSame(['Z', 'VATEX-SA-32', 'Export of goods', '0.00'], [$category->category, $category->exemptionCode, $category->exemptionText, $category->vat]);
    }

    public function testCalculate_RoundsTheTaxOfACategoryOnItsTaxableAmountAndNotOnItsLines(): void
    {
        $result = $this->calculate([['0.10'], ['0.10'], ['0.10']]);

        $this->assertSame('0.02', $result->lines['0']->vat);
        $this->assertSame(['0.30', '0.05'], [$result->taxable(), $result->vat()]);
    }

    public function testCalculate_TaxesWhatTheLineDiscountLeaves(): void
    {
        $this->assertSame(['90.00', '13.50', '103.50'], self::line($this->calculate([['90']])));
    }

    public function testCalculate_TaxesWhatTheInvoiceDiscountLeavesOnEveryLine(): void
    {
        $result = $this->calculate([['27'], ['63']]);

        $this->assertSame(['90.00', '13.50'], [$result->taxable(), $result->vat()]);
    }

    public function testCalculate_RoundsALineOfFractionalUnitsToTwoDecimalsBeforeItsTax(): void
    {
        $this->assertSame(['100.00', '15.00', '115.00'], self::line($this->calculate([['99.999']])));
        $this->assertSame(['3.09', '0.46', '3.55'], self::line($this->calculate([['3.0875']])));
        $this->assertSame(['1.00', '0.15', '1.15'], self::line($this->calculate([['0.9999']])));
    }

    public function testCalculate_RoundsTheTaxOfADebitNoteToTheHalala(): void
    {
        $this->assertSame(['33.33', '5.00'], [$this->calculate([['33.33']])->taxable(), $this->calculate([['33.33']])->vat()]);
    }

    public function testCalculate_KeepsTheExactTaxOfTheNetForTheTaxRowsOfTheSale(): void
    {
        $result = $this->calculate([['0.10'], ['0.10'], ['0.10']]);

        $this->assertSame('0.015000', $result->lines['0']->exact);
        $this->assertSame($result->vat(), VatCalculator::round(bcmul($result->lines['0']->exact, '3', 6), 2));
    }

    public function testCalculate_LeavesWhatSmallPricesHoldingTheTaxComeToForTheRoundingAmount(): void
    {
        $result = $this->calculate(array_fill(0, 10, ['0.99', true]));

        $this->assertSame(['8.60', '1.29', '9.89'], [$result->taxable(), $result->vat(), $result->totalWithVat()]);
    }

    public function testCalculate_KeepsTheCategoriesOfAnInvoiceDiscountOnMixedLinesApart(): void
    {
        $result = $this->calculate([['45'], ['45', false, self::zero()], ['10']]);

        $this->assertCount(2, $result->categories);
        $this->assertSame(['55.00', '8.25'], [$result->categories['S|15.000|']->taxable, $result->categories['S|15.000|']->vat]);
        $this->assertSame(['45.00', '0.00', 'VATEX-SA-32'], [$result->categories['Z|0.000|VATEX-SA-32']->taxable, $result->categories['Z|0.000|VATEX-SA-32']->vat, $result->categories['Z|0.000|VATEX-SA-32']->exemptionCode]);
    }

    public function testCalculate_ChargesNothingOnALineGivenAwayWhole(): void
    {
        $this->assertSame(['0.00', '0.00', '0.00'], self::line($this->calculate([['0']])));
    }

    public function testCalculate_ChargesAReturnAtTheRateOfTheSaleItGivesBack(): void
    {
        $this->assertSame(['-100.00', '-5.00', '-105.00'], self::line($this->calculate([['-100', false, self::standard('5')]])));
    }

    public function testCalculate_RoundsAReturnAsItsSaleWasRounded(): void
    {
        $sale   = $this->calculate([['0.10']]);
        $return = $this->calculate([['-0.10']]);

        $this->assertSame([$sale->lines['0']->net, $sale->lines['0']->vat], [ltrim($return->lines['0']->net, '-'), ltrim($return->lines['0']->vat, '-')]);
    }

    public function testCalculate_RejectsACategoryThatMixesPricesWithAndWithoutTheTax(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculate([['100'], ['100', true]]);
    }

    public function testWithCharge_RoundsTheVatOfACategoryOnceOnItsLinesAndItsCharge(): void
    {
        $result     = $this->calculate([['0.10'], ['0.10'], ['0.10']]);
        $categories = $this->calculator->withCharge($result->categories, '0.03', '15', false);
        $standard   = $categories['S|15.000|'];

        $this->assertSame(['0.33', '0.05', '0.03', '0.30'], [$standard->taxable, $standard->vat, $standard->charges, $standard->lines()]);
    }

    public function testWithCharge_OpensACategoryForARateNoLineCarriesAndKeepsTheSignOfAReturn(): void
    {
        $categories = $this->calculator->withCharge($this->calculate([['-40', false, self::zero()]])->categories, '-10', '15', false);

        $this->assertSame(['-10.00', '-1.50', '-10.00'], [$categories['S|15.000|']->taxable, $categories['S|15.000|']->vat, $categories['S|15.000|']->charges]);
        $this->assertSame('-40.00', $categories['Z|0.000|VATEX-SA-32']->taxable);
    }

    public function testCapReturn_GivesBackTogetherTheVatOfTheSaleAcrossPartialReturns(): void
    {
        $sale = $this->calculate([['0.30']]);
        $left = ['S|15.000|' => ['taxable' => $sale->taxable(), 'vat' => $sale->vat(), 'returned_taxable' => '0.00', 'returned_vat' => '0.00']];
        $vats = [];

        foreach ([['-0.10', []], ['-0.10', []], ['-0.10', ['S|15.000|']]] as [$base, $exhausted]) {
            $category = $this->calculator->capReturn($this->calculate([[$base]]), $left, $exhausted)->categories['S|15.000|'];
            $vats[]   = $category->vat;
            $left     = $this->after($left, $category);
        }

        $this->assertSame('0.05', $sale->vat());
        $this->assertSame(['-0.02', '-0.01', '-0.02'], $vats, 'each note within a halala of its own taxable amount times the rate (BR-CO-17 for one line)');
        $this->assertSame(['0.00', '0.00', '0.30', '0.05'], array_values($left['S|15.000|']), 'the three notes give back the taxable amount and the VAT of the sale, to the halala');
    }

    public function testCapReturn_NeverGivesBackMoreVatThanTheSaleChargedForElevenReturnsOfOneUnit(): void
    {
        $sale = $this->calculate([['1.10']]);
        $left = ['S|15.000|' => ['taxable' => $sale->taxable(), 'vat' => $sale->vat(), 'returned_taxable' => '0.00', 'returned_vat' => '0.00']];

        for ($unit = 11; $unit > 0; $unit--) {
            $category = $this->calculator->capReturn($this->calculate([['-0.10']]), $left, $unit === 1 ? ['S|15.000|'] : [])->categories['S|15.000|'];
            $left     = $this->after($left, $category);

            $this->assertGreaterThanOrEqual(0, bccomp($left['S|15.000|']['vat'], '0', 2), 'never more VAT back than the sale charged, after ' . (12 - $unit) . ' returns');
            $this->assertLessThanOrEqual(1, abs((int) bcmul(bcsub(self::absolute($category->vat), VatCalculator::round(bcmul(self::absolute($category->taxable), '0.15', 10), 2), 2), '100')), 'BR-CO-17 for one line');
        }

        $this->assertSame(['0.17', '1.10', '0.17'], [$sale->vat(), $left['S|15.000|']['returned_taxable'], $left['S|15.000|']['returned_vat']], 'eleven notes rounded each on its own would have given back 0.22');
    }

    public function testCapReturn_GivesBackWhatIsLeftForTheLastUnitsAndMovesItOntoTheLargestLine(): void
    {
        $sale = $this->calculate([['0.999']]);
        $left = ['S|15.000|' => ['taxable' => bcsub($sale->taxable(), '0.66', 2), 'vat' => bcsub($sale->vat(), '0.10', 2)]];

        $return = $this->calculator->capReturn($this->calculate([['-0.333']]), $left, ['S|15.000|']);

        $this->assertSame(['1.00', '0.34'], [$sale->taxable(), $left['S|15.000|']['taxable']]);
        $this->assertSame(['-0.34', '-0.05'], [$return->categories['S|15.000|']->taxable, $return->categories['S|15.000|']->vat]);
        $this->assertSame('-0.05', $return->lines['0']->vat);
        $this->assertSame('-0.34', $return->lines['0']->net, 'the lines still add up to the category');
    }

    public function testCapReturn_LeavesAReturnWithinWhatIsLeftAsItWasWorkedOut(): void
    {
        $return = $this->calculator->capReturn($this->calculate([['-50']]), ['S|15.000|' => ['taxable' => '100.00', 'vat' => '15.00']]);

        $this->assertSame(['-50.00', '-7.50'], [$return->categories['S|15.000|']->taxable, $return->categories['S|15.000|']->vat]);
    }

    public function testCalculate_HoldsTheRulesOfTheStandardForTenThousandRandomInvoices(): void
    {
        mt_srand(20261121);

        for ($invoice = 0; $invoice < 10000; $invoice++) {
            $lines    = [];
            $included = mt_rand(0, 1) === 1;

            for ($count = mt_rand(1, 8), $line = 0; $line < $count; $line++) {
                $quantity = bcdiv((string) mt_rand(1, 5000), '1000', 3);
                $price    = bcdiv((string) mt_rand(1, 2000000), '10000', 4);
                $class    = [self::standard(), self::standard('5'), self::zero()][mt_rand(0, 2)];
                $lines[]  = [bcmul($quantity, $price, 7), $included, $class];
            }

            $result = $this->calculate($lines);
            $nets   = '0.00';

            foreach ($result->lines as $line) {
                $this->assertSame(bcadd($line->net, $line->vat, 2), $line->gross, 'BR-KSA-51');
                $nets = bcadd($nets, $line->net, 2);
            }

            foreach ($result->categories as $category) {
                $this->assertSame(VatCalculator::round(bcmul($category->taxable, bcdiv($category->percent, '100', 10), 10), 2), $category->vat, 'BR-CO-17');
            }

            $this->assertSame($nets, $result->taxable(), 'BR-CO-10');
            $this->assertSame(bcadd($result->taxable(), $result->vat(), 2), $result->totalWithVat(), 'BR-CO-15');
        }
    }

    public function testCapReturn_GivesBackExactlyTheTaxableAmountAndTheVatOfTheSaleForRandomReturnsInParts(): void
    {
        mt_srand(1121);

        for ($sale = 0; $sale < 2000; $sale++) {
            $units = mt_rand(2, 12);
            $price = bcdiv((string) mt_rand(1, 500000), '1000', 3);
            $sold  = $this->calculate([[bcmul((string) $units, $price, 3)]]);
            $left  = ['S|15.000|' => ['taxable' => $sold->taxable(), 'vat' => $sold->vat(), 'returned_taxable' => '0.00', 'returned_vat' => '0.00']];

            while ($units > 0) {
                $taken  = mt_rand(1, $units);
                $units -= $taken;
                $return = $this->calculator->capReturn($this->calculate([['-' . bcmul((string) $taken, $price, 3)]]), $left, $units === 0 ? ['S|15.000|'] : []);
                $total  = $return->categories['S|15.000|'];

                $this->assertLessThanOrEqual(1, abs((int) bcmul(bcsub(self::absolute($total->vat), VatCalculator::round(bcmul(self::absolute($total->taxable), '0.15', 10), 2), 2), '100')), 'BR-CO-17 within the halala the standard allows for one line');
                $this->assertSame($total->taxable, $return->lines['0']->net, 'the line adds up to its category');

                $left = $this->after($left, $total);

                $this->assertGreaterThanOrEqual(0, bccomp($left['S|15.000|']['taxable'], '0', 2), 'never more taxable amount than was charged');
                $this->assertGreaterThanOrEqual(0, bccomp($left['S|15.000|']['vat'], '0', 2), 'never more VAT than was charged');
            }

            $this->assertSame(['0.00', '0.00'], [$left['S|15.000|']['taxable'], $left['S|15.000|']['vat']], 'the returns give back the sale to the halala');
        }
    }

    /**
     * What is left of a sale and what came back once a return is saved, as the snapshot reads it.
     *
     * @param array<string, array{taxable: string, vat: string, returned_taxable: string, returned_vat: string}> $left
     *
     * @return array<string, array{taxable: string, vat: string, returned_taxable: string, returned_vat: string}>
     */
    private function after(array $left, VatCategoryTotal $return): array
    {
        $key = $return->key();

        return [...$left, $key => [
            'taxable'          => bcadd($left[$key]['taxable'], $return->taxable, 2),
            'vat'              => bcadd($left[$key]['vat'], $return->vat, 2),
            'returned_taxable' => bcsub($left[$key]['returned_taxable'], $return->taxable, 2),
            'returned_vat'     => bcsub($left[$key]['returned_vat'], $return->vat, 2),
        ]];
    }

    private static function absolute(string $amount): string
    {
        return ltrim($amount, '-');
    }
}
