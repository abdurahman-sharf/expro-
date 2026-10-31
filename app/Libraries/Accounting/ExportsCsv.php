<?php

namespace App\Libraries\Accounting;

/**
 * What a controller that gives a report as a file needs to write it: a row of cells in which a
 * text that a spreadsheet would read as a formula is shown as text, because names of accounts,
 * employees and lines are typed by people, and the text of the file once it is complete. A number is
 * left as it is.
 */
trait ExportsCsv
{
    /**
     * @param resource     $handle
     * @param list<string> $cells
     */
    private function csvRow($handle, array $cells): void
    {
        fputcsv($handle, array_map(static fn (string $cell): string => self::safeCell($cell), $cells), ',', '"', '');
    }

    /**
     * @param resource $handle
     */
    private function csvNote($handle, ?string $note): void
    {
        if ($note !== null) {
            $this->csvRow($handle, [$note]);
        }
    }

    /**
     * @param resource $handle
     */
    private function csvText($handle): string
    {
        rewind($handle);
        $text = (string) stream_get_contents($handle);
        fclose($handle);

        return $text;
    }

    private static function safeCell(string $cell): string
    {
        if ($cell === '' || is_numeric($cell) || ! in_array($cell[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return $cell;
        }

        return "'" . $cell;
    }
}
