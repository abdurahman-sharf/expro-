<?php

namespace App\Libraries\SalesOrders;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Models\Appconfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The two switches of the sales orders: whether the module is on, and whether an invoice may take more
 * of an item than the order holds. Both are off until somebody turns them on, so nothing of the module
 * shows or works where nobody asked for it.
 */
final class SalesOrderSettings
{
    public const PERMISSION = 'config';
    private const ENABLED   = 'enable_sales_order';
    private const EXCESS    = 'sales_order_allow_excess';

    private Appconfig $config;
    private AuditLog $audit;
    private AccountingGuard $guard;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null)
    {
        $this->config = model(Appconfig::class);
        $this->audit  = new AuditLog($db ?? Database::connect());
        $this->guard  = $guard ?? new AccountingGuard();
    }

    public function isEnabled(): bool
    {
        return $this->config->get_value(self::ENABLED, '0') === '1';
    }

    public function allowsExcess(): bool
    {
        return $this->config->get_value(self::EXCESS, '0') === '1';
    }

    /**
     * @param array{enabled?: bool, allow_excess?: bool} $input
     *
     * @throws AccountingException
     */
    public function update(array $input, ?int $employeeId): void
    {
        $this->guard->require(self::PERMISSION, $employeeId);

        $before = ['enabled' => $this->isEnabled(), 'allow_excess' => $this->allowsExcess()];
        $after  = [
            'enabled'      => array_key_exists('enabled', $input) ? (bool) $input['enabled'] : $before['enabled'],
            'allow_excess' => array_key_exists('allow_excess', $input) ? (bool) $input['allow_excess'] : $before['allow_excess'],
        ];

        if ($before === $after) {
            return;
        }

        $this->config->batch_save([self::ENABLED => $after['enabled'] ? '1' : '0', self::EXCESS => $after['allow_excess'] ? '1' : '0']);
        $this->audit->write('sales_order_settings', null, 'settings_changed', $employeeId, $employeeId === null ? JournalOrigin::System : JournalOrigin::User, ['before' => $before, 'after' => $after]);
    }
}
