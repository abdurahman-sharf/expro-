<?php

namespace App\Libraries\Stock;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Models\Appconfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The settings of multiple units, bundles, tracking and negative stock. Units, bundles and tracking are
 * off and a sale that takes stock below nothing is allowed until someone says otherwise, so a shop that
 * never opens them keeps the behaviour it has. They are changed only by whoever may change the
 * accounting settings, and every change is written to the audit log with what it was before.
 */
final class StockOptions
{
    public const UNITS          = 'stock_units_enabled';
    public const BUNDLES        = 'stock_bundles_enabled';
    public const TRACKING       = 'stock_tracking_enabled';
    public const EXPIRED_POLICY = 'stock_expired_sale_policy';
    public const NEGATIVE_STOCK = 'negative_stock_option';
    public const EXPIRED_REFUSE = 'refuse';
    public const EXPIRED_ALLOW  = 'allow';
    private const FLAGS         = ['units' => self::UNITS, 'bundles' => self::BUNDLES, 'tracking' => self::TRACKING];

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

    public function unitsEnabled(): bool
    {
        return $this->config->get_value(self::UNITS, '0') === '1';
    }

    public function bundlesEnabled(): bool
    {
        return $this->config->get_value(self::BUNDLES, '0') === '1';
    }

    public function trackingEnabled(): bool
    {
        return $this->config->get_value(self::TRACKING, '0') === '1';
    }

    public function expiredSalesRefused(): bool
    {
        return $this->config->get_value(self::EXPIRED_POLICY, self::EXPIRED_REFUSE) !== self::EXPIRED_ALLOW;
    }

    public function negativeStock(): NegativeStockOption
    {
        return NegativeStockOption::tryFrom((string) $this->config->get_value(self::NEGATIVE_STOCK, NegativeStockOption::Allow->value)) ?? NegativeStockOption::Allow;
    }

    /**
     * @return array{units: bool, bundles: bool, tracking: bool, expired_refused: bool, negative_stock: string}
     */
    public function all(): array
    {
        return [
            'units'           => $this->unitsEnabled(),
            'bundles'         => $this->bundlesEnabled(),
            'tracking'        => $this->trackingEnabled(),
            'expired_refused' => $this->expiredSalesRefused(),
            'negative_stock'  => $this->negativeStock()->value,
        ];
    }

    /**
     * @param array{units?: bool, bundles?: bool, tracking?: bool, expired_refused?: bool, negative_stock?: string} $input What is left out stays as it is.
     *
     * @throws AccountingException
     */
    public function update(array $input, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::SETTINGS_MANAGE, $employeeId);

        if (array_key_exists('negative_stock', $input) && NegativeStockOption::tryFrom((string) $input['negative_stock']) === null) {
            throw new AccountingException(AccountingError::InvalidName, 'The negative stock option is not one of the three.', ['name' => (string) $input['negative_stock']]);
        }

        $before = $this->all();
        $after  = $before;

        foreach ([...array_keys(self::FLAGS), 'expired_refused'] as $flag) {
            if (array_key_exists($flag, $input)) {
                $after[$flag] = (bool) $input[$flag];
            }
        }

        if (array_key_exists('negative_stock', $input)) {
            $after['negative_stock'] = (string) $input['negative_stock'];
        }

        $changes = array_filter($after, static fn (bool|string $value, string $key): bool => $value !== $before[$key], ARRAY_FILTER_USE_BOTH);

        if ($changes === []) {
            return;
        }

        if (array_key_exists('tracking', $changes)) {
            $this->assertTrackingMayChange((bool) $changes['tracking']);
        }

        $values = [];

        foreach ($changes as $name => $value) {
            match ($name) {
                'expired_refused' => $values[self::EXPIRED_POLICY] = $value ? self::EXPIRED_REFUSE : self::EXPIRED_ALLOW,
                'negative_stock'  => $values[self::NEGATIVE_STOCK] = (string) $value,
                default           => $values[self::FLAGS[$name]]   = $value ? '1' : '0',
            };
        }

        $this->config->batch_save($values);
        $this->audit->write('settings', null, 'stock_options_updated', $employeeId, JournalOrigin::User, ['before' => array_intersect_key($before, $changes), 'after' => $changes]);
    }

    /**
     * While tracking is off a tracked item is changed like any other, so it may be switched off only when no
     * tracked item holds stock, and switched on again only when every tracked item still ties.
     *
     * @throws AccountingException
     */
    private function assertTrackingMayChange(bool $on): void
    {
        $ties = new TrackingTies($this->db);

        if ($on) {
            $untied = $ties->untied(1);

            if ($untied !== []) {
                throw new AccountingException(AccountingError::TrackingOnUntied, 'A tracked item holds stock its serial numbers or batches do not account for.', ['name' => (string) $untied[0]->name]);
            }

            return;
        }

        $holding = $ties->holding(1);

        if ($holding !== []) {
            throw new AccountingException(AccountingError::TrackingOffWithStock, 'A tracked item still holds stock.', ['name' => (string) $holding[0]->name]);
        }
    }
}
