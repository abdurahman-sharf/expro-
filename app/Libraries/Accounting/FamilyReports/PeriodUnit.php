<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use DateTimeImmutable;

enum PeriodUnit: string
{
    case Day   = 'day';
    case Week  = 'week';
    case Month = 'month';
    case Year  = 'year';

    public const MAX_BUCKETS = 1500;

    public function start(string $day): string
    {
        $date = new DateTimeImmutable($day);

        return match ($this) {
            self::Day   => $date->format('Y-m-d'),
            self::Week  => $date->modify('monday this week')->format('Y-m-d'),
            self::Month => $date->format('Y-m-01'),
            self::Year  => $date->format('Y-01-01'),
        };
    }

    public function end(string $start): string
    {
        return (new DateTimeImmutable($this->next($start)))->modify('-1 day')->format('Y-m-d');
    }

    public function next(string $start): string
    {
        $date = new DateTimeImmutable($start);

        return match ($this) {
            self::Day   => $date->modify('+1 day')->format('Y-m-d'),
            self::Week  => $date->modify('+7 days')->format('Y-m-d'),
            self::Month => $date->modify('+1 month')->format('Y-m-d'),
            self::Year  => $date->modify('+1 year')->format('Y-m-d'),
        };
    }

    public function label(string $start): string
    {
        $date = new DateTimeImmutable($start);

        return match ($this) {
            self::Day   => $start,
            self::Week  => $start . ' - ' . $this->end($start),
            self::Month => lang('Accounting_family_reports.month_' . (int) $date->format('n')) . ' ' . $date->format('Y'),
            self::Year  => $date->format('Y'),
        };
    }

    /**
     * The first day of every period from the one the first day is in to the one the last day is in.
     *
     * @return list<string>
     *
     * @throws AccountingException When there would be more periods than a screen can hold.
     */
    public function buckets(string $from, string $to): array
    {
        $starts = [];
        $start  = $this->start($from);

        while ($start <= $to) {
            $starts[] = $start;

            if (count($starts) > self::MAX_BUCKETS) {
                throw new AccountingException(AccountingError::ReportTooLarge, 'The period holds too many periods to list.', ['max' => self::MAX_BUCKETS]);
            }

            $start = $this->next($start);
        }

        return $starts;
    }

    public function defaultFrom(string $today): ?string
    {
        $date = new DateTimeImmutable($today);

        return match ($this) {
            self::Day   => $date->modify('-1 month')->format('Y-m-d'),
            self::Week  => $date->modify('-3 months')->format('Y-m-d'),
            self::Month => $date->modify('-1 year')->format('Y-m-d'),
            self::Year  => null,
        };
    }
}
