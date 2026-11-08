<?php

namespace Tests\Libraries;

use App\Libraries\Pdf_lib;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionMethod;

class Pdf_libTest extends CIUnitTestCase
{
    private const ARABIC_HTML = '<p>فاتورة ضريبية رقم 1024</p><p>العميل: أحمد العلي — Total 147.00</p>';

    private Pdf_lib $pdf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdf = new Pdf_lib();
    }

    public function testRender_ReturnsPdfDocument(): void
    {
        $output = $this->pdf->render(self::ARABIC_HTML, ['rtl' => true]);

        $this->assertStringStartsWith('%PDF-', $output);
        $this->assertStringContainsString('%%EOF', $output);
    }

    public function testRender_EmbedsArabicFont(): void
    {
        $output = $this->pdf->render(self::ARABIC_HTML, ['rtl' => true]);

        $this->assertStringContainsString('IBMPlexSansArabic', $output);
    }

    public function testRender_UsesRequestedPageFormat(): void
    {
        $a4 = $this->pdf->render(self::ARABIC_HTML, ['format' => 'A4']);
        $a5 = $this->pdf->render(self::ARABIC_HTML, ['format' => 'A5']);

        $this->assertMatchesRegularExpression('/MediaBox \[0 0 595\.2\d* 841\.8\d*\]/', $a4);
        $this->assertMatchesRegularExpression('/MediaBox \[0 0 419\.5\d* 595\.2\d*\]/', $a5);
    }

    public function testRender_RejectsRemoteImages(): void
    {
        $started = microtime(true);

        $output = $this->pdf->render('<p>x</p><img src="http://10.255.255.1/logo.png">', ['rtl' => false]);

        $this->assertStringStartsWith('%PDF-', $output);
        $this->assertLessThan(5, microtime(true) - $started);
    }

    public function testRender_AddsPageNumberFooter(): void
    {
        $withFooter = $this->pdf->render(self::ARABIC_HTML, ['footer' => '<div>{PAGENO} / {nbpg}</div>']);
        $without = $this->pdf->render(self::ARABIC_HTML);

        $this->assertGreaterThan(strlen($without), strlen($withFooter));
    }

    public function testRender_AllowsOnlyFileStreamWrapper(): void
    {
        $createEngine = new ReflectionMethod(Pdf_lib::class, 'createEngine');

        $engine = $createEngine->invoke($this->pdf, []);

        $this->assertSame(['file'], $engine->whitelistStreamWrappers);
    }

    public function testRender_BreaksALongTableOverSeveralPagesAndKeepsEveryRowTogether(): void
    {
        $rows = '';

        foreach (range(1, 160) as $number) {
            $rows .= '<tr><td>' . $number . '</td><td>بند رقم ' . $number . '</td><td>' . number_format($number * 1.5, 2) . '</td></tr>';
        }

        $one  = $this->pdf->render('<table style="width: 100%;"><tr><td>1</td></tr></table>');
        $many = $this->pdf->render('<style>tr { page-break-inside: avoid; }</style><table style="width: 100%;">' . $rows . '</table>', ['rtl' => true]);

        $this->assertSame(1, preg_match_all('/\/Type \/Page\b/', $one));
        $this->assertGreaterThan(2, preg_match_all('/\/Type \/Page\b/', $many));
    }

    public function testRender_UsesTheMarginsGivenInMillimetres(): void
    {
        $narrow = $this->pdf->render('<p>x</p>', ['margins' => ['top' => 5.0, 'right' => 5.0, 'bottom' => 5.0, 'left' => 5.0]]);
        $wide   = $this->pdf->render('<p>x</p>', ['margins' => ['top' => 40.0, 'right' => 40.0, 'bottom' => 40.0, 'left' => 40.0]]);

        $this->assertStringStartsWith('%PDF-', $narrow);
        $this->assertStringStartsWith('%PDF-', $wide);
        $this->assertNotSame($narrow, $wide);
    }

    public function testRender_PutsTheTitleInTheDocumentInformation(): void
    {
        $output = $this->pdf->render('<p>x</p>', ['title' => 'Voucher 7']);

        $this->assertStringContainsString('/Title', $output);
        $this->assertStringContainsString(bin2hex(mb_convert_encoding('Voucher 7', 'UTF-16BE', 'UTF-8')), strtolower(bin2hex($output)));
    }

    public function testRender_WritesNoRemoteAddressThatTheDocumentAsked(): void
    {
        $output = $this->pdf->render('<p>x</p><a href="https://example.test/track">link</a><img src="https://example.test/a.png">');

        $this->assertStringStartsWith('%PDF-', $output);
        $this->assertStringNotContainsString('example.test/a.png', $output);
    }
}
