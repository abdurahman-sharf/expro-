<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AuditPeriods;
use CodeIgniter\Test\CIUnitTestCase;

class AuditPeriodsTest extends CIUnitTestCase
{
    public function testResolve_ReadsTheCurrentAndTheLastMonthAsTheObservedRanges(): void
    {
        $this->assertSame(['from' => '2026-10-01', 'to' => '2026-10-27'], AuditPeriods::resolve(AuditPeriods::CURRENT_MONTH, '2026-10-27'));
        $this->assertSame(['from' => '2026-09-27', 'to' => '2026-10-27'], AuditPeriods::resolve(AuditPeriods::LAST_MONTH, '2026-10-27'));
        $this->assertSame(['from' => '2026-10-27', 'to' => '2026-10-27'], AuditPeriods::resolve(AuditPeriods::TODAY, '2026-10-27'));
    }

    public function testResolve_CutsTheLastMonthShortWhenTheMonthBeforeHasFewerDays(): void
    {
        $this->assertSame(['from' => '2026-02-28', 'to' => '2026-03-31'], AuditPeriods::resolve(AuditPeriods::LAST_MONTH, '2026-03-31'));
        $this->assertSame(['from' => '2028-02-29', 'to' => '2028-03-31'], AuditPeriods::resolve(AuditPeriods::LAST_MONTH, '2028-03-31'));
        $this->assertSame(['from' => '2025-12-15', 'to' => '2026-01-15'], AuditPeriods::resolve(AuditPeriods::LAST_MONTH, '2026-01-15'));
    }

    public function testResolve_RejectsANameThatIsNotOneOfThemAndADayThatIsNotADay(): void
    {
        $this->assertNull(AuditPeriods::resolve('', '2026-10-27'));
        $this->assertNull(AuditPeriods::resolve('last_year', '2026-10-27'));
        $this->assertNull(AuditPeriods::resolve(AuditPeriods::TODAY, '2026-02-30'));
        $this->assertNull(AuditPeriods::resolve(AuditPeriods::TODAY, 'today'));
    }
}
