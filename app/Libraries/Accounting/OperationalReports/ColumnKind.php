<?php

namespace App\Libraries\Accounting\OperationalReports;

enum ColumnKind: string
{
    case Text     = 'text';
    case Date     = 'date';
    case Amount   = 'amount';
    case Quantity = 'quantity';
    case Percent  = 'percent';

    public function isNumber(): bool
    {
        return $this !== self::Text && $this !== self::Date;
    }
}
