<?php

namespace App\Libraries;

/**
 * The discount on the whole of a sale as it is saved with it: what was entered, what it comes to,
 * and the share each line carries. The shares carry the sign of their line's quantity, so that the
 * lines of a return take back what the sale gave.
 */
final readonly class InvoiceDiscountPlan
{
    /**
     * @param array<int|string, string> $shares By the key of the line.
     */
    public function __construct(
        public ?int $type,
        public ?string $value,
        public string $amount,
        public array $shares,
    ) {
    }

    public function isEmpty(): bool
    {
        return bccomp($this->amount, '0', InvoiceDiscount::SCALE) === 0;
    }

    public function share(int|string $key): string
    {
        return $this->shares[$key] ?? '0.0000';
    }

    /**
     * The rows of a cart with the share each carries, or without one when there is no discount.
     *
     * @param array<int|string, array<string, mixed>> $rows
     *
     * @return array<int|string, array<string, mixed>>
     */
    public function applyTo(array $rows): array
    {
        foreach ($rows as $key => $row) {
            unset($rows[$key]['invoice_discount_share']);

            if (! $this->isEmpty()) {
                $rows[$key]['invoice_discount_share'] = $this->share($key);
            }
        }

        return $rows;
    }
}
