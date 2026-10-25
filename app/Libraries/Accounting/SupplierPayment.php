<?php

namespace App\Libraries\Accounting;

final readonly class SupplierPayment
{
    public function __construct(
        public int $paymentId,
        public string $type,
        public string $amount,
        public string $date,
        public PaymentStatus $status = PaymentStatus::Completed,
        public ?int $treasuryId = null,
    ) {
    }
}
