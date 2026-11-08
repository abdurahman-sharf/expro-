<?php

namespace Tests\Libraries\Accounting\Export;

use App\Libraries\Accounting\Export\ExportOptions;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class ExportOptionsTest extends CIUnitTestCase
{
    public function testFromQuery_AcceptsTheOrientationsAndTheBoundsOfTheFontSize(): void
    {
        $this->assertSame('landscape', ExportOptions::fromQuery(['orientation' => 'landscape'])->orientation);
        $this->assertSame('portrait', ExportOptions::fromQuery(['orientation' => 'portrait'])->orientation);
        $this->assertSame(10, ExportOptions::fromQuery(['font_size' => '10'])->fontSize);
        $this->assertSame(50, ExportOptions::fromQuery(['font_size' => '50'])->fontSize);
        $this->assertSame(14, ExportOptions::fromQuery(['font_size' => '14'])->fontSize);
    }

    public function testFromQuery_RejectsAFontSizeOutsideTheBoundsOrNotANumber(): void
    {
        foreach (['9', '51', '0', '-12', '12.5', 'big', '', '1e1'] as $size) {
            $this->assertSame(ExportOptions::DEFAULT_FONT_SIZE, ExportOptions::fromQuery(['font_size' => $size])->fontSize, 'Size ' . $size);
        }

        $this->assertSame(ExportOptions::DEFAULT_FONT_SIZE, ExportOptions::fromQuery(['font_size' => ['20']])->fontSize);
    }

    public function testFromQuery_RejectsAnOrientationThatIsNotOneOfTheTwo(): void
    {
        foreach (['diagonal', '', 'LANDSCAPE'] as $orientation) {
            $this->assertNull(ExportOptions::fromQuery(['orientation' => $orientation])->orientation, 'Orientation ' . $orientation);
        }

        $this->assertNull(ExportOptions::fromQuery(['orientation' => ['landscape']])->orientation);
        $this->assertNull(ExportOptions::fromQuery([])->orientation);
    }

    public function testLandscape_FollowsTheWidthOfTheTableUnlessTheOrientationIsAsked(): void
    {
        $this->assertFalse((new ExportOptions())->landscape(6));
        $this->assertTrue((new ExportOptions())->landscape(7));
        $this->assertTrue((new ExportOptions(ExportOptions::ORIENTATION_LANDSCAPE))->landscape(2));
        $this->assertFalse((new ExportOptions(ExportOptions::ORIENTATION_PORTRAIT))->landscape(12));
    }
}
