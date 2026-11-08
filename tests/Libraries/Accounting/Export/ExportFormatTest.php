<?php

namespace Tests\Libraries\Accounting\Export;

use App\Libraries\Accounting\Export\ExportFormat;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class ExportFormatTest extends CIUnitTestCase
{
    public function testAddressOf_ChangesTheLastCsvSegmentBeforeTheQueryAndKeepsTheFilters(): void
    {
        $csv = 'http://localhost/accounting_reports/ledger/csv?account=5&to=2026-10-31';

        $this->assertSame('http://localhost/accounting_reports/ledger/xlsx?account=5&to=2026-10-31', ExportFormat::Xlsx->addressOf($csv));
        $this->assertSame('http://localhost/accounting_reports/ledger/pdf?account=5&to=2026-10-31', ExportFormat::Pdf->addressOf($csv));
    }

    public function testAddressOf_ChangesAnAddressWithoutAQuery(): void
    {
        $this->assertSame('http://localhost/accounting_reports/xlsx', ExportFormat::Xlsx->addressOf('http://localhost/accounting_reports/csv'));
    }

    public function testAddressOf_LeavesAWordThatOnlyStartsWithCsvAlone(): void
    {
        $this->assertSame('http://localhost/accounting_reports/csvx?a=1', ExportFormat::Pdf->addressOf('http://localhost/accounting_reports/csvx?a=1'));
        $this->assertSame('http://localhost/csv_reports/pdf?a=/csv', ExportFormat::Pdf->addressOf('http://localhost/csv_reports/csv?a=/csv'));
    }

    public function testContentType_NamesTheTypeOfEachFormat(): void
    {
        $this->assertSame('text/csv', ExportFormat::Csv->contentType());
        $this->assertSame('application/pdf', ExportFormat::Pdf->contentType());
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ExportFormat::Xlsx->contentType());
    }

    public function testTryFrom_KnowsOnlyTheThreeFormats(): void
    {
        $this->assertSame(ExportFormat::Xlsx, ExportFormat::tryFrom('xlsx'));
        $this->assertNull(ExportFormat::tryFrom('xls'));
        $this->assertNull(ExportFormat::tryFrom('PDF'));
    }
}
