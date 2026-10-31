<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\ProfitPeriodKind;
use CodeIgniter\Test\CIUnitTestCase;
use DateTimeImmutable;

/**
 * How a range is cut into calendar months, quarters and years, and what each column is called.
 */
final class ProfitPeriodKindTest extends CIUnitTestCase
{
    public function testFromRequest_ReadsTheThreeKindsAndFallsBackToMonthly(): void
    {
        $this->assertSame(ProfitPeriodKind::Monthly, ProfitPeriodKind::fromRequest('monthly'));
        $this->assertSame(ProfitPeriodKind::Quarterly, ProfitPeriodKind::fromRequest(' quarterly '));
        $this->assertSame(ProfitPeriodKind::Yearly, ProfitPeriodKind::fromRequest('yearly'));
        $this->assertSame(ProfitPeriodKind::Monthly, ProfitPeriodKind::fromRequest('weekly'));
        $this->assertSame(ProfitPeriodKind::Monthly, ProfitPeriodKind::fromRequest(['yearly']));
        $this->assertSame(ProfitPeriodKind::Monthly, ProfitPeriodKind::fromRequest(null));
    }

    public function testAround_GivesTheFirstAndTheLastDayOfTheCalendarPeriodTheDayFallsIn(): void
    {
        $day = new DateTimeImmutable('2026-11-17');

        $this->assertSame(['2026-11-01', '2026-11-30'], array_map(static fn (DateTimeImmutable $date): string => $date->format('Y-m-d'), ProfitPeriodKind::Monthly->around($day)));
        $this->assertSame(['2026-10-01', '2026-12-31'], array_map(static fn (DateTimeImmutable $date): string => $date->format('Y-m-d'), ProfitPeriodKind::Quarterly->around($day)));
        $this->assertSame(['2026-01-01', '2026-12-31'], array_map(static fn (DateTimeImmutable $date): string => $date->format('Y-m-d'), ProfitPeriodKind::Yearly->around($day)));
        $this->assertSame(['2028-02-01', '2028-02-29'], array_map(static fn (DateTimeImmutable $date): string => $date->format('Y-m-d'), ProfitPeriodKind::Monthly->around(new DateTimeImmutable('2028-02-10'))), 'February of a leap year');
    }

    public function testLabel_NamesAColumnByItsMonthQuarterOrYear(): void
    {
        $this->assertSame('2026-10', ProfitPeriodKind::Monthly->label(new DateTimeImmutable('2026-10-01')));
        $this->assertSame('Q4 2026', ProfitPeriodKind::Quarterly->label(new DateTimeImmutable('2026-10-01')));
        $this->assertSame('Q1 2026', ProfitPeriodKind::Quarterly->label(new DateTimeImmutable('2026-01-01')));
        $this->assertSame('2026', ProfitPeriodKind::Yearly->label(new DateTimeImmutable('2026-01-01')));
    }
}
