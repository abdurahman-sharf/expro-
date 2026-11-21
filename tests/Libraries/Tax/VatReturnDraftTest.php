<?php

namespace Tests\Libraries\Tax;

use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\TaxBasis;
use App\Libraries\Accounting\TaxRateRow;
use App\Libraries\Accounting\TaxReportResult;
use App\Libraries\Accounting\TaxSection;
use App\Libraries\Accounting\TaxSectionFigures;
use App\Libraries\Tax\VatReturnDraft;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The boxes of the draft return for a period worked out by hand: sales and returns at 15% and at no tax, a receipt
 * voucher, purchases and a purchase return, an expense, and what the categories of the sales tell of the part that
 * carries no tax.
 */
class VatReturnDraftTest extends CIUnitTestCase
{
    private function section(TaxSection $section, string $base, string $tax, array $rates = []): TaxSectionFigures
    {
        return new TaxSectionFigures($section, $base, $tax, 1, $rates, 0, '0', '0');
    }

    private function report(): TaxReportResult
    {
        return new TaxReportResult(
            [
                $this->section(TaxSection::SalesInvoices, '1300.0000', '150.0000', [new TaxRateRow('15', '1000.0000', '150.0000', 2), new TaxRateRow('0', '300.0000', '0.0000', 2)]),
                $this->section(TaxSection::SalesReturns, '-100.0000', '-15.0000', [new TaxRateRow('15', '-100.0000', '-15.0000', 1)]),
                $this->section(TaxSection::Purchases, '-600.0000', '-75.0000', [new TaxRateRow('15', '-500.0000', '-75.0000', 1), new TaxRateRow('0', '-100.0000', '0.0000', 1)]),
                $this->section(TaxSection::PurchaseReturns, '50.0000', '7.5000', [new TaxRateRow('15', '50.0000', '7.5000', 1)]),
                $this->section(TaxSection::Expenses, '-200.0000', '-30.0000'),
                $this->section(TaxSection::Revenues, '40.0000', '6.0000'),
            ],
            [],
            ReportScope::resolve(null, null),
            '2026-10-01',
            '2026-10-31',
            TaxBasis::Accrual,
            null,
            0,
            0,
            true,
            true,
        );
    }

    public function testBoxes_PutsEveryFigureOfThePeriodInItsBoxAndWorksOutWhatIsDue(): void
    {
        $draft = VatReturnDraft::boxes($this->report(), ['zero' => '120', 'exports' => '100', 'exempt' => '50', 'outside' => '10'], ['14' => ['vat' => '3.00'], '15' => ['vat' => '5.00']]);
        $boxes = $draft['boxes'];

        $this->assertSame(['amount' => '940.00', 'vat' => '141.00'], $boxes['1'], 'sales at 15% less the return, and the receipt voucher');
        $this->assertSame(['amount' => '140.00', 'vat' => '0.00'], $boxes['3'], 'zero-rated sales, with the 20 the categories cannot tell');
        $this->assertSame(['amount' => '100.00', 'vat' => '0.00'], $boxes['4']);
        $this->assertSame(['amount' => '50.00', 'vat' => '0.00'], $boxes['5']);
        $this->assertSame(['amount' => '1230.00', 'vat' => '141.00'], $boxes['6']);
        $this->assertSame(['amount' => '650.00', 'vat' => '97.50'], $boxes['7'], 'purchases at 15% less the return, and the expense');
        $this->assertSame(['amount' => '100.00', 'vat' => '0.00'], $boxes['10']);
        $this->assertSame(['amount' => '750.00', 'vat' => '97.50'], $boxes['12']);
        $this->assertSame('43.50', $boxes['13']['vat']);
        $this->assertSame('41.50', $boxes['16']['vat'], 'with the correction of an earlier period and the credit carried forward');
        $this->assertSame('20.00', $draft['estimated']);
    }

    public function testBoxes_TakesWhatTheBooksDoNotHoldAsItIsTyped(): void
    {
        $boxes = VatReturnDraft::boxes($this->report(), ['zero' => '300', 'exports' => '0', 'exempt' => '0', 'outside' => '0'], ['8' => ['amount' => '1000', 'vat' => '150']])['boxes'];

        $this->assertSame(['amount' => '1000.00', 'vat' => '150.00'], $boxes['8']);
        $this->assertSame('247.50', $boxes['12']['vat']);
        $this->assertSame(['2', '8', '9', '11', '14', '15'], VatReturnDraft::MANUAL);
    }
}
