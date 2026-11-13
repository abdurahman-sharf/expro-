<?php

namespace App\Libraries\Portal;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Models\Appconfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The switch of the customer portal. It is off until an employee who holds the permission to manage the
 * portal turns it on, and while it is off every route of the portal answers as a page that does not exist.
 */
final class PortalSettings
{
    public const KEY    = 'portal_enabled';
    public const ENTITY = 'portal_setting';

    private BaseConnection $db;
    private Appconfig $config;
    private AuditLog $audit;
    private AccountingGuard $guard;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null)
    {
        $this->db     = $db ?? Database::connect();
        $this->config = model(Appconfig::class);
        $this->audit  = new AuditLog($this->db);
        $this->guard  = $guard ?? new AccountingGuard();
    }

    public function isEnabled(): bool
    {
        return $this->config->get_value(self::KEY, '0') === '1';
    }

    /**
     * @throws AccountingException
     */
    public function setEnabled(bool $enabled, ?int $employeeId): void
    {
        if ($employeeId === null || ! $this->guard->has(AccountingGuard::PORTAL_MANAGE, $employeeId)) {
            throw new AccountingException(AccountingError::PermissionDenied, 'Missing permission ' . AccountingGuard::PORTAL_MANAGE . '.', ['permission' => AccountingGuard::PORTAL_MANAGE]);
        }

        if ($this->isEnabled() === $enabled) {
            return;
        }

        $this->db->transStart();
        $this->db->table('app_config')->replace(['key' => self::KEY, 'value' => $enabled ? '1' : '0']);
        $this->audit->write(self::ENTITY, null, 'portal_setting_changed', $employeeId, JournalOrigin::User, ['enabled' => $enabled]);
        $this->db->transComplete();
    }
}
