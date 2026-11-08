<?php

namespace App\Libraries\Accounting\OperationalReports;

/**
 * What a report adds up beside what the books say for the same documents or movements, read from the
 * journals and not from the figures the report is built of. The two agree to the tolerance, which is
 * nothing unless the report says that rounding may part them. A check that cannot be made, because the
 * amounts of the report and of the books are not in one currency, is refused by name and says why.
 */
final readonly class TieCheck
{
    /**
     * @param list<string> $refusalArguments
     */
    public function __construct(
        public string $labelKey,
        public string $reported,
        public string $ledger,
        public string $tolerance = '0.0000',
        public ?string $refusal = null,
        public array $refusalArguments = [],
    ) {
    }

    /**
     * @param list<string> $refusalArguments
     */
    public static function refused(string $labelKey, string $refusal, array $refusalArguments = []): self
    {
        return new self($labelKey, Amounts::ZERO, Amounts::ZERO, Amounts::ZERO, $refusal, $refusalArguments);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
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
        if ($this->isRefused()) {
            return false;
        }

        $difference = $this->difference();

        return Amounts::compare(ltrim($difference, '-') === '' ? '0' : ltrim($difference, '-'), $this->tolerance) <= 0;
    }
}
