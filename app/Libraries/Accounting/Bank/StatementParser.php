<?php

namespace App\Libraries\Accounting\Bank;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use DateTimeImmutable;

/**
 * Reads the text of a bank statement saved as a CSV file into rows, and says what is wrong with each
 * row that is not valid. The file is refused as a whole when it is too large, empty, not UTF-8 text, or
 * holds more rows than the limit; every other fault is a fault of one row, named by its number, the
 * reason and the text that was read, and a file with one such row is not imported at all. The checksum
 * is that of the text with the line ends made alike and the empty lines at the end taken off, so the
 * same statement saved by another program has the same checksum; the cells at the end of the last row
 * are kept, as a tab, being white space, is also a separator. Nothing here touches the database.
 */
final class StatementParser
{
    public const MAX_BYTES           = 2097152;
    public const MAX_ROWS            = 5000;
    public const MAX_REPORTED_ERRORS = 50;
    public const DESCRIPTION_MAX     = 500;
    public const REFERENCE_MAX       = 100;
    private const SCALE              = 4;
    private const LARGEST_INTEGER    = 99999999999;
    private const FIRST_YEAR         = 1990;
    private const LAST_YEAR          = 2100;
    private const ARABIC_DIGITS      = ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٫' => '.', '٬' => ','];

    public function __construct(private readonly int $maxBytes = self::MAX_BYTES, private readonly int $maxRows = self::MAX_ROWS)
    {
    }

    /**
     * @throws AccountingException
     */
    public function parse(string $content, StatementMapping $mapping): ParsedStatement
    {
        if (strlen($content) > $this->maxBytes) {
            throw new AccountingException(AccountingError::BankStatementTooLarge, 'The file is larger than the limit.', ['max' => round($this->maxBytes / 1048576, 1)]);
        }

        $text = $this->normalised($content);

        if (trim($text) === '') {
            throw new AccountingException(AccountingError::BankStatementEmpty, 'The file has no rows.');
        }

        $checksum = hash('sha256', $text);
        $records  = $this->records($text, $mapping);
        $rows     = [];
        $errors   = [];
        $failed   = 0;
        $seen     = [];

        foreach ($records as $number => $cells) {
            $result = $this->row($number, $cells, $mapping);

            if ($result instanceof StatementRow) {
                $occurrence = $seen[$result->dedupeKey] = ($seen[$result->dedupeKey] ?? 0) + 1;

                $rows[] = new StatementRow($result->rowNumber, $result->date, $result->description, $result->reference, $result->amount, hash('sha256', $result->dedupeKey . '|' . $occurrence));

                continue;
            }

            $failed++;

            if (count($errors) < self::MAX_REPORTED_ERRORS) {
                $errors[] = $result;
            }
        }

        return new ParsedStatement($rows, $errors, $failed, count($records), $checksum);
    }

    /**
     * @throws AccountingException
     */
    private function normalised(string $content): string
    {
        $content = self::withoutByteOrderMark($content);

        if (str_contains($content, "\0") || ! mb_check_encoding($content, 'UTF-8')) {
            throw new AccountingException(AccountingError::BankStatementEncodingInvalid, 'The file is not UTF-8 text.');
        }

        $lines = explode("\n", (string) preg_replace('/\R/u', "\n", $content));

        while ($lines !== [] && trim((string) end($lines)) === '') {
            array_pop($lines);
        }

        return rtrim(implode("\n", $lines), ' ');
    }

    private static function withoutByteOrderMark(string $content): string
    {
        return str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;
    }

    /**
     * The rows of the file that are not empty, by their number in the file, the heading excluded when
     * the mapping skips it.
     *
     * @return array<int, list<string|null>>
     *
     * @throws AccountingException
     */
    private function records(string $text, StatementMapping $mapping): array
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $text);
        rewind($stream);

        $records = [];
        $number  = 0;

        while (($cells = fgetcsv($stream, 0, $mapping->delimiterCharacter(), '"', '')) !== false) {
            $number++;

            if ($number === 1 && $mapping->skipFirstRow) {
                continue;
            }

            if ($cells === [null] || trim(implode('', array_map(static fn (?string $cell): string => (string) $cell, $cells))) === '') {
                continue;
            }

            if (count($records) >= $this->maxRows) {
                fclose($stream);

                throw new AccountingException(AccountingError::BankStatementTooManyRows, 'The file has more rows than the limit.', ['max' => $this->maxRows]);
            }

            $records[$number] = $cells;
        }

        fclose($stream);

        return $records;
    }

    /**
     * @param list<string|null> $cells
     */
    private function row(int $number, array $cells, StatementMapping $mapping): StatementRow|StatementRowError
    {
        if (count($cells) < $mapping->widestRequired()) {
            return new StatementRowError($number, StatementRowError::COLUMNS, (string) count($cells));
        }

        $cell = static fn (?int $column): string => $column === null ? '' : trim((string) ($cells[$column - 1] ?? ''));

        $dateText = $cell($mapping->dateColumn);

        if ($dateText === '') {
            return new StatementRowError($number, StatementRowError::DATE_MISSING);
        }

        $date = $this->date($dateText, $mapping->dateOrder);

        if ($date === null) {
            return new StatementRowError($number, StatementRowError::DATE_INVALID, $dateText);
        }

        $amount = $this->amount($number, $cell($mapping->depositColumn), $cell($mapping->withdrawalColumn));

        if ($amount instanceof StatementRowError) {
            return $amount;
        }

        $description = $cell($mapping->descriptionColumn);
        $reference   = $cell($mapping->referenceColumn);

        if (mb_strlen($description) > self::DESCRIPTION_MAX || mb_strlen($reference) > self::REFERENCE_MAX) {
            return new StatementRowError($number, StatementRowError::TEXT_TOO_LONG, mb_strlen($description) > self::DESCRIPTION_MAX ? mb_substr($description, 0, 30) : mb_substr($reference, 0, 30));
        }

        $key = $date . '|' . $amount . '|' . self::fingerprintText($reference) . '|' . hash('sha256', self::fingerprintText($description));

        return new StatementRow($number, $date, $description, $reference, $amount, $key);
    }

    private static function fingerprintText(string $text): string
    {
        return BankText::normalised($text);
    }

    private function date(string $text, string $order): ?string
    {
        $text = strtr($text, self::ARABIC_DIGITS);
        $text = (string) preg_replace('/[\sT]+\d{1,2}:\d{2}(:\d{2}(\.\d+)?)?\s*(Z|[+-]\d{2}:?\d{2})?$/i', '', $text);

        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $text, $found) === 1) {
            [$year, $month, $day] = [(int) $found[1], (int) $found[2], (int) $found[3]];
        } elseif ($order !== StatementMapping::YEAR_FIRST && preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $text, $found) === 1) {
            $monthFirst           = $order === StatementMapping::MONTH_FIRST;
            [$year, $month, $day] = [(int) $found[3], (int) ($monthFirst ? $found[1] : $found[2]), (int) ($monthFirst ? $found[2] : $found[1])];
        } else {
            return null;
        }

        if ($year < self::FIRST_YEAR || $year > self::LAST_YEAR || ! checkdate($month, $day, $year)) {
            return null;
        }

        $date = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));

        return $date->format('Y-m-d');
    }

    private function amount(int $number, string $deposit, string $withdrawal): StatementRowError|string
    {
        $in  = $this->figure($number, $deposit);
        $out = $this->figure($number, $withdrawal);

        if ($in instanceof StatementRowError) {
            return $in;
        }

        if ($out instanceof StatementRowError) {
            return $out;
        }

        $hasIn  = bccomp($in, '0', self::SCALE) !== 0;
        $hasOut = bccomp($out, '0', self::SCALE) !== 0;

        if ($hasIn && $hasOut) {
            return new StatementRowError($number, StatementRowError::AMOUNT_BOTH, $deposit . ' / ' . $withdrawal);
        }

        if (! $hasIn && ! $hasOut) {
            return new StatementRowError($number, StatementRowError::AMOUNT_MISSING);
        }

        return $hasIn ? $in : bcsub('0', $out, self::SCALE);
    }

    private function figure(int $number, string $text): StatementRowError|string
    {
        if ($text === '') {
            return '0.0000';
        }

        $clean = str_replace(["\u{00A0}", ' ', "'"], '', strtr($text, self::ARABIC_DIGITS));

        if (preg_match('/^[-−]|^\(.*\)$/u', $clean) === 1) {
            return new StatementRowError($number, StatementRowError::AMOUNT_NEGATIVE, $text);
        }

        $clean = ltrim($clean, '+');
        $plain = $this->plainNumber($clean);

        if ($plain === null) {
            return new StatementRowError($number, StatementRowError::AMOUNT_INVALID, $text);
        }

        [$whole, $fraction] = array_pad(explode('.', $plain, 2), 2, '');

        if (strlen($fraction) > self::SCALE) {
            return new StatementRowError($number, StatementRowError::AMOUNT_PRECISION, $text);
        }

        if (strlen(ltrim($whole, '0')) > strlen((string) self::LARGEST_INTEGER) || (int) $whole > self::LARGEST_INTEGER) {
            return new StatementRowError($number, StatementRowError::AMOUNT_TOO_LARGE, $text);
        }

        return bcadd($whole === '' ? '0' : $whole, '0.' . str_pad($fraction, self::SCALE, '0'), self::SCALE);
    }

    private function plainNumber(string $text): ?string
    {
        $hasDot   = str_contains($text, '.');
        $hasComma = str_contains($text, ',');

        if ($hasDot && $hasComma) {
            $decimal  = strrpos($text, '.') > strrpos($text, ',') ? '.' : ',';
            $thousand = $decimal === '.' ? ',' : '.';

            if (preg_match('/^\d{1,3}(' . preg_quote($thousand, '/') . '\d{3})+' . preg_quote($decimal, '/') . '\d*$/', $text) !== 1) {
                return null;
            }

            $text = str_replace($decimal, '.', str_replace($thousand, '', $text));
        } elseif ($hasComma) {
            if (preg_match('/^\d{1,3}(,\d{3})+$/', $text) === 1) {
                $text = str_replace(',', '', $text);
            } elseif (preg_match('/^\d*,\d+$/', $text) === 1) {
                $text = str_replace(',', '.', $text);
            } else {
                return null;
            }
        } elseif ($hasDot && substr_count($text, '.') > 1) {
            if (preg_match('/^\d{1,3}(\.\d{3})+$/', $text) !== 1) {
                return null;
            }

            $text = str_replace('.', '', $text);
        }

        return preg_match('/^(\d+(\.\d*)?|\.\d+)$/', $text) === 1 ? rtrim($text, '.') : null;
    }
}
