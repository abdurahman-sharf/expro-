<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\DocumentCurrency;
use App\Libraries\Accounting\ForeignConversion;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class ForeignConversionTest extends CIUnitTestCase
{
    /**
     * @param list<JournalLineDraft> $lines
     */
    private function draft(array $lines): JournalDraft
    {
        return new JournalDraft(date: '2026-10-27', lines: $lines, sourceType: 'sale', sourceId: 1, employeeId: 1);
    }

    /**
     * @return array{string, string}
     */
    private function sums(JournalDraft $draft): array
    {
        $debit = $credit = '0';

        foreach ($draft->lines as $line) {
            $debit  = bcadd($debit, $line->debit, 4);
            $credit = bcadd($credit, $line->credit, 4);
        }

        return [$debit, $credit];
    }

    public function testToBaseConvertsTheObservedInvoiceLineByLineAndItBalancesWithoutACorrection(): void
    {
        $draft = $this->draft([
            new JournalLineDraft(1, debit: '268.3300'),
            new JournalLineDraft(2, credit: '35.0000'),
            new JournalLineDraft(3, credit: '233.3300'),
        ]);

        $base = ForeignConversion::toBase($draft, new DocumentCurrency('USD', '3.75000000', 2), 2, 2);

        $this->assertSame(['1006.2400', '0'], [$base->lines[0]->debit, $base->lines[0]->credit]);
        $this->assertSame('131.2500', $base->lines[1]->credit);
        $this->assertSame('874.9900', $base->lines[2]->credit);
        $this->assertSame(['USD', '3.75000000'], [$base->currencyCode, $base->exchangeRate]);
        $this->assertSame(['268.3300', '35.0000', '233.3300'], array_map(static fn (JournalLineDraft $line): ?string => $line->foreignAmount, $base->lines));
        $this->assertSame(['USD', 'USD', 'USD'], array_map(static fn (JournalLineDraft $line): ?string => $line->currencyCode, $base->lines));
    }

    public function testToBasePutsTheRoundingDifferenceOnTheNamedLineAndOnlyThere(): void
    {
        $draft = $this->draft([
            new JournalLineDraft(1, debit: '100.0000'),
            new JournalLineDraft(2, credit: '33.3300'),
            new JournalLineDraft(3, credit: '33.3300'),
            new JournalLineDraft(4, credit: '33.3400'),
        ]);

        $base = ForeignConversion::toBase($draft, new DocumentCurrency('USD', '1.00500000', 2), 2, 3);

        $this->assertSame('100.5000', $base->lines[0]->debit);
        $this->assertSame('33.5000', $base->lines[1]->credit);
        $this->assertSame('33.5000', $base->lines[2]->credit);
        $this->assertSame('33.5000', $base->lines[3]->credit);
        $this->assertSame(['100.5000', '100.5000'], $this->sums($base));
    }

    public function testToBaseTakesTheDifferenceOnADebitLineToo(): void
    {
        $draft = $this->draft([
            new JournalLineDraft(1, debit: '33.3300'),
            new JournalLineDraft(2, debit: '33.3300'),
            new JournalLineDraft(3, debit: '33.3400'),
            new JournalLineDraft(4, credit: '100.0000'),
        ]);

        $base = ForeignConversion::toBase($draft, new DocumentCurrency('USD', '1.00500000', 2), 2, 2);

        $this->assertSame('33.5000', $base->lines[2]->debit);
        $this->assertSame(['100.5000', '100.5000'], $this->sums($base));
    }

    public function testToBaseRoundsToZeroDecimalsOfABaseWithoutFractions(): void
    {
        $draft = $this->draft([
            new JournalLineDraft(1, debit: '23.5000'),
            new JournalLineDraft(2, credit: '23.5000'),
        ]);

        $base = ForeignConversion::toBase($draft, new DocumentCurrency('USD', '3.75000000', 2), 0, 1);

        $this->assertSame(['88.0000', '88.0000'], [$base->lines[0]->debit, $base->lines[1]->credit]);
    }

    public function testToBaseRoundsToThreeDecimalsOfABaseWithMills(): void
    {
        $draft = $this->draft([
            new JournalLineDraft(1, debit: '30.3750'),
            new JournalLineDraft(2, credit: '30.3750'),
        ]);

        $base = ForeignConversion::toBase($draft, new DocumentCurrency('KWD', '12.04917000', 3), 3, 1);

        $this->assertSame('365.9940', $base->lines[0]->debit);
        $this->assertSame('365.9940', $base->lines[1]->credit);
    }

    public function testToBaseRefusesADifferenceThatIsMoreThanRounding(): void
    {
        $draft = $this->draft([
            new JournalLineDraft(1, debit: '100.0000'),
            new JournalLineDraft(2, credit: '60.0000'),
        ]);

        try {
            ForeignConversion::toBase($draft, new DocumentCurrency('USD', '3.75000000', 2), 2, 1);
            $this->fail('A refusal was expected.');
        } catch (PostingException $exception) {
            $this->assertSame(PostingError::ForeignRoundingTooBig, $exception->error);
        }
    }

    public function testToBaseRefusesALineThatTheDifferenceWouldTurnToNothing(): void
    {
        $draft = $this->draft([
            new JournalLineDraft(1, debit: '1.0000'),
            new JournalLineDraft(2, credit: '0.0100'),
            new JournalLineDraft(3, credit: '0.9900'),
        ]);

        $this->expectException(PostingException::class);

        ForeignConversion::toBase($draft, new DocumentCurrency('USD', '0.00300000', 2), 2, 1);
    }

    public function testToBaseRefusesALineThatIsNotInTheJournal(): void
    {
        $draft = $this->draft([new JournalLineDraft(1, debit: '1.0000'), new JournalLineDraft(2, credit: '1.0000')]);

        $this->expectException(PostingException::class);

        ForeignConversion::toBase($draft, new DocumentCurrency('USD', '3.75000000', 2), 2, 5);
    }

    public function testIndexOfFindsTheLineOfAnAccountAndRefusesAnAccountThatIsNotThere(): void
    {
        $draft = $this->draft([new JournalLineDraft(7, debit: '1.0000'), new JournalLineDraft(9, credit: '1.0000')]);

        $this->assertSame(1, ForeignConversion::indexOf($draft, 9));

        $this->expectException(PostingException::class);

        ForeignConversion::indexOf($draft, 8);
    }

    public function testToBaseKeepsTheDescriptionPartyAndCostCenterOfEveryLine(): void
    {
        $draft = $this->draft([
            new JournalLineDraft(1, debit: '10.0000', description: 'owed', partyType: 'customer', partyId: 5),
            new JournalLineDraft(2, credit: '10.0000', description: 'earned', costCenterId: 3),
        ]);

        $base = ForeignConversion::toBase($draft, new DocumentCurrency('USD', '2.00000000', 2), 2, 1);

        $this->assertSame(['owed', 'customer', 5], [$base->lines[0]->description, $base->lines[0]->partyType, $base->lines[0]->partyId]);
        $this->assertSame(['earned', 3], [$base->lines[1]->description, $base->lines[1]->costCenterId]);
        $this->assertSame(['sale', 1, '2026-10-27'], [$base->sourceType, $base->sourceId, $base->date]);
    }
}
