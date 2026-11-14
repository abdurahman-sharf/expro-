<?php

namespace App\Libraries\Accounting\FamilyReports;

final readonly class StreamEvent
{
    public const OPEN  = 'open';
    public const ROW   = 'row';
    public const CLOSE = 'close';

    private function __construct(
        public string $kind,
        public ?string $title = null,
        public ?ReportRow $row = null,
    ) {
    }

    public static function open(?string $title): self
    {
        return new self(self::OPEN, $title);
    }

    public static function row(ReportRow $row): self
    {
        return new self(self::ROW, null, $row);
    }

    public static function close(?ReportRow $total = null): self
    {
        return new self(self::CLOSE, null, $total);
    }
}
