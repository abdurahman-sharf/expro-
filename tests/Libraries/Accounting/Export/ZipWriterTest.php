<?php

namespace Tests\Libraries\Accounting\Export;

use App\Libraries\Accounting\Export\ZipWriter;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use Tests\Support\WorkbookPartsTrait;
use ZipArchive;

/**
 * @internal
 */
final class ZipWriterTest extends CIUnitTestCase
{
    use WorkbookPartsTrait;

    public function testRender_WritesEveryFileWithItsNameItsContentAndItsChecksum(): void
    {
        $zip = new ZipWriter();

        $zip->add('[Content_Types].xml', '<Types/>');
        $zip->add('xl/worksheets/sheet1.xml', str_repeat('<c r="A1"><v>1</v></c>', 400));
        $zip->add('xl/arabic.xml', 'الحساب والرصيد');
        $zip->add('empty.txt', '');

        $parts = $this->workbookParts($zip->render());

        $this->assertSame(['[Content_Types].xml', 'xl/worksheets/sheet1.xml', 'xl/arabic.xml', 'empty.txt'], array_keys($parts));
        $this->assertSame('<Types/>', $parts['[Content_Types].xml']);
        $this->assertSame(str_repeat('<c r="A1"><v>1</v></c>', 400), $parts['xl/worksheets/sheet1.xml']);
        $this->assertSame('الحساب والرصيد', $parts['xl/arabic.xml']);
        $this->assertSame('', $parts['empty.txt']);
    }

    public function testRender_DeflatesAFileThatShrinksAndStoresOneThatDoesNot(): void
    {
        $zip = new ZipWriter();

        $zip->add('long.txt', str_repeat('abcdef', 500));
        $zip->add('short.txt', 'x');

        $file = $zip->render();

        $this->assertLessThan(strlen(str_repeat('abcdef', 500)), strlen($file), 'The repeated text is smaller in the file than it was');
        $this->assertStringContainsString('x', $file, 'A file that would not shrink is stored as it is');
        $this->assertSame(['long.txt', 'short.txt'], array_keys($this->workbookParts($file)));
    }

    public function testRender_GivesAnArchiveWithNoFilesTheEndRecordOnly(): void
    {
        $file = (new ZipWriter())->render();

        $this->assertSame(22, strlen($file));
        $this->assertSame([], $this->workbookParts($file));
    }

    public function testRender_GivesAFileThatTheZipExtensionOpensAndReadsBackWhenItIsInstalled(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('The zip extension is not installed');
        }

        $zip = new ZipWriter();

        $zip->add('a/b.xml', str_repeat('<x/>', 300));
        $zip->add('c.txt', 'text');

        $path = tempnam(sys_get_temp_dir(), 'zipunit');

        file_put_contents($path, $zip->render());

        $reader = new ZipArchive();

        $this->assertTrue($reader->open($path, ZipArchive::CHECKCONS));
        $this->assertSame(2, $reader->numFiles);
        $this->assertSame(str_repeat('<x/>', 300), $reader->getFromName('a/b.xml'));
        $this->assertSame('text', $reader->getFromName('c.txt'));

        $reader->close();
        unlink($path);
    }

    public function testAdd_RejectsAnEmptyNameAndANameThatIsNotPlainText(): void
    {
        foreach (['', "bad\x00name", 'été.xml', str_repeat('a', 70_000)] as $name) {
            try {
                (new ZipWriter())->add($name, 'x');

                $this->fail('The name ' . substr($name, 0, 20) . ' is refused');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }
}
