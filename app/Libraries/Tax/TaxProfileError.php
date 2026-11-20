<?php

namespace App\Libraries\Tax;

enum TaxProfileError: string
{
    case AccountingDisabled   = 'accounting_disabled';
    case EffectiveDateInvalid = 'effective_date_invalid';
    case EffectiveDateInPast  = 'effective_date_in_past';
    case ModeAlreadyOff       = 'mode_already_off';
    case ModeAlreadyOn        = 'mode_already_on';
    case ModeNeedsNumber      = 'mode_needs_number';
    case ModeNeedsRegistered  = 'mode_needs_registered';
    case NumberInvalid        = 'number_invalid';
    case NumberTooLong        = 'number_too_long';
    case ReasonRequired       = 'reason_required';
    case ReasonTooLong        = 'reason_too_long';
}
