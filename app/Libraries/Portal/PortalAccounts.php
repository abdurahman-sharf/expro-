<?php

namespace App\Libraries\Portal;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Database;

/**
 * The portal accounts of the customers. An account is made, reset, disabled and enabled only by an
 * employee who holds the permission to manage the portal, one account for each customer, and the password
 * it starts with is a temporary one that is shown once to the employee and must be changed at the first
 * sign-in. Only the hash of a password is ever stored, and nothing is sent to the customer: the employee
 * hands the temporary password over. Every change is written to the audit log without any password.
 */
final class PortalAccounts
{
    public const ENTITY            = 'portal_account';
    public const TEMPORARY_SECONDS = 604800;
    public const USERNAME_PATTERN  = '/^[a-z0-9._@-]{3,50}$/';

    private BaseConnection $db;
    private AuditLog $audit;
    private AccountingGuard $guard;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null)
    {
        $this->db    = $db ?? Database::connect();
        $this->audit = new AuditLog($this->db);
        $this->guard = $guard ?? new AccountingGuard();
    }

    public static function normalise(string $username): string
    {
        return mb_strtolower(trim($username));
    }

    public function find(int $accountId): ?object
    {
        return $this->db->table('portal_accounts')->where('account_id', $accountId)->get()->getRow();
    }

    public function findByUsername(string $username): ?object
    {
        return $this->db->table('portal_accounts')->where('username', self::normalise($username))->get()->getRow();
    }

    public function forCustomer(int $customerId): ?object
    {
        return $this->db->table('portal_accounts')->where('customer_id', $customerId)->get()->getRow();
    }

    /**
     * The account of a session that is still good: the account is there and active, its customer is not
     * deleted, and the password has not changed since the session began.
     */
    public function live(int $accountId, int $credentialVersion): ?object
    {
        $account = $this->find($accountId);

        if ($account === null || $account->status !== 'active' || (int) $account->credential_version !== $credentialVersion || ! $this->customerIsLive((int) $account->customer_id)) {
            return null;
        }

        return $account;
    }

    /**
     * The account a user name and a password open, or null and the reason why none does.
     *
     * @return array{0: object|null, 1: string}
     */
    public function check(string $username, string $password, ?int $now = null): array
    {
        $account = $this->findByUsername($username);

        if ($account === null) {
            PortalPasswords::verify($password, PortalPasswords::absent());

            return [null, 'unknown_user'];
        }

        if (! PortalPasswords::verify($password, (string) $account->password_hash)) {
            return [null, 'wrong_password'];
        }

        if ($account->status !== 'active') {
            return [null, 'disabled'];
        }

        if (! $this->customerIsLive((int) $account->customer_id)) {
            return [null, 'customer_deleted'];
        }

        if ((int) $account->must_change === 1 && $account->temporary_expires_at !== null && strtotime((string) $account->temporary_expires_at) < ($now ?? time())) {
            return [null, 'temporary_expired'];
        }

        return [$account, ''];
    }

    /**
     * Makes the account of a customer and returns the temporary password, which is not kept anywhere.
     *
     * @return array{account_id: int, temporary_password: string}
     *
     * @throws AccountingException
     */
    public function create(int $customerId, string $username, ?int $employeeId): array
    {
        $this->authorise($employeeId);

        $username = self::normalise($username);

        if (preg_match(self::USERNAME_PATTERN, $username) !== 1) {
            throw new AccountingException(AccountingError::PortalUsernameInvalid, 'The user name does not meet the rules.');
        }

        if (! $this->customerIsLive($customerId)) {
            throw new AccountingException(AccountingError::PortalCustomerUnknown, 'The customer does not exist or was deleted.');
        }

        if ($this->forCustomer($customerId) !== null) {
            throw new AccountingException(AccountingError::PortalAccountExists, 'The customer already has a portal account.');
        }

        $temporary = PortalPasswords::temporary();

        $this->db->transStart();

        try {
            $inserted = $this->db->table('portal_accounts')->insert([
                'customer_id'          => $customerId,
                'username'             => $username,
                'password_hash'        => PortalPasswords::hash($temporary),
                'must_change'          => 1,
                'temporary_expires_at' => date('Y-m-d H:i:s', time() + self::TEMPORARY_SECONDS),
                'created_by'           => $employeeId,
            ]);
        } catch (DatabaseException) {
            $inserted = false;
        }

        if ($inserted === false) {
            $this->db->transComplete();

            throw new AccountingException(AccountingError::PortalUsernameTaken, 'The user name belongs to another account.');
        }

        $accountId = (int) $this->db->insertID();

        $this->audit->write(self::ENTITY, $accountId, 'portal_account_created', $employeeId, JournalOrigin::User, ['customer_id' => $customerId, 'username' => $username]);
        $this->db->transComplete();

        return ['account_id' => $accountId, 'temporary_password' => $temporary];
    }

    /**
     * Replaces the password with a new temporary one, ends every session of the account and lifts any
     * lock on its user name.
     *
     * @throws AccountingException
     */
    public function resetPassword(int $accountId, ?int $employeeId): string
    {
        $this->authorise($employeeId);

        $account   = $this->existing($accountId);
        $temporary = PortalPasswords::temporary();

        $this->db->transStart();
        $this->db->table('portal_accounts')->where('account_id', $accountId)->update([
            'password_hash'        => PortalPasswords::hash($temporary),
            'must_change'          => 1,
            'temporary_expires_at' => date('Y-m-d H:i:s', time() + self::TEMPORARY_SECONDS),
            'credential_version'   => (int) $account->credential_version + 1,
        ]);
        (new PortalLoginGuard($this->db))->clear(PortalLoginGuard::userKey((string) $account->username));
        $this->audit->write(self::ENTITY, $accountId, 'portal_password_reset', $employeeId, JournalOrigin::User, ['customer_id' => (int) $account->customer_id]);
        $this->db->transComplete();

        return $temporary;
    }

    /**
     * @throws AccountingException
     */
    public function setActive(int $accountId, bool $active, ?int $employeeId): void
    {
        $this->authorise($employeeId);

        $account = $this->existing($accountId);

        if (($account->status === 'active') === $active) {
            return;
        }

        $this->db->transStart();
        $this->db->table('portal_accounts')->where('account_id', $accountId)->update([
            'status'             => $active ? 'active' : 'disabled',
            'credential_version' => (int) $account->credential_version + 1,
        ]);
        $this->audit->write(self::ENTITY, $accountId, $active ? 'portal_account_enabled' : 'portal_account_disabled', $employeeId, JournalOrigin::User, ['customer_id' => (int) $account->customer_id]);
        $this->db->transComplete();
    }

    /**
     * The customer changes the password of the account: the current one must be right and the new one must
     * meet the rules. Every other session of the account ends.
     *
     * @throws AccountingException
     */
    public function changePassword(int $accountId, string $current, string $new): int
    {
        $account = $this->existing($accountId);

        if (! PortalPasswords::verify($current, (string) $account->password_hash)) {
            throw new AccountingException(AccountingError::PortalPasswordWrong, 'The current password is not correct.');
        }

        PortalPasswords::check($new, (string) $account->username, $current);

        $version = (int) $account->credential_version + 1;

        $this->db->transStart();
        $this->db->table('portal_accounts')->where('account_id', $accountId)->update([
            'password_hash'        => PortalPasswords::hash($new),
            'must_change'          => 0,
            'temporary_expires_at' => null,
            'credential_version'   => $version,
            'password_changed_at'  => date('Y-m-d H:i:s'),
        ]);
        $this->audit->write(self::ENTITY, $accountId, 'portal_password_changed', null, JournalOrigin::User, ['customer_id' => (int) $account->customer_id]);
        $this->db->transComplete();

        return $version;
    }

    /**
     * Closes the account of a customer who was deleted: it is disabled, every session of it ends, and a shop
     * that scrambles the data of deleted customers scrambles the user name too. No permission is asked,
     * because the customer was deleted by someone who may delete customers.
     */
    public function closeForCustomer(int $customerId, bool $scramble, ?int $employeeId): void
    {
        $account = $this->forCustomer($customerId);

        if ($account === null) {
            return;
        }

        $this->db->transStart();
        $this->db->table('portal_accounts')->where('account_id', (int) $account->account_id)->update([
            'status'             => 'disabled',
            'credential_version' => (int) $account->credential_version + 1,
            'username'           => $scramble ? 'deleted-' . (int) $account->account_id : $account->username,
        ]);
        $this->audit->write(self::ENTITY, (int) $account->account_id, 'portal_account_disabled', $employeeId, JournalOrigin::User, ['customer_id' => $customerId, 'with_customer' => true]);
        $this->db->transComplete();
    }

    public function recordLogin(object $account, string $password): void
    {
        $changes = ['last_login_at' => date('Y-m-d H:i:s')];

        if (PortalPasswords::needsRehash((string) $account->password_hash)) {
            $changes['password_hash'] = PortalPasswords::hash($password);
        }

        $this->db->table('portal_accounts')->where('account_id', (int) $account->account_id)->update($changes);
    }

    private function customerIsLive(int $customerId): bool
    {
        return $this->db->table('customers')->where(['person_id' => $customerId, 'deleted' => 0])->countAllResults() === 1;
    }

    /**
     * @throws AccountingException
     */
    private function authorise(?int $employeeId): void
    {
        if ($employeeId === null || ! $this->guard->has(AccountingGuard::PORTAL_MANAGE, $employeeId)) {
            throw new AccountingException(AccountingError::PermissionDenied, 'Missing permission ' . AccountingGuard::PORTAL_MANAGE . '.', ['permission' => AccountingGuard::PORTAL_MANAGE]);
        }
    }

    /**
     * @throws AccountingException
     */
    private function existing(int $accountId): object
    {
        return $this->find($accountId) ?? throw new AccountingException(AccountingError::PortalAccountUnknown, 'The portal account does not exist.');
    }
}
