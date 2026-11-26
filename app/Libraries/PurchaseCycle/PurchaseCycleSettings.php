<?php

namespace App\Libraries\PurchaseCycle;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Models\Appconfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The switches of the purchase cycle: whether the four documents are on at all, whether a receiving may take
 * more of an item than the purchase order holds, and, for each of the four documents, whether it may carry a
 * status set by hand. All of them are off until somebody turns them on, so nothing of the cycle shows or
 * works where nobody asked for it.
 */
final class PurchaseCycleSettings
{
    public const PERMISSION = 'config';
    private const ENABLED   = 'enable_purchase_cycle';
    private const EXCESS    = 'purchase_order_allow_excess';

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

    public function allowsManualStatus(PurchaseDocumentKind $kind): bool
    {
        return $this->config->get_value($kind->manualStatusSetting(), '0') === '1';
    }

    /**
     * @return array{enabled: bool, allow_excess: bool, manual: array<string, bool>}
     */
    public function current(): array
    {
        $manual = [];

        foreach (PurchaseDocumentKind::cases() as $kind) {
            $manual[$kind->value] = $this->allowsManualStatus($kind);
        }

        return ['enabled' => $this->isEnabled(), 'allow_excess' => $this->allowsExcess(), 'manual' => $manual];
    }

    /**
     * @param array{enabled?: bool, allow_excess?: bool, manual?: array<string, bool>} $input A switch that is not given stays as it is.
     *
     * @throws AccountingException
     */
    public function update(array $input, ?int $employeeId): void
    {
        $this->guard->require(self::PERMISSION, $employeeId);

        $before = $this->current();
        $after  = [
            'enabled'      => array_key_exists('enabled', $input) ? (bool) $input['enabled'] : $before['enabled'],
            'allow_excess' => array_key_exists('allow_excess', $input) ? (bool) $input['allow_excess'] : $before['allow_excess'],
            'manual'       => $before['manual'],
        ];

        foreach (PurchaseDocumentKind::cases() as $kind) {
            if (isset($input['manual']) && array_key_exists($kind->value, $input['manual'])) {
                $after['manual'][$kind->value] = (bool) $input['manual'][$kind->value];
            }
        }

        if ($before === $after) {
            return;
        }

        $values = [self::ENABLED => $after['enabled'] ? '1' : '0', self::EXCESS => $after['allow_excess'] ? '1' : '0'];

        foreach (PurchaseDocumentKind::cases() as $kind) {
            $values[$kind->manualStatusSetting()] = $after['manual'][$kind->value] ? '1' : '0';
        }

        $this->config->batch_save($values);
        $this->audit->write('purchase_cycle_settings', null, 'settings_updated', $employeeId, $employeeId === null ? JournalOrigin::System : JournalOrigin::User, ['before' => $before, 'after' => $after]);
    }
}
