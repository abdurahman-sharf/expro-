<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\PaymentState;
use App\Libraries\Accounting\ReceivingType;
use App\Libraries\Accounting\SaleAmounts;

final readonly class PurchaseDocument
{
    /**
     * @param numeric-string      $value        What the lines come to before any discount.
     * @param numeric-string      $discount     The discounts of the lines and the share of the discount of the invoice.
     * @param numeric-string      $tax
     * @param numeric-string      $total        What the document comes to, always positive.
     * @param numeric-string      $settled      What was paid against it and what credit was applied to it; nothing on a return.
     * @param numeric-string|null $journalTotal What the journal that stands for it says, null when none does.
     * @param list<PurchaseLine>  $lines
     */
    public function __construct(
        public int $receivingId,
        public string $number,
        public string $date,
        public ReceivingType $type,
        public ?int $supplierId,
        public string $supplierName,
        public string $taxNumber,
        public ?int $employeeId,
        public string $employeeName,
        public ?int $branchId,
        public string $branchCode,
        public string $value,
        public string $discount,
        public string $tax,
        public string $total,
        public string $settled,
        public ?string $journalTotal,
        public array $lines,
        public ?PaymentState $state,
    ) {
    }

    public function isReturn(): bool
    {
        return $this->type === ReceivingType::Return;
    }

    /**
     * What the document adds to the net purchases: the total, taken away on a return.
     *
     * @return numeric-string
     */
    public function signed(string $amount): string
    {
        return $this->isReturn() ? bcsub('0', $amount, SaleAmounts::SCALE) : bcadd($amount, '0', SaleAmounts::SCALE);
    }

    /**
     * @return numeric-string
     */
    public function open(): string
    {
        if ($this->isReturn()) {
            return '0.0000';
        }

        $left = bcsub($this->total, $this->settled, SaleAmounts::SCALE);

        return bccomp($left, '0', SaleAmounts::SCALE) > 0 ? $left : '0.0000';
    }
}
