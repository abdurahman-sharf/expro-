<?php

namespace App\Libraries\Accounting\Export;

enum ExportFormat: string
{
    case Csv  = 'csv';
    case Xlsx = 'xlsx';
    case Pdf  = 'pdf';

    public function contentType(): string
    {
        return match ($this) {
            self::Csv  => 'text/csv',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Pdf  => 'application/pdf',
        };
    }

    public function maxRows(): int
    {
        return match ($this) {
            self::Pdf => ReportExporter::MAX_PDF_ROWS,
            default   => ReportExporter::MAX_ROWS,
        };
    }
}
