<?php

namespace Tests\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\OperationalReports\DateFilter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The days of a report read from the request: a range, a single day, every day before or after one, the
 * five shortcuts, both ways of typing a day, and the refusal of what is not a day.
 */
class DateFilterTest extends CIUnitTestCase
{
    private const TODAY = '2026-10-27';

    private function read(array $get, string $default = ''): DateFilter
    {
        return DateFilter::read($get, self::TODAY, $default);
    }

    public function testRead_TakesARangeAndDefaultsTheEndToToday(): void
    {
        $range = $this->read(['from' => '2026-09-01', 'to' => '2026-09-30']);
        $open  = $this->read(['from' => '2026-09-01']);
        $none  = $this->read([]);

        $this->assertSame(['2026-09-01', '2026-09-30'], [$range->from, $range->to]);
        $this->assertSame(['2026-09-01', self::TODAY], [$open->from, $open->to]);
        $this->assertSame([null, self::TODAY], [$none->from, $none->to]);
        $this->assertTrue($none->isRun());
    }

    public function testRead_AcceptsADayTypedAsDayMonthYear(): void
    {
        $filter = $this->read(['from' => '01/09/2026', 'to' => '30/09/2026']);

        $this->assertSame(['2026-09-01', '2026-09-30'], [$filter->from, $filter->to]);
        $this->assertSame('01/09/2026', $filter->fromInput);
    }

    public function testRead_ReadsASingleDayAndEveryDayBeforeOrAfter(): void
    {
        $this->assertSame(['2026-10-20', '2026-10-20'], $this->pair($this->read(['mode' => 'on', 'date' => '2026-10-20'])));
        $this->assertSame([null, '2026-10-19'], $this->pair($this->read(['mode' => 'before', 'date' => '2026-10-20'])));
        $this->assertSame(['2026-10-21', self::TODAY], $this->pair($this->read(['mode' => 'after', 'date' => '2026-10-20'])));
        $this->assertSame(['2026-11-06', '2026-11-06'], $this->pair($this->read(['mode' => 'after', 'date' => '2026-11-05'])));
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private function pair(DateFilter $filter): array
    {
        return [$filter->from, $filter->to];
    }

    public function testRead_KnowsTheFiveShortcuts(): void
    {
        $this->assertSame(['2026-10-19', '2026-10-25'], $this->pair($this->read(['preset' => 'last_week'])));
        $this->assertSame(['2026-09-01', '2026-09-30'], $this->pair($this->read(['preset' => 'last_month'])));
        $this->assertSame(['2026-10-01', self::TODAY], $this->pair($this->read(['preset' => 'month_to_date'])));
        $this->assertSame(['2025-01-01', '2025-12-31'], $this->pair($this->read(['preset' => 'last_year'])));
        $this->assertSame(['2026-01-01', self::TODAY], $this->pair($this->read(['preset' => 'year_to_date'])));
    }

    public function testRead_IgnoresAShortcutItDoesNotKnow(): void
    {
        $this->assertSame([null, self::TODAY], $this->pair($this->read(['preset' => 'next_week'])));
    }

    public function testRead_StartsFromTheDefaultShortcutOnlyWhenGivenNoDayAtAll(): void
    {
        $this->assertSame(['2026-09-01', '2026-09-30'], $this->pair($this->read([], 'last_month')));
        $this->assertSame(['2026-10-01', '2026-10-05'], $this->pair($this->read(['from' => '2026-10-01', 'to' => '2026-10-05'], 'last_month')));
        $this->assertSame(['2026-10-01', self::TODAY], $this->pair($this->read(['preset' => 'month_to_date'], 'last_month')));
    }

    public function testRead_RefusesADayThatDoesNotExistAndAPeriodThatEndsBeforeItStarts(): void
    {
        $impossible = $this->read(['from' => '31/02/2026']);
        $words      = $this->read(['to' => 'yesterday']);
        $inverted   = $this->read(['from' => '2026-10-31', 'to' => '2026-10-01']);
        $single     = $this->read(['mode' => 'on']);

        $this->assertSame(AccountingError::InvalidDate, $impossible->refusal?->error);
        $this->assertSame(AccountingError::InvalidDate, $words->refusal?->error);
        $this->assertSame(AccountingError::ReportPeriodInvalid, $inverted->refusal?->error);
        $this->assertSame(AccountingError::InvalidDate, $single->refusal?->error);
        $this->assertFalse($impossible->isRun());
        $this->assertSame([null, self::TODAY], $this->pair($impossible));
    }

    public function testRead_IgnoresWhatIsNotText(): void
    {
        $filter = DateFilter::read(['from' => ['2026-01-01'], 'to' => ['x']], self::TODAY);

        $this->assertSame([null, self::TODAY], $this->pair($filter));
        $this->assertNull($filter->refusal);
        $this->assertSame([null, self::TODAY], $this->pair(DateFilter::read('nonsense', self::TODAY)));
    }
}
