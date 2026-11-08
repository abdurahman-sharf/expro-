<?php

namespace Tests\Libraries\Accounting\Export;

use App\Libraries\Accounting\Export\ExportOptions;
use App\Libraries\Accounting\Export\ReportPdf;
use App\Libraries\Accounting\Export\ReportSheet;
use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\OSPOS;

/**
 * @internal
 */
final class ReportPdfTest extends CIUnitTestCase
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

    private function sheet(): ReportSheet
    {
        return new ReportSheet('Trial balance', [
            ['Trial balance'],
            ['Period', 'Up to 2026-10-31'],
            ['Code', 'Account', 'Debit'],
            ['1101', 'Cash <b>', '7777.0000'],
            [],
            ['Balanced'],
            ['Totals', '', '7777.0000'],
        ]);
    }

    public function testHtml_ShowsTheTitleOnceThePeriodLineAndTheHeadingInTheHeadOfTheTable(): void
    {
        $html = (new ReportPdf())->html($this->sheet(), new ExportOptions());

        $this->assertSame(1, substr_count($html, 'Trial balance</div>'), 'The title row is not repeated as a line');
        $this->assertStringContainsString('Period  Up to 2026-10-31', $html);
        $this->assertMatchesRegularExpression('#<thead>\s*<tr>\s*<th[^>]*>Code</th>\s*<th[^>]*>Account</th>\s*<th[^>]*>Debit</th>#', $html);
    }

    public function testHtml_EscapesTheCellsAndKeepsAmountsLeftToRight(): void
    {
        $html = (new ReportPdf())->html($this->sheet(), new ExportOptions());

        $this->assertStringContainsString('Cash &lt;b&gt;', $html);
        $this->assertStringNotContainsString('Cash <b>', $html);
        $this->assertStringContainsString('<span dir="ltr">7777.0000</span>', $html);
    }

    public function testHtml_SpansAWholeRowForANoteAndPadsAShortRowToTheWidthOfTheTable(): void
    {
        $html = (new ReportPdf())->html($this->sheet(), new ExportOptions());

        $this->assertMatchesRegularExpression('#<td class="[^"]*" colspan="3">Balanced</td>#', $html);
        preg_match('#<tr[^>]*>(?:(?!</tr>).)*Totals(?:(?!</tr>).)*</tr>#s', $html, $row);

        $this->assertSame(3, substr_count($row[0], '<td'), 'A row of two filled cells is drawn in three cells');
    }

    public function testHtml_NamesTheCompanyAboveTheTitleAndEscapesIt(): void
    {
        $settings = config(OSPOS::class)->settings;

        config(OSPOS::class)->settings['company'] = 'Acme <Trading> Co';

        try {
            $html = (new ReportPdf())->html($this->sheet(), new ExportOptions());
        } finally {
            config(OSPOS::class)->settings = $settings;
        }

        $this->assertStringContainsString('<div class="doc-company-name">Acme &lt;Trading&gt; Co</div>', $html);
        $this->assertLessThan(strpos($html, 'doc-title doc-title-small'), strpos($html, 'doc-company-name"'));
    }

    public function testHtml_LeavesOutTheCompanyLineWhenTheCompanyHasNoName(): void
    {
        $settings = config(OSPOS::class)->settings;

        config(OSPOS::class)->settings['company'] = '';

        try {
            $html = (new ReportPdf())->html($this->sheet(), new ExportOptions());
        } finally {
            config(OSPOS::class)->settings = $settings;
        }

        $this->assertStringNotContainsString('<div class="doc-company-name">', $html);
    }

    public function testHtml_EscapesTheHeadingOfTheTable(): void
    {
        $html = (new ReportPdf())->html(new ReportSheet('T', [['Code <i>', 'Name & Co'], ['1', 'x']]), new ExportOptions());

        $this->assertStringContainsString('Code &lt;i&gt;', $html);
        $this->assertStringContainsString('Name &amp; Co', $html);
        $this->assertStringNotContainsString('<i>', $html);
    }

    public function testHtml_DrawsARowWithOneFilledCellAmongSeveralInACellOfItsOwnAndNotAcrossTheRow(): void
    {
        $html = (new ReportPdf())->html(new ReportSheet('T', [['Code', 'Name', 'Amount'], ['Only first', '', '']]), new ExportOptions());

        preg_match('#<tr[^>]*>(?:(?!</tr>).)*Only first(?:(?!</tr>).)*</tr>#s', $html, $row);

        $this->assertSame(3, substr_count($row[0], '<td'));
        $this->assertStringNotContainsString('colspan', $row[0]);
    }

    public function testHtml_AppliesTheSizeOfTheTypeAsked(): void
    {
        $this->assertStringContainsString('font-size: 10pt;', (new ReportPdf())->html($this->sheet(), new ExportOptions()));
        $this->assertStringContainsString('font-size: 24pt;', (new ReportPdf())->html($this->sheet(), new ExportOptions(fontSize: 24)));
    }

    public function testHtml_DrawsASheetWithoutAHeadingAsABodyOnly(): void
    {
        $html = (new ReportPdf())->html(new ReportSheet('T', [['one'], ['two']]), new ExportOptions());

        $this->assertStringNotContainsString('<thead>', $html);
        $this->assertStringContainsString('one', $html);
    }

    public function testRender_GivesAPdfThatEmbedsTheArabicFont(): void
    {
        $pdf = (new ReportPdf())->render(new ReportSheet('ميزان المراجعة', [['الرمز', 'الحساب'], ['1101', 'الصندوق']]), new ExportOptions());

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('IBMPlexSansArabic', $pdf);
    }

    public function testRender_TurnsTheSheetToLandscapeWhenItHasMoreThanSixColumnsOrWhenAsked(): void
    {
        $wide   = new ReportSheet('T', [array_fill(0, 7, 'h'), array_fill(0, 7, 'v')]);
        $narrow = new ReportSheet('T', [['a', 'b'], ['1', '2']]);

        $this->assertMatchesRegularExpression('/MediaBox \[0 0 841\.8\d* 595\.2\d*\]/', (new ReportPdf())->render($wide, new ExportOptions()));
        $this->assertMatchesRegularExpression('/MediaBox \[0 0 595\.2\d* 841\.8\d*\]/', (new ReportPdf())->render($narrow, new ExportOptions()));
        $this->assertMatchesRegularExpression('/MediaBox \[0 0 841\.8\d* 595\.2\d*\]/', (new ReportPdf())->render($narrow, new ExportOptions(ExportOptions::ORIENTATION_LANDSCAPE)));
        $this->assertMatchesRegularExpression('/MediaBox \[0 0 595\.2\d* 841\.8\d*\]/', (new ReportPdf())->render($wide, new ExportOptions(ExportOptions::ORIENTATION_PORTRAIT)));
    }
}
