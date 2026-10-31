<?php

namespace App\Libraries\Accounting\Bank;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;

/**
 * Where the five fields of a statement line are in the rows of a file: the separator of the cells,
 * whether the first row is a heading to be skipped, the column of each field counted from one, and the
 * order of the day, the month and the year in a date written with three numbers. The date, the deposit
 * and the withdrawal are required and the description and the reference are optional, as the reference
 * system's wizard has them, and no two fields take the same column.
 */
final class StatementMapping
{
    public const COMMA     = 'comma';
    public const SEMICOLON = 'semicolon';
    public const TAB       = 'tab';
    public const DELIMITERS = [self::COMMA => ',', self::SEMICOLON => ';', self::TAB => "\t"];
    public const DAY_FIRST   = 'dmy';
    public const MONTH_FIRST = 'mdy';
    public const YEAR_FIRST  = 'ymd';
    public const DATE_ORDERS = [self::DAY_FIRST, self::MONTH_FIRST, self::YEAR_FIRST];
    public const MAX_COLUMN  = 30;

    public function __construct(
        public readonly string $delimiter,
        public readonly bool $skipFirstRow,
        public readonly int $dateColumn,
        public readonly ?int $descriptionColumn,
        public readonly int $depositColumn,
        public readonly int $withdrawalColumn,
        public readonly ?int $referenceColumn,
        public readonly string $dateOrder = self::DAY_FIRST,
    ) {
        $columns = array_filter([$dateColumn, $descriptionColumn, $depositColumn, $withdrawalColumn, $referenceColumn], static fn (?int $column): bool => $column !== null);

        if (! isset(self::DELIMITERS[$delimiter]) || ! in_array($dateOrder, self::DATE_ORDERS, true)) {
            throw new AccountingException(AccountingError::BankStatementMappingInvalid, 'The separator or the order of the date is not one of the known ones.');
        }

        foreach ($columns as $column) {
            if ($column < 1 || $column > self::MAX_COLUMN) {
                throw new AccountingException(AccountingError::BankStatementMappingInvalid, 'A column is outside 1 to ' . self::MAX_COLUMN . '.', ['max' => self::MAX_COLUMN]);
            }
        }

        if (count($columns) !== count(array_unique($columns))) {
            throw new AccountingException(AccountingError::BankStatementMappingInvalid, 'Two fields cannot be read from the same column.');
        }
    }

    /**
     * The mapping of the sample file of the reference system: date, description, deposit, withdrawal and
     * reference, in that order.
     */
    public static function standard(string $delimiter = self::COMMA, bool $skipFirstRow = true): self
    {
        return new self($delimiter, $skipFirstRow, 1, 2, 3, 4, 5);
    }

    /**
     * @param array<string, mixed> $input delimiter, skip_first_row, date_column, description_column, deposit_column, withdrawal_column, reference_column and date_order.
     *
     * @throws AccountingException
     */
    public static function fromInput(array $input): self
    {
        return new self(
            is_string($input['delimiter'] ?? null) ? $input['delimiter'] : self::COMMA,
            in_array($input['skip_first_row'] ?? '1', [1, '1', true, 'on'], true),
            self::column($input['date_column'] ?? null, true),
            self::column($input['description_column'] ?? null, false),
            self::column($input['deposit_column'] ?? null, true),
            self::column($input['withdrawal_column'] ?? null, true),
            self::column($input['reference_column'] ?? null, false),
            is_string($input['date_order'] ?? null) ? $input['date_order'] : self::DAY_FIRST,
        );
    }

    public function delimiterCharacter(): string
    {
        return self::DELIMITERS[$this->delimiter];
    }

    public function widestRequired(): int
    {
        return max($this->dateColumn, $this->depositColumn, $this->withdrawalColumn);
    }

    /**
     * @throws AccountingException
     */
    private static function column(mixed $value, bool $required): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            if ($required) {
                throw new AccountingException(AccountingError::BankStatementMappingInvalid, 'The date, the deposit and the withdrawal need a column each.');
            }

            return null;
        }

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new AccountingException(AccountingError::BankStatementMappingInvalid, 'A column is a number counted from one.', ['max' => self::MAX_COLUMN]);
        }

        return (int) $value;
    }
}
