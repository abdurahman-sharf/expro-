<?php

namespace Tests\Libraries\Tax;

use App\Libraries\Tax\AdvanceVat;
use App\Libraries\Tax\VatCalculator;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class AdvanceVatTest extends CIUnitTestCase
{
    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function exactSplits(): array
    {
        return [
            'the example of the standard' => ['100.00', '15', '86.96', '13.04'],
            'a whole amount'              => ['115.00', '15', '100.00', '15.00'],
            'a small amount'              => ['0.12', '15', '0.10', '0.02'],
            'another rate'                => ['105.00', '5', '100.00', '5.00'],
        ];
    }

    #[DataProvider('exactSplits')]
    public function testSplit_KeepsTheVatTheTaxableAmountTimesTheRate(string $gross, string $percent, string $taxable, string $vat): void
    {
        $this->assertSame(['taxable' => $taxable, 'vat' => $vat], AdvanceVat::split($gross, $percent));
    }

    public function testSplit_LeavesTheCentOfAnAmountThatDoesNotSplitExactly(): void
    {
        $split = AdvanceVat::split('100.01', '15');

        $this->assertSame(['taxable' => '86.97', 'vat' => '13.05'], $split, 'the VAT is still the taxable amount times the rate');
        $this->assertSame('-0.01', bcsub('100.01', bcadd($split['taxable'], $split['vat'], 2), 2), 'the cent is the rounding of the document');
    }

    public function testSplit_KeepsTheRuleAndEveryAmountWithinACent(): void
    {
        for ($cents = 1; $cents <= 1000; $cents++) {
            $gross = bcdiv((string) ($cents * 37), '100', 2);
            $split = AdvanceVat::split($gross, '15');

            $this->assertSame(VatCalculator::round(bcmul($split['taxable'], '0.15', 6), 2), $split['vat'], $gross);
            $this->assertLessThanOrEqual(0, bccomp(ltrim(bcsub($gross, bcadd($split['taxable'], $split['vat'], 2), 2), '-'), '0.01', 2), $gross);
        }
    }

    public function testWithin_NeverTakesMoreVatThanIsLeftOfTheAdvance(): void
    {
        $this->assertSame(['taxable' => '0.06', 'vat' => '0.01'], AdvanceVat::within('0.07', ['taxable' => '100.00', 'vat' => '15.00'], '15'), 'a part that has room keeps its own split');
        $this->assertSame(['taxable' => '0.07', 'vat' => '0.00'], AdvanceVat::within('0.07', ['taxable' => '0.10', 'vat' => '0.00'], '15'), 'no VAT is left to give back');
        $this->assertSame(['taxable' => '0.05', 'vat' => '0.02'], AdvanceVat::within('0.07', ['taxable' => '0.05', 'vat' => '0.05'], '15'), 'no more taxable amount than is left');
    }

    public function testWithin_GivesBackNoMoreVatThanTheAdvanceCarriedForManySmallParts(): void
    {
        $charged = AdvanceVat::split('115.00', '15');
        $left    = $charged;

        for ($part = 0; $part < 1642; $part++) {
            $taken = AdvanceVat::within('0.07', $left, '15');
            $left  = ['taxable' => bcsub($left['taxable'], $taken['taxable'], 2), 'vat' => bcsub($left['vat'], $taken['vat'], 2)];

            $this->assertGreaterThanOrEqual(0, bccomp($left['vat'], '0', 2), 'never more VAT than the advance carried, after ' . ($part + 1) . ' parts');
            $this->assertGreaterThanOrEqual(0, bccomp($left['taxable'], '0', 2));
        }

        $this->assertSame(['100.00', '15.00'], [$charged['taxable'], $charged['vat']], 'parts split each on its own would have taken 16.42 of VAT');
    }
}
