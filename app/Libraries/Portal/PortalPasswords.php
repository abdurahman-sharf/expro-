<?php

namespace App\Libraries\Portal;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;

/**
 * The passwords of the portal. They are hashed with the algorithm the employee login uses
 * (PASSWORD_DEFAULT), kept as the hash only, and a temporary password made for an employee to hand over
 * is long and drawn from letters and digits that cannot be mistaken for each other.
 */
final class PortalPasswords
{
    public const MIN_LENGTH       = 10;
    public const MAX_BYTES        = 72;
    public const TEMPORARY_LENGTH = 14;

    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    private static ?string $absent = null;

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT);
    }

    /**
     * A hash that no password matches, checked when the user name is unknown so that an unknown name
     * and a wrong password take the same time.
     */
    public static function absent(): string
    {
        return self::$absent ??= password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    }

    public static function temporary(): string
    {
        $password = '';

        for ($index = 0; $index < self::TEMPORARY_LENGTH; $index++) {
            $password .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $password;
    }

    /**
     * @throws AccountingException
     */
    public static function check(string $password, string $username, ?string $current): void
    {
        $refused = mb_strlen($password) < self::MIN_LENGTH
            || strlen($password) > self::MAX_BYTES
            || mb_strtolower($password) === mb_strtolower($username)
            || ($current !== null && $password === $current);

        if ($refused) {
            throw new AccountingException(AccountingError::PortalPasswordInvalid, 'The new password does not meet the rules.', ['min' => self::MIN_LENGTH, 'max' => self::MAX_BYTES]);
        }
    }
}
