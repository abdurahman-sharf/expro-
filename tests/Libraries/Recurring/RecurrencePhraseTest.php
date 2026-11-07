<?php

namespace Tests\Libraries\Recurring;

use App\Libraries\Recurring\RecurrencePhrase;
use App\Libraries\Recurring\RecurrenceUnit;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * How often a schedule repeats and when a reminder falls, said with the noun in the number the count asks for.
 *
 * @internal
 */
final class RecurrencePhraseTest extends CIUnitTestCase
{
    private string $locale;

    protected function setUp(): void
    {
        parent::setUp();

        $this->locale = service('language')->getLocale();
    }

    protected function tearDown(): void
    {
        service('language')->setLocale($this->locale);

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: RecurrenceUnit, 3: string}>
     */
    public static function everyProvider(): array
    {
        return [
            'one month'         => ['en', 1, RecurrenceUnit::Months, 'Every month'],
            'one day'           => ['en', 1, RecurrenceUnit::Days, 'Every day'],
            'one week'          => ['en', 1, RecurrenceUnit::Weeks, 'Every week'],
            'one year'          => ['en', 1, RecurrenceUnit::Years, 'Every year'],
            'two weeks'         => ['en', 2, RecurrenceUnit::Weeks, 'Every 2 weeks'],
            'eleven months'     => ['en', 11, RecurrenceUnit::Months, 'Every 11 months'],
            'arabic one month'  => ['ar-EG', 1, RecurrenceUnit::Months, 'كل شهر'],
            'arabic two months' => ['ar-EG', 2, RecurrenceUnit::Months, 'كل شهرين'],
            'arabic two years'  => ['ar-EG', 2, RecurrenceUnit::Years, 'كل سنتين'],
            'arabic three days' => ['ar-EG', 3, RecurrenceUnit::Days, 'كل 3 أيام'],
            'arabic eleven'     => ['ar-EG', 11, RecurrenceUnit::Months, 'كل 11 شهرًا'],
            'arabic hundred'    => ['ar-EG', 100, RecurrenceUnit::Weeks, 'كل 100 أسبوع'],
        ];
    }

    #[DataProvider('everyProvider')]
    public function testEvery_SaysTheUnitInTheNumberTheCountAsksFor(string $locale, int $count, RecurrenceUnit $unit, string $expected): void
    {
        service('language')->setLocale($locale);

        $this->assertSame($expected, RecurrencePhrase::every($count, $unit));
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: int|null, 3: string}>
     */
    public static function reminderProvider(): array
    {
        return [
            'on the issue date'    => ['en', 0, null, 'On the issue date'],
            'on the invoice date'  => ['en', 1, null, 'On the invoice date'],
            'on the due date'      => ['en', 2, null, 'On the due date'],
            'one day before'       => ['en', 3, 1, '1 day before the invoice date'],
            'five days before'     => ['en', 3, 5, '5 days before the invoice date'],
            'one day after'        => ['en', 4, 1, '1 day after the invoice date'],
            'zero days after'      => ['en', 4, 0, '0 days after the invoice date'],
            'arabic one day'       => ['ar-EG', 3, 1, 'قبل تاريخ الفاتورة بيوم'],
            'arabic two days'      => ['ar-EG', 3, 2, 'قبل تاريخ الفاتورة بيومين'],
            'arabic five days'     => ['ar-EG', 3, 5, 'قبل تاريخ الفاتورة بـ 5 أيام'],
            'arabic twelve days'   => ['ar-EG', 4, 12, 'بعد تاريخ الفاتورة بـ 12 يومًا'],
        ];
    }

    #[DataProvider('reminderProvider')]
    public function testReminder_SaysTheDaysInTheNumberTheCountAsksFor(string $locale, int $sendWhen, ?int $days, string $expected): void
    {
        service('language')->setLocale($locale);

        $this->assertSame($expected, RecurrencePhrase::reminder($sendWhen, $days));
    }
}
