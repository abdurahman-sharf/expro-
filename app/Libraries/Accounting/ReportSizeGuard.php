<?php

namespace App\Libraries\Accounting;

/**
 * What a report may hold on a screen before it is refused by name instead of failing with an empty page.
 * A report that keeps its rows may hold what the memory of the request leaves after the page itself, at the
 * measured cost of a row; a report that reads its rows one after the other is limited by the time that
 * takes, which is the number of rows a screen scans.
 */
final class ReportSizeGuard
{
    public const BASE_BYTES = 40 * 1_048_576;
    public const SCAN_ROWS  = 400_000;
    public const MIN_ROWS   = 1_000;

    /**
     * @return int What the memory of the request allows, in bytes, or the largest integer when it has no limit.
     */
    public static function memoryLimit(): int
    {
        $text = trim((string) ini_get('memory_limit'));

        if ($text === '' || $text === '-1') {
            return PHP_INT_MAX;
        }

        $number = (int) $text;

        return match (strtolower(substr($text, -1))) {
            'g'     => $number * 1_073_741_824,
            'm'     => $number * 1_048_576,
            'k'     => $number * 1024,
            default => $number,
        };
    }

    /**
     * @return int How many rows a report that keeps each of them in $bytesPerRow bytes may hold.
     */
    public static function memoryRows(int $bytesPerRow, ?int $limit = null): int
    {
        $limit ??= self::memoryLimit();

        if ($limit === PHP_INT_MAX) {
            return PHP_INT_MAX;
        }

        return max(self::MIN_ROWS, intdiv(max(0, $limit - self::BASE_BYTES), max(1, $bytesPerRow)));
    }

    /**
     * @param int $bytesPerRow What a row costs while it is held; 0 for a report that does not hold its rows.
     */
    public static function screenRows(int $bytesPerRow = 0, ?int $limit = null): int
    {
        return $bytesPerRow === 0 ? self::SCAN_ROWS : min(self::SCAN_ROWS, self::memoryRows($bytesPerRow, $limit));
    }

    /**
     * @throws AccountingException When the rows are more than a screen may hold.
     */
    public static function require(int $rows, int $maxRows, AccountingError $error = AccountingError::ReportRowsExceedScreen): void
    {
        if ($rows > $maxRows) {
            throw new AccountingException($error, 'The report holds more rows than a screen may show.', ['count' => $rows, 'max' => $maxRows]);
        }
    }
}
