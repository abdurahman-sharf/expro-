<?php

namespace Tests\Libraries\Accounting\Export;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\Export\ExportFormat;
use App\Libraries\Accounting\Export\ExportOptions;
use App\Libraries\Accounting\Export\ReportExporter;
use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class ReportExporterTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $seedOnce    = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private static bool $doneBootstrap = false;

    protected function setUp(): void
    {
        if (self::$doneBootstrap === false) {
            Config::seeder($this->DBGroup)->call('App\Database\Seeds\TestDatabaseBootstrapSeeder');
            Config::connect($this->DBGroup)->close();

            self::$doneBootstrap = true;
        }

        parent::setUp();
    }

    private function csv(int $rows): string
    {
        $text = "Code,Amount\n";

        for ($index = 1; $index < $rows; $index++) {
            $text .= $index . ",5.00\n";
        }

        return $text;
    }

    public function testConvert_ReturnsTheCsvAsItWasWritten(): void
    {
        $csv = "\xEF\xBB\xBFCode,Amount\n1,5.00\n";

        $this->assertSame($csv, (new ReportExporter())->convert(ExportFormat::Csv, $csv, 'T', new ExportOptions()));
    }

    public function testConvert_WritesAWorkbookAndAPdfFromTheSameRows(): void
    {
        $exporter = new ReportExporter();

        $this->assertStringStartsWith('PK', $exporter->convert(ExportFormat::Xlsx, $this->csv(3), 'T', new ExportOptions()));
        $this->assertStringStartsWith('%PDF-', $exporter->convert(ExportFormat::Pdf, $this->csv(3), 'T', new ExportOptions()));
    }

    public function testConvert_AcceptsAFileOfExactlyTheLimitOfRows(): void
    {
        $exporter = new ReportExporter(maxRows: 5, maxPdfRows: 3);

        $this->assertStringStartsWith('PK', $exporter->convert(ExportFormat::Xlsx, $this->csv(5), 'T', new ExportOptions()));
        $this->assertStringStartsWith('%PDF-', $exporter->convert(ExportFormat::Pdf, $this->csv(3), 'T', new ExportOptions()));
    }

    public function testConvert_RefusesAWorkbookOneRowOverTheLimitByNameAndSaysTheLimit(): void
    {
        try {
            (new ReportExporter(maxRows: 5, maxPdfRows: 3))->convert(ExportFormat::Xlsx, $this->csv(6), 'T', new ExportOptions());

            $this->fail('A workbook over the limit is refused');
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::ReportFileTooLarge, $refusal->error);
            $this->assertSame(5, $refusal->context['max']);
        }
    }

    public function testConvert_RefusesAPdfOneRowOverItsOwnSmallerLimit(): void
    {
        try {
            (new ReportExporter(maxRows: 5, maxPdfRows: 3))->convert(ExportFormat::Pdf, $this->csv(4), 'T', new ExportOptions());

            $this->fail('A PDF over the limit is refused');
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::ReportFileTooLarge, $refusal->error);
            $this->assertSame(3, $refusal->context['max']);
        }
    }

    public function testConvert_HoldsTheCsvToNoLimitOfItsOwn(): void
    {
        $csv = $this->csv(50);

        $this->assertSame($csv, (new ReportExporter(maxRows: 5, maxPdfRows: 3))->convert(ExportFormat::Csv, $csv, 'T', new ExportOptions()));
    }

    public function testConvert_KeepsTheRealLimitsOfTwentyThousandAndThreeThousandRows(): void
    {
        $this->assertSame(20_000, ReportExporter::MAX_ROWS);
        $this->assertSame(3_000, ReportExporter::MAX_PDF_ROWS);
    }
}
