<?php

namespace App\Libraries\Accounting\FamilyReports;

enum ColumnKind: string
{
    case Text     = 'text';
    case Amount   = 'amount';
    case Quantity = 'quantity';
    case Date     = 'date';
    case Plain    = 'plain';

    public function numeric(): bool
    {
        return $this !== self::Text && $this !== self::Date;
    }
}
