<?php

namespace App\Libraries\Accounting;

enum ChequeStatus: string
{
    case Received  = 'received';
    case Deposited = 'deposited';
    case Collected = 'collected';
    case Rejected  = 'rejected';
    case Issued    = 'issued';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function isFirst(): bool
    {
        return $this === self::Received || $this === self::Issued;
    }

    public function labelKey(): string
    {
        return 'Accounting_cheques.status_' . $this->value;
    }

    /**
     * @return list<self>
     */
    public static function of(ChequeDirection $direction): array
    {
        return $direction === ChequeDirection::Received
            ? [self::Received, self::Deposited, self::Collected, self::Rejected]
            : [self::Issued, self::Delivered, self::Collected, self::Rejected, self::Cancelled];
    }
}
