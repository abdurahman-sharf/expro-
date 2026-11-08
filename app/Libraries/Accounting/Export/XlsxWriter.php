<?php

namespace App\Libraries\Accounting\Export;

use RuntimeException;
use ZipArchive;

/**
 * Writes a report as an .xlsx workbook of one sheet: the zip of the parts the format requires, numbers
 * as numbers and every other cell as text. No cell is ever a formula, so a name that starts with an equals
 * sign is shown as it was typed.
 */
final class XlsxWriter
{
    private const MAX_CELL_LENGTH    = 32_767;
    private const MAX_SHEET_NAME     = 31;
    private const MIN_COLUMN_WIDTH   = 8;
    private const MAX_COLUMN_WIDTH   = 60;
    private const MAX_DECIMALS       = 8;
    private const FIRST_NUMBER_STYLE = 2;
    private const FIRST_FORMAT_ID    = 164;

    public function render(ReportSheet $sheet, bool $rightToLeft = false): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');

        if ($path === false) {
            throw new RuntimeException('A temporary file for the workbook could not be made.');
        }

        try {
            $zip = new ZipArchive();

            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('The workbook could not be opened for writing.');
            }

            $zip->addFromString('[Content_Types].xml', $this->contentTypes());
            $zip->addFromString('_rels/.rels', $this->rootRelationships());
            $zip->addFromString('xl/workbook.xml', $this->workbook($sheet->title));
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships());
            $zip->addFromString('xl/styles.xml', $this->styles());
            $zip->addFromString('xl/worksheets/sheet1.xml', $this->worksheet($sheet, $rightToLeft));

            if (! $zip->close()) {
                throw new RuntimeException('The workbook could not be written.');
            }

            return (string) file_get_contents($path);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public static function sheetName(string $title): string
    {
        $name = trim((string) preg_replace('/[\[\]:*?\/\\\\]+/u', ' ', $title), " '");

        return $name === '' ? 'Report' : mb_substr($name, 0, self::MAX_SHEET_NAME);
    }

    public static function columnName(int $index): string
    {
        $name = '';

        for ($number = $index + 1; $number > 0; $number = intdiv($number - 1, 26)) {
            $name = chr(65 + ($number - 1) % 26) . $name;
        }

        return $name;
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private function rootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbook(string $title): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::escape(self::sheetName($title)) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private function workbookRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private function styles(): string
    {
        $formats = '';
        $cells   = '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>';

        for ($decimals = 0; $decimals <= self::MAX_DECIMALS; $decimals++) {
            $formatId = self::FIRST_FORMAT_ID + $decimals;
            $code     = '#,##0' . ($decimals === 0 ? '' : '.' . str_repeat('0', $decimals));
            $formats .= '<numFmt numFmtId="' . $formatId . '" formatCode="' . self::escape($code) . '"/>';
            $cells   .= '<xf numFmtId="' . $formatId . '" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="' . (self::MAX_DECIMALS + 1) . '">' . $formats . '</numFmts>'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . (self::FIRST_NUMBER_STYLE + self::MAX_DECIMALS + 1) . '">' . $cells . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private function worksheet(ReportSheet $sheet, bool $rightToLeft): string
    {
        $header = $sheet->headerIndex();
        $widths = array_fill(0, max(1, $sheet->columnCount()), self::MIN_COLUMN_WIDTH);
        $rows   = '';

        foreach ($sheet->rows as $rowIndex => $cells) {
            $xml = '';

            foreach ($cells as $columnIndex => $cell) {
                if ($cell === '') {
                    continue;
                }

                $xml .= $this->cell(self::columnName($columnIndex) . ($rowIndex + 1), $cell, $rowIndex === $header);

                $widths[$columnIndex] = max($widths[$columnIndex], min(self::MAX_COLUMN_WIDTH, mb_strlen($cell) + 2));
            }

            $rows .= $xml === '' ? '<row r="' . ($rowIndex + 1) . '"/>' : '<row r="' . ($rowIndex + 1) . '">' . $xml . '</row>';
        }

        $columns = '';

        foreach ($widths as $index => $width) {
            $columns .= '<col min="' . ($index + 1) . '" max="' . ($index + 1) . '" width="' . $width . '" customWidth="1"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"' . ($rightToLeft ? ' rightToLeft="1"' : '') . '/></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . '<cols>' . $columns . '</cols>'
            . '<sheetData>' . $rows . '</sheetData>'
            . '</worksheet>';
    }

    private function cell(string $reference, string $text, bool $bold): string
    {
        $number = ReportSheet::number($text);

        if ($number !== null && ! $bold) {
            return '<c r="' . $reference . '" s="' . (self::FIRST_NUMBER_STYLE + min(self::MAX_DECIMALS, $number['decimals'])) . '"><v>' . $number['value'] . '</v></c>';
        }

        return '<c r="' . $reference . '" t="inlineStr"' . ($bold ? ' s="1"' : '') . '><is><t xml:space="preserve">' . self::escape(mb_substr($text, 0, self::MAX_CELL_LENGTH)) . '</t></is></c>';
    }

    private static function escape(string $text): string
    {
        $clean = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FFFE}\x{FFFF}]/u', '', mb_scrub($text));

        return htmlspecialchars($clean, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
