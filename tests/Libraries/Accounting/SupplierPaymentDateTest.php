<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\SupplierPaymentDate;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The date entered for a payment to a supplier: a real day, no later than today and, while the books
 * are on, no earlier than the day they start. What a payment saved before dates could be entered
 * shows is the day it was saved.
 */
class SupplierPaymentDateTest extends CIUnitTestCase
{
    private function refusal(mixed $value, ?string $today = '2026-10-28', ?string $booksStart = null): AccountingException
    {
        try {
            SupplierPaymentDate::entered($value, $today, $booksStart);
        } catch (AccountingException $exception) {
            return $exception;
        }

        $this->fail('The date was not refused.');
    }

    public function testEntered_AcceptsTodayAndEveryEarlierDay(): void
    {
        $this->assertSame('2026-10-28', SupplierPaymentDate::entered('2026-10-28', '2026-10-28'));
        $this->assertSame('2026-10-27', SupplierPaymentDate::entered('2026-10-27', '2026-10-28'));
        $this->assertSame('2020-02-29', SupplierPaymentDate::entered('2020-02-29', '2026-10-28'), 'A day of a leap year');
    }

    public function testEntered_TakesTodayFromTheClockWhenNoneIsGiven(): void
    {
        $this->assertSame(date('Y-m-d'), SupplierPaymentDate::entered(date('Y-m-d')));
        $this->assertSame(AccountingError::PaymentDateInFuture, $this->refusal(date('Y-m-d', strtotime('+1 day')), null)->error);
    }

    public function testEntered_RefusesTheDayAfterToday(): void
    {
        $exception = $this->refusal('2026-10-29');

        $this->assertSame(AccountingError::PaymentDateInFuture, $exception->error);
        $this->assertSame(['date' => '2026-10-29'], $exception->context);
        $this->assertSame(AccountingError::PaymentDateInFuture, $this->refusal('2027-01-01')->error);
    }

    public function testEntered_RefusesADayBeforeTheDayTheBooksStartAndAcceptsThatDay(): void
    {
        $exception = $this->refusal('2026-09-30', '2026-10-28', '2026-10-01');

        $this->assertSame(AccountingError::PaymentDateBeforeBooks, $exception->error);
        $this->assertSame(['date' => '2026-09-30', 'until' => '2026-10-01'], $exception->context);
        $this->assertSame('2026-10-01', SupplierPaymentDate::entered('2026-10-01', '2026-10-28', '2026-10-01'));
        $this->assertSame('2026-09-30', SupplierPaymentDate::entered('2026-09-30', '2026-10-28', null), 'Without a start of the books there is nothing to keep to');
    }

    public function testEntered_AsksTheFutureBeforeTheStartOfTheBooks(): void
    {
        $this->assertSame(AccountingError::PaymentDateInFuture, $this->refusal('2026-11-01', '2026-10-28', '2026-12-01')->error);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function notADayProvider(): array
    {
        return [
            'a day that does not exist' => ['2026-02-30'],
            'a month that does not exist' => ['2026-13-01'],
            'a day of a year that is not a leap year' => ['2026-02-29'],
            'words' => ['today'],
            'another order' => ['28/10/2026'],
            'one digit' => ['2026-1-5'],
            'a time with it' => ['2026-10-20 10:00:00'],
            'nothing' => [''],
            'only spaces' => ['   '],
            'nothing at all' => [null],
            'a number' => [20261020],
            'a list' => [['2026-10-20']],
        ];
    }

    #[DataProvider('notADayProvider')]
    public function testEntered_RefusesWhatIsNotARealDay(mixed $value): void
    {
        $this->assertSame(AccountingError::InvalidDate, $this->refusal($value)->error);
    }

    public function testEntered_IgnoresSpacesAroundTheDay(): void
    {
        $this->assertSame('2026-10-20', SupplierPaymentDate::entered(" 2026-10-20\n", '2026-10-28'));
    }

    public function testOf_GivesTheDateEnteredAndOtherwiseTheDayTheTimeWasSaved(): void
    {
        $this->assertSame('2026-10-20', SupplierPaymentDate::of('2026-10-20', '2026-10-28 09:30:00'));
        $this->assertSame('2026-10-28', SupplierPaymentDate::of(null, '2026-10-28 09:30:00'));
        $this->assertSame('2026-10-28', SupplierPaymentDate::of('', '2026-10-28 09:30:00'));
        $this->assertSame('2026-10-20', SupplierPaymentDate::of('2026-10-20 00:00:00', '2026-10-28 09:30:00'), 'Whatever the column holds is cut to the day');
    }
}
