<?php

namespace App\Libraries\Accounting;

final class ChequeSources
{
    public const RECEIVED          = 'cheque_in_received';
    public const DEPOSITED         = 'cheque_in_deposited';
    public const COLLECTED         = 'cheque_in_collected';
    public const DEPOSIT_COLLECTED = 'cheque_in_dep_collected';
    public const REJECTED          = 'cheque_in_rejected';
    public const DEPOSIT_REJECTED  = 'cheque_in_dep_rejected';
    public const DELIVERED         = 'cheque_out_delivered';
    public const PAID_COLLECTED    = 'cheque_out_collected';
    public const PAID_REJECTED     = 'cheque_out_rejected';

    public const ALL = [
        self::RECEIVED,
        self::DEPOSITED,
        self::COLLECTED,
        self::DEPOSIT_COLLECTED,
        self::REJECTED,
        self::DEPOSIT_REJECTED,
        self::DELIVERED,
        self::PAID_COLLECTED,
        self::PAID_REJECTED,
    ];
}
