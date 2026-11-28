<?php

namespace App\Libraries\Accounting;

use App\Libraries\Accounting\Engine\PostingFacts;

/**
 * The figures of a sale and of the cost of its goods, for the posting engine. A figure the code would leave
 * out of the journal is given as nothing, and a sale that comes to nothing gives nothing at all.
 *
 * A sale gives what the customer owes, the discount when the discount has an account (it is otherwise taken
 * off the revenue), the goods in one part for each account of revenue its items name and one for those that
 * name none, the tax in one part for each tax that has an account of its own and one for the rest, the
 * shipping, the adjustment and the rounding of the tax, all in the currency of the sale: the rules bring a
 * foreign journal to the base. Which account an item or a tax names is a field of the document, as the
 * account of a category is on an expense; the routing decides the rest. The checks the code makes of a sale
 * before it posts it are made here too, so that a sale posted by the engine passes them.
 *
 * The cost gives what the goods that left cost, location by location, from the movements the code makes.
 */
final class SaleFacts
{
    private const SCALE = SaleAmounts::SCALE;

    /**
     * @throws PostingException
     */
    public static function invoice(SaleDocument $sale, int $revision, ?int $employeeId, int $decimals, int $baseDecimals, RoutingResolver $resolver): PostingFacts
    {
        self::assertPostable($sale, $resolver);

        $figures = SaleAmounts::figures($sale, $decimals);
        $given   = ['total' => '0', 'discount' => '0', 'shipping' => '0', 'adjustment' => '0', 'rounding' => '0'];
        $lists   = ['goods' => [], 'taxes' => []];

        if (bccomp($figures['total'], '0', self::SCALE) !== 0) {
            self::assertReceivable($sale, $resolver);

            $discounted = self::discountHasAccount($sale, $figures['discount'], $resolver);
            $goods      = $discounted ? bcadd($figures['revenue'], $figures['discount'], self::SCALE) : $figures['revenue'];

            $given = [
                'total'      => $figures['total'],
                'discount'   => $discounted ? $figures['discount'] : '0',
                'shipping'   => bccomp($figures['shipping'], '0', self::SCALE) > 0 ? $figures['shipping'] : '0',
                'adjustment' => $figures['adjustment'],
                'rounding'   => $figures['rounding'],
            ];

            $lists = [
                'goods' => bccomp($goods, '0', self::SCALE) === 0 ? [] : self::goods($sale, $goods, $discounted, $decimals, $resolver),
                'taxes' => self::taxes($sale, $figures['tax']),
            ];
        }

        return new PostingFacts(
            operation: 'sale',
            sourceId: $sale->saleId,
            date: $sale->date,
            branchId: $sale->branchId,
            revision: $revision,
            employeeId: $employeeId,
            figures: $given,
            fields: ['customer_id' => $sale->customerId, 'shipping_account_id' => $sale->charges?->shippingAccountId],
            flags: ['has_customer' => $sale->customerId !== null, 'counter' => $sale->customerId === null],
            lists: $lists,
            currencyCode: $sale->currency?->code,
            description: ReturnOverride::describe('Sale #' . $sale->saleId, $sale->returnOverride),
            currencyRate: $sale->currency?->rate,
            baseDecimals: $baseDecimals,
        );
    }

    /**
     * @param list<StockMovement> $movements The movements the code takes the goods out with, at their cost.
     */
    public static function cost(SaleDocument $sale, array $movements, int $revision, ?int $employeeId): PostingFacts
    {
        $byLocation = [];
        $total      = '0';

        foreach ($movements as $movement) {
            $cost                              = SaleAmounts::absolute($movement->value);
            $byLocation[$movement->locationId] = bcadd($byLocation[$movement->locationId] ?? '0', $cost, self::SCALE);
            $total                             = bcadd($total, $cost, self::SCALE);
        }

        $locations = [];

        if (bccomp($total, '0', self::SCALE) !== 0) {
            foreach ($byLocation as $locationId => $cost) {
                $locations[] = ['fields' => ['location_id' => $locationId], 'figures' => ['value' => bccomp($cost, '0', self::SCALE) > 0 ? $cost : '0']];
            }
        }

        return new PostingFacts(
            operation: 'sale_cost',
            sourceId: $sale->saleId,
            date: $sale->date,
            branchId: $sale->branchId,
            revision: $revision,
            employeeId: $employeeId,
            figures: ['cost' => bccomp($total, '0', self::SCALE) === 0 ? '0' : $total],
            lists: ['locations' => $locations],
            description: ReturnOverride::describe('Cost of sale #' . $sale->saleId, $sale->returnOverride),
        );
    }

    /**
     * @throws PostingException
     */
    private static function assertPostable(SaleDocument $sale, RoutingResolver $resolver): void
    {
        foreach ($sale->lines as $line) {
            if (str_starts_with($line->quantity, '-') && bccomp($line->quantity, '0', 3) !== 0) {
                throw new PostingException(PostingError::SaleNotPostable, "Line {$line->line} of the sale has a quantity that does not fit a sale.", ['line' => $line->line]);
            }
        }

        if ($resolver->accountFor(RoutingKey::ProductSales) !== null) {
            throw new PostingException(PostingError::RoutingTypeUnsupported, 'The routing of ' . RoutingKey::ProductSales->value . ' is in use, which posting does not apply yet.', ['routing_key' => RoutingKey::ProductSales->value]);
        }
    }

    /**
     * The code asks for the account of whoever owes the sale before anything else of it, so a sale that has
     * none is refused for that before anything the facts look up could refuse it for something else.
     *
     * @throws PostingException
     */
    private static function assertReceivable(SaleDocument $sale, RoutingResolver $resolver): void
    {
        $key = $sale->customerId === null ? RoutingKey::CounterCustomers : RoutingKey::Customers;

        if ($resolver->accountFor($key, $sale->customerId) === null) {
            throw new PostingException(PostingError::RoutingRowCancelled, "The routing of {$key->value} is cancelled, but a sale cannot be posted without it.", ['routing_key' => $key->value]);
        }
    }

    /**
     * Whether the discount is a line of its own: its row names an account and there is a discount. A
     * discount on prices that hold the tax cannot be.
     *
     * @throws PostingException
     */
    private static function discountHasAccount(SaleDocument $sale, string $discount, RoutingResolver $resolver): bool
    {
        if ($resolver->accountFor(RoutingKey::SalesDiscount) === null || bccomp($discount, '0', self::SCALE) === 0) {
            return false;
        }

        foreach ($sale->lines as $line) {
            if (bccomp($line->includedTax, '0', self::SCALE) !== 0) {
                throw new PostingException(PostingError::DiscountNeedsAdditiveTax, 'A discount account needs prices that do not include the tax.');
            }
        }

        return true;
    }

    /**
     * The goods in parts: one for each account of revenue the items name and one for the items that name
     * none, shared as the goods of the lines are, while the revenue row takes the accounts of the items; one
     * part otherwise.
     *
     * @return list<array{fields: array<string, ?int>, figures: array<string, string>}>
     *
     * @throws PostingException
     */
    private static function goods(SaleDocument $sale, string $goods, bool $discounted, int $decimals, RoutingResolver $resolver): array
    {
        if ($resolver->typeOf(RoutingKey::SalesRevenue) !== RoutingType::PerItem || bccomp($goods, '0', self::SCALE) < 0) {
            return [['fields' => ['item_account_id' => null], 'figures' => ['value' => $goods]]];
        }

        $accounts = $resolver->itemAccountsFor(RoutingKey::SalesRevenue, array_map(static fn (SaleLine $line): int => $line->itemId, $sale->lines));
        $shares   = [];
        $named    = [];

        foreach ($sale->lines as $line) {
            $choice         = $accounts[$line->itemId];
            $group          = $choice->accountId . ($choice->own ? ':item' : ':row');
            $named[$group]  = $choice->own ? $choice->accountId : null;
            $shares[$group] = bcadd($shares[$group] ?? '0', SaleAmounts::lineGoods($line, $decimals, $discounted), 8);
        }

        $parts = GoodsSplit::parts($shares, $goods);

        if ($parts === null) {
            throw new PostingException(PostingError::SaleNotPostable, 'The goods of the sale are too small to be shared over the accounts of its items.', ['sale_id' => $sale->saleId, 'routing_key' => RoutingKey::SalesRevenue->value]);
        }

        $entries = [];

        foreach ($parts as $group => $amount) {
            if (bccomp($amount, '0', self::SCALE) !== 0) {
                $entries[] = ['fields' => ['item_account_id' => $named[$group]], 'figures' => ['value' => $amount]];
            }
        }

        return $entries;
    }

    /**
     * The tax in parts: what is not held by a tax with an account of its own first, then what each such tax
     * holds. When every tax has an account and the categories were worked out, the few units they part from
     * the rows by fall on the largest.
     *
     * @return list<array{fields: array<string, ?int>, figures: array<string, string>}>
     *
     * @throws PostingException
     */
    private static function taxes(SaleDocument $sale, string $tax): array
    {
        $own       = SaleAmounts::accountTaxes($sale);
        $remainder = bcsub($tax, array_reduce($own, static fn (string $sum, string $amount): string => bcadd($sum, $amount, self::SCALE), '0'), self::SCALE);

        if ($sale->vat !== [] && $own !== [] && self::everyTaxHasAnAccount($sale)) {
            $shippingTax   = bcadd($sale->charges?->shippingTax ?? '0', '0', self::SCALE);
            $largest       = array_search(max($own), $own, true);
            $own[$largest] = bcadd($own[$largest], bcsub($remainder, $shippingTax, self::SCALE), self::SCALE);
            $remainder     = $shippingTax;
        }

        if (bccomp($remainder, '0', self::SCALE) < 0) {
            throw new PostingException(PostingError::TaxAccountsExceedTax, 'The tax rows of the sale that carry an account add up to more than the tax of the sale.', ['saleId' => $sale->saleId]);
        }

        $entries = bccomp($remainder, '0', self::SCALE) > 0 ? [['fields' => ['account_id' => null], 'figures' => ['value' => $remainder]]] : [];

        foreach ($own as $accountId => $amount) {
            $entries[] = ['fields' => ['account_id' => $accountId], 'figures' => ['value' => $amount]];
        }

        return $entries;
    }

    private static function everyTaxHasAnAccount(SaleDocument $sale): bool
    {
        foreach ($sale->lines as $line) {
            $tax  = bcadd(SaleAmounts::absolute($line->includedTax), SaleAmounts::absolute($line->addedTax), self::SCALE);
            $held = array_reduce($line->accountTaxes, static fn (string $sum, string $amount): string => bcadd($sum, $amount, self::SCALE), '0');

            if (bccomp($tax, $held, self::SCALE) !== 0) {
                return false;
            }
        }

        return true;
    }
}
