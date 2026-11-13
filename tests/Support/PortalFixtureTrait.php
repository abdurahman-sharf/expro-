<?php

namespace Tests\Support;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Portal\PortalAccounts;
use App\Libraries\Portal\PortalPasswords;
use App\Libraries\Portal\PortalSession;
use Config\Services;

/**
 * What a test needs to work with the customer portal: an employee who may manage it, the switch, an account
 * with a password the test knows, the session of a customer who is signed in, and a clean ledger of failed
 * sign-ins afterwards. A class that uses it also uses AccountingFixtureTrait and EmployeeFixtureTrait.
 */
trait PortalFixtureTrait
{
    protected const PORTAL_PASSWORD = 'Correct-Horse-9';

    protected function portalManager(): int
    {
        return $this->employeeWithGrants([AccountingGuard::PORTAL_MANAGE, 'customers']);
    }

    protected function switchPortal(bool $on): void
    {
        $this->setConfigValue('portal_enabled', $on ? '1' : '0');
    }

    /**
     * An account of the customer that is active and has the password PORTAL_PASSWORD, to be changed by nobody.
     *
     * @return array{account_id: int, username: string, customer_id: int}
     */
    protected function portalAccountOf(int $customerId, string $username = 'ahmed', bool $mustChange = false): array
    {
        $made = (new PortalAccounts())->create($customerId, $username, $this->portalManager());

        $this->accountingDb()->table('portal_accounts')->where('account_id', $made['account_id'])->update([
            'password_hash'        => PortalPasswords::hash(self::PORTAL_PASSWORD),
            'must_change'          => $mustChange ? 1 : 0,
            'temporary_expires_at' => null,
        ]);

        return ['account_id' => $made['account_id'], 'username' => $username, 'customer_id' => $customerId];
    }

    /**
     * @param array{account_id: int, username: string, customer_id: int} $account
     *
     * @return array<string, mixed>
     */
    protected function portalSessionOf(array $account, ?int $now = null): array
    {
        $now ??= time();
        $row = (new PortalAccounts())->find($account['account_id']);

        return [
            PortalSession::KEY_CUSTOMER => $account['customer_id'],
            PortalSession::KEY_ACCOUNT  => $account['account_id'],
            PortalSession::KEY_VERSION  => (int) $row->credential_version,
            PortalSession::KEY_STARTED  => $now,
            PortalSession::KEY_ACTIVE   => $now,
        ];
    }

    /**
     * The token filter reads its token from the session when the service is first made, and the service lives
     * as long as the process, so a test that posts starts from a service made afresh.
     */
    protected function freshSecurity(): void
    {
        Services::resetSingle('security');
    }

    protected function cleanPortal(): void
    {
        $db = $this->accountingDb();

        $db->query('DELETE FROM ' . $db->prefixTable('portal_accounts'));
        $db->query('DELETE FROM ' . $db->prefixTable('portal_login_failures'));
        $db->table('accounting_audit_log')->whereIn('entity_type', ['portal_account', 'portal_setting'])->delete();
        $db->table('grants')->where('permission_id', AccountingGuard::PORTAL_MANAGE)->delete();
        $this->switchPortal(false);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function portalAudit(string $action): array
    {
        return $this->accountingDb()->table('accounting_audit_log')->where('action', $action)->orderBy('audit_id', 'ASC')->get()->getResultArray();
    }
}
