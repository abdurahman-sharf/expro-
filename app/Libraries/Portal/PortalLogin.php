<?php

namespace App\Libraries\Portal;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * One attempt to sign in, or to change the password of a session that is signed in. A name or an address
 * that is locked is refused before the password is looked at; a wrong name, a wrong password, a disabled
 * account and a temporary password that ran out all answer the same way, count as a failure of the name
 * and of the address, and are written to the audit log with the reason and the address but never the
 * password and never the name that was typed, which may be a password put in the wrong field: a failure
 * carries a reference that is the same for the same name and tells nothing of it. A wrong current password
 * in a change of the password counts the same way, so that a session that was taken cannot guess it. A
 * success clears the failures of the name.
 */
final class PortalLogin
{
    private const REFERENCE_LENGTH = 16;

    private BaseConnection $db;
    private PortalAccounts $accounts;
    private PortalLoginGuard $guard;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null, ?PortalAccounts $accounts = null, ?PortalLoginGuard $guard = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->accounts = $accounts ?? new PortalAccounts($this->db);
        $this->guard    = $guard ?? new PortalLoginGuard($this->db);
        $this->audit    = new AuditLog($this->db);
    }

    public function attempt(string $username, string $password, string $address, ?int $now = null): PortalLoginResult
    {
        $userKey = PortalLoginGuard::userKey($username);

        if (! $this->guard->takeTurn($userKey)) {
            return new PortalLoginResult(PortalLoginOutcome::Locked);
        }

        try {
            return $this->decide($username, $password, $address, $userKey, PortalLoginGuard::addressKey($address), $now);
        } finally {
            $this->guard->endTurn($userKey);
        }
    }

    /**
     * @throws AccountingException
     */
    public function changePassword(int $accountId, string $current, string $new, string $address, ?int $now = null): int
    {
        $account = $this->accounts->find($accountId) ?? throw new AccountingException(AccountingError::PortalAccountUnknown, 'The portal account does not exist.');
        $userKey = PortalLoginGuard::userKey((string) $account->username);

        if (! $this->guard->takeTurn($userKey)) {
            throw new AccountingException(AccountingError::PortalLocked, 'Too many failed attempts.');
        }

        try {
            return $this->change($account, $current, $new, $address, $userKey, PortalLoginGuard::addressKey($address), $now);
        } finally {
            $this->guard->endTurn($userKey);
        }
    }

    private function decide(string $username, string $password, string $address, string $userKey, string $addressKey, ?int $now): PortalLoginResult
    {
        if ($this->guard->lockedUntil($userKey, $now) !== null || $this->guard->lockedUntil($addressKey, $now) !== null) {
            return new PortalLoginResult(PortalLoginOutcome::Locked);
        }

        [$account, $reason] = $this->accounts->check($username, $password, $now);

        if ($account === null) {
            $this->fail($this->accounts->findByUsername($username), 'portal_login_failed', $reason, $address, $userKey, $addressKey, $now);

            return new PortalLoginResult(PortalLoginOutcome::Invalid);
        }

        $this->guard->clear($userKey);
        $this->accounts->recordLogin($account, $password);
        $this->audit->write(PortalAccounts::ENTITY, (int) $account->account_id, 'portal_login_succeeded', null, JournalOrigin::User, ['customer_id' => (int) $account->customer_id, 'address' => $address]);

        return new PortalLoginResult(PortalLoginOutcome::Success, $account);
    }

    /**
     * @throws AccountingException
     */
    private function change(object $account, string $current, string $new, string $address, string $userKey, string $addressKey, ?int $now): int
    {
        if ($this->guard->lockedUntil($userKey, $now) !== null || $this->guard->lockedUntil($addressKey, $now) !== null) {
            throw new AccountingException(AccountingError::PortalLocked, 'Too many failed attempts.');
        }

        try {
            $version = $this->accounts->changePassword((int) $account->account_id, $current, $new);
        } catch (AccountingException $failure) {
            if ($failure->error === AccountingError::PortalPasswordWrong) {
                $this->fail($account, 'portal_password_change_failed', 'wrong_password', $address, $userKey, $addressKey, $now);
            }

            throw $failure;
        }

        $this->guard->clear($userKey);

        return $version;
    }

    private function fail(?object $known, string $action, string $reason, string $address, string $userKey, string $addressKey, ?int $now): void
    {
        $this->audit->write(PortalAccounts::ENTITY, $known === null ? null : (int) $known->account_id, $action, null, JournalOrigin::User, [
            'name_ref' => substr($userKey, 0, self::REFERENCE_LENGTH),
            'reason'   => $reason,
            'address'  => $address,
        ]);

        $nameLocked    = $this->guard->recordFailure($userKey, PortalLoginGuard::USER_THRESHOLD, $now);
        $addressLocked = $this->guard->recordFailure($addressKey, PortalLoginGuard::ADDRESS_THRESHOLD, $now);

        if ($nameLocked || $addressLocked) {
            $this->audit->write(PortalAccounts::ENTITY, $known === null ? null : (int) $known->account_id, 'portal_login_locked', null, JournalOrigin::User, ['by' => $nameLocked ? 'username' : 'address', 'address' => $address]);
        }
    }
}
