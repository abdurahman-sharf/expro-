<?php

namespace App\Libraries\Accounting;

/**
 * A figure typed in a form, read as text. A field that came as a list (a name sent twice, a name with
 * brackets) is refused with the error of the field and is never cast, which PHP would answer with a warning
 * and the application with a server error.
 */
final class TextInput
{
    /**
     * @throws AccountingException
     */
    public static function of(mixed $value, AccountingError $error): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        throw new AccountingException($error, 'The field is not a single value.');
    }
}
