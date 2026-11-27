<?php

namespace App\Libraries\Accounting;

/**
 * What the facts of a payment to a supplier share: the ways of paying a supplier can be paid with, the
 * account the money goes through (only to know whether a payment moves any money), and the conversion
 * of a foreign amount to the base currency, as the journal of the payment converts it.
 */
final class SupplierPaymentType
{
    /**
     * @throws PostingException
     */
    public static function of(string $label): PaymentType
    {
        $type = PaymentType::fromLabel($label);

        if ($type === null || $type === PaymentType::Giftcard || $type === PaymentType::Rewards) {
            throw new PostingException(PostingError::PaymentTypeUnknown, "The payment type {$label} is not one a purchase can be paid with.", ['payment_type' => $label]);
        }

        return $type;
    }

    /**
     * Whether the money of a payment goes through any account: a payment left on credit moves none.
     *
     * @throws PostingException
     */
    public static function movesMoney(?string $label, ?int $treasuryId, RoutingResolver $resolver): bool
    {
        if ($label === null) {
            return false;
        }

        $type = self::of($label);

        $account = $treasuryId !== null && $type->usesTreasury()
            ? $resolver->accountFor(RoutingKey::Treasuries, $treasuryId)
            : $resolver->accountForPayment($type);

        return $account !== null;
    }

    /**
     * A foreign amount at a rate, its size converted and its sign kept. An amount that comes to nothing
     * in the base currency is refused, as the conversion of the journal refuses it.
     *
     * @return numeric-string
     *
     * @throws PostingException
     */
    public static function toBase(string $amount, string $rate, int $baseDecimals): string
    {
        $base = Money::toBase(SaleAmounts::absolute($amount), $rate, $baseDecimals);

        if (bccomp($base, '0', SaleAmounts::SCALE) === 0 && bccomp($amount, '0', SaleAmounts::SCALE) !== 0) {
            throw new PostingException(PostingError::ForeignRoundingTooBig, 'The line that balances the journal would come to nothing.', ['line' => 0]);
        }

        return bccomp($amount, '0', SaleAmounts::SCALE) < 0 ? bcmul($base, '-1', SaleAmounts::SCALE) : $base;
    }
}
