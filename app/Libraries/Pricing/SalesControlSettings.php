<?php

namespace App\Libraries\Pricing;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Models\Appconfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The settings of the price lists and of the controls on prices and on credit. All of them are off
 * until someone turns them on, so a shop that never opens this page sells as it always did. They are
 * financial controls, so they are changed only by whoever may change the accounting settings, and
 * every change is written in the audit log with what it was before.
 */
final class SalesControlSettings
{
    public const PRICE_LISTS       = 'sales_price_lists_enabled';
    public const DEFAULT_LIST      = 'sales_default_price_list';
    public const CHANGE_LIST       = 'sales_change_price_list';
    public const MINIMUM_PRICE     = 'sales_minimum_price_enabled';
    public const MAX_DISCOUNT_ON   = 'sales_max_discount_enabled';
    public const MAX_DISCOUNT      = 'sales_max_discount_percent';
    public const EXEMPT_CATEGORIES = 'sales_discount_exempt_categories';
    public const CREDIT_LIMIT      = 'accounting_credit_limit_enabled';
    public const CREDIT_PERIOD     = 'accounting_credit_period_enabled';
    private const CATEGORY_MAX     = 255;

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

    public function priceListsEnabled(): bool
    {
        return $this->config->get_value(self::PRICE_LISTS, '0') === '1';
    }

    public function defaultPriceListId(): ?int
    {
        $value = $this->config->get_value(self::DEFAULT_LIST);

        return ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    public function changePriceListOnInvoice(): bool
    {
        return $this->config->get_value(self::CHANGE_LIST, '0') === '1';
    }

    public function minimumPriceEnabled(): bool
    {
        return $this->config->get_value(self::MINIMUM_PRICE, '0') === '1';
    }

    public function maxDiscountEnabled(): bool
    {
        return $this->config->get_value(self::MAX_DISCOUNT_ON, '0') === '1';
    }

    /**
     * The highest discount any line may carry, in percent, or null when there is none.
     */
    public function maxDiscountPercent(): ?string
    {
        $value = $this->config->get_value(self::MAX_DISCOUNT);

        return self::isPercent($value) ? bcadd($value, '0', 2) : null;
    }

    /**
     * @return list<string> The item categories that the highest discount of the shop does not apply to.
     */
    public function discountExemptCategories(): array
    {
        $decoded = json_decode($this->config->get_value(self::EXEMPT_CATEGORIES, '[]'), true);

        return is_array($decoded) ? array_values(array_filter($decoded, static fn (mixed $category): bool => is_string($category) && $category !== '')) : [];
    }

    public function creditLimitEnabled(): bool
    {
        return $this->config->get_value(self::CREDIT_LIMIT, '0') === '1';
    }

    public function creditPeriodEnabled(): bool
    {
        return $this->config->get_value(self::CREDIT_PERIOD, '0') === '1';
    }

    /**
     * @return array{price_lists: bool, default_list: ?int, change_list: bool, minimum_price: bool, max_discount_enabled: bool, max_discount: ?string, exempt_categories: list<string>, credit_limit: bool, credit_period: bool}
     */
    public function all(): array
    {
        return [
            'price_lists'          => $this->priceListsEnabled(),
            'default_list'         => $this->defaultPriceListId(),
            'change_list'          => $this->changePriceListOnInvoice(),
            'minimum_price'        => $this->minimumPriceEnabled(),
            'max_discount_enabled' => $this->maxDiscountEnabled(),
            'max_discount'         => $this->maxDiscountPercent(),
            'exempt_categories'    => $this->discountExemptCategories(),
            'credit_limit'         => $this->creditLimitEnabled(),
            'credit_period'        => $this->creditPeriodEnabled(),
        ];
    }

    /**
     * @param array{price_lists?: bool, default_list?: int|string|null, change_list?: bool, minimum_price?: bool, max_discount_enabled?: bool, max_discount?: string|null, exempt_categories?: list<string>, credit_limit?: bool, credit_period?: bool} $input What is left out stays as it is.
     *
     * @throws AccountingException
     */
    public function update(array $input, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::SETTINGS_MANAGE, $employeeId);

        $before = $this->all();
        $after  = $before;

        foreach (['price_lists', 'change_list', 'minimum_price', 'max_discount_enabled', 'credit_limit', 'credit_period'] as $flag) {
            if (array_key_exists($flag, $input)) {
                $after[$flag] = (bool) $input[$flag];
            }
        }

        if (array_key_exists('default_list', $input)) {
            $after['default_list'] = $this->listIdOf($input['default_list']);
        }

        if (array_key_exists('max_discount', $input)) {
            $after['max_discount'] = $this->percentOf($input['max_discount']);
        }

        if (array_key_exists('exempt_categories', $input)) {
            $after['exempt_categories'] = $this->categoriesOf($input['exempt_categories']);
        }

        $changes = array_filter($after, static fn (mixed $value, string $key): bool => $value !== $before[$key], ARRAY_FILTER_USE_BOTH);

        if ($changes === []) {
            return;
        }

        $keys   = ['price_lists' => self::PRICE_LISTS, 'default_list' => self::DEFAULT_LIST, 'change_list' => self::CHANGE_LIST, 'minimum_price' => self::MINIMUM_PRICE, 'max_discount_enabled' => self::MAX_DISCOUNT_ON, 'max_discount' => self::MAX_DISCOUNT, 'exempt_categories' => self::EXEMPT_CATEGORIES, 'credit_limit' => self::CREDIT_LIMIT, 'credit_period' => self::CREDIT_PERIOD];
        $values = [];

        foreach ($changes as $name => $value) {
            $values[$keys[$name]] = match (true) {
                is_bool($value)  => $value ? '1' : '0',
                is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
                default          => $value === null ? '' : (string) $value,
            };
        }

        $this->config->batch_save($values);
        $this->audit->write('settings', null, 'settings_updated', $employeeId, JournalOrigin::User, ['before' => array_intersect_key($before, $changes), 'after' => $changes]);
    }

    /**
     * The categories the items of the shop are in, to choose the excluded ones from.
     *
     * @return list<string>
     */
    public function itemCategories(): array
    {
        $rows = $this->db->table('items')->distinct()->select('category')->where('deleted', 0)->where('category !=', '')->orderBy('category', 'ASC')->get()->getResultArray();

        return array_column($rows, 'category');
    }

    public static function isPercent(string $value): bool
    {
        return preg_match('/^\d{1,3}(\.\d{1,2})?$/', $value) === 1 && bccomp($value, '100', 2) <= 0;
    }

    /**
     * @throws AccountingException
     */
    private function listIdOf(int|string|null $value): ?int
    {
        if ($value === null || $value === '' || (string) $value === '0') {
            return null;
        }

        if (! ctype_digit((string) $value) || $this->db->table('price_lists')->where('price_list_id', (int) $value)->countAllResults() === 0) {
            throw new AccountingException(AccountingError::ControlSettingInvalid, 'The default price list does not exist.');
        }

        return (int) $value;
    }

    /**
     * @throws AccountingException
     */
    private function percentOf(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (! self::isPercent($value)) {
            throw new AccountingException(AccountingError::ControlSettingInvalid, 'The highest discount is a percent from 0 to 100.');
        }

        return bcadd($value, '0', 2);
    }

    /**
     * @param list<string> $categories
     *
     * @return list<string>
     *
     * @throws AccountingException
     */
    private function categoriesOf(array $categories): array
    {
        $clean = [];

        foreach ($categories as $category) {
            if (! is_string($category) || mb_strlen($category) > self::CATEGORY_MAX) {
                throw new AccountingException(AccountingError::ControlSettingInvalid, 'A category is text of up to 255 characters.');
            }

            $category = trim($category);

            if ($category !== '' && ! in_array($category, $clean, true)) {
                $clean[] = $category;
            }
        }

        sort($clean);

        return $clean;
    }
}
