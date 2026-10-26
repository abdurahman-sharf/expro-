<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\ReportPeriod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReportPeriodTest extends TestCase
{
    public function testOf_KeepsTheTwoDays(): void
    {
        $period = ReportPeriod::of('2026-10-01', '2026-10-31');

        $this->assertSame('2026-10-01', $period->from);
        $this->assertSame('2026-10-31', $period->to);
    }

    public function testOf_StartsWithTheBooksWhenThereIsNoFirstDay(): void
    {
        $this->assertNull(ReportPeriod::of(null, '2026-10-31')->from);
        $this->assertNull(ReportPeriod::of('', '2026-10-31')->from);
        $this->assertNull(ReportPeriod::of('  ', '2026-10-31')->from);
    }

    public function testOf_AcceptsAOneDayPeriod(): void
    {
        $period = ReportPeriod::of('2026-10-31', '2026-10-31');

        $this->assertSame($period->from, $period->to);
    }

    /**
     * @return array<string, array{?string, string}>
     */
    public static function invalidDays(): array
    {
        return [
            'text'            => [null, 'yesterday'],
            'a day that is not'    => [null, '2026-02-30'],
            'the wrong order'      => [null, '31/10/2026'],
            'a bad first day'      => ['2026-13-01', '2026-10-31'],
            'empty last day'       => [null, ''],
        ];
    }

    #[DataProvider('invalidDays')]
    public function testOf_RefusesADayThatIsNotOne(?string $from, string $to): void
    {
        $this->expectException(AccountingException::class);

        try {
            ReportPeriod::of($from, $to);
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::InvalidDate, $refusal->error);

            throw $refusal;
        }
    }

    public function testOf_RefusesAPeriodThatEndsBeforeItStarts(): void
    {
        try {
            ReportPeriod::of('2026-11-01', '2026-10-31');
            $this->fail('A period that ends before it starts was accepted.');
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::ReportPeriodInvalid, $refusal->error);
            $this->assertSame(['from' => '2026-11-01', 'to' => '2026-10-31'], $refusal->context);
        }
    }
}
