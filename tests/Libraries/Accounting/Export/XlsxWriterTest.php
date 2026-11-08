<?php

namespace Tests\Libraries\Accounting\Export;

use App\Libraries\Accounting\Export\ReportSheet;
use App\Libraries\Accounting\Export\XlsxWriter;
use CodeIgniter\Test\CIUnitTestCase;
use DOMDocument;
use DOMXPath;
use ZipArchive;

/**
 * @internal
 */
final class XlsxWriterTest extends CIUnitTestCase
{
    private const MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /**
     * @return array<string, string> The text of each part of the workbook by its name.
     */
    private function parts(string $workbook): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsxunit');

        file_put_contents($path, $workbook);

        $zip = new ZipArchive();

        $this->assertTrue($zip->open($path));

        $parts = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $parts[(string) $zip->getNameIndex($index)] = (string) $zip->getFromIndex($index);
        }

        $zip->close();
        unlink($path);

        return $parts;
    }

    private function sheetXpath(string $workbook): DOMXPath
    {
        $document = new DOMDocument();

        $this->assertTrue($document->loadXML($this->parts($workbook)['xl/worksheets/sheet1.xml']));

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('m', self::MAIN);

        return $xpath;
    }

    /**
     * @param list<list<string>> $rows
     */
    private function render(array $rows, bool $rtl = false, string $title = 'Report'): string
    {
        return (new XlsxWriter())->render(new ReportSheet($title, $rows), $rtl);
    }

    public function testRender_WritesTheSixPartsOfAWorkbookAsWellFormedXml(): void
    {
        $parts = $this->parts($this->render([['Code', 'Amount'], ['1101', '5.00']]));

        $this->assertEqualsCanonicalizing(['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'], array_keys($parts));

        foreach ($parts as $name => $text) {
            $document = new DOMDocument();

            $this->assertTrue($document->loadXML($text), $name . ' is well formed');
        }
    }

    public function testRender_StartsWithTheZipSignatureAndRelatesTheWorkbookToTheSheetAndTheStyles(): void
    {
        $workbook = $this->render([['a']]);
        $parts    = $this->parts($workbook);

        $this->assertStringStartsWith('PK', $workbook);
        $this->assertStringContainsString('Target="xl/workbook.xml"', $parts['_rels/.rels']);
        $this->assertStringContainsString('Target="worksheets/sheet1.xml"', $parts['xl/_rels/workbook.xml.rels']);
        $this->assertStringContainsString('Target="styles.xml"', $parts['xl/_rels/workbook.xml.rels']);
        $this->assertStringContainsString('/xl/worksheets/sheet1.xml', $parts['[Content_Types].xml']);
    }

    public function testRender_WritesDecimalsAsNumbersWithTheirDecimalsAndNamesAsText(): void
    {
        $xpath = $this->sheetXpath($this->render([['Account', 'Amount'], ['Cash', '1234.5000'], ['Sales', '-7'], ['Note', 'abc']]));

        $this->assertSame('1234.5000', $xpath->query('//m:c[@r="B2"]/m:v')->item(0)->textContent);
        $this->assertSame('', $xpath->query('//m:c[@r="B2"]')->item(0)->getAttribute('t'), 'A number has no text type');
        $this->assertSame('-7', $xpath->query('//m:c[@r="B3"]/m:v')->item(0)->textContent);
        $this->assertSame('inlineStr', $xpath->query('//m:c[@r="A2"]')->item(0)->getAttribute('t'));
        $this->assertSame('Cash', $xpath->query('//m:c[@r="A2"]/m:is/m:t')->item(0)->textContent);
        $this->assertSame('inlineStr', $xpath->query('//m:c[@r="B4"]')->item(0)->getAttribute('t'));
    }

    public function testRender_GivesEachNumberTheStyleOfItsDecimals(): void
    {
        $workbook = $this->render([['x', 'y', 'z'], ['1', '2.50', '3.1234']]);
        $xpath    = $this->sheetXpath($workbook);
        $styles   = $this->parts($workbook)['xl/styles.xml'];

        $this->assertSame('2', $xpath->query('//m:c[@r="A2"]')->item(0)->getAttribute('s'));
        $this->assertSame('4', $xpath->query('//m:c[@r="B2"]')->item(0)->getAttribute('s'));
        $this->assertSame('6', $xpath->query('//m:c[@r="C2"]')->item(0)->getAttribute('s'));
        $this->assertStringContainsString('formatCode="#,##0.00"', $styles);
        $this->assertStringContainsString('formatCode="#,##0.0000"', $styles);
    }

    public function testRender_NeverWritesAFormulaWhateverTheTextSays(): void
    {
        $workbook = $this->render([['Name', 'Note'], ['=HYPERLINK("http://evil","x")', '+cmd|calc'], ['@SUM(1)', "-2+3"]]);
        $xpath    = $this->sheetXpath($workbook);

        $this->assertSame(0, $xpath->query('//m:f')->length);
        $this->assertSame('=HYPERLINK("http://evil","x")', $xpath->query('//m:c[@r="A2"]/m:is/m:t')->item(0)->textContent);
        $this->assertSame('inlineStr', $xpath->query('//m:c[@r="B2"]')->item(0)->getAttribute('t'));
        $this->assertSame('inlineStr', $xpath->query('//m:c[@r="B3"]')->item(0)->getAttribute('t'));
        $this->assertStringNotContainsString('<f>', $this->parts($workbook)['xl/worksheets/sheet1.xml']);
    }

    public function testRender_EscapesMarkupAndDropsCharactersThatBreakTheFile(): void
    {
        $xpath = $this->sheetXpath($this->render([['a', 'b'], ['<b>&"\'', "bad\x01\x0Bchar\x1F ok"]]));

        $this->assertSame('<b>&"\'', $xpath->query('//m:c[@r="A2"]/m:is/m:t')->item(0)->textContent);
        $this->assertSame('badchar ok', $xpath->query('//m:c[@r="B2"]/m:is/m:t')->item(0)->textContent);
    }

    public function testRender_KeepsArabicTextAndLineBreaksAndLeadingSpaces(): void
    {
        $xpath = $this->sheetXpath($this->render([['a', 'b'], ['حساب الصندوق', "  two\nlines"]]));

        $this->assertSame('حساب الصندوق', $xpath->query('//m:c[@r="A2"]/m:is/m:t')->item(0)->textContent);
        $this->assertSame("  two\nlines", $xpath->query('//m:c[@r="B2"]/m:is/m:t')->item(0)->textContent);
        $this->assertSame('preserve', $xpath->query('//m:c[@r="B2"]/m:is/m:t')->item(0)->attributes->getNamedItem('space')->nodeValue);
    }

    public function testRender_ReplacesInvalidUtf8InsteadOfLosingTheCell(): void
    {
        $xpath = $this->sheetXpath($this->render([['a', 'b'], ["bad\xC3(text", 'x']]));

        $this->assertStringContainsString('text', $xpath->query('//m:c[@r="A2"]/m:is/m:t')->item(0)->textContent);
    }

    public function testRender_CutsATextAtTheLimitOfACell(): void
    {
        $xpath = $this->sheetXpath($this->render([['a', 'b'], [str_repeat('x', 40_000), 'y']]));

        $this->assertSame(32_767, mb_strlen($xpath->query('//m:c[@r="A2"]/m:is/m:t')->item(0)->textContent));
    }

    public function testRender_MakesTheHeadingOfTheTableBoldText(): void
    {
        $xpath = $this->sheetXpath($this->render([['Title'], ['Code', 'Name', 'Amount'], ['1', 'Cash', '5']]));

        $this->assertSame('1', $xpath->query('//m:c[@r="A2"]')->item(0)->getAttribute('s'));
        $this->assertSame('1', $xpath->query('//m:c[@r="C2"]')->item(0)->getAttribute('s'));
        $this->assertSame('', $xpath->query('//m:c[@r="A1"]')->item(0)->getAttribute('s'));
        $this->assertSame('', $xpath->query('//m:c[@r="B3"]')->item(0)->getAttribute('s'));
    }

    public function testRender_KeepsAHeadingThatLooksLikeNumbersAsBoldText(): void
    {
        $xpath = $this->sheetXpath($this->render([['2025', '2026', '2027'], ['1', '2', '3']]));

        $this->assertSame('inlineStr', $xpath->query('//m:c[@r="B1"]')->item(0)->getAttribute('t'));
        $this->assertSame('1', $xpath->query('//m:c[@r="B1"]')->item(0)->getAttribute('s'));
        $this->assertSame('', $xpath->query('//m:c[@r="B2"]')->item(0)->getAttribute('t'), 'The row below is numbers');
    }

    public function testRender_SkipsEmptyCellsButKeepsTheRowNumbersAndTheColumnOfEachCell(): void
    {
        $xpath = $this->sheetXpath($this->render([['a', 'b', 'c'], [], ['', 'x', '']]));

        $this->assertSame(0, $xpath->query('//m:row[@r="2"]/m:c')->length);
        $this->assertSame(1, $xpath->query('//m:row[@r="3"]/m:c')->length);
        $this->assertSame('B3', $xpath->query('//m:row[@r="3"]/m:c')->item(0)->getAttribute('r'));
    }

    public function testRender_MarksTheSheetRightToLeftOnlyWhenAsked(): void
    {
        $this->assertSame('1', $this->sheetXpath($this->render([['a']], true))->query('//m:sheetView')->item(0)->getAttribute('rightToLeft'));
        $this->assertFalse($this->sheetXpath($this->render([['a']]))->query('//m:sheetView')->item(0)->hasAttribute('rightToLeft'));
    }

    public function testRender_WidensEachColumnToItsLongestTextWithinLimits(): void
    {
        $xpath = $this->sheetXpath($this->render([['a', 'b', 'c'], ['x', str_repeat('y', 30), str_repeat('z', 200)]]));

        $this->assertSame('8', $xpath->query('//m:col[@min="1"]')->item(0)->getAttribute('width'));
        $this->assertSame('32', $xpath->query('//m:col[@min="2"]')->item(0)->getAttribute('width'));
        $this->assertSame('60', $xpath->query('//m:col[@min="3"]')->item(0)->getAttribute('width'));
    }

    public function testRender_WritesAnEmptySheetForAReportWithNoRows(): void
    {
        $xpath = $this->sheetXpath($this->render([]));

        $this->assertSame(0, $xpath->query('//m:row')->length);
    }

    public function testRender_NamesTheSheetAfterTheTitleWithinTheRulesOfASheetName(): void
    {
        $workbook = $this->render([['a']], false, 'Profit/Loss: [2026]?');
        $document = new DOMDocument();
        $document->loadXML($this->parts($workbook)['xl/workbook.xml']);

        $this->assertSame('Profit Loss 2026', trim((string) preg_replace('/\s+/', ' ', $document->getElementsByTagName('sheet')->item(0)->getAttribute('name'))));
    }

    public function testSheetName_CutsALongTitleToThirtyOneCharactersAndNeverReturnsNothing(): void
    {
        $this->assertSame(31, mb_strlen(XlsxWriter::sheetName(str_repeat('ا', 50))));
        $this->assertSame('Report', XlsxWriter::sheetName(''));
        $this->assertSame('Report', XlsxWriter::sheetName('[]:*?/\\'));
        $this->assertSame('Trial balance', XlsxWriter::sheetName("'Trial balance'"));
    }

    public function testColumnName_CountsLikeASpreadsheet(): void
    {
        $this->assertSame('A', XlsxWriter::columnName(0));
        $this->assertSame('Z', XlsxWriter::columnName(25));
        $this->assertSame('AA', XlsxWriter::columnName(26));
        $this->assertSame('AZ', XlsxWriter::columnName(51));
        $this->assertSame('BA', XlsxWriter::columnName(52));
        $this->assertSame('AAA', XlsxWriter::columnName(702));
    }

    public function testRender_WritesAColumnBeyondZWithItsTwoLetterReference(): void
    {
        $xpath = $this->sheetXpath($this->render([array_merge(array_fill(0, 26, 'a'), ['edge'])]));

        $this->assertSame('edge', $xpath->query('//m:c[@r="AA1"]/m:is/m:t')->item(0)->textContent);
    }
}
