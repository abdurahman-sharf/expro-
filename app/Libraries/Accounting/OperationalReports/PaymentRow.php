<?php

namespace App\Libraries\Accounting\OperationalReports;

/**
 * A payment of a customer as the payments reports count it: what its journal credited the account of the
 * customer, a refund below nothing, and what the same journal put on the other side, the account the money
 * went into, read apart so that the two can be compared. The method is the one the payment was made by,
 * which the account the money went into does not say.
 */
final readonly class PaymentRow
{
    public function __construct(
        public int $paymentId,
        public int $saleId,
        public string $date,
        public int $branchId,
        public string $branchCode,
        public ?int $customerId,
        public string $customerName,
        public string $invoiceNumber,
        public ?int $userId,
        public string $userName,
        public ?int $collectorId,
        public string $collectorName,
        public string $methodKey,
        public string $method,
        public string $reference,
        public int $moneyAccountId,
        public string $moneyAccount,
        public string $amount,
        public string $ledgerMoney,
    ) {
    }
}
