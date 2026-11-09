<?php

namespace App\Libraries\Portal;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The ledger of failed sign-ins that locks a user name, or an address, for a while. A name is kept
 * whether or not an account has it, so a locked name looks the same for a real account and for a guess.
 * Five failures lock a name and twenty lock an address; the first lock lasts fifteen minutes and every
 * lock after it twice as long as the one before, up to a day. Failures older than a day are forgotten, a
 * success clears the name, and an employee who resets the password clears it too.
 */
final class PortalLoginGuard
{
    public const USER_THRESHOLD    = 5;
    public const ADDRESS_THRESHOLD = 20;
    public const BASE_LOCK_SECONDS = 900;
    public const MAX_LOCK_SECONDS  = 86400;
    public const WINDOW_SECONDS    = 86400;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public static function userKey(string $username): string
    {
        return self::key('user|' . mb_strtolower(trim($username)));
    }

    public static function addressKey(string $address): string
    {
        return self::key('address|' . $address);
    }

    /**
     * The moment a lock ends, or null when the key is not locked.
     */
    public function lockedUntil(string $key, ?int $now = null): ?int
    {
        $row   = $this->db->table('portal_login_failures')->select('locked_until')->where('failure_key', $key)->get()->getRow();
        $until = $row === null || $row->locked_until === null ? null : strtotime((string) $row->locked_until);

        return $until !== null && $until > ($now ?? time()) ? $until : null;
    }

    /**
     * Counts a failure and returns whether it locked the key.
     */
    public function recordFailure(string $key, int $threshold, ?int $now = null): bool
    {
        $now ??= time();
        $table = $this->db->prefixTable('portal_login_failures');

        $this->db->transStart();

        $row      = $this->db->query("SELECT failures, lockouts, last_failure_at FROM {$table} WHERE failure_key = ? FOR UPDATE", [$key])->getRow();
        $failures = 1;
        $lockouts = 0;

        if ($row !== null) {
            $forgotten = strtotime((string) $row->last_failure_at) < $now - self::WINDOW_SECONDS;
            $failures  = ($forgotten ? 0 : (int) $row->failures) + 1;
            $lockouts  = (int) $row->lockouts;
        }

        $locked = $failures >= $threshold;
        $until  = null;

        if ($locked) {
            $until = date('Y-m-d H:i:s', $now + min(self::MAX_LOCK_SECONDS, self::BASE_LOCK_SECONDS * (2 ** $lockouts)));
            $lockouts++;
            $failures = 0;
        }

        $this->db->query(
            "INSERT INTO {$table} (failure_key, failures, lockouts, locked_until, last_failure_at) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE failures = VALUES(failures), lockouts = VALUES(lockouts), locked_until = VALUES(locked_until), last_failure_at = VALUES(last_failure_at)",
            [$key, $failures, $lockouts, $until, date('Y-m-d H:i:s', $now)],
        );

        $this->db->transComplete();

        return $locked;
    }

    public function clear(string $key): void
    {
        $this->db->table('portal_login_failures')->where('failure_key', $key)->delete();
    }

    private static function key(string $subject): string
    {
        helper('security');

        return hash_hmac('sha256', $subject, checkThrottleEncryption());
    }
}
