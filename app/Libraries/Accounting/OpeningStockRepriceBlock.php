<?php

namespace App\Libraries\Accounting;

/**
 * What stands in the way of posting the opening stock of a location again at the cost its items
 * carry now. Every one of them leaves the books as they are.
 */
enum OpeningStockRepriceBlock: string
{
    case NoOpening         = 'no_opening';
    case LaterMovements    = 'later_movements';
    case AccountHoldsValue = 'account_holds_value';
    case PeriodClosed      = 'period_closed';
    case NothingToChange   = 'nothing_to_change';
}
