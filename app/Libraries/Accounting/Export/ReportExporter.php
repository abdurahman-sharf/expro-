<?php

namespace App\Libraries\Accounting\Export;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;

/**
 * Turns the file a controller wrote for a report into the file asked for in another format. The rows are
 * the very rows of the CSV the same address gives, so the figures and the totals cannot differ between the
 * formats, and a file larger than a format can carry is refused by name.
 */
final class ReportExporter
{
    public const MAX_ROWS     = 20_000;
    public const MAX_PDF_ROWS = 3_000;

    /**
     * @throws AccountingException
     */
    public function convert(ExportFormat $format, string $csv, string $title, ExportOptions $options): string
    {
        $sheet = ReportSheet::fromCsv($csv, $title);

        if ($sheet->rowCount() > $format->maxRows()) {
            throw new AccountingException(AccountingError::ReportFileTooLarge, 'The report is too large for the file asked for.', ['max' => $format->maxRows()]);
        }

        return match ($format) {
            ExportFormat::Csv  => $csv,
            ExportFormat::Xlsx => (new XlsxWriter())->render($sheet, isRtl()),
            ExportFormat::Pdf  => (new ReportPdf())->render($sheet, $options),
        };
    }
}
