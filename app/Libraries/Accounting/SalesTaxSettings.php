<?php

namespace App\Libraries\Accounting;

use App\Models\Appconfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The two settings of the tax of a sale that are not rates: whether the taxes that have no mode of
 * their own are inside the price (the default invoice tax mode, kept under the key the shop has
 * always used), and whether every line of a sale must carry a tax. A change is audited with what it
 * was and what it became, and the same value saved again writes nothing.
 */
final class SalesTaxSettings
{
    public const INCLUDED_KEY = 'tax_included';
    public const TAX_ONE_KEY  = 'require_tax_1';

    private BaseConnection $db;
    private Appconfig $config;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db     = $db ?? Database::connect();
        $this->config = model(Appconfig::class);
        $this->audit  = new AuditLog($this->db);
    }

    public function defaultIncluded(): bool
    {
        return $this->flag(self::INCLUDED_KEY);
    }

    public function requireTaxOne(): bool
    {
        return $this->flag(self::TAX_ONE_KEY);
    }

    /**
     * @param array{tax_included?: bool, require_tax_1?: bool} $input
     *
     * @return array<string, bool> What changed, by setting.
     */
    public function update(array $input, ?int $employeeId = null): array
    {
        $before  = [self::INCLUDED_KEY => $this->defaultIncluded(), self::TAX_ONE_KEY => $this->requireTaxOne()];
        $changes = [];

        foreach ($before as $key => $value) {
            if (array_key_exists($key, $input) && (bool) $input[$key] !== $value) {
                $changes[$key] = (bool) $input[$key];
            }
        }

        if ($changes === []) {
            return [];
        }

        $this->config->batch_save(array_map(static fn (bool $value): string => $value ? '1' : '0', $changes));
        $this->audit->write('settings', null, 'sales_tax_settings_updated', $employeeId, JournalOrigin::User, ['before' => array_intersect_key($before, $changes), 'after' => $changes]);

        return $changes;
    }

    /**
     * The first line of a sale that has no tax, or null when every line has one. A tax of nothing
     * is still a tax: it is a line that was given one.
     *
     * @param array<int|string, array<string, mixed>> $items     The lines of the sale, each with its item and line number.
     * @param list<array<string, mixed>>              $itemTaxes The tax rows worked out for the lines.
     */
    public static function lineWithoutTax(array $items, array $itemTaxes): ?int
    {
        $taxed = [];

        foreach ($itemTaxes as $tax) {
            $taxed[$tax['item_id'] . ':' . $tax['line']] = true;
        }

        foreach ($items as $item) {
            if (! isset($taxed[$item['item_id'] . ':' . $item['line']])) {
                return (int) $item['line'];
            }
        }

        return null;
    }

    private function flag(string $key): bool
    {
        return ! in_array($this->config->get_value($key, '0'), ['', '0'], true);
    }
}
