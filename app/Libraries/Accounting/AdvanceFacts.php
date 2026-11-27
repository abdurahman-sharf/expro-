<?php

namespace App\Libraries\Accounting;

use App\Libraries\Accounting\Engine\PostingFacts;

/**
 * The figures of money paid in by a customer or out to a supplier with no invoice behind it, for the
 * posting engine: the amount, the party, and the treasury or the way of paying the money went through.
 * The treasury and the way of paying were already checked when the advance was taken.
 */
final class AdvanceFacts
{
    public static function customer(int $advanceId, int $customerId, string $amount, string $date, string $paymentType, ?int $treasuryId, int $branchId, ?int $employeeId): PostingFacts
    {
        return self::of(CustomerAdvances::SOURCE, $advanceId, $customerId, $amount, $date, $paymentType, $treasuryId, $branchId, $employeeId, 'Advance payment from customer #' . $customerId);
    }

    public static function supplier(int $advanceId, int $supplierId, string $amount, string $date, string $paymentType, ?int $treasuryId, int $branchId, ?int $employeeId): PostingFacts
    {
        return self::of(SupplierAdvances::SOURCE, $advanceId, $supplierId, $amount, $date, $paymentType, $treasuryId, $branchId, $employeeId, 'Payment to supplier #' . $supplierId . ' without a purchase');
    }

    private static function of(string $operation, int $advanceId, int $partyId, string $amount, string $date, string $paymentType, ?int $treasuryId, int $branchId, ?int $employeeId, string $description): PostingFacts
    {
        return new PostingFacts(
            operation: $operation,
            sourceId: $advanceId,
            date: $date,
            branchId: $branchId,
            employeeId: $employeeId,
            figures: ['amount' => bcadd($amount, '0', SaleAmounts::SCALE)],
            fields: ['party_id' => $partyId, 'treasury_id' => $treasuryId, 'payment_type' => $paymentType],
            flags: ['by_treasury' => $treasuryId !== null, 'by_payment' => $treasuryId === null],
            description: $description,
        );
    }
}
