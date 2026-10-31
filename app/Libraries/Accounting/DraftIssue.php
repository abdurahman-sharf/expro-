<?php

namespace App\Libraries\Accounting;

use App\Libraries\InvoiceDiscountProblem;

/**
 * What it takes to save a draft invoice as an issued one, worked out from what the draft holds: its
 * lines as the register would carry them, the taxes they come to now, its payments and the discount
 * on the whole of it.
 */
final readonly class DraftIssue
{
    /**
     * @param array<int, array<string, mixed>>                                            $items
     * @param array{0: array<string, array<string, mixed>>, 1: list<array<string, mixed>>} $taxes
     * @param array<string, array<string, mixed>>                                          $payments
     * @param array{type: int, value: string}|null                                         $invoiceDiscount
     */
    public function __construct(
        public int $saleId,
        public array $items,
        public array $taxes,
        public array $payments,
        public ?int $customerId,
        public string $comment,
        public ?int $dinnerTableId,
        public bool $delivered,
        public ?array $invoiceDiscount,
        public ?string $invoiceNumber,
        public ?string $invoiceDate,
        public ?int $paymentTermsDays,
        public bool $paidWithStoredValue,
        public ?InvoiceDiscountProblem $problem,
        public ?SaleDocument $document,
    ) {
    }
}
