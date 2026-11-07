<?php

namespace App\Libraries\Accounting\OperationalReports;

/**
 * What a report adds up beside what the books say for the same documents or movements, read from the
 * journals and not from the figures the report is built of. The two agree to the tolerance, which is
 * nothing unless the report says that rounding may part them.
 */
final readonly class TieCheck
{
    public function __construct(
        public string $labelKey,
        public string $reported,
        public string $ledger,
        public string $tolerance = '0.0000',
    ) {
    }

    /**
     * @return numeric-string
     */
    public function difference(): string
    {
        return Amounts::sub($this->reported, $this->ledger);
    }

    public function ties(): bool
    {
        $difference = $this->difference();

        return Amounts::compare(ltrim($difference, '-') === '' ? '0' : ltrim($difference, '-'), $this->tolerance) <= 0;
    }
}
