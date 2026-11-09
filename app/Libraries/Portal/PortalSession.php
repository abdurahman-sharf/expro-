<?php

namespace App\Libraries\Portal;

use Closure;
use CodeIgniter\Events\Events;
use CodeIgniter\Session\Session;

/**
 * The session of a signed-in customer. It lives in a realm of its own: its cookie has its own name, it holds
 * its own keys and none of the keys of an employee, and the customer it names is the one the account was
 * made for, read from the session and never from a request. The identifier changes when a customer signs
 * in and when the password changes, a session that has been idle for half an hour or open for eight hours
 * ends, and the keys of the other realm are never accepted.
 */
final class PortalSession
{
    public const KEY_CUSTOMER = 'portal_customer_id';
    public const KEY_ACCOUNT  = 'portal_account_id';
    public const KEY_VERSION  = 'portal_credential_version';
    public const KEY_STARTED  = 'portal_started_at';
    public const KEY_ACTIVE   = 'portal_last_activity';
    public const KEY_EMPLOYEE = 'person_id';
    public const IDLE_SECONDS = 1800;
    public const MAX_SECONDS  = 28800;
    public const REGENERATED  = 'portal.session_regenerated';

    private Session $session;
    private Closure $isActive;

    public function __construct(?Session $session = null, ?Closure $isActive = null)
    {
        $this->session  = $session ?? session();
        $this->isActive = $isActive ?? static fn (): bool => session_status() === PHP_SESSION_ACTIVE;
    }

    public function begin(object $account, ?int $now = null): void
    {
        $now ??= time();

        $this->regenerate();
        $this->wipe();
        $this->session->set([
            self::KEY_CUSTOMER => (int) $account->customer_id,
            self::KEY_ACCOUNT  => (int) $account->account_id,
            self::KEY_VERSION  => (int) $account->credential_version,
            self::KEY_STARTED  => $now,
            self::KEY_ACTIVE   => $now,
        ]);
    }

    public function end(): void
    {
        $this->wipe();
        $this->regenerate();
    }

    public function renew(int $credentialVersion): void
    {
        $this->regenerate();
        $this->session->set(self::KEY_VERSION, $credentialVersion);
    }

    public function customerId(): ?int
    {
        $id = $this->session->get(self::KEY_CUSTOMER);

        return is_int($id) && $id > 0 ? $id : null;
    }

    public function accountId(): ?int
    {
        $id = $this->session->get(self::KEY_ACCOUNT);

        return is_int($id) && $id > 0 ? $id : null;
    }

    public function credentialVersion(): int
    {
        return (int) $this->session->get(self::KEY_VERSION);
    }

    public function holdsPortal(): bool
    {
        return $this->session->get(self::KEY_CUSTOMER) !== null || $this->session->get(self::KEY_ACCOUNT) !== null;
    }

    public function holdsEmployee(): bool
    {
        return $this->session->get(self::KEY_EMPLOYEE) !== null;
    }

    public function expired(?int $now = null): bool
    {
        $now    ??= time();
        $started = (int) $this->session->get(self::KEY_STARTED);
        $active  = (int) $this->session->get(self::KEY_ACTIVE);

        return $now - $active > self::IDLE_SECONDS || $now - $started > self::MAX_SECONDS;
    }

    public function touch(?int $now = null): void
    {
        $this->session->set(self::KEY_ACTIVE, $now ?? time());
    }

    private function regenerate(): void
    {
        if (($this->isActive)()) {
            $this->session->regenerate(true);
        }

        Events::trigger(self::REGENERATED);
    }

    private function wipe(): void
    {
        foreach (array_keys((array) $this->session->get()) as $key) {
            $this->session->remove((string) $key);
        }
    }
}
