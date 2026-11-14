<?php

namespace App\Libraries\Accounting\OpeningImport;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;

/**
 * Reads the text of a file of opening balances: UTF-8 with or without a mark at its start, separated by
 * commas, semicolons or tabs as a spreadsheet saves it. Its first row names the columns, of which party and
 * amount are needed and date and note may follow, in any order, and a line that starts with # is a remark.
 * It does not judge what the cells say: it hands them to the check as they were written.
 */
final class OpeningImportParser
{
    public const MAX_ROWS  = 20000;
    public const MAX_BYTES = 5242880;
    private const ALIASES  = [
        'party'  => ['party', 'customer', 'supplier', 'name', 'id', 'code'],
        'amount' => ['amount', 'balance'],
        'date'   => ['date', 'as_of', 'as_of_date', 'asof'],
        'note'   => ['note', 'notes', 'comment', 'remark'],
    ];

    /**
     * @return list<array{line: int, party: string, amount: string, date: string, note: string}>
     *
     * @throws AccountingException
     */
    public function parse(string $text): array
    {
        if (strlen($text) > self::MAX_BYTES) {
            throw new AccountingException(AccountingError::OpeningImportFileTooBig, 'The file is too large.', ['max' => self::MAX_ROWS]);
        }

        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }

        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new AccountingException(AccountingError::OpeningImportFileInvalid, 'The text of the file is not UTF-8.');
        }

        $delimiter = $this->delimiterOf($text);
        $handle    = fopen('php://temp', 'r+b');

        fwrite($handle, $text);
        rewind($handle);

        $columns = null;
        $rows    = [];
        $line    = 0;

        while (($cells = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $line++;

            if ($cells === [null] || $this->isBlank($cells) || str_starts_with(ltrim((string) $cells[0]), '#')) {
                continue;
            }

            if ($columns === null) {
                $columns = $this->columnsOf($cells);

                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                fclose($handle);

                throw new AccountingException(AccountingError::OpeningImportFileTooBig, 'The file holds too many rows.', ['max' => self::MAX_ROWS]);
            }

            $rows[] = [
                'line'   => $line,
                'party'  => $this->cell($cells, $columns['party']),
                'amount' => $this->cell($cells, $columns['amount']),
                'date'   => $this->cell($cells, $columns['date'] ?? null),
                'note'   => $this->cell($cells, $columns['note'] ?? null),
            ];
        }

        fclose($handle);

        if ($columns === null) {
            throw new AccountingException(AccountingError::OpeningImportFileInvalid, 'The file has no row that names the columns.');
        }

        if ($rows === []) {
            throw new AccountingException(AccountingError::OpeningImportEmpty, 'The file holds no row of balances.');
        }

        return $rows;
    }

    private function delimiterOf(string $text): string
    {
        $first = '';

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (trim($line) !== '' && ! str_starts_with(ltrim($line), '#')) {
                $first = $line;

                break;
            }
        }

        $best  = ',';
        $count = substr_count($first, ',');

        foreach ([';', "\t"] as $candidate) {
            if (substr_count($first, $candidate) > $count) {
                $best  = $candidate;
                $count = substr_count($first, $candidate);
            }
        }

        return $best;
    }

    /**
     * @param list<string|null> $cells
     *
     * @return array{party: int, amount: int, date?: int, note?: int}
     *
     * @throws AccountingException
     */
    private function columnsOf(array $cells): array
    {
        $columns = [];

        foreach ($cells as $index => $cell) {
            $name = strtolower(preg_replace('/[^a-z_]+/i', '_', trim((string) $cell)) ?? '');
            $name = trim($name, '_');

            foreach (self::ALIASES as $canonical => $aliases) {
                if (in_array($name, $aliases, true) && ! isset($columns[$canonical])) {
                    $columns[$canonical] = $index;
                }
            }
        }

        if (! isset($columns['party'], $columns['amount'])) {
            throw new AccountingException(AccountingError::OpeningImportFileInvalid, 'The first row must name the columns party and amount.');
        }

        return $columns;
    }

    /**
     * @param list<string|null> $cells
     */
    private function cell(array $cells, ?int $index): string
    {
        return $index === null ? '' : trim((string) ($cells[$index] ?? ''));
    }

    /**
     * @param list<string|null> $cells
     */
    private function isBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
