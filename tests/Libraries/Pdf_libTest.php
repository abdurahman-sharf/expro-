<?php

namespace Tests\Libraries;

use App\Libraries\Pdf_lib;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionMethod;

/**
 * @internal
 */
final class Pdf_libTest extends CIUnitTestCase
{
    private const ARABIC_HTML = '<p>فاتورة ضريبية رقم 1024</p><p>العميل: أحمد العلي — Total 147.00</p>';

    private Pdf_lib $pdf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdf = new Pdf_lib();
    }

    public function testRenderReturnsPdfDocument(): void
    {
        $output = $this->pdf->render(self::ARABIC_HTML, ['rtl' => true]);

        $this->assertStringStartsWith('%PDF-', $output);
        $this->assertStringContainsString('%%EOF', $output);
    }

    public function testRenderEmbedsArabicFont(): void
    {
        $output = $this->pdf->render(self::ARABIC_HTML, ['rtl' => true]);

        $this->assertStringContainsString('IBMPlexSansArabic', $output);
    }

    public function testRenderUsesRequestedPageFormat(): void
    {
        $a4 = $this->pdf->render(self::ARABIC_HTML, ['format' => 'A4']);
        $a5 = $this->pdf->render(self::ARABIC_HTML, ['format' => 'A5']);

        $this->assertMatchesRegularExpression('/MediaBox \[0 0 595\.2\d* 841\.8\d*\]/', $a4);
        $this->assertMatchesRegularExpression('/MediaBox \[0 0 419\.5\d* 595\.2\d*\]/', $a5);
    }

    public function testRenderRejectsRemoteImages(): void
    {
        $started = microtime(true);

        $output = $this->pdf->render('<p>x</p><img src="http://10.255.255.1/logo.png">', ['rtl' => false]);

        $this->assertStringStartsWith('%PDF-', $output);
        $this->assertLessThan(5, microtime(true) - $started);
    }

    public function testRenderAllowsOnlyFileStreamWrapper(): void
    {
        $createEngine = new ReflectionMethod(Pdf_lib::class, 'createEngine');

        $engine = $createEngine->invoke($this->pdf, []);

        $this->assertSame(['file'], $engine->whitelistStreamWrappers);
    }
}
