<?php

namespace App\Libraries\Accounting;

/**
 * Where a payment stands. Only a completed payment is in the books and settles an invoice;
 * the others are kept on the sale and posted when, and if, they are completed.
 */
enum PaymentStatus: string
{
    case Incomplete = 'incomplete';
    case Completed  = 'completed';
    case Review     = 'review';
    case Failed     = 'failed';
    case Draft      = 'draft';

    public function posts(): bool
    {
        return $this === self::Completed;
    }

    public static function fromStored(?string $status): self
    {
        return self::tryFrom((string) $status) ?? self::Completed;
    }

    public function languageKey(): string
    {
        return 'Sales.payment_status_' . $this->value;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
