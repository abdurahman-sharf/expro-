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

        if ($title === '' && isset($rows[0]) && count($rows[0]) === 1) {
            $title = $rows[0][0];
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
     * The heading of the table in a file that opens with a title and notes and ends with ties and notes: the
     * first row of two cells or more that holds no number and is followed by a row of the same width (a
     * one-cell row of a group may come between). A table of two columns has a heading only when the row that
     * follows holds a number and it does not come right after another row of two cells, which is what a run of
     * notes and their values looks like. A file with no such
     * row falls back to the first row that fills the widest line, when that is three cells or more.
     */
    public function headerIndex(): ?int
    {
        foreach ($this->rows as $index => $row) {
            $next = $this->nextRow($index);

            if (count($row) >= 2 && $this->holdsNoNumber($row) && $next !== null && count($next) === count($row) && (count($row) >= 3 || (! $this->holdsNoNumber($next) && ($index === 0 || count($this->rows[$index - 1]) !== 2)))) {
                return $index;
            }
        }

        return $this->firstWidestRow();
    }

    private function firstWidestRow(): ?int
    {
        $width = $this->columnCount();

        if ($width < 3) {
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
     * @param list<string> $row
     */
    private function holdsNoNumber(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== '' && self::number($cell) !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>|null The next row that is not a note of one cell.
     */
    private function nextRow(int $index): ?array
    {
        for ($next = $index + 1; isset($this->rows[$next]); $next++) {
            if (count($this->rows[$next]) !== 1) {
                return $this->rows[$next];
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
