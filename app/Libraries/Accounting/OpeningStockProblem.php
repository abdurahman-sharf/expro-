<?php

namespace App\Libraries\Accounting;

/**
 * What stops stock being posted as opening stock, or leaves one item out of it. None of these
 * is fixed by the posting: each is a decision for a person, and is reported and left alone.
 */
enum OpeningStockProblem: string
{
    case AccountMissing         = 'account_missing';
    case AccountHoldsValue      = 'account_holds_value';
    case PostedBefore           = 'posted_before';
    case NegativeStock          = 'negative_stock';
    case MovementsExceedShelves = 'movements_exceed_shelves';
}
