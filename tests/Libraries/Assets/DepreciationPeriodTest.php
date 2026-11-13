<?php

namespace Tests\Libraries\Assets;

use App\Libraries\Assets\DepreciationPeriod;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class DepreciationPeriodTest extends CIUnitTestCase
{
    public function testFromMonth_AcceptsAMonthAndGivesItsFirstAndLastDay(): void
    {
        $period = DepreciationPeriod::fromMonth('2026-02');

        $this->assertSame('2026-02-01', $period->start);
        $this->assertSame('2026-02-28', $period->end);
        $this->assertSame('2028-02-29', DepreciationPeriod::fromMonth('2028-02')->end);
        $this->assertSame('2026-02', $period->label());
    }

    public function testFromMonth_RejectsWhatIsNotAMonth(): void
    {
        foreach (['2026-13', '2026-00', '2026-2', '26-02', '2026-02-01', '', 'march'] as $text) {
            $this->assertNull(DepreciationPeriod::fromMonth($text), $text);
        }
    }

    public function testContaining_GivesTheMonthOfADay(): void
    {
        $period = DepreciationPeriod::containing('2026-03-17');

        $this->assertSame('2026-03-01', $period->start);
        $this->assertSame('2026-03-31', $period->end);
    }

    public function testPrevious_CrossesTheYear(): void
    {
        $this->assertSame('2025-12', DepreciationPeriod::fromMonth('2026-01')->previous()->label());
        $this->assertSame('2026-02', DepreciationPeriod::fromMonth('2026-03')->previous()->label());
    }

    public function testNext_CrossesTheYear(): void
    {
        $this->assertSame('2026-01', DepreciationPeriod::fromMonth('2025-12')->next()->label());
    }

    public function testHasEnded_IsTrueOnlyAfterTheLastDay(): void
    {
        $period = DepreciationPeriod::fromMonth('2026-03');

        $this->assertFalse($period->hasEnded('2026-03-31'));
        $this->assertTrue($period->hasEnded('2026-04-01'));
    }
}
