<?php

namespace App\Libraries\Accounting\Export;

use App\Libraries\Pdf_lib;
use Mpdf\MpdfException;

/**
 * Draws a report as a PDF file through the engine of the documents, with its Arabic shaping and its
 * direction: the title and notes, then the table with its heading repeated on every page.
 */
final class ReportPdf
{
    private const MIN_WEIGHT = 4;
    private const MAX_WEIGHT = 40;

    /**
     * @throws MpdfException
     */
    public function render(ReportSheet $sheet, ExportOptions $options): string
    {
        return (new Pdf_lib())->render($this->html($sheet, $options), [
            'format' => $options->landscape($sheet->columnCount()) ? 'A4-L' : 'A4',
            'title'  => $sheet->title,
            'footer' => '<div style="text-align: center; font-size: 8pt; color: #56657f;">{PAGENO} / {nbpg}</div>',
        ]);
    }

    public function html(ReportSheet $sheet, ExportOptions $options): string
    {
        $columns = max(1, $sheet->columnCount());
        $header  = $sheet->headerIndex();

        return view('documents/report', [
            'company' => (string) (documentConfig()['company'] ?? ''),
            'title'   => $sheet->title,
            'date'    => to_datetime(time()),
            'size'    => $options->fontSize,
            'columns' => $columns,
            'widths'  => $this->widths($sheet, $columns),
            'intro'   => $this->intro($sheet, $header),
            'heading' => $header === null ? [] : $this->padded($sheet->rows[$header], $columns),
            'body'    => $this->body($sheet, $header, $columns),
        ]);
    }

    /**
     * @return list<string>
     */
    private function intro(ReportSheet $sheet, ?int $header): array
    {
        $lines = [];

        foreach (array_slice($sheet->rows, 0, $header ?? 0) as $index => $row) {
            $text = trim(implode('  ', array_filter($row, static fn (string $cell): bool => $cell !== '')));

            if ($text !== '' && ! ($index === 0 && $text === $sheet->title)) {
                $lines[] = $text;
            }
        }

        return $lines;
    }

    /**
     * @return list<array{cells: list<string>, span: bool}>
     */
    private function body(ReportSheet $sheet, ?int $header, int $columns): array
    {
        $rows = [];

        foreach (array_slice($sheet->rows, $header === null ? 0 : $header + 1, null, true) as $row) {
            $filled = array_values(array_filter($row, static fn (string $cell): bool => $cell !== ''));

            $rows[] = $columns > 1 && count($filled) === 1 && count($row) === 1
                ? ['cells' => [$filled[0]], 'span' => true]
                : ['cells' => $this->padded($row, $columns), 'span' => false];
        }

        return $rows;
    }

    /**
     * @param list<string> $row
     *
     * @return list<string>
     */
    private function padded(array $row, int $columns): array
    {
        return array_slice(array_pad($row, $columns, ''), 0, $columns);
    }

    /**
     * Share of the width of each column, by the length of its longest value.
     *
     * @return list<int>
     */
    private function widths(ReportSheet $sheet, int $columns): array
    {
        $weights = array_fill(0, $columns, self::MIN_WEIGHT);

        foreach ($sheet->rows as $row) {
            if (count($row) < $columns && $columns > 1) {
                continue;
            }

            foreach ($row as $index => $cell) {
                $weights[$index] = max($weights[$index], min(self::MAX_WEIGHT, mb_strlen($cell)));
            }
        }

        $total = array_sum($weights);

        return array_map(static fn (int $weight): int => max(3, (int) round(100 * $weight / $total)), $weights);
    }
}
