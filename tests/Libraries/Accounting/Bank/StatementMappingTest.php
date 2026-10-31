<?php

namespace Tests\Libraries\Accounting\Bank;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\Bank\StatementMapping;
use CodeIgniter\Test\CIUnitTestCase;

class StatementMappingTest extends CIUnitTestCase
{
    private function refusal(callable $action): AccountingException
    {
        try {
            $action();
        } catch (AccountingException $exception) {
            return $exception;
        }

        $this->fail('The mapping was not refused.');
    }

    public function testStandard_IsTheFiveColumnsOfTheSampleFileOfTheReferenceSystem(): void
    {
        $mapping = StatementMapping::standard();

        $this->assertSame([1, 2, 3, 4, 5, true, ','], [$mapping->dateColumn, $mapping->descriptionColumn, $mapping->depositColumn, $mapping->withdrawalColumn, $mapping->referenceColumn, $mapping->skipFirstRow, $mapping->delimiterCharacter()]);
        $this->assertSame(';', StatementMapping::standard(StatementMapping::SEMICOLON)->delimiterCharacter());
        $this->assertSame("\t", StatementMapping::standard(StatementMapping::TAB)->delimiterCharacter());
        $this->assertFalse(StatementMapping::standard(skipFirstRow: false)->skipFirstRow);
    }

    public function testFromInput_ReadsTheFormAndLeavesTheOptionalColumnsOut(): void
    {
        $mapping = StatementMapping::fromInput(['delimiter' => 'tab', 'skip_first_row' => '1', 'date_column' => '2', 'description_column' => '', 'deposit_column' => '3', 'withdrawal_column' => '4', 'reference_column' => '0', 'date_order' => 'mdy']);

        $this->assertSame(['tab', true, 2, null, 3, 4, null, 'mdy'], [$mapping->delimiter, $mapping->skipFirstRow, $mapping->dateColumn, $mapping->descriptionColumn, $mapping->depositColumn, $mapping->withdrawalColumn, $mapping->referenceColumn, $mapping->dateOrder]);
        $this->assertFalse(StatementMapping::fromInput(['date_column' => '1', 'deposit_column' => '2', 'withdrawal_column' => '3'])->skipFirstRow === false);
        $this->assertFalse(StatementMapping::fromInput(['skip_first_row' => '0', 'date_column' => '1', 'deposit_column' => '2', 'withdrawal_column' => '3'])->skipFirstRow);
    }

    public function testConstruct_RejectsAnUnknownSeparatorAnUnknownDateOrderAndAColumnOutOfRange(): void
    {
        foreach ([
            fn () => new StatementMapping('pipe', true, 1, 2, 3, 4, 5),
            fn () => new StatementMapping(StatementMapping::COMMA, true, 1, 2, 3, 4, 5, 'ydm'),
            fn () => new StatementMapping(StatementMapping::COMMA, true, 0, 2, 3, 4, 5),
            fn () => new StatementMapping(StatementMapping::COMMA, true, 1, 2, 3, 4, StatementMapping::MAX_COLUMN + 1),
        ] as $build) {
            $this->assertSame(AccountingError::BankStatementMappingInvalid, $this->refusal($build)->error);
        }
    }

    public function testConstruct_RejectsTwoFieldsInTheSameColumn(): void
    {
        $this->assertSame(AccountingError::BankStatementMappingInvalid, $this->refusal(fn () => new StatementMapping(StatementMapping::COMMA, true, 1, 2, 3, 3, 5))->error);
        $this->assertSame(AccountingError::BankStatementMappingInvalid, $this->refusal(fn () => new StatementMapping(StatementMapping::COMMA, true, 1, null, 3, 4, 1))->error);
        $this->assertInstanceOf(StatementMapping::class, new StatementMapping(StatementMapping::COMMA, true, 1, null, 2, 3, null));
    }

    public function testFromInput_RejectsAMissingRequiredColumnAndANonNumericOne(): void
    {
        $this->assertSame(AccountingError::BankStatementMappingInvalid, $this->refusal(fn () => StatementMapping::fromInput(['date_column' => '1', 'deposit_column' => '2']))->error);
        $this->assertSame(AccountingError::BankStatementMappingInvalid, $this->refusal(fn () => StatementMapping::fromInput(['date_column' => 'A', 'deposit_column' => '2', 'withdrawal_column' => '3']))->error);
        $this->assertSame(AccountingError::BankStatementMappingInvalid, $this->refusal(fn () => StatementMapping::fromInput(['date_column' => '1.5', 'deposit_column' => '2', 'withdrawal_column' => '3']))->error);
    }
}
