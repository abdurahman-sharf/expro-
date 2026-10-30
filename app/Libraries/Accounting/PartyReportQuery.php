<?php

namespace App\Libraries\Accounting;

/**
 * What a screen of customers or suppliers reads from the request: the day an aged ledger is read
 * on and the length of its buckets, or the period a balance report covers. What is not a day, or
 * a period that ends before it starts, is refused and the report is read for today instead, with
 * the refusal left to say why. The day is given only when the request names one, so a statement
 * without it stays the position of today.
 */
final readonly class PartyReportQuery
{
    private function __construct(
        public ReportPeriod $period,
        public ?string $asOf,
        public string $from,
        public string $to,
        public int $days,
        public bool $showEmpty,
        public ?AccountingException $refusal,
    ) {
    }

    public static function forAging(mixed $input, string $today): self
    {
        return self::read($input, $today, false);
    }

    public static function forBalances(mixed $input, string $today): self
    {
        return self::read($input, $today, true);
    }

    public function day(): string
    {
        return $this->period->to;
    }

    private static function read(mixed $input, string $today, bool $withFrom): self
    {
        $get  = is_array($input) ? $input : [];
        $text = static fn (string $name): string => isset($get[$name]) && is_string($get[$name]) ? trim($get[$name]) : '';

        $from    = $withFrom ? $text('from') : '';
        $to      = $text('to') === '' ? $today : $text('to');
        $days    = ctype_digit($text('period')) && strlen($text('period')) <= 4 ? (int) $text('period') : AgingBuckets::DEFAULT_PERIOD;
        $refusal = null;

        try {
            $period = ReportPeriod::of($from, $to);
        } catch (AccountingException $exception) {
            $refusal = $exception;
            $period  = ReportPeriod::of(null, $today);
        }

        $asOf = $refusal === null && $text('to') !== '' ? $period->to : null;

        return new self($period, $asOf, $from, $to, max(1, min($days, AgingBuckets::MAX_PERIOD)), $text('empty') === '1', $refusal);
    }
}
