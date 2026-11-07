<?php

namespace App\Libraries\Pricing;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Models\Item;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The minimum price of an item and the highest discount a line may carry. A line is checked as the
 * register adds or changes it and again when the sale is completed, inside the transaction that
 * saves it, so a line that was never checked at the register (a draft that is issued, a quote that
 * becomes an invoice) is still stopped. The unit price after the discount is compared with the minimum
 * and the discount of the line, with its share of the discount on the whole sale, with the highest
 * discount: that of the item when it has one of its own, else the one of the shop, which the items of
 * the excluded categories are not held to. Only an employee who holds the permission to override
 * prices sells such a line, and the audit log says so. Both controls are off until they are turned on.
 */
final class PriceControls
{
    public const AUDIT_OVERRIDE = 'price_override';
    public const AUDIT_REFUSED  = 'price_refused';

    private BaseConnection $db;
    private AccountingGuard $guard;
    private AuditLog $audit;
    private SalesControlSettings $settings;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->guard    = $guard ?? new AccountingGuard();
        $this->audit    = new AuditLog($this->db);
        $this->settings = new SalesControlSettings($this->db);
    }

    public function isActive(): bool
    {
        return $this->settings->minimumPriceEnabled() || $this->settings->maxDiscountEnabled();
    }

    /**
     * @param array<int|string, array<string, mixed>> $lines The lines of the register or of the sale, as they carry price, quantity, discount and the share of the discount on the whole sale.
     * @param numeric-string                          $rate  The rate of a sale in a foreign currency: the minimum price is a price in the base currency, so the prices of the lines are compared with it at this rate.
     *
     * @return list<PriceViolation> In the order of the lines, with the prices in the base currency.
     */
    public function violationsOf(array $lines, string $rate = '1'): array
    {
        if (! $this->isActive() || $lines === []) {
            return [];
        }

        $items    = $this->itemsOf($lines);
        $exempt   = $this->settings->discountExemptCategories();
        $global   = $this->settings->maxDiscountEnabled() ? $this->settings->maxDiscountPercent() : null;
        $checkMin = $this->settings->minimumPriceEnabled();
        $found    = [];
        $number   = 0;

        foreach ($lines as $line) {
            $number++;

            $shown = (int) ($line['line'] ?? 0) > 0 ? (int) $line['line'] : $number;

            $quantity = bcadd((string) $line['quantity'], '0', 4);
            $item     = $items[(int) $line['item_id']] ?? null;

            if ($item === null || bccomp($quantity, '0', 4) <= 0) {
                continue;
            }

            $price    = bcadd((string) $line['price'], '0', 4);
            $discount = trim((string) ($line['discount'] ?? '')) === '' ? '0' : (string) $line['discount'];
            $net      = Item::priceAfterDiscount($price, $discount, (int) ($line['discount_type'] ?? PERCENT));
            $share    = trim((string) ($line['invoice_discount_share'] ?? '')) === '' ? '0' : (string) $line['invoice_discount_share'];

            $net     = bcsub($net, bcdiv($share, $quantity, 4), 4);
            $percent = bccomp($price, '0', 4) > 0 ? bcdiv(bcmul(bcsub($price, $net, 6), '100', 6), $price, 4) : '0.0000';
            $minimum = bcadd((string) $item->minimum_price, '0', 4);
            $netBase = bcmul($net, $rate, 4);

            if ($checkMin && bccomp($minimum, '0', 4) > 0 && bccomp($netBase, $minimum, 2) < 0) {
                $found[] = new PriceViolation(PriceRule::BelowMinimum, $shown, (int) $item->item_id, (string) $item->name, $netBase, $minimum, $percent);
            }

            $ceiling = $this->ceilingOf($item, $global, $exempt);

            if ($ceiling !== null && bccomp($percent, $ceiling, 2) > 0) {
                $found[] = new PriceViolation(PriceRule::AboveCeiling, $shown, (int) $item->item_id, (string) $item->name, $netBase, $ceiling, $percent);
            }
        }

        return $found;
    }

    public function mayOverride(?int $employeeId): bool
    {
        return $this->guard->has(AccountingGuard::PRICE_OVERRIDE, $employeeId);
    }

    /**
     * Writes down that a sale went through under the controls with the permission to, in the
     * transaction that saves the sale.
     *
     * @param list<PriceViolation> $violations
     */
    public function recordOverride(int $saleId, ?int $branchId, ?int $employeeId, array $violations): void
    {
        $this->audit->write('sale', $saleId, self::AUDIT_OVERRIDE, $employeeId, JournalOrigin::User, ['lines' => array_map(static fn (PriceViolation $violation): array => $violation->toArray(), $violations)], $branchId);
    }

    /**
     * Writes down that a sale was refused. It runs after the transaction has rolled back, or the
     * entry would go with it.
     *
     * @param list<PriceViolation> $violations
     */
    public function recordRefusal(?int $branchId, ?int $employeeId, array $violations): void
    {
        $this->audit->write('sale', null, self::AUDIT_REFUSED, $employeeId, JournalOrigin::User, ['lines' => array_map(static fn (PriceViolation $violation): array => $violation->toArray(), $violations)], $branchId);
    }

    /**
     * @param list<string> $exempt
     *
     * @return numeric-string|null
     */
    private function ceilingOf(object $item, ?string $global, array $exempt): ?string
    {
        if (! $this->settings->maxDiscountEnabled()) {
            return null;
        }

        if ($item->max_discount_percent !== null) {
            return bcadd((string) $item->max_discount_percent, '0', 2);
        }

        return $global !== null && ! in_array((string) $item->category, $exempt, true) ? $global : null;
    }

    /**
     * @param array<int|string, array<string, mixed>> $lines
     *
     * @return array<int, object>
     */
    private function itemsOf(array $lines): array
    {
        $ids = array_values(array_unique(array_map(static fn (array $line): int => (int) $line['item_id'], $lines)));
        $map = [];

        foreach ($this->db->table('items')->select('item_id, name, category, minimum_price, max_discount_percent')->whereIn('item_id', $ids)->get()->getResult() as $row) {
            $map[(int) $row->item_id] = $row;
        }

        return $map;
    }
}
