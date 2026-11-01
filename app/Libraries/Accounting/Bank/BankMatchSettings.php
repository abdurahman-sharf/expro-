<?php

namespace App\Libraries\Accounting\Bank;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\RunsInTransaction;
use App\Models\Appconfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * How far apart in days a statement line and a line of the books may be for the screen to suggest them
 * as the same movement: 7 days until it is changed, from 0 (the same day) to 60. The window only
 * orders and limits the suggestions; a match made by hand is not held to it. Changing it takes the
 * permission to reconcile and is written to the audit log.
 */
final class BankMatchSettings
{
    use RunsInTransaction;

    public const KEY     = 'accounting_bank_match_window_days';
    public const DEFAULT = 7;
    public const MIN     = 0;
    public const MAX     = 60;

    private BaseConnection $db;
    private Appconfig $config;
    private AccountingGuard $guard;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null)
    {
        $this->db     = $db ?? Database::connect();
        $this->config = model(Appconfig::class);
        $this->guard  = $guard ?? new AccountingGuard();
        $this->audit  = new AuditLog($this->db);
    }

    public function windowDays(): int
    {
        $value = $this->config->get_value(self::KEY);

        return ctype_digit($value) && (int) $value >= self::MIN && (int) $value <= self::MAX ? (int) $value : self::DEFAULT;
    }

    /**
     * @throws AccountingException
     */
    public function setWindowDays(mixed $days, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::BANK_RECONCILE, $employeeId);

        if ((! is_int($days) && ! (is_string($days) && ctype_digit(trim($days)))) || (int) $days < self::MIN || (int) $days > self::MAX) {
            throw new AccountingException(AccountingError::BankMatchWindowInvalid, 'The window is a number of days.', ['min' => self::MIN, 'max' => self::MAX]);
        }

        $days = (int) $days;

        $this->transactional(function () use ($days, $employeeId): void {
            $before = $this->windowDays();

            if ($before === $days) {
                return;
            }

            $this->db->query(
                'INSERT INTO `' . $this->db->prefixTable('app_config') . '` (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
                [self::KEY, (string) $days],
            );
            $this->audit->write('bank_settings', null, 'bank_match_window_changed', $employeeId, JournalOrigin::User, ['before' => $before, 'after' => $days]);
        });
    }
}
