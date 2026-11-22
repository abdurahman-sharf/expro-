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
 * The boxes of the draft return for a period worked out by hand, from a tax report shaped as the books shape it: only
 * what carries tax is in it, by rate, and what the sales saved under the Saudi rules carried at no tax comes from
 * their categories. The sales without tax saved before the rules are never put in a box until someone shares them out.
 */
class VatReturnDraftTest extends CIUnitTestCase
{
    private const CATEGORIES = ['citizens' => '30', 'zero' => '120', 'exports' => '100', 'exempt' => '50', 'outside' => '10'];

    private function section(TaxSection $section, string $base, string $tax, array $rates = []): TaxSectionFigures
    {
        return new TaxSectionFigures($section, $base, $tax, 1, $rates, 0, '0', '0');
    }

    /**
     * @param array<string, TaxSectionFigures> $changed By section value.
     */
    private function report(array $changed = [], int $unmatched = 0, bool $routed = true): TaxReportResult
    {
        $sections = [
            $this->section(TaxSection::SalesInvoices, '1000.0000', '150.0000', [new TaxRateRow('15', '1000.0000', '150.0000', 2)]),
            $this->section(TaxSection::SalesReturns, '-100.0000', '-15.0000', [new TaxRateRow('15', '-100.0000', '-15.0000', 1)]),
            $this->section(TaxSection::Purchases, '-500.0000', '-75.0000', [new TaxRateRow('15', '-500.0000', '-75.0000', 1)]),
            $this->section(TaxSection::PurchaseReturns, '50.0000', '7.5000', [new TaxRateRow('15', '50.0000', '7.5000', 1)]),
            $this->section(TaxSection::Expenses, '-200.0000', '-30.0000'),
            $this->section(TaxSection::Revenues, '40.0000', '6.0000'),
        ];

        foreach ($sections as $index => $figures) {
            $sections[$index] = $changed[$figures->section->value] ?? $figures;
        }

        return new TaxReportResult($sections, [], ReportScope::resolve(null, null), '2026-10-01', '2026-10-31', TaxBasis::Accrual, null, 0, $unmatched, $routed, true);
    }

    public function testBoxes_PutsEveryFigureOfThePeriodInItsBoxAndWorksOutWhatIsDue(): void
    {
        $draft = VatReturnDraft::boxes($this->report(), self::CATEGORIES, ['14' => ['vat' => '3.00'], '15' => ['vat' => '5.00']]);
        $boxes = $draft['boxes'];

        $this->assertSame(['amount' => '940.00', 'vat' => '141.00'], $boxes['1'], 'sales at 15% less the return, and the receipt voucher');
        $this->assertSame(['amount' => '30.00', 'vat' => '0.00'], $boxes['2'], 'private education and healthcare to citizens, from their categories');
        $this->assertSame(['amount' => '120.00', 'vat' => '0.00'], $boxes['3']);
        $this->assertSame(['amount' => '100.00', 'vat' => '0.00'], $boxes['4']);
        $this->assertSame(['amount' => '50.00', 'vat' => '0.00'], $boxes['5']);
        $this->assertSame(['amount' => '1240.00', 'vat' => '141.00'], $boxes['6'], 'every sale but what is out of scope');
        $this->assertSame(['amount' => '650.00', 'vat' => '97.50'], $boxes['7'], 'purchases at 15% less the return, and the expense');
        $this->assertSame(['amount' => '0.00', 'vat' => '0.00'], $boxes['10'], 'the books do not tell a zero-rated purchase: it is typed in');
        $this->assertSame(['amount' => '650.00', 'vat' => '97.50'], $boxes['12']);
        $this->assertSame('43.50', $boxes['13']['vat']);
        $this->assertSame('41.50', $boxes['16']['vat'], 'with the correction of an earlier period and the credit carried forward');
        $this->assertSame(['0.00', [], 0, true], [$draft['unclassified'], $draft['blockers'], $draft['unmatched'], $draft['ties']]);
    }

    public function testBoxes_LeavesTheUntaxedSalesSavedBeforeTheRulesOutOfEveryBoxUntilTheyAreSharedOut(): void
    {
        $unreviewed = VatReturnDraft::boxes($this->report(), self::CATEGORIES, [], '200');

        $this->assertSame('200.00', $unreviewed['unclassified']);
        $this->assertSame(['120.00', '100.00', '50.00', '1240.00'], [$unreviewed['boxes']['3']['amount'], $unreviewed['boxes']['4']['amount'], $unreviewed['boxes']['5']['amount'], $unreviewed['boxes']['6']['amount']], 'nothing is guessed into box 3');
        $this->assertSame([VatReturnDraft::BLOCK_UNREVIEWED], $unreviewed['blockers']);

        $reviewed = VatReturnDraft::boxes($this->report(), self::CATEGORIES, [], '200', ['zero' => '120', 'exports' => '50', 'outside' => '30', 'note' => 'Read from the invoices of October']);

        $this->assertSame(['240.00', '150.00', '50.00', '1410.00'], [$reviewed['boxes']['3']['amount'], $reviewed['boxes']['4']['amount'], $reviewed['boxes']['5']['amount'], $reviewed['boxes']['6']['amount']], 'what is out of scope stays out of every box');
        $this->assertSame([], $reviewed['blockers']);

        $this->assertSame([VatReturnDraft::BLOCK_UNREVIEWED], VatReturnDraft::boxes($this->report(), self::CATEGORIES, [], '200', ['zero' => '120', 'exports' => '50', 'note' => 'Read'])['blockers'], 'shared out short of the whole');
        $this->assertSame([VatReturnDraft::BLOCK_UNREVIEWED], VatReturnDraft::boxes($this->report(), self::CATEGORIES, [], '200', ['zero' => '200'])['blockers'], 'shared out without a note');
    }

    public function testBoxes_KeepsTheTaxTheReportHoldsOutsideItsRates(): void
    {
        $purchases = $this->section(TaxSection::Purchases, '-500.0000', '-225.0000', [new TaxRateRow('15', '-500.0000', '-75.0000', 1)]);
        $boxes     = VatReturnDraft::boxes($this->report([TaxSection::Purchases->value => $purchases]), self::CATEGORIES)['boxes'];

        $this->assertSame(['amount' => '650.00', 'vat' => '247.50'], $boxes['7'], 'the 150 of a purchase deleted in another period still counts');
    }

    public function testBoxes_OwesAndDeductsTheReverseChargeAtOnce(): void
    {
        $boxes = VatReturnDraft::boxes($this->report(), self::CATEGORIES, ['9' => ['amount' => '1000', 'vat' => '150'], '8' => ['amount' => '400', 'vat' => '60']])['boxes'];

        $this->assertSame(['amount' => '1000.00', 'vat' => '150.00'], $boxes['9']);
        $this->assertSame(['amount' => '2050.00', 'vat' => '307.50'], $boxes['12']);
        $this->assertSame('-16.50', $boxes['13']['vat'], 'the reverse charge is owed and deducted at once; the customs VAT is deducted');
    }

    public function testBoxes_AddsEachBoxAtFourDecimalsAndRoundsItOnce(): void
    {
        $purchases = $this->section(TaxSection::Purchases, '-200.0000', '-20.0060', [new TaxRateRow('15', '-100.0000', '-15.0045', 1), new TaxRateRow('5', '-100.0000', '-5.0015', 1)]);
        $boxes     = VatReturnDraft::boxes($this->report([TaxSection::Purchases->value => $purchases, TaxSection::PurchaseReturns->value => $this->section(TaxSection::PurchaseReturns, '0', '0'), TaxSection::Expenses->value => $this->section(TaxSection::Expenses, '0', '0')]), self::CATEGORIES)['boxes'];

        $this->assertSame(['amount' => '200.00', 'vat' => '20.01'], $boxes['7'], '20.006 rounds to 20.01; added by truncating it came to 20.00');
    }

    public function testBoxes_KeepsADraftFromApprovalWhileTheReportIsNotExplainedOrDoesNotTie(): void
    {
        $this->assertSame([VatReturnDraft::BLOCK_UNMATCHED], VatReturnDraft::boxes($this->report([], 2), self::CATEGORIES)['blockers']);
        $this->assertSame([], VatReturnDraft::boxes($this->report([], 2), self::CATEGORIES, [], '0', ['unmatched_note' => 'Two purchases deleted after the period closed'])['blockers']);
        $this->assertSame([VatReturnDraft::BLOCK_UNTIED], VatReturnDraft::boxes($this->report([], 0, false), self::CATEGORIES)['blockers']);
    }

    public function testBoxes_TakesWhatTheBooksDoNotHoldAsItIsTyped(): void
    {
        $boxes = VatReturnDraft::boxes($this->report(), self::CATEGORIES, ['8' => ['amount' => '1000', 'vat' => '150'], '10' => ['amount' => '70'], '11' => ['amount' => 'not a number']])['boxes'];

        $this->assertSame(['amount' => '1000.00', 'vat' => '150.00'], $boxes['8']);
        $this->assertSame(['amount' => '70.00', 'vat' => '0.00'], $boxes['10']);
        $this->assertSame(['amount' => '0.00', 'vat' => '0.00'], $boxes['11']);
        $this->assertSame('247.50', $boxes['12']['vat']);
        $this->assertSame(['8', '9', '10', '11', '14', '15'], VatReturnDraft::MANUAL);
    }
}
