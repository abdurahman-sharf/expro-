<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;

/**
 * The journals a saved sale needs, as drafts. It writes nothing. Whatever it cannot decide
 * with certainty it refuses with a named failure, so that a journal that looks right and is
 * not never reaches the books.
 *
 * A sale has up to three kinds of journal, as in the reference system: the invoice, which
 * puts the whole amount on the customer, the cost of what was delivered, and one journal for
 * each payment, which settles the customer. A sale without a customer is posted to the
 * account of counter customers instead, which must be settled in full.
 */
final class SalePostingBuilder
{
    public const SOURCE_SALE    = 'sale';
    public const SOURCE_PAYMENT = 'sale_payment';
    private const TOLERANCE     = '0.005';
    public const FALLBACK_NOTE  = 'Account of the routing row: the item names none';

    public function __construct(
        private readonly RoutingResolver $resolver,
        private readonly int $decimals = 2,
    ) {
    }

    /**
     * The builder the live posting and the retroactive posting both use, set up as the settings of
     * the shop say: the decimals of a total.
     */
    public static function forDatabase(BaseConnection $db): self
    {
        return new self(new RoutingResolver($db), totals_decimals());
    }

    /**
     * The invoice journal, or null when the sale comes to nothing.
     *
     * @throws PostingException
     */
    public function invoice(SaleDocument $sale, int $revision, ?int $employeeId = null): ?JournalDraft
    {
        $this->assertPostable($sale);
        $this->assertUnusedRows();

        $figures = SaleAmounts::figures($sale, $this->decimals);

        if (bccomp($figures['total'], '0', SaleAmounts::SCALE) === 0) {
            return null;
        }

        $receivable = $this->receivableAccount($sale);
        $discount   = $this->discountLine($sale, $figures['discount']);

        $goods = $discount === null ? $figures['revenue'] : bcadd($figures['revenue'], $figures['discount'], SaleAmounts::SCALE);

        $customerSide = [$receivable, $figures['total']];
        $incomeSide   = [];

        if (bccomp($goods, '0', SaleAmounts::SCALE) !== 0) {
            foreach ($this->goodsLines($sale, $sale->isReturn() ? RoutingKey::SalesReturns : RoutingKey::SalesRevenue, $goods, $discount !== null) as $goodsLine) {
                $incomeSide[] = $goodsLine;
            }
        }

        foreach ($this->taxLines($sale, $figures['tax']) as $taxLine) {
            $incomeSide[] = $taxLine;
        }

        if (bccomp($figures['shipping'], '0', SaleAmounts::SCALE) > 0) {
            $incomeSide[] = [$this->required(RoutingKey::SalesShipping), $figures['shipping']];
        }

        $against        = null;
        $adjustmentSign = bccomp($figures['adjustment'], '0', SaleAmounts::SCALE);

        if ($adjustmentSign !== 0) {
            $adjustment = [$this->required(RoutingKey::SalesAdjustment), SaleAmounts::absolute($figures['adjustment'])];

            if ($adjustmentSign > 0) {
                $incomeSide[] = $adjustment;
            } else {
                $against = $adjustment;
            }
        }

        $lines = $sale->isReturn()
            ? $this->mirror($customerSide, $incomeSide, $discount, $figures['discount'], $against)
            : $this->forward($customerSide, $incomeSide, $discount, $figures['discount'], $against);

        return new JournalDraft(
            date: $sale->date,
            lines: $lines,
            sourceType: self::SOURCE_SALE,
            sourceId: $sale->saleId,
            revision: $revision,
            origin: JournalOrigin::System,
            employeeId: $employeeId,
            description: ReturnOverride::describe(($sale->isReturn() ? 'Return #' : 'Sale #') . $sale->saleId, $sale->returnOverride),
            branchId: $sale->branchId,
            entryType: JournalEntryType::Revenue,
        );
    }

    /**
     * The cost of the goods that left (or, for a return, came back), or null when there is
     * none to post: the sale has not been delivered, the cost of sales row is cancelled, or the
     * goods cost nothing.
     *
     * @throws PostingException
     */
    public function cost(SaleDocument $sale, int $revision, ?int $employeeId = null): ?JournalDraft
    {
        return $this->costPlan($sale, $revision, $employeeId)->journal;
    }

    /**
     * The cost journal of a sale with the stock movements that give it its stock lines. The
     * movements are empty when nothing is posted: the sale has not been delivered, or the cost
     * of sales row is cancelled, so that no stock reaches the books without its journal. Goods
     * that cost nothing are movements without a journal.
     *
     * @throws PostingException
     */
    public function costPlan(SaleDocument $sale, int $revision, ?int $employeeId = null): StockPostingPlan
    {
        $this->assertPostable($sale);

        if (! $sale->delivered || $this->returnsGoodsThatNeverLeft($sale)) {
            return new StockPostingPlan(null, []);
        }

        $cogs = $this->resolver->accountFor(RoutingKey::CostOfGoodsSold);

        if ($cogs === null) {
            return new StockPostingPlan(null, []);
        }

        $movements  = [];
        $byLocation = [];
        $total      = '0';
        $back       = $sale->isReturn() ? [] : $sale->returnedUnits;

        foreach ($sale->lines as $line) {
            if (! $line->hasStock) {
                continue;
            }

            $units = SaleAmounts::absolute($line->quantity);

            if (! $sale->isReturn()) {
                [$units, $back] = UndeliveredReturns::remaining($line->itemId, $line->quantity, $back);

                if (bccomp($units, '0', 3) === 0) {
                    continue;
                }
            }

            $cost = $sale->isReturn()
                ? $this->returnedCost($sale, $line)
                : SaleAmounts::cost($units, $line->costPrice);

            $movements[] = $sale->isReturn()
                ? new StockMovement($line->itemId, $line->locationId, $units, $cost)
                : new StockMovement($line->itemId, $line->locationId, bcsub('0', $units, 3), bcsub('0', $cost, SaleAmounts::SCALE));

            $byLocation[$line->locationId] = bcadd($byLocation[$line->locationId] ?? '0', $cost, SaleAmounts::SCALE);
            $total                         = bcadd($total, $cost, SaleAmounts::SCALE);
        }

        if (bccomp($total, '0', SaleAmounts::SCALE) === 0) {
            return new StockPostingPlan(null, $movements);
        }

        $inventory = [];

        foreach ($byLocation as $locationId => $amount) {
            if (bccomp($amount, '0', SaleAmounts::SCALE) > 0) {
                $inventory[] = [$this->required(RoutingKey::StockLocations, $locationId), $amount];
            }
        }

        $lines = [];

        if ($sale->isReturn()) {
            foreach ($inventory as [$account, $amount]) {
                $lines[] = new JournalLineDraft($account, debit: $amount);
            }

            $lines[] = new JournalLineDraft($cogs, credit: $total);
        } else {
            $lines[] = new JournalLineDraft($cogs, debit: $total);

            foreach ($inventory as [$account, $amount]) {
                $lines[] = new JournalLineDraft($account, credit: $amount);
            }
        }

        return new StockPostingPlan(new JournalDraft(
            date: $sale->date,
            lines: $lines,
            sourceType: self::SOURCE_SALE,
            sourceId: $sale->saleId,
            revision: $revision,
            origin: JournalOrigin::System,
            employeeId: $employeeId,
            description: ReturnOverride::describe(($sale->isReturn() ? 'Cost of return #' : 'Cost of sale #') . $sale->saleId, $sale->returnOverride),
            branchId: $sale->branchId,
            entryType: JournalEntryType::Cogs,
        ), $movements);
    }

    /**
     * The journal of one payment, or null when it settles nothing: a payment that is not
     * completed, a payment left on credit, or one that nets to zero after the change given back. A refund (a negative payment) mirrors
     * a receipt. A cash adjustment is the cash rounding of the sale, which goes to the cash
     * differences account.
     *
     * @throws PostingException
     */
    public function payment(SaleDocument $sale, SalePayment $payment, int $revision, ?int $employeeId = null): ?JournalDraft
    {
        if (! $payment->status->posts()) {
            return null;
        }

        $receivable = $this->receivableAccount($sale);

        if ($payment->cashAdjustment) {
            $amount   = $payment->amount;
            $account  = $this->required(RoutingKey::CashDifference);
            $inverted = true;
        } else {
            $type = PaymentType::fromLabel($payment->type);

            if ($type === null) {
                throw new PostingException(PostingError::PaymentTypeUnknown, "The payment type {$payment->type} is not one posting knows.", ['payment_type' => $payment->type]);
            }

            $account = $payment->treasuryId !== null && $type->usesTreasury()
                ? $this->resolver->accountFor(RoutingKey::Treasuries, $payment->treasuryId)
                : $this->resolver->accountForPayment($type);

            if ($account === null) {
                return null;
            }

            $amount   = bcsub($payment->amount, $payment->cashRefund, SaleAmounts::SCALE);
            $inverted = false;
        }

        $sign = bccomp($amount, '0', SaleAmounts::SCALE);

        if ($sign === 0) {
            return null;
        }

        $magnitude = SaleAmounts::absolute($amount);

        // A receipt takes from the customer what the account receives. A cash adjustment is the
        // other way round: a positive one means the customer paid more than the sale came to.
        $receipt = ($sign > 0) !== $inverted;
        $lines   = $receipt
            ? [new JournalLineDraft($account, debit: $magnitude), new JournalLineDraft($receivable, credit: $magnitude)]
            : [new JournalLineDraft($receivable, debit: $magnitude), new JournalLineDraft($account, credit: $magnitude)];

        return new JournalDraft(
            date: $payment->date,
            lines: $lines,
            sourceType: self::SOURCE_PAYMENT,
            sourceId: $payment->paymentId,
            revision: $revision,
            origin: JournalOrigin::System,
            employeeId: $employeeId,
            description: 'Payment #' . $payment->paymentId . ' of sale #' . $sale->saleId,
            branchId: $sale->branchId,
            entryType: JournalEntryType::General,
        );
    }

    /**
     * A sale without a customer has nobody to owe the rest, so what was paid must be all of
     * what it came to, cash rounding included.
     *
     * @throws PostingException
     */
    public function assertSettled(SaleDocument $sale): void
    {
        if ($sale->customerId !== null) {
            return;
        }

        $total   = SaleAmounts::figures($sale, $this->decimals)['total'];
        $owed    = $sale->isReturn() ? bcsub('0', $total, SaleAmounts::SCALE) : $total;
        $settled = '0';

        foreach ($sale->payments as $payment) {
            if (! $payment->status->posts()) {
                continue;
            }

            if ($payment->cashAdjustment) {
                $settled = bcsub($settled, $payment->amount, SaleAmounts::SCALE);

                continue;
            }

            $type = PaymentType::fromLabel($payment->type);

            if ($type === null || $type === PaymentType::Due) {
                continue;
            }

            $settled = bcadd($settled, bcsub($payment->amount, $payment->cashRefund, SaleAmounts::SCALE), SaleAmounts::SCALE);
        }

        $difference = bcsub($owed, $settled, SaleAmounts::SCALE);

        if (bccomp(SaleAmounts::absolute($difference), self::TOLERANCE, SaleAmounts::SCALE) > 0) {
            throw new PostingException(PostingError::CreditNeedsCustomer, 'A sale without a customer must be settled in full.', ['difference' => $difference]);
        }
    }

    /**
     * A credit note on an invoice whose goods were never delivered gives back nothing that left the
     * stock, so it moves no stock and posts no cost. The delivery that comes later takes out only
     * what was not returned. A refund invoice of such an invoice is not accepted.
     */
    private function returnsGoodsThatNeverLeft(SaleDocument $sale): bool
    {
        return $sale->isReturn() && ! $sale->refundInvoice && $sale->origin !== null && ! $sale->origin->delivered;
    }

    /**
     * @throws PostingException
     */
    private function assertPostable(SaleDocument $sale): void
    {
        foreach ($sale->lines as $line) {
            $negative = str_starts_with($line->quantity, '-');

            if ($sale->isReturn() !== $negative && bccomp($line->quantity, '0', 3) !== 0) {
                throw new PostingException(PostingError::SaleNotPostable, "Line {$line->line} of the sale has a quantity that does not fit a " . ($sale->isReturn() ? 'return' : 'sale') . '.', ['line' => $line->line]);
            }
        }
    }

    /**
     * The rows of the routing that decide nothing in a sale today must be where posting expects
     * them. A row set to a type posting does not apply is refused here, however it was saved.
     *
     * @throws PostingException
     */
    private function assertUnusedRows(): void
    {
        if ($this->resolver->accountFor(RoutingKey::ProductSales) !== null) {
            throw new PostingException(PostingError::RoutingTypeUnsupported, 'The routing of ' . RoutingKey::ProductSales->value . ' is in use, which posting does not apply yet.', ['routing_key' => RoutingKey::ProductSales->value]);
        }
    }

    /**
     * @throws PostingException
     */
    private function receivableAccount(SaleDocument $sale): int
    {
        return $sale->customerId === null
            ? $this->required(RoutingKey::CounterCustomers)
            : $this->required(RoutingKey::Customers, $sale->customerId);
    }

    /**
     * @throws PostingException
     */
    private function required(RoutingKey $key, ?int $entityId = null): int
    {
        $account = $this->resolver->accountFor($key, $entityId);

        if ($account === null) {
            throw new PostingException(PostingError::RoutingRowCancelled, "The routing of {$key->value} is cancelled, but a sale cannot be posted without it.", ['routing_key' => $key->value]);
        }

        return $account;
    }

    /**
     * The lines of the goods of the invoice: one for the row of the routing, or when the row is set to per
     * item one for each account the items name, and one for the items that name none, which go to the
     * account of the row and say so in the description of their line. The parts add up to the goods.
     *
     * @param numeric-string $goods
     *
     * @return list<array{int, string, 2?: ?string}>
     *
     * @throws PostingException
     */
    private function goodsLines(SaleDocument $sale, RoutingKey $key, string $goods, bool $discountHasAccount): array
    {
        if ($this->resolver->typeOf($key) !== RoutingType::PerItem || bccomp($goods, '0', SaleAmounts::SCALE) < 0) {
            return [[$this->required($key), $goods]];
        }

        $accounts = $this->resolver->itemAccountsFor($key, array_map(static fn (SaleLine $line): int => $line->itemId, $sale->lines));
        $shares   = [];
        $routed   = [];

        foreach ($sale->lines as $line) {
            $choice = $accounts[$line->itemId];
            $group  = $choice->accountId . ($choice->own ? ':item' : ':row');

            $routed[$group] = $choice;
            $shares[$group] = bcadd($shares[$group] ?? '0', SaleAmounts::lineGoods($line, $this->decimals, $discountHasAccount), 8);
        }

        $parts = GoodsSplit::parts($shares, $goods);

        if ($parts === null) {
            throw new PostingException(PostingError::SaleNotPostable, 'The goods of the sale are too small to be shared over the accounts of its items.', ['sale_id' => $sale->saleId, 'routing_key' => $key->value]);
        }

        $lines = [];

        foreach ($parts as $group => $amount) {
            if (bccomp($amount, '0', SaleAmounts::SCALE) !== 0) {
                $lines[] = [$routed[$group]->accountId, $amount, $routed[$group]->own ? null : self::FALLBACK_NOTE];
            }
        }

        return $lines;
    }

    /**
     * The credit lines of the tax: what taxes with a liability account of their own took goes to
     * that account, and the rest to the account of tax collected, so that the lines add up to the tax
     * of the sale. A sale whose taxes have no account of their own has the one line it always had.
     *
     * @param numeric-string $tax
     *
     * @return list<array{int, string}>
     *
     * @throws PostingException
     */
    private function taxLines(SaleDocument $sale, string $tax): array
    {
        $own       = SaleAmounts::accountTaxes($sale);
        $remainder = bcsub($tax, array_reduce($own, static fn (string $sum, string $amount): string => bcadd($sum, $amount, SaleAmounts::SCALE), '0'), SaleAmounts::SCALE);

        if (bccomp($remainder, '0', SaleAmounts::SCALE) < 0) {
            throw new PostingException(PostingError::TaxAccountsExceedTax, 'The tax rows of the sale that carry an account add up to more than the tax of the sale.', ['saleId' => $sale->saleId]);
        }

        $lines = bccomp($remainder, '0', SaleAmounts::SCALE) > 0 ? [[$this->required(RoutingKey::TaxOutput), $remainder]] : [];

        foreach ($own as $accountId => $amount) {
            $lines[] = [$accountId, $amount];
        }

        return $lines;
    }

    /**
     * The account the discount is posted to, or null when discounts only lower the revenue.
     *
     * @throws PostingException
     */
    private function discountLine(SaleDocument $sale, string $discount): ?int
    {
        $account = $this->resolver->accountFor(RoutingKey::SalesDiscount);

        if ($account === null || bccomp($discount, '0', SaleAmounts::SCALE) === 0) {
            return null;
        }

        foreach ($sale->lines as $line) {
            if (bccomp($line->includedTax, '0', SaleAmounts::SCALE) !== 0) {
                throw new PostingException(PostingError::DiscountNeedsAdditiveTax, 'A discount account needs prices that do not include the tax.');
            }
        }

        return $account;
    }

    /**
     * @param array{int, string}                    $customer
     * @param list<array{int, string, 2?: ?string}> $income
     * @param array{int, string}|null               $against  A negative adjustment, which takes from the income.
     *
     * @return list<JournalLineDraft>
     */
    private function forward(array $customer, array $income, ?int $discountAccount, string $discount, ?array $against): array
    {
        $lines = [new JournalLineDraft($customer[0], debit: $customer[1])];

        if ($discountAccount !== null) {
            $lines[] = new JournalLineDraft($discountAccount, debit: $discount);
        }

        if ($against !== null) {
            $lines[] = new JournalLineDraft($against[0], debit: $against[1]);
        }

        foreach ($income as $entry) {
            $lines[] = new JournalLineDraft($entry[0], credit: $entry[1], description: $entry[2] ?? null);
        }

        return $lines;
    }

    /**
     * @param array{int, string}                    $customer
     * @param list<array{int, string, 2?: ?string}> $income
     * @param array{int, string}|null               $against  A negative adjustment, which takes from the income.
     *
     * @return list<JournalLineDraft>
     */
    private function mirror(array $customer, array $income, ?int $discountAccount, string $discount, ?array $against): array
    {
        $lines = [];

        foreach ($income as $entry) {
            $lines[] = new JournalLineDraft($entry[0], debit: $entry[1], description: $entry[2] ?? null);
        }

        if ($discountAccount !== null) {
            $lines[] = new JournalLineDraft($discountAccount, credit: $discount);
        }

        if ($against !== null) {
            $lines[] = new JournalLineDraft($against[0], credit: $against[1]);
        }

        $lines[] = new JournalLineDraft($customer[0], credit: $customer[1]);

        return $lines;
    }

    /**
     * The cost of a returned line is what the same item cost when the original sale made it:
     * the average of the original lines of the item, weighted by their quantities. A return
     * that cannot be tied to such a line has no cost this can vouch for.
     *
     * @return numeric-string
     *
     * @throws PostingException
     */
    private function returnedCost(SaleDocument $return, SaleLine $line): string
    {
        $origin = $return->origin;

        if ($origin === null && $return->returnOverride) {
            return SaleAmounts::cost($line->quantity, $line->costPrice);
        }

        if ($origin === null) {
            throw new PostingException(PostingError::ReturnOriginRequired, 'A return of stock items must say which sale it takes them back from.', ['item_id' => $line->itemId]);
        }

        if ($origin->status !== COMPLETED || $origin->isReturn()) {
            throw new PostingException(PostingError::ReturnOriginInvalid, 'A return can only be taken from a completed sale.', ['origin_id' => $origin->saleId]);
        }

        if (! $origin->delivered) {
            throw new PostingException(PostingError::ReturnOfUndelivered, 'The sale was not delivered, so nothing of it can come back.', ['origin_id' => $origin->saleId]);
        }

        $quantity = '0';
        $cost     = '0';

        foreach ($origin->lines as $sold) {
            if ($sold->itemId === $line->itemId && $sold->hasStock) {
                $quantity = bcadd($quantity, SaleAmounts::absolute($sold->quantity), 8);
                $cost     = bcadd($cost, bcmul(SaleAmounts::absolute($sold->quantity), $sold->costPrice, 8), 8);
            }
        }

        if (bccomp($quantity, '0', 8) === 0) {
            throw new PostingException(PostingError::ReturnLineNotOnOrigin, 'An item of the return is not on the sale it comes from.', ['item_id' => $line->itemId, 'origin_id' => $origin->saleId]);
        }

        return SaleAmounts::round(bcdiv(bcmul($cost, SaleAmounts::absolute($line->quantity), 8), $quantity, 8), SaleAmounts::SCALE);
    }
}
