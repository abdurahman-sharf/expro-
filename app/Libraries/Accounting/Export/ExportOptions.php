<?php

namespace App\Libraries\Accounting\Export;

/**
 * What a person asks of the page of a PDF file: its orientation and the size of the type. A value that is
 * not one of those allowed is the default, so a hand-made address never fails.
 */
final readonly class ExportOptions
{
    public const ORIENTATION_PORTRAIT  = 'portrait';
    public const ORIENTATION_LANDSCAPE = 'landscape';
    public const MIN_FONT_SIZE         = 10;
    public const MAX_FONT_SIZE         = 50;
    public const DEFAULT_FONT_SIZE     = 10;

    public function __construct(public ?string $orientation = null, public int $fontSize = self::DEFAULT_FONT_SIZE)
    {
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query): self
    {
        $orientation = $query['orientation'] ?? null;
        $size        = $query['font_size'] ?? null;

        return new self(
            in_array($orientation, [self::ORIENTATION_PORTRAIT, self::ORIENTATION_LANDSCAPE], true) ? $orientation : null,
            is_string($size) && ctype_digit($size) && (int) $size >= self::MIN_FONT_SIZE && (int) $size <= self::MAX_FONT_SIZE ? (int) $size : self::DEFAULT_FONT_SIZE,
        );
    }

    public function landscape(int $columns): bool
    {
        return $this->orientation === null ? $columns > 6 : $this->orientation === self::ORIENTATION_LANDSCAPE;
    }
}
