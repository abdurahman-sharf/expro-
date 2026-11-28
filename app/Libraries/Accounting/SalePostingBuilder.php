<?php

namespace App\Libraries\Accounting;

use Closure;
use CodeIgniter\Database\BaseConnection;

/**
 * The journals a saved sale needs, as drafts. It writes nothing. Whatever it cannot decide
 * with certainty it refuses with a named failure, so that a journal that looks right and is
 * not never reaches the books.
 *
 * A sale has up to three kinds of journal: the invoice, which
 * puts the whole amount on the customer, the cost of what was delivered, and one journal for
 * each payment, which settles the customer. A sale without a customer is posted to the
 * account of counter customers instead, which must be settled in full.
 *
 * A sale in a foreign currency is built in the amounts of its own currency and then converted line by
 * line to the base currency at the rate captured for it, the rounding falling on the goods (ForeignConversion).
 * Its payments are posted at the rate of the day they were made, in a journal of their own, and what is
 * left between that and what the document owed at its own rate is one more journal, the exchange
 * difference, tied to the payment by its source and taken back with it.
 */
final class SalePostingBuilder
{
    public const SOURCE_SALE       = 'sale';
    public const SOURCE_PAYMENT    = 'sale_payment';
    public const SOURCE_PAYMENT_FX = 'sale_payment_fx';
    private const TOLERANCE        = '0.005';
    public const FALLBACK_NOTE     = 'Account of the routing row: the item names none';

    /**
     * @param (Closure(): int)|null $baseDecimals The decimals the base currency is kept in, asked only when a foreign document needs them.
     */
    public function __construct(
        private readonly RoutingResolver $resolver,
        private readonly int $decimals = 2,
        private readonly ?Closure $baseDecimals = null,
    ) {
    }

    /**
     * The builder the live posting and the retroactive posting both use, set up as the settings of
     * the shop say: the decimals of a total.
     */
    public static function forDatabase(BaseConnection $db): self
    {
        return new self(new RoutingResolver($db), totals_decimals(), static fn (): int => (new Currencies($db))->baseDecimals());
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

        $goodsCount = 0;

        if (bccomp($goods, '0', SaleAmounts::SCALE) !== 0) {
            foreach ($this->goodsLines($sale, $sale->isReturn() ? RoutingKey::SalesReturns : RoutingKey::SalesRevenue, $goods, $discount !== null) as $goodsLine) {
                $incomeSide[] = $goodsLine;
                $goodsCount++;
            }
        }

        foreach ($this->taxLines($sale, $figures['tax']) as $taxLine) {
            $incomeSide[] = $taxLine;
        }

        if (bccomp($figures['shipping'], '0', SaleAmounts::SCALE) > 0) {
            $incomeSide[] = [$this->shippingAccount($sale), $figures['shipping']];
        }

        $against = [];

        foreach ([[RoutingKey::SalesAdjustment, $figures['adjustment']], [RoutingKey::TaxRounding, $figures['rounding']]] as [$key, $amount]) {
            $sign = bccomp($amount, '0', SaleAmounts::SCALE);

            if ($sign === 0) {
                continue;
            }

            $entry = [$this->required($key), SaleAmounts::absolute($amount)];

            if ($sign > 0) {
                $incomeSide[] = $entry;
            } else {
                $against[] = $entry;
            }
        }

        $lines = $sale->isReturn()
            ? $this->mirror($customerSide, $incomeSide, $discount, $figures['discount'], $against)
            : $this->forward($customerSide, $incomeSide, $discount, $figures['discount'], $against);

        $draft = new JournalDraft(
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

        if ($sale->currency === null) {
            return $draft;
        }

        $first = $sale->isReturn() ? 0 : 1 + ($discount !== null ? 1 : 0) + count($against);

        return ForeignConversion::toBase($draft, $sale->currency, $this->baseDecimalsOf(), $goodsCount > 0
            ? $first + $this->largestOf(array_slice($incomeSide, 0, $goodsCount))
            : ForeignConversion::indexOf($draft, $this->shippingAccount($sale)));
    }

    /**
     * The position of the largest amount of the lines, the first of them when two are alike, which is the
     * goods line that takes the rounding of the conversion to the base currency, as it takes the rounding
     * of the split of the goods.
     *
     * @param list<array{int, string, 2?: ?string}> $lines
     */
    private function largestOf(array $lines): int
    {
        $largest = 0;

        foreach ($lines as $position => $line) {
            if (bccomp($line[1], $lines[$largest][1], SaleAmounts::SCALE) > 0) {
                $largest = $position;
            }
        }

        return $largest;
    }

    /**
     * The cost of the goods that left (or, for a return, came back), or null when there is
     * none to post: the sale has not been delivered, or the goods cost nothing.
     *
     * @throws PostingException
     */
    public function cost(SaleDocument $sale, int $revision, ?int $employeeId = null): ?JournalDraft
    {
        return $this->costPlan($sale, $revision, $employeeId)->journal;
    }

    /**
     * The cost journal of a sale with the stock movements that give it its stock lines. The
     * movements are empty while the sale has not been delivered, so that the goods and their cost
     * move together. Goods that cost nothing are movements without a journal.
     *
     * @throws PostingException
     */
    public function costPlan(SaleDocument $sale, int $revision, ?int $employeeId = null): StockPostingPlan
    {
        $this->assertPostable($sale);

        if (! $sale->delivered || $this->returnsGoodsThatNeverLeft($sale)) {
            return new StockPostingPlan(null, []);
        }

        // A cost of sales row that names no account sets nothing of its own, so the cost goes to the
        // account the chart opened for it: goods never leave the stock without their cost.
        $cogs = $this->resolver->accountFor(RoutingKey::CostOfGoodsSold) ?? $this->resolver->systemAccount((string) RoutingKey::CostOfGoodsSold->defaultSystemKey());

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
     * completed, a payment left on credit, or one that nets to zero after the change given back.
     * Any other payment whose way of paying gives no account is refused rather than dropped. A refund (a negative payment) mirrors
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

        if ($sale->currency !== null && $payment->cashAdjustment) {
            throw new PostingException(PostingError::FxNotSupported, 'The rounding of cash is not supported on a document in a foreign currency.', ['what' => 'cash_adjustment', 'sale_id' => $sale->saleId]);
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
                if ($type === PaymentType::Due) {
                    return null;
                }

                throw new PostingException(PostingError::PaymentTypeUnknown, "The payment type {$payment->type} has no account to be paid into.", ['payment_type' => $payment->type]);
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

        $draft = new JournalDraft(
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

        if ($sale->currency === null) {
            return $draft;
        }

        return ForeignConversion::toBase($draft, $this->currencyOfPayment($sale, $payment), $this->baseDecimalsOf(), 0);
    }

    /**
     * The journal of what a payment of a foreign document leaves over: the customer is owed or owes the
     * amount in the currency of the document at the rate of the document, while the money moved at the rate
     * of the day of the payment. What the document's rate says the customer's balance falls by, from the
     * payments before it and this one added together so that the payments of a document never drift apart
     * from its total by a rounding unit, less what the payment journal moved, is posted to the account
     * of exchange losses when the customer's balance falls by more than the money received, and to
     * the account of exchange gains when it falls by less. Null when the two agree.
     *
     * @throws PostingException
     */
    public function paymentDifference(SaleDocument $sale, SalePayment $payment, int $revision, ?int $employeeId = null): ?JournalDraft
    {
        if ($sale->currency === null) {
            return null;
        }

        $net = $this->bookedNet($payment);

        if ($net === null) {
            return null;
        }

        $current  = $this->currencyOfPayment($sale, $payment);
        $decimals = $this->baseDecimalsOf();
        $before   = '0';

        foreach ($sale->payments as $earlier) {
            if ($earlier->paymentId >= $payment->paymentId) {
                break;
            }

            $amount = $this->bookedNet($earlier);

            if ($amount !== null) {
                $before = bcadd($before, $amount, SaleAmounts::SCALE);
            }
        }

        $after      = bcadd($before, $net, SaleAmounts::SCALE);
        $settled    = bcsub(Money::toBase($after, $sale->currency->rate, $decimals), Money::toBase($before, $sale->currency->rate, $decimals), SaleAmounts::SCALE);
        $difference = bcsub($settled, Money::toBase($net, $current->rate, $decimals), SaleAmounts::SCALE);

        if (bccomp($difference, '0', SaleAmounts::SCALE) === 0) {
            return null;
        }

        $customer  = $this->receivableAccount($sale);
        $magnitude = SaleAmounts::absolute($difference);
        $lines     = bccomp($difference, '0', SaleAmounts::SCALE) > 0
            ? [new JournalLineDraft($this->fxAccount(RoutingKey::FxLoss), debit: $magnitude), new JournalLineDraft($customer, credit: $magnitude)]
            : [new JournalLineDraft($customer, debit: $magnitude), new JournalLineDraft($this->fxAccount(RoutingKey::FxGain), credit: $magnitude)];

        return new JournalDraft(
            date: $payment->date,
            lines: $lines,
            sourceType: self::SOURCE_PAYMENT_FX,
            sourceId: $payment->paymentId,
            revision: $revision,
            origin: JournalOrigin::System,
            employeeId: $employeeId,
            description: 'Exchange difference of payment #' . $payment->paymentId . ' of sale #' . $sale->saleId,
            branchId: $sale->branchId,
            entryType: JournalEntryType::General,
        );
    }

    /**
     * What a payment moves, in the currency of its document and with the sign of the money: null when it
     * books nothing (it is not completed, it is the rounding of cash, it is left on credit, or it nets to
     * zero after the change given back).
     *
     * @return numeric-string|null
     *
     * @throws PostingException
     */
    private function bookedNet(SalePayment $payment): ?string
    {
        if (! $payment->status->posts() || $payment->cashAdjustment) {
            return null;
        }

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

        $net = bcsub($payment->amount, $payment->cashRefund, SaleAmounts::SCALE);

        return bccomp($net, '0', SaleAmounts::SCALE) === 0 ? null : $net;
    }

    /**
     * @throws PostingException
     */
    private function currencyOfPayment(SaleDocument $sale, SalePayment $payment): DocumentCurrency
    {
        if ($sale->currency === null || $payment->rate === null) {
            throw new PostingException(PostingError::ForeignRateMismatch, 'The payment of a document in a foreign currency has no rate.', ['payment_id' => $payment->paymentId]);
        }

        return new DocumentCurrency($sale->currency->code, $payment->rate, $sale->currency->decimals);
    }

    private function baseDecimalsOf(): int
    {
        return $this->baseDecimals === null ? 2 : ($this->baseDecimals)();
    }

    /**
     * @throws PostingException
     */
    private function fxAccount(RoutingKey $key): int
    {
        try {
            $account = $this->resolver->accountFor($key);
        } catch (PostingException $failure) {
            throw new PostingException(PostingError::FxAccountsMissing, "The routing of {$key->value} has no usable account, so the exchange difference cannot be posted.", ['routing_key' => $key->value], $failure);
        }

        if ($account === null) {
            throw new PostingException(PostingError::FxAccountsMissing, "The routing of {$key->value} has no account, so the exchange difference cannot be posted.", ['routing_key' => $key->value]);
        }

        return $account;
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
    /**
     * Where the shipping charge of a sale is credited: the account of the shipping company it names, which is
     * what the shop owes on, or else the routed account of shipping revenue.
     */
    private function shippingAccount(SaleDocument $sale): int
    {
        return $sale->charges?->shippingAccountId ?? $this->required(RoutingKey::SalesShipping);
    }

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

        if ($sale->vat !== [] && $own !== [] && $this->everyTaxHasAnAccount($sale)) {
            $shippingTax   = bcadd($sale->charges?->shippingTax ?? '0', '0', SaleAmounts::SCALE);
            $largest       = array_search(max($own), $own, true);
            $own[$largest] = bcadd($own[$largest], bcsub($remainder, $shippingTax, SaleAmounts::SCALE), SaleAmounts::SCALE);
            $remainder     = $shippingTax;
        }

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
     * Whether every tax row of the sale went to an account of its own, so that the VAT of its categories, rounded
     * on the taxable amounts, is all theirs and the few halalas it parts from their rows by fall on the largest.
     */
    private function everyTaxHasAnAccount(SaleDocument $sale): bool
    {
        foreach ($sale->lines as $line) {
            $tax  = bcadd(SaleAmounts::absolute($line->includedTax), SaleAmounts::absolute($line->addedTax), SaleAmounts::SCALE);
            $held = array_reduce($line->accountTaxes, static fn (string $sum, string $amount): string => bcadd($sum, $amount, SaleAmounts::SCALE), '0');

            if (bccomp($tax, $held, SaleAmounts::SCALE) !== 0) {
                return false;
            }
        }

        return true;
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
     * @param list<array{int, string}>              $against  What takes from the income: a negative adjustment, a rounding below the revenue.
     *
     * @return list<JournalLineDraft>
     */
    private function forward(array $customer, array $income, ?int $discountAccount, string $discount, array $against): array
    {
        $lines = [new JournalLineDraft($customer[0], debit: $customer[1])];

        if ($discountAccount !== null) {
            $lines[] = new JournalLineDraft($discountAccount, debit: $discount);
        }

        foreach ($against as $entry) {
            $lines[] = new JournalLineDraft($entry[0], debit: $entry[1]);
        }

        foreach ($income as $entry) {
            $lines[] = new JournalLineDraft($entry[0], credit: $entry[1], description: $entry[2] ?? null);
        }

        return $lines;
    }

    /**
     * @param array{int, string}                    $customer
     * @param list<array{int, string, 2?: ?string}> $income
     * @param list<array{int, string}>              $against  What takes from the income: a negative adjustment, a rounding below the revenue.
     *
     * @return list<JournalLineDraft>
     */
    private function mirror(array $customer, array $income, ?int $discountAccount, string $discount, array $against): array
    {
        $lines = [];

        foreach ($income as $entry) {
            $lines[] = new JournalLineDraft($entry[0], debit: $entry[1], description: $entry[2] ?? null);
        }

        if ($discountAccount !== null) {
            $lines[] = new JournalLineDraft($discountAccount, credit: $discount);
        }

        foreach ($against as $entry) {
            $lines[] = new JournalLineDraft($entry[0], credit: $entry[1]);
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
