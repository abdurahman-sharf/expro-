<?php

namespace App\Libraries\Accounting\Export;

/**
 * A report as a grid of text: the rows of the file a controller writes for it, whatever the format the
 * file is asked in. A cell is text until it is an exact decimal that a spreadsheet may hold as a number
 * without changing a digit.
 */
final readonly class ReportSheet
{
    private const MAX_NUMBER_DIGITS  = 15;
    private const MAX_INTEGER_DIGITS = 9;

    /**
     * @param list<list<string>> $rows
     */
    public function __construct(public string $title, public array $rows)
    {
    }

    public static function fromCsv(string $csv, string $title): self
    {
        $handle = fopen('php://temp', 'r+b');

        fwrite($handle, str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv);
        rewind($handle);

        $rows = [];

        while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rows[] = $cells === [null] ? [] : array_map(static fn (?string $cell): string => (string) $cell, $cells);
        }

        fclose($handle);

        while ($rows !== [] && end($rows) === []) {
            array_pop($rows);
        }

        return new self($title, $rows);
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    public function columnCount(): int
    {
        return $this->rows === [] ? 0 : max(1, ...array_map('count', $this->rows));
    }

    /**
     * The first row that fills every column, which is the heading of the table in a file that opens with
     * a title and notes.
     */
    public function headerIndex(): ?int
    {
        $width = $this->columnCount();

        if ($width < 2) {
            return null;
        }

        foreach ($this->rows as $index => $row) {
            if (count($row) === $width) {
                return $index;
            }
        }

        return null;
    }

    /**
     * The digits a cell holds as a number, or null when it is text: a code with a leading zero, a long
     * identifier or anything that is not a plain decimal stays as it was written.
     *
     * @return array{value: string, decimals: int}|null
     */
    public static function number(string $cell): ?array
    {
        if (preg_match('/^(-?)(0|[1-9]\d*)(?:\.(\d+))?$/', $cell, $match) !== 1) {
            return null;
        }

        $fraction = $match[3] ?? '';
        $integer  = $match[2];

        if ($fraction === '' && strlen($integer) > self::MAX_INTEGER_DIGITS) {
            return null;
        }

        if (strlen($integer) + strlen($fraction) > self::MAX_NUMBER_DIGITS) {
            return null;
        }

        $negative = $match[1] === '-' && trim($integer . $fraction, '0') !== '';

        return ['value' => ($negative ? '-' : '') . $integer . ($fraction === '' ? '' : '.' . $fraction), 'decimals' => strlen($fraction)];
    }
}
