<?php

namespace App\Libraries\Tax;

enum VatClassificationError: string
{
    case CategoryInvalid      = 'category_invalid';
    case ClassNotFound        = 'class_not_found';
    case CodeInvalid          = 'code_invalid';
    case CodeTaken            = 'code_taken';
    case DateInvalid          = 'date_invalid';
    case ExemptionCodeInvalid = 'exemption_code_invalid';
    case ExemptionNotAllowed  = 'exemption_not_allowed';
    case ExemptionTextMissing = 'exemption_text_missing';
    case FirstRateAfterToday  = 'first_rate_after_today';
    case NameMissing          = 'name_missing';
    case NoRateOnDate         = 'no_rate_on_date';
    case RateBeforeToday      = 'rate_before_today';
    case RateInvalid          = 'rate_invalid';
    case RateNotAfterLast     = 'rate_not_after_last';
    case TaxNameMissing       = 'tax_name_missing';
}
