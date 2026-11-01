<?php

namespace App\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use DateTimeImmutable;

/**
 * The days a report of the family reads from the request: a range, one day, every day before a day
 * or every day after it, or one of the shortcuts (last week, last month, the month so far, last year,
 * the year so far). A day is typed as year-month-day or as day/month/year. What is not a day, or a range
 * that ends before it starts, is refused and the report is read up to today instead, with the refusal
 * left to say why.
 */
final readonly class DateFilter
{
    public const MODES   = ['between', 'on', 'before', 'after'];
    public const PRESETS = ['last_week', 'last_month', 'month_to_date', 'last_year', 'year_to_date'];

    private function __construct(
        public ?string $from,
        public string $to,
        public string $mode,
        public string $preset,
        public string $fromInput,
        public string $toInput,
        public string $dateInput,
        public ?AccountingException $refusal,
    ) {
    }

    public static function read(mixed $input, string $today, string $defaultPreset = ''): self
    {
        $get  = is_array($input) ? $input : [];
        $text = static fn (string $name): string => isset($get[$name]) && is_string($get[$name]) ? trim($get[$name]) : '';

        $mode   = in_array($text('mode'), self::MODES, true) ? $text('mode') : 'between';
        $preset = in_array($text('preset'), self::PRESETS, true) ? $text('preset') : '';
        $echo   = [$text('from'), $text('to'), $text('date')];

        if ($preset === '' && $defaultPreset !== '' && $echo === ['', '', ''] && $text('preset') === '') {
            $preset = $defaultPreset;
        }

        try {
            [$from, $to] = $preset !== '' ? self::presetRange($preset, $today) : self::range($mode, $text('from'), $text('to'), $text('date'), $today);

            return new self($from, $to, $mode, $preset, $echo[0], $echo[1], $echo[2], null);
        } catch (AccountingException $refusal) {
            return new self(null, $today, $mode, $preset, $echo[0], $echo[1], $echo[2], $refusal);
        }
    }

    public function isRun(): bool
    {
        return $this->refusal === null;
    }

    /**
     * @return array{0: ?string, 1: string}
     *
     * @throws AccountingException
     */
    private static function range(string $mode, string $from, string $to, string $date, string $today): array
    {
        if ($mode === 'between') {
            $start = $from === '' ? null : self::day($from);
            $end   = $to === '' ? $today : self::day($to);

            if ($start !== null && $start > $end) {
                throw new AccountingException(AccountingError::ReportPeriodInvalid, 'The period ends before it starts.', ['from' => $start, 'to' => $end]);
            }

            return [$start, $end];
        }

        $day = self::day($date);

        return match ($mode) {
            'on'     => [$day, $day],
            'before' => [null, self::shift($day, '-1 day')],
            default  => [self::shift($day, '+1 day'), max($today, self::shift($day, '+1 day'))],
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function presetRange(string $preset, string $today): array
    {
        $day = new DateTimeImmutable($today);

        return match ($preset) {
            'last_week'     => [$day->modify('monday this week')->modify('-7 days')->format('Y-m-d'), $day->modify('monday this week')->modify('-1 day')->format('Y-m-d')],
            'last_month'    => [$day->modify('first day of last month')->format('Y-m-d'), $day->modify('last day of last month')->format('Y-m-d')],
            'month_to_date' => [$day->format('Y-m-01'), $today],
            'last_year'     => [((int) $day->format('Y') - 1) . '-01-01', ((int) $day->format('Y') - 1) . '-12-31'],
            default         => [$day->format('Y-01-01'), $today],
        };
    }

    /**
     * @throws AccountingException
     */
    private static function day(string $value): string
    {
        foreach (['!Y-m-d', '!d/m/Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);

            if ($date !== false && $date->format(substr($format, 1)) === $value) {
                return $date->format('Y-m-d');
            }
        }

        throw new AccountingException(AccountingError::InvalidDate, 'The date is not a valid date.');
    }

    private static function shift(string $day, string $by): string
    {
        return (new DateTimeImmutable($day))->modify($by)->format('Y-m-d');
    }
}
