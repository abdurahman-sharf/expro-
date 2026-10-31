<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\InvoiceTiming;
use CodeIgniter\Test\CIUnitTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ParityCaseTrait;

/**
 * The day typed on an invoice, its payment terms and the due date they give, as the reference
 * system showed them: a due date is the invoice date plus the days of the terms, and an invoice
 * that falls due today is not late.
 */
class InvoiceTimingTest extends CIUnitTestCase
{
    use ParityCaseTrait;

    private const TODAY = '2026-10-27';

    public function testDate_AcceptsADayUpToToday(): void
    {
        $this->assertSame('2026-10-27', InvoiceTiming::date('2026-10-27', self::TODAY));
        $this->assertSame('2026-10-01', InvoiceTiming::date(' 2026-10-01 ', self::TODAY));
    }

    public function testDate_TakesNothingTypedAsNoDate(): void
    {
        $this->assertNull(InvoiceTiming::date(null, self::TODAY));
        $this->assertNull(InvoiceTiming::date('', self::TODAY));
        $this->assertNull(InvoiceTiming::date('   ', self::TODAY));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unrealDateProvider(): array
    {
        return [
            'not a date'          => ['tomorrow'],
            'the day after today' => ['2026-10-28'],
            'a far future day'    => ['2099-01-01'],
            'february 30th'       => ['2026-02-30'],
            'month 13'            => ['2026-13-01'],
            'written the local way' => ['27/10/2026'],
            'with a time'         => ['2026-10-27 10:00:00'],
        ];
    }

    #[DataProvider('unrealDateProvider')]
    public function testDate_RejectsWhatIsNotARealDayUpToToday(string $typed): void
    {
        try {
            InvoiceTiming::date($typed, self::TODAY);
            $this->fail('The date should have been refused.');
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::InvoiceDateInvalid, $refusal->error);
        }
    }

    public function testDate_RejectsADayBeforeTheBooksStart(): void
    {
        try {
            InvoiceTiming::date('2025-12-31', self::TODAY, '2026-01-01');
            $this->fail('The date should have been refused.');
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::InvoiceDateBeforeStart, $refusal->error);
            $this->assertSame(['date' => '2025-12-31', 'min' => '2026-01-01'], $refusal->context);
        }
    }

    public function testDate_AcceptsTheDayTheBooksStart(): void
    {
        $this->assertSame('2026-01-01', InvoiceTiming::date('2026-01-01', self::TODAY, '2026-01-01'));
    }

    public function testTerms_AcceptsAWholeNumberOfDays(): void
    {
        $this->assertSame(30, InvoiceTiming::terms('30'));
        $this->assertSame(1, InvoiceTiming::terms(1));
        $this->assertSame(7, InvoiceTiming::terms('007'));
        $this->assertSame(InvoiceTiming::MAX_TERMS_DAYS, InvoiceTiming::terms((string) InvoiceTiming::MAX_TERMS_DAYS));
    }

    public function testTerms_TakesNothingOrZeroAsNoTerms(): void
    {
        $this->assertNull(InvoiceTiming::terms(null));
        $this->assertNull(InvoiceTiming::terms(''));
        $this->assertNull(InvoiceTiming::terms('0'));
        $this->assertNull(InvoiceTiming::terms(0));
    }

    /**
     * @return array<string, array{int|string}>
     */
    public static function badTermsProvider(): array
    {
        return [
            'negative'          => ['-1'],
            'a fraction'        => ['1.5'],
            'words'             => ['soon'],
            'beyond the limit'  => [(string) (InvoiceTiming::MAX_TERMS_DAYS + 1)],
            'a negative number' => [-5],
            'a very long number' => ['0000000030'],
        ];
    }

    #[DataProvider('badTermsProvider')]
    public function testTerms_RejectsWhatIsNotADayCountFromZeroUpToTheLimit(int|string $typed): void
    {
        try {
            InvoiceTiming::terms($typed);
            $this->fail('The terms should have been refused.');
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::PaymentTermsInvalid, $refusal->error);
            $this->assertSame(['max' => InvoiceTiming::MAX_TERMS_DAYS], $refusal->context);
        }
    }

    public function testDueDate_AddsTheTermsToTheInvoiceDate(): void
    {
        $this->assertSame('2026-11-26', InvoiceTiming::dueDate('2026-10-27', 30));
        $this->assertSame('2026-10-26', InvoiceTiming::dueDate('2026-10-25', 1));
        $this->assertSame('2026-11-03', InvoiceTiming::dueDate('2026-10-27', 7));
        $this->assertSame('2027-03-01', InvoiceTiming::dueDate('2027-02-28', 1));
    }

    public function testDueDate_IsTheInvoiceDatePlusTheTermsAsTheReferenceSystemShowedOnAnInvoiceOfTheTwentyFifth(): void
    {
        $case = $this->referenceCase('T-31', 'due_date', 'due-date-is-the-invoice-date-plus-its-payment-terms', 'exact');
        $date = DateTimeImmutable::createFromFormat('!d/m/Y', $case['inputs']['invoice_date']);
        $due  = InvoiceTiming::dueDate($date->format('Y-m-d'), (int) $case['inputs']['payment_terms_days']);

        $this->assertSameAsFixture(['due_date' => $case['expected']['due_date']], ['due_date' => DateTimeImmutable::createFromFormat('!Y-m-d', $due)->format('d/m/Y')], 'due date');
    }

    public function testDueDate_IsTheInvoiceDateWhenThereAreNoTerms(): void
    {
        $this->assertSame('2026-10-20', InvoiceTiming::dueDate('2026-10-20', null));
    }

    public function testIsOverdue_IsTrueOnlyWhenSomethingIsOwedAndTheDueDateIsBeforeToday(): void
    {
        $this->assertTrue(InvoiceTiming::isOverdue('2026-10-26', self::TODAY, true));
        $this->assertFalse(InvoiceTiming::isOverdue('2026-10-27', self::TODAY, true));
        $this->assertFalse(InvoiceTiming::isOverdue('2026-11-24', self::TODAY, true));
        $this->assertFalse(InvoiceTiming::isOverdue('2026-10-26', self::TODAY, false));
    }
}
