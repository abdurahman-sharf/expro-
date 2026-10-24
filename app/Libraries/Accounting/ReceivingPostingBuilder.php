<?php

namespace App\Libraries\Accounting;

/**
 * The journals a saved purchase needs, as drafts, with the stock movements that give them their
 * stock lines. It writes nothing. Whatever it cannot decide with certainty it refuses with a
 * named failure, so that a journal that looks right and is not never reaches the books.
 *
 * A purchase has one journal and, when it was paid at once, a second one for the payment, as in
 * the reference system. Goods that come in go into the account of their stock location at what
 * they cost, and the tax on them into the account of tax paid. Goods that go back to the
 * supplier leave the account at what they are worth in stock, and what the supplier gives back
 * for them beyond that goes to the cost of sales. A transfer moves stock from one location to
 * another at what it is worth where it leaves.
 */
final class ReceivingPostingBuilder
{
    public const SOURCE_PURCHASE         = 'purchase';
    public const SOURCE_PAYMENT          = 'purchase_payment';
    public const SOURCE_SUPPLIER_PAYMENT = 'supplier_payment';
    private const SCALE                  = ReceivingAmounts::SCALE;

    public function __construct(
        private readonly RoutingResolver $resolver,
        private readonly StockLedger $ledger,
    ) {
    }

    /**
     * The journal of a purchase and its stock movements. The journal is null when the goods
     * come to nothing, and the movements are still written, because goods that cost nothing
     * are still goods.
     *
     * @throws PostingException
     */
    public function purchase(ReceivingDocument $purchase, int $revision, ?int $employeeId = null): StockPostingPlan
    {
        $this->assertPostable($purchase);

        return match ($purchase->type) {
            ReceivingType::Receive     => $this->receive($purchase, $revision, $employeeId),
            ReceivingType::Return      => $this->giveBack($purchase, $revision, $employeeId),
            ReceivingType::Requisition => $this->transfer($purchase, $revision, $employeeId),
        };
    }

    /**
     * The journal of the payment of a purchase, or null when there is none: the purchase was
     * left on credit, has no supplier to settle (it was paid in its own journal), or only moves
     * stock. A return paid back by the supplier mirrors a payment.
     *
     * @throws PostingException
     */
    public function payment(ReceivingDocument $purchase, int $revision, ?int $employeeId = null): ?JournalDraft
    {
        if ($purchase->type === ReceivingType::Requisition || $purchase->supplierId === null) {
            return null;
        }

        $account = $this->paymentAccount($purchase->paymentType);

        if ($account === null) {
            return null;
        }

        $total = $this->totals($purchase)['total'];

        if (bccomp($total, '0', self::SCALE) === 0) {
            return null;
        }

        $supplier = $this->required(RoutingKey::Suppliers, $purchase->supplierId);
        $lines    = $purchase->type === ReceivingType::Receive
            ? [new JournalLineDraft($supplier, debit: $total), new JournalLineDraft($account, credit: $total)]
            : [new JournalLineDraft($account, debit: $total), new JournalLineDraft($supplier, credit: $total)];

        return new JournalDraft(
            date: $purchase->date,
            lines: $lines,
            sourceType: self::SOURCE_PAYMENT,
            sourceId: $purchase->receivingId,
            revision: $revision,
            origin: JournalOrigin::System,
            employeeId: $employeeId,
            description: ($purchase->type === ReceivingType::Receive ? 'Payment of purchase #' : 'Refund of purchase return #') . $purchase->receivingId,
            branchId: $purchase->branchId,
            entryType: JournalEntryType::General,
        );
    }

    /**
     * The journal of a payment made against a purchase after it was saved, or null when the
     * payment is not one the books hold (it is not completed). A payment to the supplier debits
     * the account of the supplier and credits the account it was paid from; a negative one is
     * money the supplier gave back, and goes the other way.
     *
     * @throws PostingException
     */
    public function supplierPayment(ReceivingDocument $purchase, SupplierPayment $payment, int $revision, ?int $employeeId = null): ?JournalDraft
    {
        if (! $payment->status->posts()) {
            return null;
        }

        if ($purchase->type !== ReceivingType::Receive) {
            throw new PostingException(PostingError::PurchaseNotPostable, 'A payment is made against a purchase, not against a return or a transfer.', ['receiving_id' => $purchase->receivingId]);
        }

        if ($purchase->supplierId === null) {
            throw new PostingException(PostingError::SupplierRequired, 'A payment to a supplier needs the purchase to have a supplier.', ['receiving_id' => $purchase->receivingId]);
        }

        $account = $this->paymentAccount($payment->type);

        if ($account === null) {
            throw new PostingException(PostingError::PaymentTypeUnknown, "The payment type {$payment->type} is not one a supplier can be paid with.", ['payment_type' => $payment->type]);
        }

        $sign = bccomp($payment->amount, '0', self::SCALE);

        if ($sign === 0) {
            return null;
        }

        $magnitude = SaleAmounts::absolute($payment->amount);
        $supplier  = $this->required(RoutingKey::Suppliers, $purchase->supplierId);
        $lines     = $sign > 0
            ? [new JournalLineDraft($supplier, debit: $magnitude), new JournalLineDraft($account, credit: $magnitude)]
            : [new JournalLineDraft($account, debit: $magnitude), new JournalLineDraft($supplier, credit: $magnitude)];

        return new JournalDraft(
            date: $payment->date,
            lines: $lines,
            sourceType: self::SOURCE_SUPPLIER_PAYMENT,
            sourceId: $payment->paymentId,
            revision: $revision,
            origin: JournalOrigin::System,
            employeeId: $employeeId,
            description: 'Payment #' . $payment->paymentId . ' of purchase #' . $purchase->receivingId,
            branchId: $purchase->branchId,
            entryType: JournalEntryType::General,
        );
    }

    /**
     * What the lines come to: the cost, the tax on it, and the total the supplier is owed,
     * which is kept to two decimals while the tax is not rounded, as in the reference system.
     *
     * @return array{net: numeric-string, tax: numeric-string, total: numeric-string}
     */
    public function totals(ReceivingDocument $purchase): array
    {
        $net = '0';
        $tax = '0';

        foreach ($purchase->lines as $line) {
            $net = bcadd($net, ReceivingAmounts::net($line), self::SCALE);
            $tax = bcadd($tax, ReceivingAmounts::tax($line), self::SCALE);
        }

        return ['net' => $net, 'tax' => $tax, 'total' => SaleAmounts::round(bcadd($net, $tax, self::SCALE), 2)];
    }

    /**
     * @throws PostingException
     */
    private function receive(ReceivingDocument $purchase, int $revision, ?int $employeeId): StockPostingPlan
    {
        $totals   = $this->totals($purchase);
        $rounding = bcsub($totals['total'], bcadd($totals['net'], $totals['tax'], self::SCALE), self::SCALE);

        $parts = [];

        foreach ($purchase->lines as $index => $line) {
            $net = ReceivingAmounts::net($line);

            if ($line->hasStock) {
                $parts['s' . $index] = $net;
            } else {
                $parts['e'] = bcadd($parts['e'] ?? '0', $net, self::SCALE);
            }
        }

        $parts = $this->withRounding($parts, $rounding);

        $movements  = [];
        $byLocation = [];

        foreach ($purchase->lines as $index => $line) {
            if (! $line->hasStock) {
                continue;
            }

            $value                         = $parts['s' . $index];
            $movements[]                   = new StockMovement($line->itemId, $line->locationId, ReceivingAmounts::units($line), $value);
            $byLocation[$line->locationId] = bcadd($byLocation[$line->locationId] ?? '0', $value, self::SCALE);
        }

        $expense = $parts['e'] ?? '0';

        $debits = [];

        foreach ($byLocation as $locationId => $amount) {
            $debits[] = [$this->required(RoutingKey::StockLocations, $locationId), $amount];
        }

        if (bccomp($expense, '0', self::SCALE) !== 0) {
            $debits[] = [$this->required(RoutingKey::CostOfGoodsSold), $expense];
        }

        if (bccomp($totals['tax'], '0', self::SCALE) > 0) {
            $debits[] = [$this->required(RoutingKey::TaxInput), $totals['tax']];
        }

        $lines = [];

        foreach ($debits as [$account, $amount]) {
            if (bccomp($amount, '0', self::SCALE) > 0) {
                $lines[] = new JournalLineDraft($account, debit: $amount);
            }
        }

        if (bccomp($totals['total'], '0', self::SCALE) > 0) {
            $lines[] = new JournalLineDraft($this->creditor($purchase), credit: $totals['total']);
        }

        return new StockPostingPlan($this->draft($purchase, $lines, $revision, $employeeId, 'Purchase #'), $movements);
    }

    /**
     * @throws PostingException
     */
    private function giveBack(ReceivingDocument $purchase, int $revision, ?int $employeeId): StockPostingPlan
    {
        $this->assertReturnOrigin($purchase);

        $totals     = $this->totals($purchase);
        $movements  = [];
        $byLocation = [];
        $inventory  = '0';

        foreach ($purchase->lines as $line) {
            if (! $line->hasStock) {
                continue;
            }

            $units = ReceivingAmounts::units($line);
            $cost  = bcmul($units, $this->ledger->unitCost($line->itemId, $line->locationId, $line->itemCost), 8);
            $value = SaleAmounts::round($cost, self::SCALE);

            $movements[]                   = new StockMovement($line->itemId, $line->locationId, bcsub('0', $units, 3), bcsub('0', $value, self::SCALE));
            $byLocation[$line->locationId] = bcadd($byLocation[$line->locationId] ?? '0', $value, self::SCALE);
            $inventory                     = bcadd($inventory, $value, self::SCALE);
        }

        $difference = bcsub(bcsub($totals['total'], $inventory, self::SCALE), $totals['tax'], self::SCALE);
        $lines      = [];

        if (bccomp($totals['total'], '0', self::SCALE) > 0) {
            $lines[] = new JournalLineDraft($this->creditor($purchase), debit: $totals['total']);
        }

        if (bccomp($totals['tax'], '0', self::SCALE) > 0) {
            $lines[] = new JournalLineDraft($this->required(RoutingKey::TaxInput), credit: $totals['tax']);
        }

        foreach ($byLocation as $locationId => $amount) {
            if (bccomp($amount, '0', self::SCALE) > 0) {
                $lines[] = new JournalLineDraft($this->required(RoutingKey::StockLocations, $locationId), credit: $amount);
            }
        }

        $sign = bccomp($difference, '0', self::SCALE);

        if ($sign > 0) {
            $lines[] = new JournalLineDraft($this->required(RoutingKey::CostOfGoodsSold), credit: $difference);
        } elseif ($sign < 0) {
            $lines[] = new JournalLineDraft($this->required(RoutingKey::CostOfGoodsSold), debit: SaleAmounts::absolute($difference));
        }

        return new StockPostingPlan($this->draft($purchase, $lines, $revision, $employeeId, 'Purchase return #'), $movements);
    }

    /**
     * @throws PostingException
     */
    private function transfer(ReceivingDocument $purchase, int $revision, ?int $employeeId): StockPostingPlan
    {
        $outValue  = [];
        $inLines   = [];
        $movements = [];

        foreach ($purchase->lines as $line) {
            if (! $line->hasStock) {
                continue;
            }

            $units = $line->units();

            if (bccomp($units, '0', 3) < 0) {
                $value = SaleAmounts::round(bcmul(ReceivingAmounts::units($line), $this->ledger->unitCost($line->itemId, $line->locationId, $line->itemCost), 8), self::SCALE);

                $outValue[$line->itemId] = bcadd($outValue[$line->itemId] ?? '0', $value, self::SCALE);
                $movements[]             = new StockMovement($line->itemId, $line->locationId, $units, bcsub('0', $value, self::SCALE));
            } elseif (bccomp($units, '0', 3) > 0) {
                $inLines[$line->itemId][] = $line;
            }
        }

        foreach ($inLines as $itemId => $lines) {
            if (! isset($outValue[$itemId])) {
                throw new PostingException(PostingError::PurchaseNotPostable, 'A transfer must take from a stock location every item it puts in another.', ['item_id' => $itemId]);
            }

            $inUnits = '0';

            foreach ($lines as $line) {
                $inUnits = bcadd($inUnits, $line->units(), 3);
            }

            $left = $outValue[$itemId];

            foreach ($lines as $position => $line) {
                $value = $position === array_key_last($lines)
                    ? $left
                    : SaleAmounts::round(bcmul($outValue[$itemId], bcdiv($line->units(), $inUnits, 8), 8), self::SCALE);
                $left = bcsub($left, $value, self::SCALE);

                $movements[] = new StockMovement($itemId, $line->locationId, $line->units(), $value);
            }
        }

        $debit  = [];
        $credit = [];

        foreach ($movements as $movement) {
            $amount = SaleAmounts::absolute($movement->value);

            if (bccomp($movement->value, '0', self::SCALE) < 0) {
                $credit[$movement->locationId] = bcadd($credit[$movement->locationId] ?? '0', $amount, self::SCALE);
            } else {
                $debit[$movement->locationId] = bcadd($debit[$movement->locationId] ?? '0', $amount, self::SCALE);
            }
        }

        $lines = [];

        foreach ($debit as $locationId => $amount) {
            if (bccomp($amount, '0', self::SCALE) > 0) {
                $lines[] = new JournalLineDraft($this->required(RoutingKey::StockLocations, $locationId), debit: $amount);
            }
        }

        foreach ($credit as $locationId => $amount) {
            if (bccomp($amount, '0', self::SCALE) > 0) {
                $lines[] = new JournalLineDraft($this->required(RoutingKey::StockLocations, $locationId), credit: $amount);
            }
        }

        return new StockPostingPlan($this->draft($purchase, $lines, $revision, $employeeId, 'Stock transfer #'), $movements);
    }

    /**
     * The journal of a purchase from its lines, or null when there are fewer than two, which
     * is when the goods come to nothing.
     *
     * @param list<JournalLineDraft> $lines
     */
    private function draft(ReceivingDocument $purchase, array $lines, int $revision, ?int $employeeId, string $description): ?JournalDraft
    {
        if (count($lines) < 2) {
            return null;
        }

        return new JournalDraft(
            date: $purchase->date,
            lines: $lines,
            sourceType: self::SOURCE_PURCHASE,
            sourceId: $purchase->receivingId,
            revision: $revision,
            origin: JournalOrigin::System,
            employeeId: $employeeId,
            description: $description . $purchase->receivingId,
            branchId: $purchase->branchId,
            entryType: JournalEntryType::General,
        );
    }

    /**
     * Puts the rounding of the total on the part of the purchase that is worth most, so that the
     * debits come to what the supplier is owed.
     *
     * @param array<string, numeric-string> $parts
     *
     * @return array<string, numeric-string>
     *
     * @throws PostingException
     */
    private function withRounding(array $parts, string $rounding): array
    {
        if ($parts === [] || bccomp($rounding, '0', self::SCALE) === 0) {
            return $parts;
        }

        $largest = array_key_first($parts);

        foreach ($parts as $key => $amount) {
            if (bccomp($amount, $parts[$largest], self::SCALE) > 0) {
                $largest = $key;
            }
        }

        $parts[$largest] = bcadd($parts[$largest], $rounding, self::SCALE);

        if (bccomp($parts[$largest], '0', self::SCALE) < 0) {
            throw new PostingException(PostingError::PurchaseNotPostable, 'The purchase is too small for its total to be kept to two decimals.');
        }

        return $parts;
    }

    /**
     * Whoever the goods are paid to: the supplier, or the account the purchase is paid from when
     * there is no supplier, which is only possible when it is paid in full.
     *
     * @throws PostingException
     */
    private function creditor(ReceivingDocument $purchase): int
    {
        if ($purchase->supplierId !== null) {
            return $this->required(RoutingKey::Suppliers, $purchase->supplierId);
        }

        $account = $this->paymentAccount($purchase->paymentType);

        if ($account === null) {
            throw new PostingException(PostingError::SupplierRequired, 'A purchase that is not paid at once needs a supplier to owe it.');
        }

        return $account;
    }

    /**
     * The account a purchase is paid from, or null when it is left on credit.
     *
     * @throws PostingException
     */
    private function paymentAccount(?string $label): ?int
    {
        if ($label === null) {
            return null;
        }

        $type = PaymentType::fromLabel($label);

        if ($type === null || $type === PaymentType::Giftcard || $type === PaymentType::Rewards) {
            throw new PostingException(PostingError::PaymentTypeUnknown, "The payment type {$label} is not one a purchase can be paid with.", ['payment_type' => $label]);
        }

        return $this->resolver->accountForPayment($type);
    }

    /**
     * @throws PostingException
     */
    private function assertPostable(ReceivingDocument $purchase): void
    {
        $actual = ReceivingType::ofQuantities(array_map(static fn (ReceivingLine $line): string => $line->units(), $purchase->lines));

        if ($actual !== $purchase->type && $purchase->lines !== []) {
            throw new PostingException(PostingError::PurchaseNotPostable, 'The lines of the purchase do not fit a ' . $purchase->type->value . '.', ['type' => $purchase->type->value]);
        }
    }

    /**
     * A return gives back what one purchase brought in, to the supplier it came from.
     *
     * @throws PostingException
     */
    private function assertReturnOrigin(ReceivingDocument $purchase): void
    {
        $stock = array_filter($purchase->lines, static fn (ReceivingLine $line): bool => $line->hasStock);

        if ($stock === []) {
            return;
        }

        $origin = $purchase->origin;

        if ($origin === null) {
            throw new PostingException(PostingError::PurchaseReturnOriginRequired, 'A return of stock items must say which purchase it takes them back from.');
        }

        if ($origin->type !== ReceivingType::Receive || $origin->supplierId !== $purchase->supplierId) {
            throw new PostingException(PostingError::PurchaseReturnOriginInvalid, 'A return can only be taken from a purchase of the same supplier.', ['origin_id' => $origin->receivingId]);
        }

        $bought = [];

        foreach ($origin->lines as $line) {
            $bought[$line->itemId] = true;
        }

        foreach ($stock as $line) {
            if (! isset($bought[$line->itemId])) {
                throw new PostingException(PostingError::PurchaseReturnLineNotOnOrigin, 'An item of the return is not on the purchase it comes from.', ['item_id' => $line->itemId, 'origin_id' => $origin->receivingId]);
            }
        }
    }

    /**
     * @throws PostingException
     */
    private function required(RoutingKey $key, ?int $entityId = null): int
    {
        $account = $this->resolver->accountFor($key, $entityId);

        if ($account === null) {
            throw new PostingException(PostingError::RoutingRowCancelled, "The routing of {$key->value} is cancelled, but a purchase cannot be posted without it.", ['routing_key' => $key->value]);
        }

        return $account;
    }
}
