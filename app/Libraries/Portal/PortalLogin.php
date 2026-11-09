<?php

namespace App\Libraries\Portal;

use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * One attempt to sign in. A name or an address that is locked is refused before the password is looked at;
 * a wrong name, a wrong password, a disabled account and a temporary password that ran out all answer the
 * same way, count as a failure of the name and of the address, and are written to the audit log with the
 * reason and the address but never the password. A success clears the failures of the name.
 */
final class PortalLogin
{
    private const NAME_MAX = 50;

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
        $userKey    = PortalLoginGuard::userKey($username);
        $addressKey = PortalLoginGuard::addressKey($address);

        if ($this->guard->lockedUntil($userKey, $now) !== null || $this->guard->lockedUntil($addressKey, $now) !== null) {
            return new PortalLoginResult(PortalLoginOutcome::Locked);
        }

        [$account, $reason] = $this->accounts->check($username, $password, $now);

        if ($account === null) {
            $this->fail($username, $reason, $address, $userKey, $addressKey, $now);

            return new PortalLoginResult(PortalLoginOutcome::Invalid);
        }

        $this->guard->clear($userKey);
        $this->accounts->recordLogin($account, $password);
        $this->audit->write(PortalAccounts::ENTITY, (int) $account->account_id, 'portal_login_succeeded', null, JournalOrigin::User, ['customer_id' => (int) $account->customer_id, 'address' => $address]);

        return new PortalLoginResult(PortalLoginOutcome::Success, $account);
    }

    private function fail(string $username, string $reason, string $address, string $userKey, string $addressKey, ?int $now): void
    {
        $known = $this->accounts->findByUsername($username);

        $this->audit->write(PortalAccounts::ENTITY, $known === null ? null : (int) $known->account_id, 'portal_login_failed', null, JournalOrigin::User, [
            'username' => mb_substr(PortalAccounts::normalise($username), 0, self::NAME_MAX),
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
