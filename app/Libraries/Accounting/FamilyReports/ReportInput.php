<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Roles\RecordScope;

final readonly class ReportInput
{
    public const PAGE_SIZE = 100;

    /**
     * @param array<string, mixed> $query        What the request carries; anything that is not a single text is read as nothing.
     * @param int|null             $journalOwner The employee whose journals alone may be read, when the reader may not read everyone's.
     */
    public function __construct(
        public ReportScope $scope,
        public ?int $viewerId,
        public array $query,
        public string $today,
        public ?int $journalOwner = null,
    ) {
    }

    public function text(string $name): string
    {
        $value = $this->query[$name] ?? null;

        return is_string($value) ? trim($value) : '';
    }

    public function id(string $name): int
    {
        $value = $this->text($name);

        return ctype_digit($value) && strlen($value) <= 10 ? (int) $value : 0;
    }

    public function flag(string $name): bool
    {
        return $this->text($name) === '1';
    }

    /**
     * @param list<string> $allowed
     */
    public function choice(string $name, array $allowed, string $default): string
    {
        $value = $this->text($name);

        return in_array($value, $allowed, true) ? $value : $default;
    }

    /**
     * @throws AccountingException When a day is not a day, or the period ends before it starts.
     */
    public function period(?string $defaultFrom = null): ReportPeriod
    {
        $from = $this->text('from');
        $to   = $this->text('to');

        if ($from === '' && $to === '' && $defaultFrom !== null) {
            $from = $defaultFrom;
        }

        return ReportPeriod::of($from === '' ? null : $from, $to === '' ? $this->today : $to);
    }

    public function readsOwnRecordsOnly(): bool
    {
        return (new RecordScope())->ownOnly($this->viewerId ?? RecordScope::sessionEmployee());
    }

    public function page(): int
    {
        return max(1, min($this->id('page'), 100000));
    }
}
