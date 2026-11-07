<?php

namespace App\Libraries\Stock;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Models\Appconfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The two settings of the stock documents: the transfer permit and the stock requests. Both are off
 * until someone turns them on, so a shop that never opens them moves stock as it always did. The add
 * and issue permits and the stocktake need no setting. How the reference system switches the transfer
 * permit and the requests on was not observed, so these two are an assumption of the design. They are
 * changed only by whoever may change the accounting settings, and every change is written to the audit
 * log with what it was before.
 */
final class StockDocumentSettings
{
    public const TRANSFER_PERMIT = 'stock_transfer_permit_enabled';
    public const REQUESTS        = 'stock_requests_enabled';

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

    public function transferPermitEnabled(): bool
    {
        return $this->config->get_value(self::TRANSFER_PERMIT, '0') === '1';
    }

    public function requestsEnabled(): bool
    {
        return $this->config->get_value(self::REQUESTS, '0') === '1';
    }

    /**
     * @return array{transfer_permit: bool, requests: bool}
     */
    public function all(): array
    {
        return ['transfer_permit' => $this->transferPermitEnabled(), 'requests' => $this->requestsEnabled()];
    }

    /**
     * @param array{transfer_permit?: bool, requests?: bool} $input What is left out stays as it is.
     *
     * @throws AccountingException
     */
    public function update(array $input, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::SETTINGS_MANAGE, $employeeId);

        $before = $this->all();
        $after  = $before;

        foreach (['transfer_permit', 'requests'] as $flag) {
            if (array_key_exists($flag, $input)) {
                $after[$flag] = (bool) $input[$flag];
            }
        }

        $changes = array_filter($after, static fn (bool $value, string $key): bool => $value !== $before[$key], ARRAY_FILTER_USE_BOTH);

        if ($changes === []) {
            return;
        }

        $keys   = ['transfer_permit' => self::TRANSFER_PERMIT, 'requests' => self::REQUESTS];
        $values = [];

        foreach ($changes as $name => $value) {
            $values[$keys[$name]] = $value ? '1' : '0';
        }

        $this->config->batch_save($values);
        $this->audit->write('settings', null, 'settings_updated', $employeeId, JournalOrigin::User, ['before' => array_intersect_key($before, $changes), 'after' => $changes]);
    }
}
