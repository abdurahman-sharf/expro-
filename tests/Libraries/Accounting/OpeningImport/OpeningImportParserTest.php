<?php

namespace Tests\Libraries\Accounting\OpeningImport;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\OpeningImport\OpeningImportParser;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Reading the text of a file of opening balances the way a spreadsheet saves it.
 *
 * @internal
 */
final class OpeningImportParserTest extends CIUnitTestCase
{
    private function refusal(string $text): AccountingException
    {
        try {
            (new OpeningImportParser())->parse($text);
        } catch (AccountingException $exception) {
            return $exception;
        }

        $this->fail('The text was read.');
    }

    public function testParse_ReadsTheRowsUnderTheHeaderWithTheirLineNumbers(): void
    {
        $rows = (new OpeningImportParser())->parse("party,amount,date,note\n12,100.50,2024-12-31,first\nAli Hassan,-5,,\n");

        $this->assertSame([
            ['line' => 2, 'party' => '12', 'amount' => '100.50', 'date' => '2024-12-31', 'note' => 'first'],
            ['line' => 3, 'party' => 'Ali Hassan', 'amount' => '-5', 'date' => '', 'note' => ''],
        ], $rows);
    }

    public function testParse_SkipsRemarksAndBlankLinesButCountsThemInTheLineNumbers(): void
    {
        $rows = (new OpeningImportParser())->parse("# a remark\n\nparty,amount\n# another\n7,1\n\n8,2\n");

        $this->assertSame([5, 7], array_column($rows, 'line'));
        $this->assertSame(['7', '8'], array_column($rows, 'party'));
    }

    public function testParse_ReadsAFileThatStartsWithTheMarkOfUtf8(): void
    {
        $rows = (new OpeningImportParser())->parse("\xEF\xBB\xBFparty,amount\nمحمد علي,5\n");

        $this->assertSame('محمد علي', $rows[0]['party']);
    }

    public function testParse_ReadsSemicolonsAndTabsAsASpreadsheetOfAnotherLocaleSavesThem(): void
    {
        $semicolons = (new OpeningImportParser())->parse("party;amount;date\n7;1,5;2024-01-01\n");
        $tabs       = (new OpeningImportParser())->parse("party\tamount\n7\t15\n");

        $this->assertSame(['7', '1,5', '2024-01-01'], [$semicolons[0]['party'], $semicolons[0]['amount'], $semicolons[0]['date']]);
        $this->assertSame(['7', '15'], [$tabs[0]['party'], $tabs[0]['amount']]);
    }

    public function testParse_FindsTheColumnsByTheirNamesInAnyOrderAndByTheirAliases(): void
    {
        $rows = (new OpeningImportParser())->parse("Notes,Balance,As of,Customer\nn,9,2024-02-02,Ali\n");

        $this->assertSame(['party' => 'Ali', 'amount' => '9', 'date' => '2024-02-02', 'note' => 'n'], array_diff_key($rows[0], ['line' => 0]));
    }

    public function testParse_KeepsAQuotedCellWithACommaAndALineBreakWhole(): void
    {
        $rows = (new OpeningImportParser())->parse("party,amount,note\n7,1,\"a, b\nand c\"\n8,2,\n");

        $this->assertSame("a, b\nand c", $rows[0]['note']);
        $this->assertSame('8', $rows[1]['party']);
    }

    public function testParse_RefusesAFileWithoutThePartyAndAmountColumns(): void
    {
        $this->assertSame(AccountingError::OpeningImportFileInvalid, $this->refusal("name,note\nAli,x\n")->error);
        $this->assertSame(AccountingError::OpeningImportFileInvalid, $this->refusal("# only a remark\n")->error);
        $this->assertSame(AccountingError::OpeningImportFileInvalid, $this->refusal('')->error);
    }

    public function testParse_RefusesAFileWithNoRowOfBalances(): void
    {
        $this->assertSame(AccountingError::OpeningImportEmpty, $this->refusal("party,amount\n\n# nothing\n")->error);
    }

    public function testParse_RefusesTextThatIsNotUtf8(): void
    {
        $this->assertSame(AccountingError::OpeningImportFileInvalid, $this->refusal("party,amount\n\xFF\xFE,5\n")->error);
    }

    public function testParse_RefusesAFileWithMoreRowsOrMoreBytesThanItReads(): void
    {
        $rows = "party,amount\n" . str_repeat("1,1\n", OpeningImportParser::MAX_ROWS + 1);

        $this->assertSame([AccountingError::OpeningImportFileTooBig, OpeningImportParser::MAX_ROWS], [$this->refusal($rows)->error, $this->refusal($rows)->context['max']]);
        $this->assertCount(OpeningImportParser::MAX_ROWS, (new OpeningImportParser())->parse("party,amount\n" . str_repeat("1,1\n", OpeningImportParser::MAX_ROWS)));
        $this->assertSame(AccountingError::OpeningImportFileTooBig, $this->refusal(str_repeat('x', OpeningImportParser::MAX_BYTES + 1))->error);
    }
}
