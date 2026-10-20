<?php

namespace App\Controllers;

use App\Libraries\Pdf_lib;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

class Exports extends Secure_Controller
{
    public const MAX_ROWS          = 5000;
    private const MAX_COLUMNS      = 30;
    private const MAX_CELL_LENGTH  = 1000;
    private const MAX_TITLE_LENGTH = 120;

    public function __construct()
    {
        parent::__construct('');
    }

    /**
     * Turns the rows shown in a table into a PDF file. Used by the PDF button of the tables, see 35-export.js
     *
     * @noinspection PhpUnused
     */
    public function postPdf(): ResponseInterface
    {
        $payload = json_decode((string) $this->request->getPost('payload'), true);

        if (! is_array($payload) || ! is_array($payload['columns'] ?? null) || ! is_array($payload['rows'] ?? null)) {
            return $this->response->setStatusCode(400)->setJSON(['success' => false, 'message' => lang('Bootstrap_tables.export_failed')]);
        }

        if (count($payload['rows']) > self::MAX_ROWS) {
            return $this->response->setStatusCode(413)->setJSON(['success' => false, 'message' => lang('Bootstrap_tables.export_too_many_rows')]);
        }

        $title    = $this->cell($payload['title'] ?? '', self::MAX_TITLE_LENGTH);
        $subtitle = $this->cell($payload['subtitle'] ?? '', self::MAX_TITLE_LENGTH);
        $columns  = array_map($this->cell(...), array_slice(array_values($payload['columns']), 0, self::MAX_COLUMNS));
        $rows     = array_map(fn (mixed $row): array => $this->row($row, count($columns)), array_values($payload['rows']));

        try {
            $pdf = (new Pdf_lib())->render($this->tableHtml($title, $subtitle, $columns, $rows), [
                'format' => count($columns) > 6 ? 'A4-L' : 'A4',
                'title'  => $title,
                'footer' => '<div style="text-align: center; font-size: 8pt; color: #56657f;">{PAGENO} / {nbpg}</div>',
            ]);
        } catch (Throwable $exception) {
            log_message('error', 'Table PDF export failed: ' . $exception->getMessage());

            return $this->response->setStatusCode(500)->setJSON(['success' => false, 'message' => lang('Bootstrap_tables.export_failed')]);
        }

        $filename = trim(preg_replace('~[\\\\/:*?"<>|\x00-\x1f]+~u', '-', $title . ' ' . date('Y-m-d'))) . '.pdf';

        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'inline; filename="' . rawurlencode($filename) . '"')
            ->setBody($pdf);
    }

    /**
     * @param list<string>       $columns
     * @param list<list<string>> $rows
     */
    private function tableHtml(string $title, string $subtitle, array $columns, array $rows): string
    {
        return view('documents/table', [
            'title'    => $title,
            'subtitle' => $subtitle,
            'date'     => to_datetime(time()),
            'columns'  => $columns,
            'widths'   => $this->columnWidths($columns, $rows),
            'rows'     => $rows,
        ]);
    }

    /**
     * Share of the table width of each column, by the length of its longest value.
     *
     * @param list<string>       $columns
     * @param list<list<string>> $rows
     *
     * @return list<int>
     */
    private function columnWidths(array $columns, array $rows): array
    {
        $weights = [];

        foreach ($columns as $index => $column) {
            $longest   = max(mb_strlen($column), ...array_map(static fn (array $row): int => mb_strlen($row[$index]), $rows ?: [[0 => '']]));
            $weights[] = max(4, min(40, $longest));
        }

        $total = array_sum($weights) ?: 1;

        return array_map(static fn (int $weight): int => max(3, (int) round(100 * $weight / $total)), $weights);
    }

    /**
     * @return list<string>
     */
    private function row(mixed $row, int $columnCount): array
    {
        $cells      = is_array($row) ? array_values($row) : [];
        $normalized = [];

        for ($index = 0; $index < $columnCount; $index++) {
            $normalized[] = $this->cell($cells[$index] ?? '', self::MAX_CELL_LENGTH);
        }

        return $normalized;
    }

    private function cell(mixed $value, int $limit = self::MAX_CELL_LENGTH): string
    {
        return is_scalar($value) ? mb_substr(trim((string) $value), 0, $limit) : '';
    }
}
