<?php

namespace App\Libraries\Accounting;

final readonly class SalePayment
{
    public function __construct(
        public int $paymentId,
        public string $type,
        public string $amount,
        public string $cashRefund,
        public bool $cashAdjustment,
        public string $date,
        public PaymentStatus $status = PaymentStatus::Completed,
    ) {
    }
}
