<?php

namespace Tests\Libraries\Accounting\Export;

use App\Libraries\Accounting\Export\ReportSheet;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
final class ReportSheetTest extends CIUnitTestCase
{
    public function testFromCsv_ReadsTheRowsAndDropsTheByteOrderMark(): void
    {
        $sheet = ReportSheet::fromCsv("\xEF\xBB\xBFCode,Name\n1101,Cash\n", 'Accounts');

        $this->assertSame([['Code', 'Name'], ['1101', 'Cash']], $sheet->rows);
        $this->assertSame('Accounts', $sheet->title);
    }

    public function testFromCsv_KeepsAQuotedCellWithCommasQuotesAndLineBreaks(): void
    {
        $sheet = ReportSheet::fromCsv("A,B\n\"one, \"\"two\"\"\nthree\",x\n", 'T');

        $this->assertSame([['A', 'B'], ["one, \"two\"\nthree", 'x']], $sheet->rows);
    }

    public function testFromCsv_KeepsABlankRowInTheMiddleAndDropsTheBlankRowsAtTheEnd(): void
    {
        $sheet = ReportSheet::fromCsv("A,B\n1,2\n\nNote\n\n\n", 'T');

        $this->assertSame([['A', 'B'], ['1', '2'], [], ['Note']], $sheet->rows);
        $this->assertSame(4, $sheet->rowCount());
    }

    public function testFromCsv_KeepsABackslashAtTheEndOfACellWhole(): void
    {
        $sheet = ReportSheet::fromCsv("A,B\n\"C:\\\\\",\"x\"\n", 'T');

        $this->assertSame('C:\\\\', $sheet->rows[1][0]);
    }

    public function testFromCsv_TakesTheTitleFromAFirstRowOfOneCellWhenNoneIsGiven(): void
    {
        $sheet = ReportSheet::fromCsv("Cheques received\nA,B\n1,2\n", '');

        $this->assertSame('Cheques received', $sheet->title);
    }

    public function testFromCsv_KeepsTheTitleGivenOverTheFirstRow(): void
    {
        $sheet = ReportSheet::fromCsv("Cheques received\nA,B\n", 'Cheques');

        $this->assertSame('Cheques', $sheet->title);
    }

    public function testFromCsv_LeavesTheTitleEmptyWhenTheFirstRowIsATableRow(): void
    {
        $sheet = ReportSheet::fromCsv("A,B\n1,2\n", '');

        $this->assertSame('', $sheet->title);
    }

    public function testFromCsv_ReadsAnEmptyFileAsNoRows(): void
    {
        $sheet = ReportSheet::fromCsv("\xEF\xBB\xBF", 'T');

        $this->assertSame([], $sheet->rows);
        $this->assertSame(0, $sheet->columnCount());
        $this->assertNull($sheet->headerIndex());
    }

    public function testColumnCount_IsTheWidestRow(): void
    {
        $sheet = new ReportSheet('T', [['a'], ['a', 'b', 'c'], ['a', 'b']]);

        $this->assertSame(3, $sheet->columnCount());
    }

    public function testHeaderIndex_FindsTheFirstRowThatFillsEveryColumn(): void
    {
        $sheet = new ReportSheet('T', [['Title'], ['Period', '2026'], ['Code', 'Name', 'Amount'], ['1', 'Cash', '5'], ['Total', '', '5']]);

        $this->assertSame(2, $sheet->headerIndex());
    }

    public function testHeaderIndex_IsNullForASingleColumn(): void
    {
        $this->assertNull((new ReportSheet('T', [['a'], ['b']]))->headerIndex());
    }

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function numbers(): array
    {
        return [
            'zero'               => ['0', '0', 0],
            'integer'            => ['12', '12', 0],
            'negative decimal'   => ['-12.50', '-12.50', 2],
            'four decimals'      => ['1234.5678', '1234.5678', 4],
            'zero with decimals' => ['0.0000', '0.0000', 4],
            'minus zero'         => ['-0.00', '0.00', 2],
            'nine digit integer' => ['123456789', '123456789', 0],
            'fifteen digits'     => ['1234567890.12345', '1234567890.12345', 5],
        ];
    }

    #[DataProvider('numbers')]
    public function testNumber_AcceptsAPlainDecimalAndKeepsItsDecimals(string $cell, string $value, int $decimals): void
    {
        $this->assertSame(['value' => $value, 'decimals' => $decimals], ReportSheet::number($cell));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function texts(): array
    {
        return [
            'leading zeros'        => ['007'],
            'leading zero decimal' => ['00.5'],
            'ten digit integer'    => ['1234567890'],
            'sixteen digits'       => ['123456789.1234567'],
            'formula'              => ['=1+1'],
            'neutralised formula'  => ["'=1+1"],
            'grouped number'       => ['1,000.00'],
            'exponent'             => ['1e5'],
            'padded'               => [' 5'],
            'plus sign'            => ['+5'],
            'trailing point'       => ['5.'],
            'leading point'        => ['.5'],
            'lone minus'           => ['-'],
            'empty'                => [''],
            'date'                 => ['2026-10-05'],
            'percent'              => ['12.5%'],
        ];
    }

    #[DataProvider('texts')]
    public function testNumber_RejectsAnythingThatIsNotAPlainDecimal(string $cell): void
    {
        $this->assertNull(ReportSheet::number($cell));
    }
}
