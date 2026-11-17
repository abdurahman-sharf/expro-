<?php

namespace App\Libraries\Purchases;

final readonly class PurchaseInvoiceFilters
{
    public const STATUSES = ['received', 'return', 'has_returns', 'partly_returned', 'fully_returned'];
    public const PAYMENTS = ['unpaid', 'partial', 'paid', 'overdue'];

    public function __construct(
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
        public int $supplierId = 0,
        public string $status = '',
        public string $payment = '',
        public string $search = '',
    ) {
    }

    /**
     * What a request asks for, with whatever is not a day, a number or one of the known values left out.
     */
    public static function fromInput(mixed $input): self
    {
        $get  = is_array($input) ? $input : [];
        $text = static fn (string $name): string => isset($get[$name]) && is_string($get[$name]) ? trim($get[$name]) : '';

        $status  = $text('status');
        $payment = $text('payment');

        return new self(
            self::day($text('date_from')),
            self::day($text('date_to')),
            ctype_digit($text('supplier_id')) ? (int) $text('supplier_id') : 0,
            in_array($status, self::STATUSES, true) ? $status : '',
            in_array($payment, self::PAYMENTS, true) ? $payment : '',
            $text('search'),
        );
    }

    /**
     * Whether what is asked for cannot be told from the purchases alone but needs what was paid and returned.
     */
    public function needsFigures(): bool
    {
        return $this->payment !== '' || $this->status === 'partly_returned' || $this->status === 'fully_returned';
    }

    /**
     * How many of the filters of the card are set, for the card to say while it is folded.
     */
    public function active(): int
    {
        return count(array_filter([$this->dateFrom !== null || $this->dateTo !== null, $this->supplierId > 0, $this->status !== '', $this->payment !== ''], static fn (bool $set): bool => $set));
    }

    private static function day(string $text): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return $text;
    }
}
