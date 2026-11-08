<?php

namespace App\Libraries\MasterData;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;

/**
 * The longest value Daftra takes for the fields of a customer or a supplier that this application
 * stores longer: the business name and the city 100 characters, the postal code 20. A value that is
 * longer is refused with the name of the field, but only when it is a change: a value that was saved
 * before the limit stays as it is until somebody edits it.
 */
final class PartyLengths
{
    public const LIMITS = ['company_name' => 100, 'city' => 100, 'state' => 100, 'zip' => 20];

    /**
     * @param array<string, string|null> $given  What is being saved, by field.
     * @param array<string, string>      $labels The name of each field in the language in use.
     *
     * @throws AccountingException
     */
    public static function check(array $given, ?object $current, array $labels): void
    {
        foreach (self::LIMITS as $field => $max) {
            $value = (string) ($given[$field] ?? '');

            if (mb_strlen($value) > $max && $value !== (string) ($current->{$field} ?? '')) {
                throw new AccountingException(AccountingError::PartyFieldTooLong, "{$field} is at most {$max} characters.", ['max' => $max, 'name' => $labels[$field] ?? $field]);
            }
        }
    }
}
