<?php

namespace App\Libraries\Purchases;

use App\Libraries\Accounting\PaymentState;
use App\Libraries\Accounting\ReceivingType;
use App\Libraries\Accounting\ReturnState;
use App\Libraries\Accounting\SaleAmounts;

/**
 * A purchase invoice, or a return to a supplier, as the list shows it. The total is what the document comes to
 * with its discounts and tax and is never negative; a return counts against the purchases when they are added up.
 */
final readonly class PurchaseInvoiceRow
{
    /**
     * @param numeric-string $total
     * @param numeric-string $paid
     * @param numeric-string $due
     */
    public function __construct(
        public int $receivingId,
        public string $number,
        public string $date,
        public string $enteredAt,
        public ReceivingType $type,
        public ?int $supplierId,
        public string $supplierName,
        public string $employeeName,
        public ?int $branchId,
        public string $branchCode,
        public string $reference,
        public ?string $originNumber,
        public string $total,
        public string $paid,
        public string $due,
        public ?PaymentState $state,
        public int $overdueDays,
        public ReturnState $returnState,
        public ?string $currencyCode,
        public int $decimals,
    ) {
    }

    public function isReturn(): bool
    {
        return $this->type === ReceivingType::Return;
    }

    public function isForeign(): bool
    {
        return $this->currencyCode !== null;
    }

    /**
     * @return numeric-string
     */
    public function signedTotal(): string
    {
        return $this->isReturn() ? bcsub('0', $this->total, SaleAmounts::SCALE) : bcadd($this->total, '0', SaleAmounts::SCALE);
    }
}
