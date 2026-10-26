<?php

namespace App\Libraries\Accounting;

enum LegacyDiscountVerdict: string
{
    case LegacyByBooks  = 'legacy_by_books';
    case PerUnitByBooks = 'per_unit_by_books';
    case LegacyByDate   = 'legacy_by_date';
    case Undetermined   = 'undetermined';
}
