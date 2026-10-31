<?php

namespace Tests\Libraries\Accounting\Bank;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\Bank\StatementMapping;
use App\Libraries\Accounting\Bank\StatementParser;
use App\Libraries\Accounting\Bank\StatementRowError;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Reading the text of a bank statement into rows: the formats of dates and amounts it takes, the faults
 * of a row that are named by row and reason, the limits of the file, and the checksum and the keys by
 * which an import is idempotent. It touches no database.
 */
class StatementParserTest extends CIUnitTestCase
{
    private StatementParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new StatementParser();
    }

    private function row(string $date, string $deposit, string $withdrawal, string $description = 'Text', string $reference = 'R1', string $order = StatementMapping::DAY_FIRST): object
    {
        $parsed = $this->parser->parse("Date,Description,Deposit,Withdrawal,Reference\n{$date},{$description},{$deposit},{$withdrawal},{$reference}\n", new StatementMapping(StatementMapping::COMMA, true, 1, 2, 3, 4, 5, $order));

        return (object) ['rows' => $parsed->rows, 'errors' => $parsed->errors];
    }

    private function refusal(callable $action): AccountingException
    {
        try {
            $action();
        } catch (AccountingException $exception) {
            return $exception;
        }

        $this->fail('The file was not refused.');
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function dateProvider(): array
    {
        return [
            'iso'                    => ['2026-03-09', StatementMapping::DAY_FIRST, '2026-03-09'],
            'iso with slashes'       => ['2026/03/09', StatementMapping::DAY_FIRST, '2026-03-09'],
            'day first'              => ['09/03/2026', StatementMapping::DAY_FIRST, '2026-03-09'],
            'day first with dashes'  => ['9-3-2026', StatementMapping::DAY_FIRST, '2026-03-09'],
            'day first with dots'    => ['09.03.2026', StatementMapping::DAY_FIRST, '2026-03-09'],
            'month first'            => ['03/09/2026', StatementMapping::MONTH_FIRST, '2026-03-09'],
            'iso when month first'   => ['2026-03-09', StatementMapping::MONTH_FIRST, '2026-03-09'],
            'with a time'            => ['2026-03-09 14:30:05', StatementMapping::DAY_FIRST, '2026-03-09'],
            'with a time and a T'    => ['2026-03-09T14:30', StatementMapping::DAY_FIRST, '2026-03-09'],
            'day first with a time'  => ['09/03/2026 14:30', StatementMapping::DAY_FIRST, '2026-03-09'],
            'arabic digits'          => ['٠٩/٠٣/٢٠٢٦', StatementMapping::DAY_FIRST, '2026-03-09'],
            'leap day'               => ['29/02/2028', StatementMapping::DAY_FIRST, '2028-02-29'],
        ];
    }

    #[DataProvider('dateProvider')]
    public function testParse_ReadsTheDatesOfTheFormatsItTakes(string $text, string $order, string $expected): void
    {
        $result = $this->row($text, '1.00', '', order: $order);

        $this->assertSame([], $result->errors);
        $this->assertSame($expected, $result->rows[0]->date);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function badDateProvider(): array
    {
        return [
            'not a leap day'      => ['29/02/2026', StatementMapping::DAY_FIRST],
            'month thirteen'      => ['01/13/2026', StatementMapping::DAY_FIRST],
            'day first read as month first' => ['25/12/2026', StatementMapping::MONTH_FIRST],
            'two digit year'      => ['09/03/26', StatementMapping::DAY_FIRST],
            'words'               => ['March 9', StatementMapping::DAY_FIRST],
            'year before range'   => ['09/03/1899', StatementMapping::DAY_FIRST],
            'year after range'    => ['09/03/2101', StatementMapping::DAY_FIRST],
            'day first when year first is asked' => ['09/03/2026', StatementMapping::YEAR_FIRST],
        ];
    }

    #[DataProvider('badDateProvider')]
    public function testParse_RejectsADateThatIsNotValidInTheOrderChosenNamingTheText(string $text, string $order): void
    {
        $result = $this->row($text, '1.00', '', order: $order);

        $this->assertSame([], $result->rows);
        $this->assertSame([StatementRowError::DATE_INVALID], array_map(static fn (StatementRowError $error): string => $error->reason, $result->errors));
        $this->assertSame($text, $result->errors[0]->value);
        $this->assertSame(2, $result->errors[0]->rowNumber);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function amountProvider(): array
    {
        return [
            'integer'                    => ['100', '100.0000'],
            'point decimals'             => ['1234.5', '1234.5000'],
            'thousands commas'           => ['1,234.56', '1234.5600'],
            'thousands points comma'     => ['1.234,56', '1234.5600'],
            'comma decimals'             => ['12,5', '12.5000'],
            'comma thousands only'       => ['1,234', '1234.0000'],
            'spaces as thousands'        => ['1 234.50', '1234.5000'],
            'leading plus'               => ['+15.25', '15.2500'],
            'three decimals'             => ['0.125', '0.1250'],
            'four decimals'              => ['0.0001', '0.0001'],
            'arabic digits and marks'    => ['١٬٢٣٤٫٥٠', '1234.5000'],
            'largest amount'             => ['99999999999.9999', '99999999999.9999'],
            'only decimals'              => ['.5', '0.5000'],
        ];
    }

    #[DataProvider('amountProvider')]
    public function testParse_ReadsAnAmountInTheFormsABankWritesIt(string $text, string $expected): void
    {
        $cell = str_contains($text, ',') ? '"' . $text . '"' : $text;

        $deposit    = $this->row('2026-03-01', $cell, '');
        $withdrawal = $this->row('2026-03-01', '', $cell);

        $this->assertSame([], $deposit->errors);
        $this->assertSame($expected, $deposit->rows[0]->amount);
        $this->assertSame('-' . $expected, $withdrawal->rows[0]->amount);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function badAmountProvider(): array
    {
        return [
            'text'               => ['ten', StatementRowError::AMOUNT_INVALID],
            'two points'         => ['1.2.3x', StatementRowError::AMOUNT_INVALID],
            'mixed separators'   => ['"1,23,4.5"', StatementRowError::AMOUNT_INVALID],
            'currency symbol'    => ['SAR 10', StatementRowError::AMOUNT_INVALID],
            'negative'           => ['-10.00', StatementRowError::AMOUNT_NEGATIVE],
            'negative with the true minus sign' => ['−10.00', StatementRowError::AMOUNT_NEGATIVE],
            'in brackets'        => ['(10.00)', StatementRowError::AMOUNT_NEGATIVE],
            'five decimals'      => ['1.23456', StatementRowError::AMOUNT_PRECISION],
            'too large'          => ['100000000000', StatementRowError::AMOUNT_TOO_LARGE],
            'far too large'      => ['99999999999999999999999', StatementRowError::AMOUNT_TOO_LARGE],
        ];
    }

    #[DataProvider('badAmountProvider')]
    public function testParse_RejectsAnAmountThatIsNotValidNamingTheReasonAndTheText(string $text, string $reason): void
    {
        $result = $this->row('2026-03-01', $text, '');

        $this->assertSame([], $result->rows);
        $this->assertSame([$reason], array_map(static fn (StatementRowError $error): string => $error->reason, $result->errors));
        $this->assertSame(trim($text, '"'), $result->errors[0]->value);
    }

    public function testParse_RejectsARowThatHasBothAmountsOrNeitherOrAZeroOne(): void
    {
        $both    = $this->row('2026-03-01', '5.00', '5.00');
        $neither = $this->row('2026-03-01', '', '');
        $zeroes  = $this->row('2026-03-01', '0', '0.00');
        $mixed   = $this->row('2026-03-01', '0', '4.00');

        $this->assertSame([StatementRowError::AMOUNT_BOTH], array_map(static fn (StatementRowError $error): string => $error->reason, $both->errors));
        $this->assertSame([StatementRowError::AMOUNT_MISSING], array_map(static fn (StatementRowError $error): string => $error->reason, $neither->errors));
        $this->assertSame([StatementRowError::AMOUNT_MISSING], array_map(static fn (StatementRowError $error): string => $error->reason, $zeroes->errors));
        $this->assertSame('-4.0000', $mixed->rows[0]->amount);
    }

    public function testParse_RejectsAMissingDateAndATextThatIsTooLong(): void
    {
        $this->assertSame([StatementRowError::DATE_MISSING], array_map(static fn (StatementRowError $error): string => $error->reason, $this->row('', '1.00', '')->errors));
        $this->assertSame([StatementRowError::TEXT_TOO_LONG], array_map(static fn (StatementRowError $error): string => $error->reason, $this->row('2026-03-01', '1.00', '', str_repeat('d', StatementParser::DESCRIPTION_MAX + 1))->errors));
        $this->assertSame([StatementRowError::TEXT_TOO_LONG], array_map(static fn (StatementRowError $error): string => $error->reason, $this->row('2026-03-01', '1.00', '', 'ok', str_repeat('r', StatementParser::REFERENCE_MAX + 1))->errors));
        $this->assertSame([], $this->row('2026-03-01', '1.00', '', str_repeat('d', StatementParser::DESCRIPTION_MAX), str_repeat('r', StatementParser::REFERENCE_MAX))->errors);
    }

    public function testParse_RejectsARowWithTooFewColumnsButTakesOneWithoutTheOptionalOnesAtTheEnd(): void
    {
        $text = "Date,Description,Deposit,Withdrawal,Reference\n2026-03-01,Short,5.00\n2026-03-02,No reference,5.00,\n2026-03-03,Without trailing cells,,2.00\n";

        $parsed = $this->parser->parse($text, StatementMapping::standard());

        $this->assertSame([2], array_map(static fn (StatementRowError $error): int => $error->rowNumber, $parsed->errors));
        $this->assertSame(StatementRowError::COLUMNS, $parsed->errors[0]->reason);
        $this->assertSame(['2026-03-02', '2026-03-03'], array_map(static fn ($row): string => $row->date, $parsed->rows));
    }

    public function testParse_NamesEveryRowThatIsNotValidWithItsNumberInTheFileAndCountsThemAll(): void
    {
        $lines = ["Date,Description,Deposit,Withdrawal,Reference"];

        for ($number = 1; $number <= StatementParser::MAX_REPORTED_ERRORS + 10; $number++) {
            $lines[] = "bad-{$number},Row {$number},1.00,,R";
        }

        $lines[] = '2026-03-01,Good,1.00,,R';

        $parsed = $this->parser->parse(implode("\n", $lines), StatementMapping::standard());

        $this->assertFalse($parsed->isValid());
        $this->assertSame(StatementParser::MAX_REPORTED_ERRORS + 10, $parsed->errorCount);
        $this->assertCount(StatementParser::MAX_REPORTED_ERRORS, $parsed->errors);
        $this->assertSame(2, $parsed->errors[0]->rowNumber);
        $this->assertCount(1, $parsed->rows);
        $this->assertSame(StatementParser::MAX_REPORTED_ERRORS + 11, $parsed->rowCount);
    }

    public function testParse_SkipsEmptyRowsAndCountsTheRowNumbersOfTheFileIncludingThem(): void
    {
        $text = "Date,Description,Deposit,Withdrawal,Reference\n\n2026-03-01,One,1.00,,A\n,,,,\n   \nbad,Two,1.00,,B\n";

        $parsed = $this->parser->parse($text, StatementMapping::standard());

        $this->assertSame(2, $parsed->rowCount);
        $this->assertSame([6], array_map(static fn (StatementRowError $error): int => $error->rowNumber, $parsed->errors));
    }

    public function testParse_ReadsQuotedCellsWithTheSeparatorAndLineBreaksInsideThem(): void
    {
        $text = "Date,Description,Deposit,Withdrawal,Reference\n2026-03-01,\"Transfer, with a comma\",1.00,,A\n2026-03-02,\"Two\nlines\",,2.00,B\n2026-03-03,\"He said \"\"hi\"\"\",3.00,,C\n";

        $parsed = $this->parser->parse($text, StatementMapping::standard());

        $this->assertSame([], $parsed->errors);
        $this->assertSame(['Transfer, with a comma', "Two\nlines", 'He said "hi"'], array_map(static fn ($row): string => $row->description, $parsed->rows));
    }

    public function testParse_TakesAByteOrderMarkArabicTextAndAnyLineEnd(): void
    {
        $text = "\xEF\xBB\xBFDate;Description;Deposit;Withdrawal;Reference\r2026-03-01;تحويل من عميل;1500;;مرجع-1\r\n2026-03-02;رسوم;;12,50;\n";

        $parsed = $this->parser->parse($text, StatementMapping::standard(StatementMapping::SEMICOLON));

        $this->assertSame([], $parsed->errors);
        $this->assertSame(['تحويل من عميل', 'رسوم'], array_map(static fn ($row): string => $row->description, $parsed->rows));
        $this->assertSame(['1500.0000', '-12.5000'], array_map(static fn ($row): string => $row->amount, $parsed->rows));
        $this->assertSame('مرجع-1', $parsed->rows[0]->reference);
    }

    public function testParse_GivesTheSameChecksumWhateverTheLineEndsTheByteOrderMarkAndTheEmptyLinesAtTheEnd(): void
    {
        $text = "Date,Description,Deposit,Withdrawal,Reference\n2026-03-01,One,1.00,,A\n";

        $plain = $this->parser->parse($text, StatementMapping::standard())->checksum;

        $this->assertSame($plain, $this->parser->parse("\xEF\xBB\xBF" . str_replace("\n", "\r\n", $text) . "\r\n\r\n", StatementMapping::standard())->checksum);
        $this->assertSame($plain, $this->parser->parse(str_replace("\n", "\r", $text), StatementMapping::standard())->checksum);
        $this->assertSame(hash('sha256', rtrim($text)), $plain);
        $this->assertNotSame($plain, $this->parser->parse(str_replace('One', 'Two', $text), StatementMapping::standard())->checksum);
        $this->assertNotSame($plain, $this->parser->parse(str_replace('1.00', '1.01', $text), StatementMapping::standard())->checksum);
    }

    public function testParse_GivesALineTheSameKeyWhateverTheCaseTheSpacesAndTheDiacriticsOfItsText(): void
    {
        $first  = $this->parser->parse("H\n2026-03-01,Bank  FEE,,2.00,Ref-1\n", StatementMapping::standard())->rows[0];
        $second = $this->parser->parse("H\n2026-03-01,bank fee,,2.00,REF-1\n", StatementMapping::standard())->rows[0];
        $other  = $this->parser->parse("H\n2026-03-01,bank fee,,2.01,REF-1\n", StatementMapping::standard())->rows[0];
        $arabic = $this->parser->parse("H\n2026-03-01,رسوم البَنك,,2.00,\n", StatementMapping::standard())->rows[0];
        $plain  = $this->parser->parse("H\n2026-03-01,رسوم البنك,,2.00,\n", StatementMapping::standard())->rows[0];

        $this->assertSame($first->dedupeKey, $second->dedupeKey);
        $this->assertNotSame($first->dedupeKey, $other->dedupeKey);
        $this->assertSame($arabic->dedupeKey, $plain->dedupeKey);
        $this->assertSame(64, strlen($first->dedupeKey));
    }

    public function testParse_NumbersTheRepeatsOfALineInOneFileSoThatEachGetsAKeyOfItsOwn(): void
    {
        $parsed = $this->parser->parse("H\n2026-03-01,Fee,,2.00,F\n2026-03-01,Fee,,2.00,F\n2026-03-01,Fee,,2.00,F\n2026-03-02,Fee,,2.00,F\n", StatementMapping::standard());
        $keys   = array_map(static fn ($row): string => $row->dedupeKey, $parsed->rows);

        $this->assertCount(4, array_unique($keys));

        $again = $this->parser->parse("H\n2026-03-01,Fee,,2.00,F\n2026-03-01,Fee,,2.00,F\n", StatementMapping::standard());

        $this->assertSame(array_slice($keys, 0, 2), array_map(static fn ($row): string => $row->dedupeKey, $again->rows));
    }

    /**
     * A valid statement of 5,000 rows that is exactly the number of bytes asked for.
     */
    private function statementOfBytes(int $bytes): string
    {
        $header = "Date,Description,Deposit,Withdrawal,Reference\n";
        $row    = static fn (int $padding): string => '2026-03-01,' . str_repeat('d', 400 + $padding) . ",1.00,,\n";
        $extra  = $bytes - strlen($header) - StatementParser::MAX_ROWS * strlen($row(0));
        $text   = $header;

        for ($number = 0; $number < StatementParser::MAX_ROWS; $number++) {
            $text .= $row($number < $extra ? 1 : 0);
        }

        return $text;
    }

    public function testParse_TakesAFileOfExactlyTheSizeLimitAndRejectsOneByteMore(): void
    {
        $atLimit = $this->statementOfBytes(StatementParser::MAX_BYTES);

        $this->assertSame(StatementParser::MAX_BYTES, strlen($atLimit));
        $this->assertSame(StatementParser::MAX_ROWS, $this->parser->parse($atLimit, StatementMapping::standard())->rowCount);

        $refusal = $this->refusal(fn () => $this->parser->parse($atLimit . 'x', StatementMapping::standard()));

        $this->assertSame(AccountingError::BankStatementTooLarge, $refusal->error);
        $this->assertSame(2.0, (float) $refusal->context['max']);
        $this->assertSame(AccountingError::BankStatementTooLarge, $this->refusal(fn () => $this->parser->parse(str_repeat('a', StatementParser::MAX_BYTES + 1), StatementMapping::standard()))->error);
    }

    public function testParse_TakesTheSizeLimitItIsGivenAndNoMore(): void
    {
        $text = "H\n2026-03-01,x,1,,\n";

        $this->assertSame(1, (new StatementParser(maxBytes: strlen($text)))->parse($text, StatementMapping::standard())->rowCount);
        $this->assertSame(AccountingError::BankStatementTooLarge, $this->refusal(fn () => (new StatementParser(maxBytes: strlen($text) - 1))->parse($text, StatementMapping::standard()))->error);
    }

    public function testParse_RejectsAFileOverTheRowLimitAndTakesOneAtTheLimit(): void
    {
        $rows = static fn (int $count): string => "H\n" . str_repeat("2026-03-01,x,1.00,,\n", $count);

        $atLimit = $this->parser->parse($rows(StatementParser::MAX_ROWS), StatementMapping::standard());
        $refusal = $this->refusal(fn () => $this->parser->parse($rows(StatementParser::MAX_ROWS + 1), StatementMapping::standard()));

        $this->assertSame(StatementParser::MAX_ROWS, $atLimit->rowCount);
        $this->assertSame(AccountingError::BankStatementTooManyRows, $refusal->error);
        $this->assertSame(StatementParser::MAX_ROWS, $refusal->context['max']);
        $this->assertSame(1, (new StatementParser(maxRows: 1))->parse("H\n2026-03-01,x,1,,\n\n\n", StatementMapping::standard())->rowCount);
    }

    public function testParse_DoesNotCountTheHeadingNorTheEmptyRowsAgainstTheRowLimit(): void
    {
        $parser = new StatementParser(maxRows: 2);

        $parsed = $parser->parse("Date,Description,Deposit,Withdrawal,Reference\n\n2026-03-01,x,1,,\n\n2026-03-02,y,1,,\n\n", StatementMapping::standard());

        $this->assertSame(2, $parsed->rowCount);
        $this->assertSame(AccountingError::BankStatementTooManyRows, $this->refusal(fn () => $parser->parse("2026-03-01,x,1,,\n2026-03-02,y,1,,\n2026-03-03,z,1,,\n", StatementMapping::standard(skipFirstRow: false)))->error);
    }

    public function testParse_RejectsAFileThatIsNotUtf8TextOrIsBinaryOrEmpty(): void
    {
        $latin  = "Date,Description,Deposit,Withdrawal,Reference\n2026-03-01,caf\xE9,1.00,,\n";
        $zipped = "PK\x03\x04\x14\x00\x00\x00\x08\x00";

        $this->assertSame(AccountingError::BankStatementEncodingInvalid, $this->refusal(fn () => $this->parser->parse($latin, StatementMapping::standard()))->error);
        $this->assertSame(AccountingError::BankStatementEncodingInvalid, $this->refusal(fn () => $this->parser->parse($zipped, StatementMapping::standard()))->error);
        $this->assertSame(AccountingError::BankStatementEncodingInvalid, $this->refusal(fn () => $this->parser->parse("a\0b", StatementMapping::standard()))->error);
        $this->assertSame(AccountingError::BankStatementEmpty, $this->refusal(fn () => $this->parser->parse('', StatementMapping::standard()))->error);
        $this->assertSame(AccountingError::BankStatementEmpty, $this->refusal(fn () => $this->parser->parse("\xEF\xBB\xBF \n\r\n\t", StatementMapping::standard()))->error);
    }

    public function testParse_SkipsTheFirstRowOnlyWhenTheMappingSaysSo(): void
    {
        $text = "2026-03-01,First,1.00,,A\n2026-03-02,Second,2.00,,B\n";

        $this->assertSame(['First', 'Second'], array_map(static fn ($row): string => $row->description, $this->parser->parse($text, StatementMapping::standard(skipFirstRow: false))->rows));
        $this->assertSame(['Second'], array_map(static fn ($row): string => $row->description, $this->parser->parse($text, StatementMapping::standard())->rows));
    }
}
