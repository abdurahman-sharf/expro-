<?php

namespace App\Libraries\Security;

/**
 * What a password of an employee must be: not empty, at least eight bytes, not the username in any case and
 * not the password the installer gives the first account.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH        = 8;
    public const INSTALLER_DEFAULT = 'pointofsale';

    /**
     * The line of the Employees language file that says what is wrong with the password, null when there is
     * nothing wrong.
     */
    public static function violation(string $password, string ...$usernames): ?string
    {
        if ($password === '') {
            return 'Employees.password_required';
        }

        if (strlen($password) < self::MIN_LENGTH) {
            return 'Employees.password_minlength';
        }

        foreach ($usernames as $username) {
            if ($username !== '' && mb_strtolower($password) === mb_strtolower($username)) {
                return 'Employees.password_same_as_username';
            }
        }

        return hash_equals(self::INSTALLER_DEFAULT, $password) ? 'Employees.password_is_default' : null;
    }
}
