<?php

namespace App\Libraries\Accounting;

enum ChequeTransition: string
{
    case Receive        = 'receive';
    case Deposit        = 'deposit';
    case Collect        = 'collect';
    case DepositCollect = 'dcollect';
    case Reject         = 'reject';
    case DepositReject  = 'dreject';
    case Issue          = 'issue';
    case Deliver        = 'deliver';
    case PayCollect     = 'pcollect';
    case PayReject      = 'preject';
    case Cancel         = 'cancel';

    public function source(): ?string
    {
        return match ($this) {
            self::Receive        => ChequeSources::RECEIVED,
            self::Deposit        => ChequeSources::DEPOSITED,
            self::Collect        => ChequeSources::COLLECTED,
            self::DepositCollect => ChequeSources::DEPOSIT_COLLECTED,
            self::Reject         => ChequeSources::REJECTED,
            self::DepositReject  => ChequeSources::DEPOSIT_REJECTED,
            self::Deliver        => ChequeSources::DELIVERED,
            self::PayCollect     => ChequeSources::PAID_COLLECTED,
            self::PayReject      => ChequeSources::PAID_REJECTED,
            default              => null,
        };
    }

    public function fromStatus(): ?ChequeStatus
    {
        return match ($this) {
            self::Receive, self::Issue => null,
            self::Deposit, self::Collect, self::Reject => ChequeStatus::Received,
            self::DepositCollect, self::DepositReject => ChequeStatus::Deposited,
            self::Deliver, self::Cancel => ChequeStatus::Issued,
            self::PayCollect, self::PayReject => ChequeStatus::Delivered,
        };
    }

    public function toStatus(): ChequeStatus
    {
        return match ($this) {
            self::Receive => ChequeStatus::Received,
            self::Deposit => ChequeStatus::Deposited,
            self::Collect, self::DepositCollect, self::PayCollect => ChequeStatus::Collected,
            self::Reject, self::DepositReject, self::PayReject => ChequeStatus::Rejected,
            self::Issue   => ChequeStatus::Issued,
            self::Deliver => ChequeStatus::Delivered,
            self::Cancel  => ChequeStatus::Cancelled,
        };
    }

    public function direction(): ChequeDirection
    {
        return match ($this) {
            self::Receive, self::Deposit, self::Collect, self::DepositCollect, self::Reject, self::DepositReject => ChequeDirection::Received,
            default => ChequeDirection::Paid,
        };
    }

    public function isCollection(): bool
    {
        return $this === self::Collect || $this === self::DepositCollect || $this === self::PayCollect;
    }

    /**
     * @return list<string>
     */
    public static function sourcesOf(ChequeDirection $direction): array
    {
        $sources = [];

        foreach (self::cases() as $transition) {
            if ($transition->source() !== null && $transition->direction() === $direction) {
                $sources[] = $transition->source();
            }
        }

        return $sources;
    }
}
