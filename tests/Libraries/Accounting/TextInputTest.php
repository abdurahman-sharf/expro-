<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\TextInput;
use CodeIgniter\Test\CIUnitTestCase;
use stdClass;

/**
 * A figure typed in a form is read as text, and a field that came as a list is refused with the error of the
 * field instead of being cast.
 *
 * @internal
 */
final class TextInputTest extends CIUnitTestCase
{
    public function testOf_TrimsATextAndReadsANumberAsItsText(): void
    {
        $this->assertSame('15', TextInput::of("  15 \n", AccountingError::RecurringDateInvalid));
        $this->assertSame('7', TextInput::of(7, AccountingError::RecurringDateInvalid));
        $this->assertSame('1.5', TextInput::of(1.5, AccountingError::RecurringDateInvalid));
    }

    public function testOf_ReadsNothingAsAnEmptyText(): void
    {
        $this->assertSame('', TextInput::of(null, AccountingError::RecurringDateInvalid));
        $this->assertSame('', TextInput::of("  \t", AccountingError::RecurringDateInvalid));
    }

    public function testOf_RefusesAListAnObjectAndABooleanWithTheErrorOfTheField(): void
    {
        foreach ([['1'], [], new stdClass(), true] as $value) {
            try {
                TextInput::of($value, AccountingError::RecurringMaxRepeatInvalid);
                $this->fail('A value that is not a single text is refused: ' . get_debug_type($value));
            } catch (AccountingException $refusal) {
                $this->assertSame(AccountingError::RecurringMaxRepeatInvalid, $refusal->error);
            }
        }
    }
}
