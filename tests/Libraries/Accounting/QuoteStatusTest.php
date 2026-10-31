<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\QuoteStatus;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class QuoteStatusTest extends CIUnitTestCase
{
    public function testCases_ListTheEightObservedStates(): void
    {
        $this->assertSame(
            ['Draft' => -1, 'Open' => 0, 'Sent' => 1, 'Viewed' => 2, 'Replied' => 3, 'Approved' => 4, 'Converted' => 5, 'Ordered' => 6],
            array_column(array_map(static fn (QuoteStatus $state): array => [$state->name, $state->value], QuoteStatus::cases()), 1, 0),
        );
    }

    public function testFromStored_ReadsAQuoteThatNeverHadAStateAsOpen(): void
    {
        $this->assertSame(QuoteStatus::Open, QuoteStatus::fromStored(null));
        $this->assertSame(QuoteStatus::Open, QuoteStatus::fromStored(''));
        $this->assertSame(QuoteStatus::Open, QuoteStatus::fromStored('0'));
    }

    public function testFromStored_ReadsEveryStoredValueAndTreatsWhatItDoesNotKnowAsOpen(): void
    {
        foreach (QuoteStatus::cases() as $state) {
            $this->assertSame($state, QuoteStatus::fromStored((string) $state->value));
            $this->assertSame($state, QuoteStatus::fromStored($state->value));
        }

        $this->assertSame(QuoteStatus::Open, QuoteStatus::fromStored('99'));
    }

    public function testLanguageKey_NamesTheLineOfEachState(): void
    {
        foreach (QuoteStatus::cases() as $state) {
            $this->assertSame('Sales.quote_status_' . strtolower($state->name), $state->languageKey());
            $this->assertNotSame($state->languageKey(), lang($state->languageKey()), $state->name . ' has a line in the language files');
        }
    }

    public function testManual_ListsTheStatesAnEmployeeMaySetByHand(): void
    {
        $this->assertSame(
            [QuoteStatus::Open, QuoteStatus::Sent, QuoteStatus::Viewed, QuoteStatus::Replied, QuoteStatus::Approved, QuoteStatus::Ordered],
            QuoteStatus::manual(),
        );
    }

    /**
     * @return array<string, array{0: QuoteStatus, 1: list<QuoteStatus>}>
     */
    public static function transitionProvider(): array
    {
        return [
            'a draft is only opened'                        => [QuoteStatus::Draft, [QuoteStatus::Open]],
            'an open quote waits, is answered or approved'  => [QuoteStatus::Open, [QuoteStatus::Sent, QuoteStatus::Viewed, QuoteStatus::Replied, QuoteStatus::Approved]],
            'a sent quote waits, is answered or approved'   => [QuoteStatus::Sent, [QuoteStatus::Open, QuoteStatus::Viewed, QuoteStatus::Replied, QuoteStatus::Approved]],
            'a viewed quote waits, is answered or approved' => [QuoteStatus::Viewed, [QuoteStatus::Open, QuoteStatus::Sent, QuoteStatus::Replied, QuoteStatus::Approved]],
            'a replied quote waits or is approved'          => [QuoteStatus::Replied, [QuoteStatus::Open, QuoteStatus::Sent, QuoteStatus::Viewed, QuoteStatus::Approved]],
            'an approved quote is reopened or ordered'      => [QuoteStatus::Approved, [QuoteStatus::Open, QuoteStatus::Ordered]],
            'an ordered quote goes back to approved'        => [QuoteStatus::Ordered, [QuoteStatus::Approved]],
            'a converted quote is final'                    => [QuoteStatus::Converted, []],
        ];
    }

    /**
     * @param list<QuoteStatus> $allowed
     */
    #[DataProvider('transitionProvider')]
    public function testNext_ListsTheStatesAQuoteMayMoveToFromEachState(QuoteStatus $from, array $allowed): void
    {
        $this->assertSame($allowed, $from->next());

        foreach (QuoteStatus::cases() as $to) {
            $this->assertSame(in_array($to, $allowed, true), $from->canMoveTo($to), $from->name . ' to ' . $to->name);
        }
    }

    public function testNext_NeverLeadsBackToADraftOrToConvertedByHand(): void
    {
        foreach (QuoteStatus::cases() as $from) {
            $this->assertNotContains(QuoteStatus::Draft, $from->next(), $from->name);
            $this->assertNotContains(QuoteStatus::Converted, $from->next(), $from->name);
            $this->assertNotContains($from, $from->next(), $from->name . ' is not a move to itself');
        }
    }

    public function testNext_OnlyLeadsToTheStatesAnEmployeeMaySet(): void
    {
        foreach (QuoteStatus::cases() as $from) {
            foreach ($from->next() as $to) {
                $this->assertContains($to, QuoteStatus::manual(), $from->name . ' to ' . $to->name);
            }
        }
    }

    public function testCanConvert_AllowsEveryStateButADraftAndAConvertedQuote(): void
    {
        foreach (QuoteStatus::cases() as $state) {
            $this->assertSame(! in_array($state, [QuoteStatus::Draft, QuoteStatus::Converted], true), $state->canConvert(), $state->name);
        }
    }

    public function testIsLocked_HoldsOnlyForAConvertedQuote(): void
    {
        foreach (QuoteStatus::cases() as $state) {
            $this->assertSame($state === QuoteStatus::Converted, $state->isLocked(), $state->name);
        }
    }

    public function testIsAccepted_HoldsOnlyOnceTheCustomerApprovedOrOrdered(): void
    {
        foreach (QuoteStatus::cases() as $state) {
            $this->assertSame(in_array($state, [QuoteStatus::Approved, QuoteStatus::Ordered], true), $state->isAccepted(), $state->name);
        }
    }

    public function testAfterSave_GivesANewQuoteTheStateAsked(): void
    {
        $this->assertSame(QuoteStatus::Draft, QuoteStatus::afterSave(null, QuoteStatus::Draft));
        $this->assertSame(QuoteStatus::Open, QuoteStatus::afterSave(null, QuoteStatus::Open));
    }

    public function testAfterSave_LetsADraftBeOpenedByBeingSavedAsAQuote(): void
    {
        $this->assertSame(QuoteStatus::Open, QuoteStatus::afterSave(QuoteStatus::Draft, QuoteStatus::Open));
        $this->assertSame(QuoteStatus::Draft, QuoteStatus::afterSave(QuoteStatus::Draft, QuoteStatus::Draft));
    }

    public function testAfterSave_NeverChangesTheStateOfAQuoteThatIsNoLongerADraft(): void
    {
        foreach ([QuoteStatus::Open, QuoteStatus::Sent, QuoteStatus::Viewed, QuoteStatus::Replied, QuoteStatus::Approved, QuoteStatus::Ordered, QuoteStatus::Converted] as $stored) {
            $this->assertSame($stored, QuoteStatus::afterSave($stored, QuoteStatus::Draft), $stored->name . ' saved again as a draft');
            $this->assertSame($stored, QuoteStatus::afterSave($stored, QuoteStatus::Open), $stored->name . ' saved again as open');
        }
    }
}
